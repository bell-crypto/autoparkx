<?php
/**
 * api/user_update_name.php
 * อัปเดตชื่อผู้ใช้ + บันทึกประวัติ
 *
 * Request JSON:
 *   { "user_id": 2, "name": "bell bell" }
 *
 * Response JSON (สำเร็จ):
 *   { "ok": true, "user": { ... } }
 */

require __DIR__ . '/db.php';

$in = json_input();
if (!$in) {
  json_error('invalid json body', 400);
}

$userId = (int)($in['user_id'] ?? 0);
$name   = trim((string)($in['name'] ?? ''));

if ($userId <= 0) {
  json_error('user_id is required', 422);
}
if ($name === '') {
  json_error('name is required', 422);
}
if (mb_strlen($name) > 80) {
  json_error('name is too long', 422);
}
if (mb_strlen($name) < 2) {
  json_error('name is too short', 422);
}

try {
  $q = $pdo->prepare("
    SELECT
      id,
      name,
      email,
      phone,
      role,
      status,
      avatar_url,
      created_at,
      consent_accepted,
      consent_version,
      consent_accepted_at,
      last_name_changed_at
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $q->execute([$userId]);
  $user = $q->fetch();

  if (!$user) {
    json_error('user not found', 404);
  }

  if (isset($user['status']) && strtolower((string)$user['status']) !== 'active') {
    json_error('บัญชีนี้ถูกระงับการใช้งาน', 403);
  }

  $oldName = (string)($user['name'] ?? '');

  if ($name === $oldName) {
    $vq = $pdo->prepare("
      SELECT plate_number, plate_slot
      FROM user_vehicles
      WHERE user_id = ?
      ORDER BY plate_slot ASC
    ");
    $vq->execute([$userId]);
    $user['vehicles'] = $vq->fetchAll();

    json_ok(['user' => $user]);
  }

  $pdo->beginTransaction();

  $u = $pdo->prepare("
    UPDATE users
    SET name = ?, last_name_changed_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $u->execute([$name, $userId]);

  $h = $pdo->prepare("
    INSERT INTO user_name_history (user_id, old_name, new_name)
    VALUES (?, ?, ?)
  ");
  $h->execute([$userId, $oldName, $name]);

  $q2 = $pdo->prepare("
    SELECT
      id,
      name,
      email,
      phone,
      role,
      status,
      avatar_url,
      created_at,
      consent_accepted,
      consent_version,
      consent_accepted_at,
      last_name_changed_at
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $q2->execute([$userId]);
  $newUser = $q2->fetch();

  if (!$newUser) {
    $pdo->rollBack();
    json_error('user not found after update', 500);
  }

  $vq = $pdo->prepare("
    SELECT plate_number, plate_slot
    FROM user_vehicles
    WHERE user_id = ?
    ORDER BY plate_slot ASC
  ");
  $vq->execute([$userId]);
  $newUser['vehicles'] = $vq->fetchAll();

  $pdo->commit();

  json_ok(['user' => $newUser]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  json_error('server error', 500);
}