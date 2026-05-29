<?php
// รายชื่อผู้ใช้ทั้งหมด (สำหรับแอดมิน)
require __DIR__ . '/db.php';

try {
  $stmt = $pdo->query("
    SELECT
      id,
      name,
      email,
      phone,
      role,
      status,
      created_at
    FROM users
    ORDER BY id ASC
  ");
  $users = $stmt->fetchAll();

  if ($users) {
    $vq = $pdo->prepare("
      SELECT plate_number, plate_slot
      FROM user_vehicles
      WHERE user_id = ?
      ORDER BY plate_slot ASC
    ");

    foreach ($users as &$u) {
      $vq->execute([(int)$u['id']]);
      $u['vehicles'] = $vq->fetchAll();
    }
    unset($u);
  }

  echo json_encode(['ok' => true, 'users' => $users], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db error'], JSON_UNESCAPED_UNICODE);
}