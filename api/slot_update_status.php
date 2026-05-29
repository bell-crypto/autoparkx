<?php
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$deviceKey = $_SERVER['HTTP_X_DEVICE_KEY'] ?? '';
if ($deviceKey !== '' && $deviceKey !== 'esp32-lot-A') {
  json_error('invalid device key', 401);
}

$jin = json_input();
if (!is_array($jin)) $jin = [];
$in = array_merge($_POST ?? [], $jin);

$slotId = isset($in['slot_id']) ? (int)$in['slot_id'] : 0;
$code   = isset($in['code']) ? trim((string)$in['code']) : '';
$status = isset($in['status']) ? strtoupper(trim((string)$in['status'])) : '';

if (($slotId <= 0 && $code === '') || $status === '') {
  json_error('slot_id or code, and status are required', 422);
}

$ALLOWED = ['AVAILABLE', 'BOOKED', 'OCCUPIED', 'MAINTENANCE', 'HELD'];
if (!in_array($status, $ALLOWED, true)) {
  json_error('invalid status', 422);
}

try {
  $pdo->beginTransaction();

  if ($slotId > 0) {
    $sel = $pdo->prepare("
      SELECT id, code, status, level, last_update
      FROM parking_slots
      WHERE id = ?
      LIMIT 1
      FOR UPDATE
    ");
    $sel->execute([$slotId]);
  } else {
    $sel = $pdo->prepare("
      SELECT id, code, status, level, last_update
      FROM parking_slots
      WHERE code = ?
      LIMIT 1
      FOR UPDATE
    ");
    $sel->execute([$code]);
  }

  $slot = $sel->fetch(PDO::FETCH_ASSOC);

  if (!$slot) {
    $pdo->rollBack();
    json_error('slot not found', 404);
  }

  $slotIdReal = (int)$slot['id'];
  $slotCode   = (string)$slot['code'];
  $oldStatus  = strtoupper((string)($slot['status'] ?? ''));

  /*
    สำคัญ:
    เอา maintenance_locked ออกแล้ว
    เพื่อให้แอดมินสามารถเปลี่ยนจาก MAINTENANCE กลับเป็น AVAILABLE ได้
  */

  if ($status === 'OCCUPIED') {
    $pdo->prepare("
      UPDATE parking_slots
      SET status = 'OCCUPIED',
          last_update = NOW()
      WHERE id = ?
    ")->execute([$slotIdReal]);

    $q = $pdo->prepare("
      SELECT id
      FROM bookings
      WHERE slot_id = ?
        AND UPPER(COALESCE(status,'')) NOT IN ('CANCELLED','CANCELLED_BY_ADMIN','EXPIRED','COMPLETED')
      ORDER BY
        CASE
          WHEN start_time <= NOW() AND end_time > NOW() THEN 0
          ELSE 1
        END,
        id DESC
      LIMIT 1
      FOR UPDATE
    ");
    $q->execute([$slotIdReal]);
    $booking = $q->fetch(PDO::FETCH_ASSOC);

    if ($booking) {
      $pdo->prepare("
        UPDATE bookings
        SET status = 'OCCUPIED',
            parked_at = COALESCE(parked_at, NOW()),
            updated_at = NOW()
        WHERE id = ?
      ")->execute([(int)$booking['id']]);
    }
  }

  elseif ($status === 'AVAILABLE') {
    $q = $pdo->prepare("
      SELECT id
      FROM bookings
      WHERE slot_id = ?
        AND UPPER(COALESCE(status,'')) NOT IN ('CANCELLED','CANCELLED_BY_ADMIN','EXPIRED','COMPLETED')
      ORDER BY id DESC
      FOR UPDATE
    ");
    $q->execute([$slotIdReal]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);

    if ($ids) {
      $inMarks = implode(',', array_fill(0, count($ids), '?'));

      $pdo->prepare("
        UPDATE bookings
        SET status = 'COMPLETED',
            ended_at = COALESCE(ended_at, NOW()),
            end_time = CASE
              WHEN end_time IS NULL OR end_time > NOW() THEN NOW()
              ELSE end_time
            END,
            updated_at = NOW()
        WHERE id IN ($inMarks)
      ")->execute($ids);
    }

    $pdo->prepare("
      UPDATE parking_slots
      SET status = 'AVAILABLE',
          last_update = NOW()
      WHERE id = ?
    ")->execute([$slotIdReal]);
  }

  elseif (in_array($status, ['MAINTENANCE', 'HELD'], true)) {
    $pdo->prepare("
      UPDATE parking_slots
      SET status = ?,
          last_update = NOW()
      WHERE id = ?
    ")->execute([$status, $slotIdReal]);

    $q = $pdo->prepare("
      SELECT id
      FROM bookings
      WHERE slot_id = ?
        AND UPPER(COALESCE(status,'')) NOT IN ('CANCELLED','CANCELLED_BY_ADMIN','EXPIRED','COMPLETED')
        AND end_time > NOW()
      ORDER BY id DESC
      FOR UPDATE
    ");
    $q->execute([$slotIdReal]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);

    if ($ids) {
      $inMarks = implode(',', array_fill(0, count($ids), '?'));

      $pdo->prepare("
        UPDATE bookings
        SET status = 'CANCELLED_BY_ADMIN',
            updated_at = NOW()
        WHERE id IN ($inMarks)
      ")->execute($ids);
    }
  }

  else {
    $pdo->prepare("
      UPDATE parking_slots
      SET status = ?,
          last_update = NOW()
      WHERE id = ?
    ")->execute([$status, $slotIdReal]);
  }

  $pdo->commit();

  json_ok([
    'slot_id'    => $slotIdReal,
    'slot_code'  => $slotCode,
    'old_status' => $oldStatus,
    'new_status' => $status,
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  json_error($e->getMessage(), 500);
}