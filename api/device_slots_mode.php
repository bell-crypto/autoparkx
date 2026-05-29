<?php
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function ok($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function err($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

// ===== device key =====
$deviceKey = $_SERVER['HTTP_X_DEVICE_KEY'] ?? '';
$k = $_GET['k'] ?? '';
if ($deviceKey !== 'esp32-lot-A' && $k !== 'esp32-lot-A') err('invalid device key', 401);

// ===== time window =====
$h = isset($_GET['h']) ? (int)$_GET['h'] : 24;
if ($h < 1) $h = 24;
if ($h > 168) $h = 168;

$now   = date('Y-m-d H:i:s');
$until = date('Y-m-d H:i:s', time() + $h * 3600);

// booking statuses that do NOT occupy
$INACTIVE_SET = "('CANCELLED','CANCELLED_BY_ADMIN','EXPIRED')";

try {
  // slots
  $rows = $pdo->query("SELECT id, code, status FROM parking_slots ORDER BY level, code")->fetchAll();

  // bookings overlap (now..until)
  $q = $pdo->prepare("
    SELECT COUNT(*)
    FROM bookings
    WHERE slot_id = ?
      AND (status IS NULL OR status NOT IN $INACTIVE_SET)
      AND start_time < ?
      AND end_time   > ?
  ");

  $out = [];

  foreach ($rows as $r) {
    $id   = (int)$r['id'];
    $code = (string)$r['code'];
    $base = strtoupper(trim((string)($r['status'] ?? 'AVAILABLE')));

    // ✅ PRIORITY: admin base status first (ห้ามโดน booking ทับ)
    if ($base === 'MAINTENANCE') {
      $mode = 'MAINT';
    } elseif ($base === 'HELD') {
      $mode = 'HELD';
    } elseif ($base === 'OCCUPIED') {
      $mode = 'OCCUPIED';
    } elseif ($base === 'BOOKED') {
      $mode = 'BOOKED';
    } else {
      // ✅ เฉพาะกรณีไม่ล็อกจากแอดมิน ค่อยดู booking overlap
      $q->execute([$id, $until, $now]);
      $has = (int)$q->fetchColumn() > 0;
      $mode = $has ? 'BOOKED' : 'NORMAL';
    }

    $out[] = ['code' => $code, 'mode' => $mode];
  }

  ok([
    'ok'    => true,
    'now'   => $now,
    'until' => $until,
    'slots' => $out
  ]);

} catch (Throwable $e) {
  err($e->getMessage(), 500);
}
