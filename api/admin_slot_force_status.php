<?php
// api/admin_slot_force_status.php
// แอดมินตั้งสถานะช่อง (parking_slots) + (ออปชัน) ยกเลิก booking ACTIVE ที่ชนช่วงเวลา + คืนเงิน

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$in = json_input();
if ($in === null) $in = $_POST ?: [];

$slot_id      = (int)arr_get($in, 'slot_id', 0);
$new_status   = strtoupper(trim((string)arr_get($in, 'status', '')));
$force_cancel = (int)arr_get($in, 'force_cancel', 0) === 1;
$reason       = trim((string)arr_get($in, 'reason', 'Admin forced slot status'));
$refund_mode  = strtoupper(trim((string)arr_get($in, 'refund_mode', 'FULL'))); // FULL|RULES
if (!in_array($refund_mode, ['FULL','RULES'], true)) $refund_mode = 'FULL';

if ($slot_id <= 0) json_error('slot_id is required', 422);

$allowed = ['AVAILABLE','HELD','MAINTENANCE','BOOKED','OCCUPIED'];
if (!in_array($new_status, $allowed, true)) json_error('invalid status', 422);

$now = date('Y-m-d H:i:s');

try {
  $pdo->beginTransaction();

  // 1) lock slot
  $st = $pdo->prepare("SELECT id, code, status FROM parking_slots WHERE id=? FOR UPDATE");
  $st->execute([$slot_id]);
  $slot = $st->fetch(PDO::FETCH_ASSOC);
  if (!$slot) {
    $pdo->rollBack();
    json_error('slot not found', 404);
  }

  $cancelled = [];
  $refunded_total = 0.0;

  if ($force_cancel) {
    // 2) หา booking ACTIVE ที่ยังไม่หมดเวลา ของ slot นี้
    $ACTIVE_SET = "('ACTIVE','CONFIRMED','BOOKED','PENDING','RESERVED','OCCUPIED')";

    $q = $pdo->prepare("
      SELECT id, user_id, amount, start_time, end_time, status
      FROM bookings
      WHERE slot_id=?
        AND status IN $ACTIVE_SET
        AND end_time > ?
      ORDER BY start_time ASC
      FOR UPDATE
    ");
    $q->execute([$slot_id, $now]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $bk) {
      $booking_id = (int)$bk['id'];
      $uid        = (int)$bk['user_id'];
      $amount     = (float)($bk['amount'] ?? 0);

      $refundRef = 'CANCEL-REFUND-BOOKING-' . $booking_id;

      // กันคืนซ้ำ
      $chk = $pdo->prepare("SELECT id FROM wallet_history WHERE ref=? LIMIT 1 FOR UPDATE");
      $chk->execute([$refundRef]);
      $alreadyRefunded = (bool)$chk->fetch(PDO::FETCH_ASSOC);

      $refund = 0.0;
      if (!$alreadyRefunded) {
        if ($refund_mode === 'FULL') {
          $refund = max(0.0, $amount);
        } else {
          // RULES 10 นาที
          $startTs = strtotime((string)$bk['start_time']);
          $nowTs   = strtotime($now);
          if ($nowTs <= $startTs) $refund = max(0.0, $amount);
          else {
            $mins = ($nowTs - $startTs) / 60.0;
            $refund = ($mins <= 10) ? max(0.0, $amount) : 0.0;
          }
        }

        // lock user
        $su = $pdo->prepare("SELECT wallet FROM users WHERE id=? FOR UPDATE");
        $su->execute([$uid]);
        $urow = $su->fetch(PDO::FETCH_ASSOC);
        if (!$urow) {
          $pdo->rollBack();
          json_error('user not found (uid=' . $uid . ')', 404);
        }

        if ($refund > 0) {
          $upw = $pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE id=?");
          $upw->execute([$refund, $uid]);

          $note = "คืนเงินจากแอดมิน • booking #{$booking_id} • slot {$slot['code']} • {$reason}";
          $wh = $pdo->prepare("
            INSERT INTO wallet_history (user_id, type, amount, note, ref, created_at)
            VALUES (?, 'refund', ?, ?, ?, NOW())
          ");
          $wh->execute([$uid, $refund, $note, $refundRef]);

          $refunded_total += $refund;
        }
      }

      // set booking cancelled by admin
      $ub = $pdo->prepare("UPDATE bookings SET status='CANCELLED_BY_ADMIN', updated_at=NOW() WHERE id=?");
      $ub->execute([$booking_id]);

      $cancelled[] = [
        'booking_id' => $booking_id,
        'user_id' => $uid,
        'refunded' => $alreadyRefunded ? 0 : $refund,
        'already_refunded' => $alreadyRefunded
      ];
    }
  }

  // 3) อัปเดตสถานะช่อง
  $us = $pdo->prepare("UPDATE parking_slots SET status=?, last_update=NOW() WHERE id=?");
  $us->execute([$new_status, $slot_id]);

  $pdo->commit();

  json_ok([
    'ok' => true,
    'slot_id' => $slot_id,
    'code' => $slot['code'],
    'new_status' => $new_status,
    'force_cancel' => $force_cancel,
    'refund_mode' => $refund_mode,
    'cancelled' => $cancelled,
    'refunded_total' => $refunded_total
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_error('server error: ' . $e->getMessage(), 500);
}
