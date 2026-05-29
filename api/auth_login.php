<?php
require __DIR__ . '/db.php';

$in = json_input();
if (!$in) {
  json_error("invalid json body", 400);
}

$email = strtolower(trim((string)($in['email'] ?? '')));
$pass  = (string)($in['password'] ?? '');

if ($email === '' || $pass === '') {
  json_error('email and password are required', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  json_error('invalid email', 422);
}

try {
  $q = $pdo->prepare("
    SELECT 
      id,
      name,
      email,
      phone,
      password_hash,
      role,
      status,
      avatar_url,
      created_at,
      consent_accepted,
      consent_version,
      consent_accepted_at
    FROM users
    WHERE email = ?
    LIMIT 1
  ");
  $q->execute([$email]);
  $u = $q->fetch(PDO::FETCH_ASSOC);

  if (!$u || !password_verify($pass, $u['password_hash'])) {
    json_error('invalid credentials', 401);
  }

  if (strtolower((string)$u['status']) !== 'active') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'blocked'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $vq = $pdo->prepare("
    SELECT plate_number, plate_slot
    FROM user_vehicles
    WHERE user_id = ?
    ORDER BY plate_slot ASC
  ");
  $vq->execute([(int)$u['id']]);
  $vehicles = $vq->fetchAll(PDO::FETCH_ASSOC);

  unset($u['password_hash']);
  $u['vehicles'] = $vehicles;

  json_ok(['user' => $u]);
} catch (Throwable $e) {
  json_error('server error', 500);
}