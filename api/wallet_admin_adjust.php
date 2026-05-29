<?php
// api/wallet_admin_adjust.php
declare(strict_types=1);

/**
 * ปรับยอดวอลเล็ตโดยแอดมิน (+/-) + บันทึกประวัติ
 * แล้วตอบกลับเป็น JSON ที่ "สะอาด" 100% (กัน output หลุดจาก include/push)
 */

// กัน warning/notice โผล่เป็น HTML/ข้อความ
ini_set('display_errors', '0');
error_reporting(E_ALL);

// เริ่มจับ output ทั้งหมด (กัน db.php / push_notify.php เผลอ echo)
ob_start();

require __DIR__ . '/db.php';
require __DIR__ . '/push_notify.php'; // ส่ง OneSignal (อาจเผลอ echo)

// ตั้ง header JSON (ทำหลัง require ได้ เพราะเราจับ output ไว้แล้ว)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function flush_clean_json(array $payload, int $code = 200): void {
    // ล้าง output ที่หลุดมาก่อนหน้า (ถ้ามี) เพื่อให้เหลือ JSON ล้วน ๆ
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ---- รวม input จาก JSON / POST / GET ----
    $in = json_input();
    if (!is_array($in) || !$in) {
        $in = !empty($_POST) ? $_POST : $_GET;
    }

    $user_id = isset($in['user_id']) ? (int)$in['user_id'] : 0;
    $amount  = isset($in['amount'])  ? (float)$in['amount']  : 0;
    $mode    = isset($in['mode'])    ? (string)$in['mode']   : 'plus'; // plus / minus
    $note    = isset($in['note'])    ? trim((string)$in['note']) : '';
    $ref     = isset($in['ref'])     ? trim((string)$in['ref'])  : 'ADMIN-ADJUST';

    // ---- validate ----
    if ($user_id <= 0 || $amount <= 0) {
        flush_clean_json(['ok'=>false,'error'=>'ข้อมูลไม่ถูกต้อง'], 422);
    }
    if ($mode !== 'plus' && $mode !== 'minus') {
        flush_clean_json(['ok'=>false,'error'=>'mode ไม่ถูกต้อง'], 422);
    }
    if ($mode === 'minus') {
        $amount = -$amount; // หักเงิน = ค่าติดลบ
    }

    // เริ่ม transaction
    $pdo->beginTransaction();

    // ล็อก user row
    $stmt = $pdo->prepare("SELECT wallet FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $pdo->rollBack();
        flush_clean_json(['ok'=>false,'error'=>'ไม่พบผู้ใช้'], 404);
    }

    $current = (float)($user['wallet'] ?? 0);
    $new     = $current + $amount;

    if ($new < 0) {
        $pdo->rollBack();
        flush_clean_json(['ok'=>false,'error'=>'ยอดเงินไม่พอให้หัก'], 422);
    }

    // อัปเดตยอด wallet
    $stmt = $pdo->prepare("UPDATE users SET wallet = ? WHERE id = ?");
    $stmt->execute([$new, $user_id]);

    // บันทึกประวัติ
    if ($note === '') {
        $note = $amount >= 0 ? 'เติมเงินโดยแอดมิน' : 'หักเงินโดยแอดมิน';
    }
    $type = $amount >= 0 ? 'admin_plus' : 'admin_minus';

    $stmt = $pdo->prepare("
        INSERT INTO wallet_history (user_id, type, amount, note, ref, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$user_id, $type, $amount, $note, $ref]);

    $pdo->commit();

    // ---- ยิง Push Notification แบบไม่ให้พัง API ----
    if (function_exists('push_notify_user')) {
        $sign  = $amount >= 0 ? '+' : '-';
        $abs   = abs($amount);
        $title = 'ปรับยอดกระเป๋าเงิน';

        $body = ($amount >= 0
            ? "ระบบได้เติมเงินให้คุณ {$sign}" . number_format($abs, 2) . " ฿"
            : "ระบบได้หักเงินจากกระเป๋าของคุณ {$sign}" . number_format($abs, 2) . " ฿"
        ) . " | ยอดใหม่ " . number_format($new, 2) . " ฿";

        try {
            // กัน push_notify_user() เผลอ echo
            ob_start();
            push_notify_user($user_id, $title, $body, [
                'type'    => 'wallet_adjust',
                'amount'  => $amount,
                'balance' => $new,
                'ref'     => $ref,
            ]);
            ob_end_clean();
        } catch (Throwable $e) {
            if (ob_get_level() > 0) ob_end_clean();
            error_log('push_notify_user error: '.$e->getMessage());
        }
    }

    // ---- ส่ง JSON ตอบกลับ (สะอาด 100%) ----
    flush_clean_json(['ok'=>true, 'wallet'=>$new], 200);

} catch (Throwable $e) {
    // rollback ถ้าค้าง
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flush_clean_json(['ok'=>false,'error'=>'server error: '.$e->getMessage()], 500);
}
