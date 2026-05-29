<?php
// api/notify_prefs_get.php

require __DIR__ . '/db.php';

$in = json_input();
if ($in === null) {
    $in = $_POST ?: [];
}

$user_id = (int)($in['user_id'] ?? 0);
if ($user_id <= 0) {
    json_error('invalid user_id', 422);
}

$defaults = [
    'enabled'           => true,
    'booking_created'   => true,
    'booking_soon'      => true,
    'booking_end_soon'  => true,
    'booking_ended'     => true,
    'booking_cancelled' => true,
    'admin_reply'       => true,
    'news'              => false,
    'quiet_night'       => false,
    'wallet_topup'      => true,
];

try {
    $stmt = $pdo->prepare("
        SELECT
            enabled,
            booking_created,
            booking_soon,
            booking_end_soon,
            booking_ended,
            booking_cancelled,
            admin_reply,
            news,
            quiet_night,
            wallet_topup
        FROM user_notify_prefs
        WHERE user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([':uid' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_ok(['prefs' => $defaults]);
    }

    $prefs = [
        'enabled'           => isset($row['enabled']) ? (bool)$row['enabled'] : true,
        'booking_created'   => isset($row['booking_created']) ? (bool)$row['booking_created'] : true,
        'booking_soon'      => isset($row['booking_soon']) ? (bool)$row['booking_soon'] : true,
        'booking_end_soon'  => isset($row['booking_end_soon']) ? (bool)$row['booking_end_soon'] : true,
        'booking_ended'     => isset($row['booking_ended']) ? (bool)$row['booking_ended'] : true,
        'booking_cancelled' => isset($row['booking_cancelled']) ? (bool)$row['booking_cancelled'] : true,
        'admin_reply'       => isset($row['admin_reply']) ? (bool)$row['admin_reply'] : true,
        'news'              => isset($row['news']) ? (bool)$row['news'] : false,
        'quiet_night'       => isset($row['quiet_night']) ? (bool)$row['quiet_night'] : false,
        'wallet_topup'      => isset($row['wallet_topup']) ? (bool)$row['wallet_topup'] : true,
    ];

    json_ok(['prefs' => $prefs]);

} catch (Throwable $e) {
    error_log('[AutoParkX] notify_prefs_get error: ' . $e->getMessage());
    json_ok(['prefs' => $defaults]);
}