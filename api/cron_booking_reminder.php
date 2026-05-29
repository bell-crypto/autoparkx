<?php
// api/cron_booking_reminder.php
// ใช้รันด้วย CRON: ส่งแจ้งเตือนก่อน "ถึงเวลาเริ่มจอง" และ "ใกล้หมดเวลา" ผ่าน LINE Messaging API

require __DIR__ . '/db.php'; // ใช้ $pdo + ตั้ง timezone Asia/Bangkok

// ===== CONFIG =====

// นาทีล่วงหน้าก่อน "เริ่มจอง" ที่จะเตือน
$REMIND_BEFORE_START_MIN = 15;

// นาทีล่วงหน้าก่อน "หมดเวลา" ที่จะเตือน
$REMIND_BEFORE_END_MIN = 10;

// ขนาดหน้าต่างเวลาที่ cron จะมองหา (สมมติรันทุก 5 นาที)
$WINDOW_MIN = 5;

// สถานะ booking ที่ถือว่ายัง "มีผลอยู่"
$ACTIVE_SET = "('ACTIVE','CONFIRMED','BOOKED','PENDING','RESERVED','OCCUPIED')";

// ใช้ Channel access token ตัวเดียวกับ booking_create.php / booking_cancel.php
$LINE_CHANNEL_ACCESS_TOKEN = (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');

// ถ้าอยากให้แอดมินได้แจ้งเตือนด้วย ให้ใส่ userId ของแอดมิน
$ADMIN_LINE_ID = ''; // เช่น 'U7147e61fe81ee56c80f2f8e35b52845c'

// ===== LINE helper =====

function line_push_text(string $to, string $text): bool {
    global $LINE_CHANNEL_ACCESS_TOKEN;
    if (!$LINE_CHANNEL_ACCESS_TOKEN || !$to) return false;

    $url  = 'https://api.line.me/v2/bot/message/push';
    $body = [
        'to'       => $to,
        'messages' => [
            ['type' => 'text', 'text' => $text],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: ' . 'Bearer ' . $LINE_CHANNEL_ACCESS_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 5,
    ]);

    curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);

    return $err === 0;
}

// ===== MAIN =====

header('Content-Type: text/plain; charset=utf-8');

if (!$LINE_CHANNEL_ACCESS_TOKEN) {
    echo "LINE_CHANNEL_ACCESS_TOKEN is empty\n";
    exit;
}

