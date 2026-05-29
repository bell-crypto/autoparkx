<?php
// api/admin_booking_cancel.php
// Cancel booking (Admin) + Refund เข้าวอลเล็ต
// ❗ไม่ยุ่ง parking_slots.status
// - ตั้ง status: CANCELLED_BY_ADMIN หรือ EXPIRED
// - คืนเงินตามกฎ 10 นาที (นับจาก created_at): ภายใน 10 นาทีคืนเต็ม, เกินไม่คืน
// - ถ้า bug=1 => คืนเต็มเสมอ
//
// ใช้ schema:
// bookings: id,user_id,slot_id,start_time,end_time,amount,status,created_at,updated_at
// users: id,wallet
// wallet_history: id,user_id,type,amount,note,ref,created_at

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$REFUND_WINDOW_MIN = 10;
$INACTIVE_SET = ['CANCELLED','CANCELLED_BY_ADMIN','EXPIRED'];

// ===== รับ input (JSON หรือ form-data) =====
$in = json_input() ?? [];
$in = array_merge($_POST, $in);

$bookingId = (int)arr_get($in, 'booking_id', 0);
if ($bookingId <= 0) json_error('booking_id is required', 422);

$action = strtoupper(trim((string)arr_get($in, 'action', 'CANCELLED_BY_ADMIN')));
if (!in_array($action, ['CANCELLED_BY_ADMIN','EXPIRED'], true)) {
  json_error('action must be CANCELLED_BY_ADMIN or EXPIRED', 422);
}

$bug = ((int)arr_get($in, 'bug', 0)) === 1;
$reason = trim((string)arr_get($in, 'reason', ''));
if ($reason === '') {
  $reason = $bug ? 'Admin cancel (bug)' : 'Admin cancel';
}

$now = date('Y-m-d H:i:s');

try {
  $pdo->beginTransaction();

  // ===== 1) โหลด booking + lock =====
  $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE");
  $stmt->execute([$bookingId]);
  $b = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$b) {
    $pdo->rollBack();
    json_error('booking not found', 404);
  }

  $curStatus = strtoupper((string)($b['status'] ?? ''));
  if ($curStatus !== '' && in_array($curStatus, $INACTIVE_SET, true)) {
    $pdo->rollBack();
    json_error('booking already inactive', 409);
  }

  $userId = (int)($b['user_id'] ?? 0);
  if ($userId <= 0) {
    $pdo->rollBack();
    json_error('booking.user_id invalid', 500);
  }

  // ===== 2) คำนวณ refund =====
  $paid = (float)($b['amount'] ?? 0.0);
  if ($paid < 0) $paid = 0.0;

  $refundRate = 0.0;

  if ($bug) {
    $refundRate = 1.0;
  } else {
    $createdAt = (string)($b['created_at'] ?? '');
    $tsC = $createdAt ? strtotime($createdAt) : 0;

    if ($tsC > 0) {
      $diffMin = (int)floor((strtotime($now) - $tsC) / 60);
      if ($diffMin <= $REFUND_WINDOW_MIN) {
        $refundRate = 1.0;
      }
    }
  }

  $refund = round($paid * $refundRate, 2);

  // ===== 3) อัปเดต booking =====
  $stmtUp = $pdo->prepare("
    UPDATE bookings
    SET status = ?, updated_at = ?
    WHERE id = ?
  ");
  $stmtUp->execute([$action, $now, $bookingId]);

  // ===== 4) คืนเงินวอลเล็ต (ถ้ามี) =====
  $walletAfter = null;

  if ($refund > 0) {
    $stmtW = $pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE id = ?");
    $stmtW->execute([$refund, $userId]);

    $stmtR = $pdo->prepare("SELECT wallet FROM users WHERE id = ? LIMIT 1");
    $stmtR->execute([$userId]);
    $walletAfter = $stmtR->fetchColumn();

    $ref = 'ADMIN-CANCEL#' . $bookingId;
    $stmtH = $pdo->prepare("
      INSERT INTO wallet_history (user_id, type, amount, note, ref, created_at)
      VALUES (?, 'refund', ?, ?, ?, ?)
    ");
    $stmtH->execute([$userId, $refund, $reason, $ref, $now]);
  } else {
    $stmtR = $pdo->prepare("SELECT wallet FROM users WHERE id = ? LIMIT 1");
    $stmtR->execute([$userId]);
    $walletAfter = $stmtR->fetchColumn();
  }

  $pdo->commit();

  json_ok([
    'booking_id'   => $bookingId,
    'user_id'      => $userId,
    'action'       => $action,
    'paid'         => $paid,
    'refund_rate'  => $refundRate,
    'refund'       => $refund,
    'wallet_after'=> $walletAfter,
    'note'         => $reason,
    'now'          => $now,
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_error($e->getMessage(), 500);
}
