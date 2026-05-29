<?php
// สรุปจำนวนการจองรายวัน แยกตามสถานะ (PENDING, CONFIRMED, CANCELLED, EXPIRED)
require __DIR__ . '/db.php';

// อ่านช่วงเวลา ?from=YYYY-MM-DD&to=YYYY-MM-DD
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

if (!$from || !$to) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'missing from/to date']);
  exit;
}

try {
  // คิวรีนับการจองต่อวันต่อ status
  $sql = "
    SELECT DATE(start_time) AS d, status, COUNT(*) AS cnt
    FROM bookings
    WHERE DATE(start_time) BETWEEN :from AND :to
    GROUP BY d, status
    ORDER BY d ASC
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':from'=>$from, ':to'=>$to]);
  $rows = $stmt->fetchAll();

  // เตรียมโครงสร้างวันต่อวัน
  $period = new DatePeriod(
    new DateTime($from),
    new DateInterval('P1D'),
    (new DateTime($to))->modify('+1 day') // inclusive
  );

  $map = [];
  foreach ($period as $dt) {
    $map[$dt->format('Y-m-d')] = [
      'date' => $dt->format('Y-m-d'),
      'PENDING'=>0, 'CONFIRMED'=>0, 'CANCELLED'=>0, 'EXPIRED'=>0
    ];
  }

  foreach ($rows as $r) {
    $d = $r['d'];
    $st = $r['status'];
    $cnt = (int)$r['cnt'];
    if (isset($map[$d][$st])) {
      $map[$d][$st] = $cnt;
    }
  }

  echo json_encode(['ok'=>true, 'series'=>array_values($map)]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'db error']);
}
