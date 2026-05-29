<?php
/**
 * api/user_update_phone.php
 * อัปเดตเบอร์โทรผู้ใช้
 *
 * Request JSON:
 *   { "user_id": 2, "phone": "0812345678" }
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
$phone  = trim((string)($in['phone'] ?? ''));

if ($userId <= 0) {
  json_error('user_id is required', 422);
}
if ($phone === '') {
  json_error('phone is required', 422);
}

$phone = preg_replace('/\D+/', '', $phone);
if (!preg_match('/^0[0-9]{9}$/', $phone)) {
  json_error('กรุณากรอกเบอร์โทร 10 หลักให้ถูกต้อง', 422);
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

  if ((string)($user['phone'] ?? '') === $phone) {
    json_ok(['user' => $user]);
  }

  $u = $pdo->prepare("
    UPDATE users
    SET phone = ?
    WHERE id = ?
    LIMIT 1
  ");
  $u->execute([$phone, $userId]);

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

  json_ok(['user' => $newUser]);
} catch (Throwable $e) {
  json_error('server error', 500);
}