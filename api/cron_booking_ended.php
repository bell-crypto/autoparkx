<?php
// api/cron_booking_ended.php
// แจ้งเตือนเมื่อการจองสิ้นสุดแล้ว ผ่าน LINE Messaging API (Flex)

require __DIR__ . '/db.php';

date_default_timezone_set('Asia/Bangkok');

$LINE_CHANNEL_ACCESS_TOKEN = getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');

function looks_like_line_user_id(string $s): bool {
    $s = trim($s);
    return (bool)preg_match('/^U[0-9a-f]{32}$/i', $s);
}

function line_push_raw(array $body): array {
    global $LINE_CHANNEL_ACCESS_TOKEN;

    if ($LINE_CHANNEL_ACCESS_TOKEN === '' || $LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_NEW_LINE_CHANNEL_ACCESS_TOKEN') {
        return ['ok' => false, 'http' => 0, 'err' => 'missing line token', 'resp' => null];
    }

    if (empty($body['to']) || empty($body['messages'])) {
        return ['ok' => false, 'http' => 0, 'err' => 'missing to/messages', 'resp' => null];
    }

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $LINE_CHANNEL_ACCESS_TOKEN
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
        error_log("LINE booking_ended push fail http={$http} err={$errNo} {$errTx} resp={$resp}");
    }

    return [
        'ok'   => $ok,
        'http' => $http,
        'err'  => $errTx,
        'resp' => $resp,
    ];
}

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

function build_booking_ended_flex(
    int $bookingId,
    string $slotLabel,
    string $startLabel,
    string $endLabel,
    string $url
): array {
    return [
        'type' => 'flex',
        'altText' => "AutoParkX: การจองสิ้นสุดแล้ว #{$bookingId}",
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
                        'text' => 'การจองสิ้นสุดแล้ว',
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
                        'text' => 'กรุณาตรวจสอบสถานะรถของคุณ',
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
                            flex_kv_row('สถานะ :', 'สิ้นสุดการจองแล้ว'),
                        ],
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'md',
                    ],
                    [
                        'type' => 'text',
                        'text' => 'หากยังจอดอยู่ กรุณาต่อเวลาหรือดำเนินการในระบบโดยเร็ว',
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
                        'text' => 'AutoParkX • Ended Reminder',
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
            b.status,
            b.ended_sent,
            b.ended_sent_at,

            u.line_user_id,
            u.email,

            COALESCE(ps.code, s.code) AS slot_code,
            COALESCE(ps.level, s.level) AS slot_level,

            p.enabled,
            p.booking_ended

        FROM bookings b
        INNER JOIN users u
            ON u.id = b.user_id
        LEFT JOIN parking_slots ps
            ON ps.id = b.slot_id
        LEFT JOIN slots s
            ON s.id = b.slot_id
        LEFT JOIN user_notify_prefs p
            ON p.user_id = u.id

        WHERE (b.ended_sent = 0 OR b.ended_sent IS NULL)
          AND b.end_time <= NOW()
          AND (
                b.status IS NULL
                OR b.status = ''
                OR UPPER(b.status) NOT IN ('CANCELLED', 'EXPIRED')
              )

        ORDER BY b.end_time ASC
        LIMIT 100
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sentCount = 0;
    $skipCount = 0;

    foreach ($rows as $r) {
        $bookingId = (int)($r['id'] ?? 0);
        $userId    = (int)($r['user_id'] ?? 0);
        $slotId    = (int)($r['slot_id'] ?? 0);
        $status    = strtoupper(trim((string)($r['status'] ?? '')));
        $lineUID   = trim((string)($r['line_user_id'] ?? ''));

        $enabled      = array_key_exists('enabled', $r) && $r['enabled'] !== null ? (int)$r['enabled'] : 1;
        $bookingEnded = array_key_exists('booking_ended', $r) && $r['booking_ended'] !== null ? (int)$r['booking_ended'] : 1;

        if ($bookingId <= 0 || $userId <= 0) {
            $skipCount++;
            continue;
        }

        if (!$enabled || !$bookingEnded) {
            error_log("[AutoParkX] booking_ended skipped booking_id={$bookingId} prefs_disabled");
            $skipCount++;
            continue;
        }

        if (!looks_like_line_user_id($lineUID)) {
            error_log("[AutoParkX] booking_ended skipped booking_id={$bookingId} invalid_line_user_id");
            $skipCount++;
            continue;
        }

        if (in_array($status, ['CANCELLED', 'EXPIRED'], true)) {
            $skipCount++;
            continue;
        }

        $start = new DateTime((string)$r['start_time'], new DateTimeZone('Asia/Bangkok'));
        $end   = new DateTime((string)$r['end_time'], new DateTimeZone('Asia/Bangkok'));

        $startLabel = $start->format('d/m/Y H:i');
        $endLabel   = $end->format('d/m/Y H:i');

        $slotCode  = trim((string)($r['slot_code'] ?? ''));
        $slotLevel = trim((string)($r['slot_level'] ?? ''));

        if ($slotCode === '') {
            $slotCode = 'Slot #' . $slotId;
        }

        $slotLabel = $slotCode;
        if ($slotLevel !== '') {
            $slotLabel .= ' (ชั้น ' . $slotLevel . ')';
        }

        $url = 'https://autoparkx.com/mybookings.html';

        $flex = build_booking_ended_flex(
            $bookingId,
            $slotLabel,
            $startLabel,
            $endLabel,
            $url
        );

        $send = line_push_raw([
            'to' => $lineUID,
            'messages' => [$flex]
        ]);

        if ($send['ok']) {
            $up = $pdo->prepare("
                UPDATE bookings
                SET ended_sent = 1,
                    ended_sent_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $up->execute([
                ':id' => $bookingId
            ]);

            $sentCount++;
            error_log("[AutoParkX] booking_ended sent booking_id={$bookingId} user_id={$userId}");
        } else {
            error_log("[AutoParkX] booking_ended send_failed booking_id={$bookingId} http={$send['http']}");
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
    error_log('[AutoParkX] cron_booking_ended error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}