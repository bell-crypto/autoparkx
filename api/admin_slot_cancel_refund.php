<?php
// api/admin_slot_cancel_refund.php
// ยกเลิกการจองของช่อง (ที่ active ล่าสุด/ทับตอนนี้) + คืนเงินเต็ม + ปรับช่องเป็น AVAILABLE
// ใช้สำหรับเคสบั๊ก/สถานะผิด ที่ผู้ใช้แจ้งปัญหา

declare(strict_types=1);
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function jexit(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

// ====== (แนะนำ) ใส่การเช็คแอดมิน ======
// คุณมีระบบ admin login อยู่แล้ว (parking_user ใน localStorage ฝั่งหน้าเว็บ)
// แต่ API ควรตรวจสิทธิ์จริงด้วย (เช่น session / token / role ใน DB)
// ตอนนี้ทำเป็น "เปิดไว้" ก่อน: ถ้าคุณมี helper is_admin() ใน db.php ให้เปิดใช้ได้เลย
// if (!is_admin_request()) jexit(['ok'=>false,'error'=>'forbidden'], 403);

// ====== รับ JSON ======
$raw = file_get_contents('php://input');
$in  = json_decode($raw ?: '[]', true);
if (!is_array($in)) $in = [];

$slotId = isset($in['slot_id']) ? (int)$in['slot_id'] : 0;
$reason = trim((string)($in['reason'] ?? 'ช่องบั๊ก/สถานะผิด (ผู้ใช้แจ้งปัญหา)'));
$refundMode = strtoupper(trim((string)($in['refund_mode'] ?? 'FULL'))); // FULL เท่านั้นในเคสนี้

if ($slotId <= 0) {
  jexit(['ok'=>false,'error'=>'slot_id is required'], 422);
}

$now = date('Y-m-d H:i:s');

// ====== ปรับตรงนี้ให้ตรง schema ของคุณ ======
// ตารางที่เกี่ยว:
// - parking_slots: id, code, status, updated_at ...
// - bookings: id, user_id, slot_id, start_time, end_time, status, paid_amount/total_price ...
// - users: id, wallet_balance (หรือไปอยู่ตาราง wallet ของคุณ)
// - wallet_history: user_id, amount, type, note, ref_id, created_at

// สถานะที่ถือว่า "ยัง active"
$ACTIVE_SET = "('ACTIVE','CONFIRMED','BOOKED','PENDING','RESERVED','OCCUPIED')";

// สำหรับคืนเงิน: ถ้า booking มีคอลัมน์ยอดที่จ่ายไว้แล้ว ให้ใช้ค่านั้นก่อน
// ปรับชื่อคอลัมน์ตามจริงของคุณ (paid_amount หรือ total_price หรือ amount)
$CANDIDATE_AMOUNT_COLS = ['paid_amount','total_price','amount','price','fee'];

try {
  $pdo->beginTransaction();

  // 1) lock ช่องจอด
  $st = $pdo->prepare("SELECT id, code, status FROM parking_slots WHERE id=? FOR UPDATE");
  $st->execute([$slotId]);
  $slot = $st->fetch(PDO::FETCH_ASSOC);
  if (!$slot) {
    $pdo->rollBack();
    jexit(['ok'=>false,'error'=>'slot not found'], 404);
  }

  // 2) หา booking ที่ยัง active ของช่องนี้ (โฟกัส: booking ที่ทับ "ตอนนี้" ก่อน)
  //    ถ้าไม่มี booking ทับตอนนี้ แต่ยังมี booking future ที่ active อยู่ -> ก็เลือกอันที่ใกล้สุด (order by start_time)
  $sqlFind = "
    SELECT *
    FROM bookings
    WHERE slot_id = ?
      AND status IN $ACTIVE_SET
    ORDER BY
      CASE
        WHEN start_time <= ? AND end_time >= ? THEN 0
        WHEN start_time > ? THEN 1
        ELSE 2
      END,
      start_time ASC
    LIMIT 1
    FOR UPDATE
  ";
  $st = $pdo->prepare($sqlFind);
  $st->execute([$slotId, $now, $now, $now]);
  $bk = $st->fetch(PDO::FETCH_ASSOC);

  if (!$bk) {
    // ไม่มี booking active -> แค่ set ช่องเป็น AVAILABLE (ไม่มีเงินให้คืน)
    $st = $pdo->prepare("UPDATE parking_slots SET status='AVAILABLE', updated_at=? WHERE id=?");
    $st->execute([$now, $slotId]);

    $pdo->commit();
    jexit([
      'ok'=>true,
      'mode'=>'NO_ACTIVE_BOOKING',
      'message'=>"ไม่พบการจองที่ยัง active ของช่อง {$slot['code']} → ตั้งเป็น AVAILABLE แล้ว",
      'slot'=>['id'=>$slotId,'code'=>$slot['code'],'status'=>'AVAILABLE']
    ]);
  }

  $bookingId = (int)$bk['id'];
  $userId    = (int)$bk['user_id'];

  // 3) คำนวณเงินคืน (FULL)
  $paid = null;
  foreach ($CANDIDATE_AMOUNT_COLS as $col) {
    if (array_key_exists($col, $bk) && is_numeric($bk[$col])) { $paid = (float)$bk[$col]; break; }
  }
  if ($paid === null) {
    // fallback: ถ้าไม่มีคอลัมน์ยอดจ่าย ให้ตั้ง 0 เพื่อไม่คืนมั่ว (คุณควรปรับให้ดึงยอดจริงให้ได้)
    $paid = 0.0;
  }
  $refund = max(0.0, (float)$paid);

  // 4) ยกเลิก booking (แนะนำแยก status เพื่อ audit)
  // ปรับชื่อ status ตามที่คุณใช้จริง
  $newStatus = 'CANCELLED_BY_ADMIN';

  // (ถ้ามีคอลัมน์ cancelled_at/cancel_reason/refund_amount ก็อัปเดตเพิ่มได้)
  $st = $pdo->prepare("
    UPDATE bookings
    SET status = ?, updated_at = ?
    WHERE id = ?
  ");
  $st->execute([$newStatus, $now, $bookingId]);

  // 5) คืนเงินเข้าวอลเล็ต
  // --- กรณี A: wallet_balance อยู่ใน users ---
  // ปรับชื่อคอลัมน์ wallet_balance ตามจริงของคุณ
  if ($refund > 0) {
    $st = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
    $st->execute([$refund, $userId]);

    // บันทึกประวัติ
    $note = "ADMIN REFUND (BUG): booking#$bookingId slot#{$slotId} — " . $reason;
    $st = $pdo->prepare("
      INSERT INTO wallet_history (user_id, amount, type, note, ref_id, created_at)
      VALUES (?, ?, 'REFUND', ?, ?, ?)
    ");
    $st->execute([$userId, $refund, $note, (string)$bookingId, $now]);
  }

  // 6) ตั้งช่องเป็น AVAILABLE (เคลียร์ให้คนอื่นจองต่อได้)
  $st = $pdo->prepare("UPDATE parking_slots SET status='AVAILABLE', updated_at=? WHERE id=?");
  $st->execute([$now, $slotId]);

  $pdo->commit();

  jexit([
    'ok'=>true,
    'mode'=>'CANCEL_AND_REFUND',
    'message'=>"ยกเลิก booking#$bookingId + คืนเงิน {$refund} + ตั้งช่อง {$slot['code']} เป็น AVAILABLE แล้ว",
    'slot'=>['id'=>$slotId,'code'=>$slot['code'],'status'=>'AVAILABLE'],
    'booking'=>['id'=>$bookingId,'user_id'=>$userId,'status'=>$newStatus],
    'refund'=>['amount'=>$refund,'reason'=>$reason]
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jexit(['ok'=>false,'error'=>$e->getMessage()], 500);
}