try {
    // ใช้เวลาอ้างอิงจาก DB (ตรงกับ server MySQL)
    $nowStr = $pdo->query("SELECT NOW()")->fetchColumn();
    $now    = new DateTimeImmutable($nowStr);

    // ===== 1) เตือนก่อน "ถึงเวลาเริ่มจอง" =====
    $startFrom = $now->modify("+{$REMIND_BEFORE_START_MIN} minutes");
    $startTo   = $startFrom->modify("+{$WINDOW_MIN} minutes");

    $sqlStart = "
        SELECT
            b.id AS booking_id,
            b.user_id,
            b.slot_id,
            b.start_time,
            b.end_time,
            b.amount,
            u.email,
            u.push_token,
            s.code AS slot_code,
            s.level AS slot_level
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        LEFT JOIN slots s ON b.slot_id = s.id
        WHERE b.status IN $ACTIVE_SET
          AND b.remind_start_sent = 0
          AND b.start_time >= :start_from
          AND b.start_time <  :start_to
    ";

    $stmt = $pdo->prepare($sqlStart);
    $stmt->execute([
        ':start_from' => $startFrom->format('Y-m-d H:i:s'),
        ':start_to'   => $startTo->format('Y-m-d H:i:s'),
    ]);

    $startRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $countStart = 0;

    foreach ($startRows as $row) {
        $bookingId  = (int)$row['booking_id'];
        $uid        = (int)$row['user_id'];
        $slotId     = (int)$row['slot_id'];
        $email      = $row['email'] ?? '';
        $pushToken  = $row['push_token'] ?? null;
        $slotCode   = $row['slot_code'] ?: ('Slot #' . $slotId);
        $slotLevel  = $row['slot_level'] ?? 1;

        $startTime  = new DateTimeImmutable($row['start_time']);
        $endTime    = new DateTimeImmutable($row['end_time']);

        $startLabel = $startTime->format('d/m/Y H:i');
        $endLabel   = $endTime->format('d/m/Y H:i');
        $slotLabel  = sprintf('%s (ชั้น %s)', $slotCode, $slotLevel);

        $msg  = "⏰ ใกล้ถึงเวลาการจองที่จอดของคุณ\n";
        $msg .= "ผู้ใช้: " . ($email ?: "UID {$uid}") . "\n";
        $msg .= "Booking ID: {$bookingId}\n";
        $msg .= "ช่องจอด: {$slotLabel}\n";
        $msg .= "ช่วงเวลา: {$startLabel} - {$endLabel}\n";
        $msg .= "ระบบแจ้งเตือนล่วงหน้า {$REMIND_BEFORE_START_MIN} นาที";

        // ยิง LINE ให้เจ้าของ (ถ้ามี push_token)
        if (!empty($pushToken)) {
            line_push_text($pushToken, $msg);
        }
        // ยิงให้แอดมิน (ถ้าตั้งค่าไว้)
        if (!empty($ADMIN_LINE_ID)) {
            line_push_text($ADMIN_LINE_ID, "[ADMIN] START REMIND\n".$msg);
        }

        // อัปเดต flag ว่าส่งแล้ว
        $upd = $pdo->prepare("UPDATE bookings SET remind_start_sent = 1 WHERE id = :bid");
        $upd->execute([':bid' => $bookingId]);

        $countStart++;
    }

    // ===== 2) เตือนก่อน "ใกล้หมดเวลา" =====
    $endFrom = $now->modify("+{$REMIND_BEFORE_END_MIN} minutes");
    $endTo   = $endFrom->modify("+{$WINDOW_MIN} minutes");

    $sqlEnd = "
        SELECT
            b.id AS booking_id,
            b.user_id,
            b.slot_id,
            b.start_time,
            b.end_time,
            b.amount,
            u.email,
            u.push_token,
            s.code AS slot_code,
            s.level AS slot_level
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        LEFT JOIN slots s ON b.slot_id = s.id
        WHERE b.status IN $ACTIVE_SET
          AND b.remind_end_sent = 0
          AND b.end_time >= :end_from
          AND b.end_time <  :end_to
    ";

    $stmt = $pdo->prepare($sqlEnd);
    $stmt->execute([
        ':end_from' => $endFrom->format('Y-m-d H:i:s'),
        ':end_to'   => $endTo->format('Y-m-d H:i:s'),
    ]);

    $endRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $countEnd = 0;

    foreach ($endRows as $row) {
        $bookingId  = (int)$row['booking_id'];
        $uid        = (int)$row['user_id'];
        $slotId     = (int)$row['slot_id'];
        $email      = $row['email'] ?? '';
        $pushToken  = $row['push_token'] ?? null;
        $slotCode   = $row['slot_code'] ?: ('Slot #' . $slotId);
        $slotLevel  = $row['slot_level'] ?? 1;

        $startTime  = new DateTimeImmutable($row['start_time']);
        $endTime    = new DateTimeImmutable($row['end_time']);

        $startLabel = $startTime->format('d/m/Y H:i');
        $endLabel   = $endTime->format('d/m/Y H:i');
        $slotLabel  = sprintf('%s (ชั้น %s)', $slotCode, $slotLevel);

        $msg  = "⚠️ การจองที่จอดของคุณใกล้หมดเวลาแล้ว\n";
        $msg .= "ผู้ใช้: " . ($email ?: "UID {$uid}") . "\n";
        $msg .= "Booking ID: {$bookingId}\n";
        $msg .= "ช่องจอด: {$slotLabel}\n";
        $msg .= "ช่วงเวลา: {$startLabel} - {$endLabel}\n";
        $msg .= "ระบบแจ้งเตือนล่วงหน้า {$REMIND_BEFORE_END_MIN} นาที\n";
        $msg .= "หากต้องการใช้งานต่อ กรุณาจัดการต่อเวลาในระบบ (หากมีฟีเจอร์รองรับ)";

        if (!empty($pushToken)) {
            line_push_text($pushToken, $msg);
        }
        if (!empty($ADMIN_LINE_ID)) {
            line_push_text($ADMIN_LINE_ID, "[ADMIN] END REMIND\n".$msg);
        }

        $upd = $pdo->prepare("UPDATE bookings SET remind_end_sent = 1 WHERE id = :bid");
        $upd->execute([':bid' => $bookingId]);

        $countEnd++;
    }

    echo "OK cron_booking_reminder\n";
    echo "Start reminders sent: {$countStart}\n";
    echo "End reminders sent:   {$countEnd}\n";

} catch (Throwable $e) {
    error_log('cron_booking_reminder error: ' . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
}
