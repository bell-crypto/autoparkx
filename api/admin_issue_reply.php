<?php
// api/admin_issue_reply.php
// แอดมินตอบกลับเคสแจ้งปัญหา + บันทึกลงฐานข้อมูล + แจ้งเตือนผ่าน LINE
// เคารพค่าตั้งค่า enabled + admin_reply

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$LINE_CHANNEL_ACCESS_TOKEN = (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');
$ADMIN_LINE_ID = '';

function line_push_raw(array $body): array {
    global $LINE_CHANNEL_ACCESS_TOKEN;

    if (!$LINE_CHANNEL_ACCESS_TOKEN || empty($body['to']) || empty($body['messages'])) {
        return ['ok' => false, 'http' => 0, 'err' => 'missing token/to/messages', 'resp' => null];
    }

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $LINE_CHANNEL_ACCESS_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 12,
    ]);

    $resp  = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errTx = $errNo ? curl_error($ch) : '';
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = ($errNo === 0 && $http >= 200 && $http < 300);
    if (!$ok) {
        error_log("LINE push fail http={$http} err={$errNo} {$errTx} resp={$resp}");
    }

    return ['ok' => $ok, 'http' => $http, 'err' => $errTx, 'resp' => $resp];
}

function get_notify_prefs(PDO $pdo, int $user_id): array {
    $defaults = [
        'enabled'     => true,
        'admin_reply' => true,
    ];

    if ($user_id <= 0) return $defaults;

    try {
        $stmt = $pdo->prepare("
            SELECT enabled, admin_reply
            FROM user_notify_prefs
            WHERE user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':uid' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return $defaults;

        return [
            'enabled'     => isset($row['enabled']) ? (bool)$row['enabled'] : true,
            'admin_reply' => isset($row['admin_reply']) ? (bool)$row['admin_reply'] : true,
        ];
    } catch (Throwable $e) {
        error_log('[AutoParkX] get_notify_prefs error: ' . $e->getMessage());
        return $defaults;
    }
}

$in = json_input();
if ($in === null) $in = $_POST ?: [];

$issue_id  = (int)($in['issue_id'] ?? 0);
$admin_id  = (int)($in['admin_id'] ?? 0);
$message   = trim((string)($in['message'] ?? ''));
$status    = strtoupper(trim((string)($in['status'] ?? 'IN_PROGRESS')));
$send_line = (int)($in['send_line'] ?? 1);

if ($issue_id <= 0 || $admin_id <= 0 || $message === '') {
    json_error('ข้อมูลไม่ครบ', 422);
}

if (!in_array($status, ['NEW', 'IN_PROGRESS', 'DONE'], true)) {
    $status = 'IN_PROGRESS';
}

$issue = null;
$lineTo = '';
$lineToType = 'none';

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            i.id,
            i.issue_type,
            i.detail,
            i.booking_id,
            i.slot_id,
            i.user_id,
            i.created_at,
            u.email        AS user_email,
            u.name         AS user_name,
            u.line_user_id AS line_user_id,
            u.push_token   AS user_push_token
        FROM parking_issues i
        LEFT JOIN users u ON u.id = i.user_id
        WHERE i.id = :iid
        LIMIT 1
    ");
    $stmt->execute([':iid' => $issue_id]);
    $issue = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$issue) {
        $pdo->rollBack();
        json_error('ไม่พบเคสนี้', 404);
    }

    $stmt = $pdo->prepare("
        INSERT INTO parking_issue_messages (issue_id, sender_type, sender_id, message, created_at)
        VALUES (:iid, 'admin', :aid, :msg, NOW())
    ");
    $stmt->execute([
        ':iid' => $issue_id,
        ':aid' => $admin_id,
        ':msg' => $message,
    ]);

    $stmt = $pdo->prepare("
        UPDATE parking_issues
        SET admin_reply = :msg,
            reply_at    = NOW(),
            status      = :st,
            updated_at  = NOW()
        WHERE id = :iid
    ");
    $stmt->execute([
        ':msg' => $message,
        ':st'  => $status,
        ':iid' => $issue_id
    ]);

    $pdo->commit();

    $lineUserId = trim((string)($issue['line_user_id'] ?? ''));
    $pushToken  = trim((string)($issue['user_push_token'] ?? ''));

    if ($lineUserId !== '') {
        $lineTo = $lineUserId;
        $lineToType = 'line_user_id';
    } elseif ($pushToken !== '') {
        $lineTo = $pushToken;
        $lineToType = 'push_token';
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('admin_issue_reply error: ' . $e->getMessage());
    json_error('ไม่สามารถบันทึกข้อความได้', 500);
}

$sent_user  = false;
$sent_admin = false;
$send_reason = 'not_attempted';

try {
    $userId      = (int)($issue['user_id'] ?? 0);
    $userEmail   = trim((string)($issue['user_email'] ?? ''));
    $userName    = trim((string)($issue['user_name'] ?? ''));
    $issueType   = trim((string)($issue['issue_type'] ?? 'ไม่ระบุประเภท'));
    $issueDetail = trim((string)($issue['detail'] ?? ''));
    $bookingId   = $issue['booking_id'] ?? null;
    $slotId      = $issue['slot_id'] ?? null;
    $createdAt   = $issue['created_at'] ?? null;

    $prefs = get_notify_prefs($pdo, $userId);

    $userLabel = '-';
    if ($userId > 0) {
        $userLabel = '#' . $userId;
        if ($userEmail !== '') {
            $userLabel .= ' · ' . $userEmail;
        } elseif ($userName !== '') {
            $userLabel .= ' · ' . $userName;
        }
    }

    $createdLabel = !empty($createdAt)
        ? date('d-m-Y H:i', strtotime($createdAt)) . ' น.'
        : date('d-m-Y H:i') . ' น.';

    $bookingStr = !empty($bookingId) ? ('#' . $bookingId) : '-';
    $slotStr    = !empty($slotId) ? ('#' . $slotId) : '-';
    $userUrl    = 'https://autoparkx.com/support_history.html';

    $flex = [
        'type'    => 'flex',
        'altText' => "AutoParkX: แอดมินตอบกลับเคส #{$issue_id} แล้ว",
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '16px',
                'backgroundColor' => '#0a84ff',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'แอดมินตอบกลับแล้ว',
                        'weight' => 'bold',
                        'size' => 'md',
                        'color' => '#ffffff',
                    ],
                    [
                        'type' => 'text',
                        'text' => "ID : #{$issue_id}",
                        'size' => 'xs',
                        'color' => '#e0f2fe',
                        'margin' => 'sm',
                    ],
                    [
                        'type' => 'text',
                        'text' => "สถานะ : {$status}",
                        'size' => 'xs',
                        'color' => '#e0f2fe',
                        'margin' => 'sm',
                    ],
                ],
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '16px',
                'backgroundColor' => '#ffffff',
                'spacing' => 'md',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'สรุปเคส',
                        'weight' => 'bold',
                        'color' => '#111827',
                        'size' => 'sm',
                    ],
                    [
                        'type' => 'text',
                        'text' => "วันที่: {$createdLabel}\nผู้ใช้: {$userLabel}\nหัวข้อ: {$issueType}\nBooking: {$bookingStr}\nSlot: {$slotStr}",
                        'size' => 'xs',
                        'color' => '#374151',
                        'wrap' => true,
                    ],
                    [
                        'type' => 'separator',
                        'color' => '#e5e7eb',
                        'margin' => 'md',
                    ],
                    [
                        'type' => 'text',
                        'text' => 'รายละเอียดที่คุณแจ้ง',
                        'weight' => 'bold',
                        'color' => '#111827',
                        'size' => 'sm',
                        'margin' => 'md',
                    ],
                    [
                        'type' => 'text',
                        'text' => $issueDetail !== '' ? $issueDetail : '-',
                        'wrap' => true,
                        'size' => 'xs',
                        'color' => '#374151',
                    ],
                    [
                        'type' => 'text',
                        'text' => 'คำตอบจากแอดมิน',
                        'weight' => 'bold',
                        'color' => '#111827',
                        'size' => 'sm',
                        'margin' => 'md',
                    ],
                    [
                        'type' => 'text',
                        'text' => $message,
                        'wrap' => true,
                        'size' => 'xs',
                        'color' => '#374151',
                    ],
                ],
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#ffffff',
                'paddingAll' => '12px',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => '#0a84ff',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'uri',
                            'label' => 'ดูประวัติในเว็บ',
                            'uri' => $userUrl,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'AutoParkX • Issue Center',
                        'size' => 'xxs',
                        'color' => '#6b7280',
                        'align' => 'center',
                        'margin' => 'sm',
                    ],
                ],
            ],
        ],
    ];

    if ($send_line !== 1) {
        $send_reason = 'send_line_disabled';
    } elseif ($lineTo === '') {
        $send_reason = 'no_line_recipient';
    } elseif (empty($prefs['enabled'])) {
        $send_reason = 'notify_disabled';
    } elseif (empty($prefs['admin_reply'])) {
        $send_reason = 'admin_reply_disabled';
    } else {
        $resp = line_push_raw([
            'to'       => $lineTo,
            'messages' => [$flex],
        ]);
        $sent_user = !empty($resp['ok']);
        $send_reason = $sent_user ? 'sent' : 'line_push_failed';
    }

    if ($send_line === 1 && $ADMIN_LINE_ID !== '') {
        $respAdmin = line_push_raw([
            'to'       => $ADMIN_LINE_ID,
            'messages' => [$flex],
        ]);
        $sent_admin = !empty($respAdmin['ok']);
    }

} catch (Throwable $e) {
    error_log('admin_issue_reply LINE push error: ' . $e->getMessage());
    $send_reason = 'exception';
}

json_ok([
    'saved'       => true,
    'sent_user'   => $sent_user,
    'sent_admin'  => $sent_admin,
    'to_type'     => $lineToType,
    'send_reason' => $send_reason,
]);