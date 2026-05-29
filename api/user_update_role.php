<?php
// เปลี่ยน role ของผู้ใช้ (user <-> admin)
require __DIR__ . '/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$id    = isset($input['id']) ? (int)$input['id'] : 0;
$role  = $input['role'] ?? '';

$allow = ['user','admin'];
if ($id <= 0 || !in_array($role, $allow, true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid payload']);
  exit;
}

try {
  $stmt = $pdo->prepare("UPDATE users SET role = :role WHERE id = :id");
  $stmt->execute([':role' => $role, ':id' => $id]);

  echo json_encode(['ok' => true, 'user' => ['id' => $id, 'role' => $role]]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db error']);
}
