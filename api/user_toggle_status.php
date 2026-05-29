<?php
// บล็อก / ปลดบล็อก ผู้ใช้ (status: active <-> blocked)
require __DIR__ . '/db.php';

$input  = json_decode(file_get_contents('php://input'), true);
$id     = isset($input['id']) ? (int)$input['id'] : 0;
$status = $input['status'] ?? '';

$allow = ['active','blocked'];
if ($id <= 0 || !in_array($status, $allow, true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid payload']);
  exit;
}

try {
  $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id");
  $stmt->execute([':status' => $status, ':id' => $id]);

  echo json_encode(['ok' => true, 'user' => ['id' => $id, 'status' => $status]]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db error']);
}
