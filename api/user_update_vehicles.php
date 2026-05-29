<?php
/**
 * api/user_update_vehicles.php
 * อัปเดตทะเบียนรถผู้ใช้ได้สูงสุด 2 คัน
 *
 * Request JSON:
 * {
 *   "user_id": 2,
 *   "vehicles": [
 *     { "plate_number": "กข1234", "plate_slot": 1 },
 *     { "plate_number": "1กข5678", "plate_slot": 2 }
 *   ]
 * }
 *
 * Response JSON (สำเร็จ):
 *   { "ok": true, "user": { ... } }
 */

require __DIR__ . '/db.php';

$in = json_input();
if (!$in) {
  json_error('invalid json body', 400);
}

$userId   = (int)($in['user_id'] ?? 0);
$vehicles = $in['vehicles'] ?? null;

if ($userId <= 0) {
  json_error('user_id is required', 422);
}
if (!is_array($vehicles)) {
  json_error('vehicles is required', 422);
}
if (count($vehicles) < 1 || count($vehicles) > 2) {
  json_error('vehicles must contain 1 or 2 license plates', 422);
}

$cleanVehicles = [];
$seenPlates = [];
$seenSlots = [];

foreach ($vehicles as $v) {
  $plate = strtoupper(trim((string)($v['plate_number'] ?? '')));
  $plate = preg_replace('/\s+/', '', $plate);
  $slot  = (int)($v['plate_slot'] ?? 0);

  if ($plate === '') {
    json_error('plate_number is required', 422);
  }

  if (mb_strlen($plate) < 3) {
    json_error('ทะเบียนรถสั้นเกินไป', 422);
  }

  if (!in_array($slot, [1, 2], true)) {
    json_error('plate_slot must be 1 or 2', 422);
  }

  if (isset($seenPlates[$plate])) {
    json_error('ทะเบียนรถทั้ง 2 คันห้ามซ้ำกัน', 422);
  }

  if (isset($seenSlots[$slot])) {
    json_error('duplicate plate slot in request', 422);
  }

  $seenPlates[$plate] = true;
  $seenSlots[$slot] = true;

  $cleanVehicles[] = [
    'plate_number' => $plate,
    'plate_slot'   => $slot,
  ];
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

  // ตรวจว่าทะเบียนซ้ำกับ user คนอื่นหรือไม่
  $chkPlate = $pdo->prepare("
    SELECT uv.id
    FROM user_vehicles uv
    WHERE uv.plate_number = ?
      AND uv.user_id <> ?
    LIMIT 1
  ");

  foreach ($cleanVehicles as $v) {
    $chkPlate->execute([$v['plate_number'], $userId]);
    if ($chkPlate->fetch()) {
      json_error('license plate already exists: ' . $v['plate_number'], 409);
    }
  }

  $pdo->beginTransaction();

  // ลบของเดิมทั้งหมดก่อน
  $del = $pdo->prepare("
    DELETE FROM user_vehicles
    WHERE user_id = ?
  ");
  $del->execute([$userId]);

  // เพิ่มใหม่
  $ins = $pdo->prepare("
    INSERT INTO user_vehicles (user_id, plate_number, plate_slot, created_at)
    VALUES (?, ?, ?, NOW())
  ");

  foreach ($cleanVehicles as $v) {
    $ins->execute([
      $userId,
      $v['plate_number'],
      $v['plate_slot']
    ]);
  }

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