<?php
// ==== line_webhook_debug.php ====
// ใช้เป็น Webhook หลักของ LINE Messaging API
// - log raw event ลงไฟล์
// - ถ้าผู้ใช้ส่ง "อีเมล" มา -> ผูก email กับ LINE userId ลงตาราง users.push_token

require __DIR__ . '/db.php';

// ⚠️ ใส่ Channel access token (long-lived) ของช่อง AutoParkX Notify ตรงนี้
$LINE_CHANNEL_ACCESS_TOKEN = (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');

/**
 * ส่งข้อความกลับแบบ reply
 */
function line_reply_text(string $replyToken, string $text): bool {
    global $LINE_CHANNEL_ACCESS_TOKEN;

    if (!$LINE_CHANNEL_ACCESS_TOKEN) return false;

    $url  = 'https://api.line.me/v2/bot/message/reply';
    $body = [
        'replyToken' => $replyToken,
        'messages'   => [
            ['type' => 'text', 'text' => $text],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $LINE_CHANNEL_ACCESS_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 5,
    ]);

    curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);

    return $err === 0;
}

// ====== อ่าน raw body + log ไว้ดูก่อน ======
$raw = file_get_contents('php://input') ?: '';

file_put_contents(
    __DIR__ . '/line_webhook.log',
    "\n\n===== " . date('Y-m-d H:i:s') . " =====\n" . $raw,
    FILE_APPEND
);

// แปลงเป็น array
$data = json_decode($raw, true);

// ถ้า parse ไม่ได้ก็จบ แต่อย่าลืมตอบ 200 ให้ LINE
if (!is_array($data) || empty($data['events'])) {
    http_response_code(200);
    echo "OK";
    exit;
}

// ====== loop ทุก event ======
foreach ($data['events'] as $event) {
    $type = $event['type'] ?? '';

    // สนใจเฉพาะข้อความจาก user
    if ($type === 'message'
        && ($event['message']['type'] ?? '') === 'text'
        && ($event['source']['type'] ?? '') === 'user'
    ) {
        $userId     = $event['source']['userId'] ?? '';
        $replyToken = $event['replyToken'] ?? '';
        $text       = trim($event['message']['text'] ?? '');

        if ($userId && $text) {

            // สมมติให้ข้อความที่ส่งมา = email ที่ใช้สมัคร
            $email = $text;

            // ถ้าอยากเช็กว่าเป็น email หน่อย ๆ
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                line_reply_text($replyToken,
                    "กรุณาพิมพ์อีเมลที่คุณใช้สมัคร AutoParkX เช่น butter@gmail.com");
                continue;
            }

            try {
                // ผูก userId เข้ากับอีเมลในตาราง users
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET push_token = :uid
                    WHERE email = :email
                    LIMIT 1
                ");
                $stmt->execute([
                    ':uid'   => $userId,
                    ':email' => $email,
                ]);

                if ($stmt->rowCount() > 0) {
                    line_reply_text(
                        $replyToken,
                        "เชื่อมบัญชีสำเร็จแล้ว 🎉\nอีเมล: {$email}\nจากนี้ไปเมื่อคุณจองที่จอด จะมีแจ้งเตือนไปที่ LINE บัญชีนี้อัตโนมัติ"
                    );
                } else {
                    line_reply_text(
                        $replyToken,
                        "ไม่พบอีเมลนี้ในระบบ AutoParkX ❌\nกรุณาตรวจสอบอีเมล หรือสมัครสมาชิกในระบบก่อน"
                    );
                }
            } catch (Throwable $e) {
                line_reply_text(
                    $replyToken,
                    "เกิดข้อผิดพลาดขณะเชื่อมบัญชี, โปรดลองใหม่อีกครั้งภายหลังครับ"
                );
            }
        }
    }

    // (ถ้าอยาก handle follow / unfollow / join group เพิ่มเติม ก็มาใส่เพิ่มตรงนี้ได้)
}

// ตอบ 200 ให้ LINE เสมอ
http_response_code(200);
echo "OK";
