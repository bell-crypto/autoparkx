<?php
// api/device_push_occupied.php
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$deviceKey = $_SERVER['HTTP_X_DEVICE_KEY'] ?? '';
if ($deviceKey !== 'esp32-lot-A') {
  json_error('invalid device key', 401);
}

$in = json_input();
if (!$in) json_error('invalid json body', 400);

$slots = $in['slots'] ?? null;
if (!is_array($slots)) json_error('slots is required', 422);

try {
  $pdo->beginTransaction();

  $qSel = $pdo->prepare("SELECT id, status FROM parking_slots WHERE code=? LIMIT 1 FOR UPDATE");
  $qUpd = $pdo->prepare("UPDATE parking_slots SET status=?, last_update=NOW() WHERE id=?");

  foreach ($slots as $s) {
    $code = strtoupper(trim((string)($s['code'] ?? '')));
    $occ  = (int)($s['occupied'] ?? -1);
    if ($code === '' || ($occ !== 0 && $occ !== 1)) continue;

    $qSel->execute([$code]);
    $row = $qSel->fetch(PDO::FETCH_ASSOC);
    if (!$row) continue;

    $cur = strtoupper((string)$row['status']);

    // ✅ ไม่ให้ ESP32 ไปทับสถานะที่ระบบจอง/แอดมินตั้งไว้
    if ($cur === 'BOOKED' || $cur === 'MAINTENANCE' || $cur === 'HELD') continue;

    $newStatus = ($occ === 1) ? 'OCCUPIED' : 'AVAILABLE';
    $qUpd->execute([$newStatus, (int)$row['id']]);
  }

  $pdo->commit();
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_error('server error', 500);
}
