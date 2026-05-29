<?php
// api/auth_register.php — สมัครสมาชิกผู้ใช้ใหม่
require __DIR__ . '/db.php';

$in = json_input();
if (!$in) json_error("invalid json body", 400);

$name     = isset($in['name']) ? trim((string)$in['name']) : '';
$email    = isset($in['email']) ? strtolower(trim((string)$in['email'])) : '';
$phone    = isset($in['phone']) ? trim((string)$in['phone']) : '';
$password = isset($in['password']) ? (string)$in['password'] : '';
$vehicles = isset($in['vehicles']) ? $in['vehicles'] : [];

if ($name === '' || $email === '' || $phone === '' || $password === '') {
  json_error("name, email, phone, password are required", 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  json_error("invalid email", 422);
}

$phone = preg_replace('/\D+/', '', $phone);
if (!preg_match('/^0[0-9]{9}$/', $phone)) {
  json_error("phone must be 10 digits and start with 0", 422);
}

if (mb_strlen($password) < 6) {
  json_error("password must be at least 6 characters", 422);
}

if (!is_array($vehicles) || count($vehicles) < 1 || count($vehicles) > 2) {
  json_error("vehicles must contain 1 or 2 license plates", 422);
}

$cleanVehicles = [];
$seenPlates = [];
$seenSlots = [];

foreach ($vehicles as $v) {
  $plate = strtoupper(trim((string)($v['plate_number'] ?? '')));
  $plate = preg_replace('/\s+/', '', $plate);
  $slot  = (int)($v['plate_slot'] ?? 0);

  if ($plate === '') {
    json_error("plate_number is required", 422);
  }

  if (!in_array($slot, [1, 2], true)) {
    json_error("plate_slot must be 1 or 2", 422);
  }

  if (isset($seenPlates[$plate])) {
    json_error("duplicate plate number in request", 422);
  }

  if (isset($seenSlots[$slot])) {
    json_error("duplicate plate slot in request", 422);
  }

  $seenPlates[$plate] = true;
  $seenSlots[$slot] = true;

  $cleanVehicles[] = [
    'plate_number' => $plate,
    'plate_slot'   => $slot,
  ];
}

try {
  $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
  $chk->execute([$email]);
  if ($chk->fetch()) {
    json_error("email already registered", 409);
  }

  foreach ($cleanVehicles as $v) {
    $chkPlate = $pdo->prepare("SELECT id FROM user_vehicles WHERE plate_number = ? LIMIT 1");
    $chkPlate->execute([$v['plate_number']]);
    if ($chkPlate->fetch()) {
      json_error("license plate already exists: " . $v['plate_number'], 409);
    }
  }

  $pdo->beginTransaction();

  $hash = password_hash($password, PASSWORD_BCRYPT);

  $ins = $pdo->prepare("
    INSERT INTO users (name, email, phone, password_hash, role, status, created_at, consent_accepted)
    VALUES (?, ?, ?, ?, 'user', 'active', NOW(), 0)
  ");
  $ins->execute([$name, $email, $phone, $hash]);

  $user_id = (int)$pdo->lastInsertId();

  $insVehicle = $pdo->prepare("
    INSERT INTO user_vehicles (user_id, plate_number, plate_slot, created_at)
    VALUES (?, ?, ?, NOW())
  ");

  foreach ($cleanVehicles as $v) {
    $insVehicle->execute([
      $user_id,
      $v['plate_number'],
      $v['plate_slot']
    ]);
  }

  $pdo->commit();

  json_ok([
    "ok"   => true,
    "user" => [
      "id"       => $user_id,
      "name"     => $name,
      "email"    => $email,
      "phone"    => $phone,
      "role"     => "user",
      "status"   => "active",
      "vehicles" => $cleanVehicles
    ]
  ], 201);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  json_error("server error", 500);
}