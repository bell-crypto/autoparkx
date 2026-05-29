<?php
// api/booking_find_active.php
// หา booking ของ slot_id เพื่อนำไป cancel (Admin)
// - mode=now (default): หา booking ที่ทับ "ตอนนี้"
// - mode=any: หา booking ล่าสุดที่ยังไม่ inactive (รองรับจองล่วงหน้า)
// - optional: ส่ง start_time/end_time เพื่อหา booking ที่ทับช่วงนั้น

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slotId = isset($_GET['slot_id']) ? (int)$_GET['slot_id'] : 0;
if ($slotId <= 0) json_error('slot_id is required', 422);

$now = date('Y-m-d H:i:s');

// โหมด
$mode = isset($_GET['mode']) ? strtolower(trim($_GET['mode'])) : 'now';
if (!in_array($mode, ['now','any','range'], true)) $mode = 'now';

// optional range
$start = isset($_GET['start_time']) ? trim($_GET['start_time']) : '';
$end   = isset($_GET['end_time']) ? trim($_GET['end_time']) : '';
if (($start !== '' || $end !== '') && $mode === 'now') {
  // ถ้าส่งช่วงเวลาเข้ามาโดยไม่ได้ระบุ mode ให้ถือว่าเป็น range
  $mode = 'range';
}

// สถานะที่ไม่ถือว่ายึดช่อง (ต้องตรงกับระบบคุณ)
$INACTIVE_SET = "('CANCELLED','CANCELLED_BY_ADMIN','EXPIRED')";

try {

  if ($mode === 'now') {
    // booking ที่ทับ "ตอนนี้"
    $sql = "
      SELECT id
      FROM bookings
      WHERE slot_id = ?
        AND (status IS NULL OR status NOT IN {$INACTIVE_SET})
        AND start_time <= ?
        AND end_time   >  ?
      ORDER BY start_time DESC, id DESC
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$slotId, $now, $now]);
    $bookingId = (int)($st->fetchColumn() ?: 0);

    json_ok([
      'booking_id' => $bookingId,
      'mode' => $mode,
      'now' => $now
    ]);
  }

  if ($mode === 'range') {
    // booking ที่ทับช่วงเวลาที่กำหนด (start/end แบบ MySQL DATETIME)
    if ($start === '' || $end === '') json_error('start_time and end_time are required for mode=range', 422);

    // overlap rule: (start_time < end) AND (end_time > start)
    $sql = "
      SELECT id
      FROM bookings
      WHERE slot_id = ?
        AND (status IS NULL OR status NOT IN {$INACTIVE_SET})
        AND start_time < ?
        AND end_time   > ?
      ORDER BY start_time DESC, id DESC
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$slotId, $end, $start]);
    $bookingId = (int)($st->fetchColumn() ?: 0);

    json_ok([
      'booking_id' => $bookingId,
      'mode' => $mode,
      'now' => $now,
      'start_time' => $start,
      'end_time' => $end
    ]);
  }

  // mode=any
  // หา booking ล่าสุดที่ยังไม่ inactive (รองรับจองล่วงหน้า)
  $sql = "
    SELECT id
    FROM bookings
    WHERE slot_id = ?
      AND (status IS NULL OR status NOT IN {$INACTIVE_SET})
    ORDER BY start_time DESC, id DESC
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$slotId]);
  $bookingId = (int)($st->fetchColumn() ?: 0);

  json_ok([
    'booking_id' => $bookingId,
    'mode' => $mode,
    'now' => $now
  ]);

} catch (Throwable $e) {
  json_error($e->getMessage(), 500);
}
