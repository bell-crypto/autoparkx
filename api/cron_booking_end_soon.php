<?php
// api/cron_booking_end_soon.php
// แจ้งเตือนก่อนหมดเวลาจองประมาณ 0–10 นาที ผ่าน LINE Messaging API (Flex)

require __DIR__ . '/db.php';

date_default_timezone_set('Asia/Bangkok');

$LINE_CHANNEL_ACCESS_TOKEN = getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');

/**
 * ตรวจรูปแบบ LINE user id
 */
function looks_like_line_user_id(string $s): bool {
    $s = trim($s);
    return (bool)preg_match('/^U[0-9a-f]{32}$/i', $s);
}

/**
 * ส่ง push message แบบ raw
 */
function line_push_raw(array $body): array {
    global $LINE_CHANNEL_ACCESS_TOKEN;

    if ($LINE_CHANNEL_ACCESS_TOKEN === '' || $LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_NEW_LINE_CHANNEL_ACCESS_TOKEN') {
        return ['ok' => false, 'http' => 0, 'err' => 'missing line token', 'resp' => null];
    }

    if (empty($body['to']) || empty($body['messages'])) {
        return ['ok' => false, 'http' => 0, 'err' => 'missing to/messages', 'resp' => null];
    }

    $url = 'https://api.line.me/v2/bot/message/push';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $LINE_CHANNEL_ACCESS_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
    ]);

    $resp  = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errTx = $errNo ? curl_error($ch) : '';
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = ($errNo === 0 && $http >= 200 && $http < 300);

    if (!$ok) {
        error_log("LINE booking_end_soon push fail http={$http} err={$errNo} {$errTx} resp={$resp}");
    }

    return [
        'ok'   => $ok,
        'http' => $http,
        'err'  => $errTx,
        'resp' => $resp,
    ];
}

/**
 * helper สร้าง row รายละเอียด
 */
function flex_kv_row(string $label, string $value, bool $wrap = false): array {
    return [
        'type' => 'box',
        'layout' => 'baseline',
        'spacing' => 'sm',
        'contents' => [
            [
                'type' => 'text',
                'text' => $label,
                'size' => 'xs',
                'color' => '#6b7280',
                'flex' => 3,
            ],
            [
                'type' => 'text',
                'text' => $value,
                'size' => 'xs',
                'color' => '#111827',
                'flex' => 6,
                'wrap' => $wrap,
            ],
        ],
    ];
}

/**
 * สร้าง Flex แจ้งเตือนใกล้หมดเวลา
 */
function build_booking_end_soon_flex(
    int $bookingId,
    string $slotLabel,
    string $startLabel,
    string $endLabel,
    string $minsLeftLabel,
    string $url
): array {
    return [
        'type' => 'flex',
        'altText' => "AutoParkX: การจองใกล้หมดเวลา #{$bookingId}",
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '16px',
                'backgroundColor' => '#ef4444',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'การจองใกล้หมดเวลา',
                        'weight' => 'bold',
                        'size' => 'md',
                        'color' => '#ffffff',
                    ],
                    [
                        'type' => 'text',
                        'text' => "Booking ID : #{$bookingId}",
                        'size' => 'xs',
                        'color' => '#fee2e2',
                        'margin' => 'sm',
                    ],
                    [
                        'type' => 'text',
                        'text' => "เหลือเวลาอีก : {$minsLeftLabel}",
                        'size' => 'xs',
                        'color' => '#fee2e2',
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
                        'text' => 'รายละเอียดการจอง',
                        'weight' => 'bold',
                        'color' => '#111827',
                        'size' => 'sm',
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'spacing' => 'xs',
                        'contents' => [
                            flex_kv_row('ช่องจอด :', $slotLabel, true),
                            flex_kv_row('เริ่ม :', $startLabel),
                            flex_kv_row('สิ้นสุด :', $endLabel),
                            flex_kv_row('สถานะ :', 'ใกล้หมดเวลาจอง'),
                        ],
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'md',
                    ],
                    [
                        'type' => 'text',
                        'text' => 'กรุณากลับมาที่รถหรือดำเนินการต่อก่อนหมดเวลา',
                        'size' => 'xs',
                        'color' => '#374151',
                        'wrap' => true,
                        'margin' => 'md',
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
                        'color' => '#ef4444',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'uri',
                            'label' => 'ดูประวัติการจอง',
                            'uri' => $url,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'AutoParkX • End Reminder',
                        'size' => 'xxs',
                        'color' => '#6b7280',
                        'align' => 'center',
                        'margin' => 'sm',
                    ],
                ],
            ],
        ],
    ];
}

