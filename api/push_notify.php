<?php
/**
 * api/push_notify.php  (LIBRARY - NO OUTPUT)
 * - มีแต่ฟังก์ชันส่ง OneSignal
 * - ห้าม echo/print/exit
 * - ใช้ร่วมกับ endpoint อื่นโดย require/include ได้ปลอดภัย
 */
declare(strict_types=1);

// ====== OneSignal Config (ใส่ของจริงของคุณ) ======
const ONESIGNAL_APP_ID  = 'PUT_YOUR_ONESIGNAL_APP_ID_HERE';
const ONESIGNAL_REST_KEY = 'PUT_YOUR_ONESIGNAL_REST_API_KEY_HERE';

// Timeout กันค้าง
const ONESIGNAL_TIMEOUT = 6;

/**
 * ส่ง Push ให้ผู้ใช้ 1 คน (ดึง token จาก DB)
 * ต้องมี $pdo จาก db.php อยู่แล้ว (เพราะ endpoint หลัก require db.php มาก่อน)
 */
function push_notify_user(int $user_id, string $title, string $body, array $data = []): bool
{
    // เงียบไว้ก่อน ถ้าคอนฟิกยังไม่ใส่
    if (ONESIGNAL_APP_ID === 'PUT_YOUR_ONESIGNAL_APP_ID_HERE' || ONESIGNAL_REST_KEY === 'PUT_YOUR_ONESIGNAL_REST_API_KEY_HERE') {
        error_log('push_notify_user: OneSignal config not set');
        return false;
    }

    // ใช้ $pdo จาก global (มาจาก db.php)
    if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
        error_log('push_notify_user: $pdo not found');
        return false;
    }
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];

    try {
        // ปรับชื่อ field ให้ตรงกับตาราง users ของคุณ
        $stmt = $pdo->prepare("SELECT push_token FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $token = trim((string)($row['push_token'] ?? ''));
        if ($token === '') return false;

        return onesignal_send_to_tokens([$token], $title, $body, $data);

    } catch (Throwable $e) {
        error_log('push_notify_user error: ' . $e->getMessage());
        return false;
    }
}

/**
 * ส่ง OneSignal ไปยัง token หลายตัว
 */
function onesignal_send_to_tokens(array $tokens, string $title, string $body, array $data = []): bool
{
    $tokens = array_values(array_filter(array_map('trim', $tokens)));
    if (!$tokens) return false;

    $payload = [
        'app_id' => ONESIGNAL_APP_ID,
        'include_player_ids' => $tokens,
        'headings' => ['en' => $title],
        'contents' => ['en' => $body],
        'data' => $data,
    ];

    $ch = curl_init('https://onesignal.com/api/v1/notifications');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . ONESIGNAL_REST_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => ONESIGNAL_TIMEOUT,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        error_log('OneSignal curl error: ' . $err);
        return false;
    }

    if ($code < 200 || $code >= 300) {
        error_log("OneSignal HTTP $code resp: " . substr((string)$resp, 0, 400));
        return false;
    }

    return true;
}