try {
    $sql = "
        SELECT
            b.id,
            b.user_id,
            b.slot_id,
            b.start_time,
            b.end_time,
            b.amount,
            b.status,
            b.end_soon_sent,
            b.end_soon_sent_at,

            u.name,
            u.email,
            u.line_user_id,

            COALESCE(ps.code, s.code) AS slot_code,
            COALESCE(ps.level, s.level) AS slot_level,

            p.enabled,
            p.booking_end_soon

        FROM bookings b
        INNER JOIN users u
            ON u.id = b.user_id
        LEFT JOIN parking_slots ps
            ON ps.id = b.slot_id
        LEFT JOIN slots s
            ON s.id = b.slot_id
        LEFT JOIN user_notify_prefs p
            ON p.user_id = b.user_id

        WHERE b.end_soon_sent = 0
          AND b.end_time BETWEEN NOW()
                             AND DATE_ADD(NOW(), INTERVAL 10 MINUTE)
          AND (
                b.status IS NULL
                OR b.status = ''
                OR UPPER(b.status) NOT IN ('CANCELLED', 'EXPIRED', 'COMPLETED', 'FINISHED')
              )

        ORDER BY b.end_time ASC
        LIMIT 100
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sentCount = 0;
    $skipCount = 0;

    foreach ($rows as $r) {
        $bookingId   = (int)($r['id'] ?? 0);
        $userId      = (int)($r['user_id'] ?? 0);
        $slotId      = (int)($r['slot_id'] ?? 0);
        $status      = strtoupper(trim((string)($r['status'] ?? '')));
        $lineUserId  = trim((string)($r['line_user_id'] ?? ''));

        $enabled        = array_key_exists('enabled', $r) && $r['enabled'] !== null ? (int)$r['enabled'] : 1;
        $bookingEndSoon = array_key_exists('booking_end_soon', $r) && $r['booking_end_soon'] !== null ? (int)$r['booking_end_soon'] : 1;

        if ($bookingId <= 0 || $userId <= 0) {
            $skipCount++;
            continue;
        }

        if (!$enabled || !$bookingEndSoon) {
            error_log("[AutoParkX] booking_end_soon skipped booking_id={$bookingId} prefs_disabled");
            $skipCount++;
            continue;
        }

        if (!looks_like_line_user_id($lineUserId)) {
            error_log("[AutoParkX] booking_end_soon skipped booking_id={$bookingId} invalid_line_user_id");
            $skipCount++;
            continue;
        }

        if (in_array($status, ['CANCELLED', 'EXPIRED', 'COMPLETED', 'FINISHED'], true)) {
            $skipCount++;
            continue;
        }

        $start = new DateTime((string)$r['start_time'], new DateTimeZone('Asia/Bangkok'));
        $end   = new DateTime((string)$r['end_time'], new DateTimeZone('Asia/Bangkok'));
        $now   = new DateTime('now', new DateTimeZone('Asia/Bangkok'));

        $minsLeft = (int)floor(($end->getTimestamp() - $now->getTimestamp()) / 60);
        if ($minsLeft < 0) {
            $minsLeft = 0;
        }

        $slotCode  = trim((string)($r['slot_code'] ?? ''));
        $slotLevel = trim((string)($r['slot_level'] ?? ''));

        if ($slotCode === '') {
            $slotCode = 'Slot #' . $slotId;
        }

        $slotLabel = $slotCode;
        if ($slotLevel !== '') {
            $slotLabel .= ' (ชั้น ' . $slotLevel . ')';
        }

        $startLabel = $start->format('d/m/Y H:i');
        $endLabel   = $end->format('d/m/Y H:i');

        if ($minsLeft <= 1) {
            $minsLeftLabel = 'ไม่กี่วินาที';
        } else {
            $minsLeftLabel = $minsLeft . ' นาที';
        }

        $url = 'https://autoparkx.com/mybookings.html';

        $flex = build_booking_end_soon_flex(
            $bookingId,
            $slotLabel,
            $startLabel,
            $endLabel,
            $minsLeftLabel,
            $url
        );

        $send = line_push_raw([
            'to' => $lineUserId,
            'messages' => [$flex]
        ]);

        if ($send['ok']) {
            $up = $pdo->prepare("
                UPDATE bookings
                SET end_soon_sent = 1,
                    end_soon_sent_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $up->execute([
                ':id' => $bookingId,
            ]);

            $sentCount++;
            error_log("[AutoParkX] booking_end_soon sent booking_id={$bookingId} user_id={$userId}");
        } else {
            error_log("[AutoParkX] booking_end_soon send_failed booking_id={$bookingId} http={$send['http']}");
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'checked' => count($rows),
        'sent' => $sentCount,
        'skipped' => $skipCount,
        'server_time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[AutoParkX] cron_booking_end_soon error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}