<?php
// api/notify_prefs_save.php

require __DIR__ . '/db.php';

$in = json_input();
if ($in === null) {
    $in = $_POST ?: [];
}

$user_id = (int)($in['user_id'] ?? 0);
$prefs   = $in['prefs'] ?? null;

if ($user_id <= 0 || !is_array($prefs)) {
    json_error('invalid payload', 422);
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

$cfg = [
    'enabled'           => array_key_exists('enabled', $prefs) ? (bool)$prefs['enabled'] : $defaults['enabled'],
    'booking_created'   => array_key_exists('booking_created', $prefs) ? (bool)$prefs['booking_created'] : $defaults['booking_created'],
    'booking_soon'      => array_key_exists('booking_soon', $prefs) ? (bool)$prefs['booking_soon'] : $defaults['booking_soon'],
    'booking_end_soon'  => array_key_exists('booking_end_soon', $prefs) ? (bool)$prefs['booking_end_soon'] : $defaults['booking_end_soon'],
    'booking_ended'     => array_key_exists('booking_ended', $prefs) ? (bool)$prefs['booking_ended'] : $defaults['booking_ended'],
    'booking_cancelled' => array_key_exists('booking_cancelled', $prefs) ? (bool)$prefs['booking_cancelled'] : $defaults['booking_cancelled'],
    'admin_reply'       => array_key_exists('admin_reply', $prefs) ? (bool)$prefs['admin_reply'] : $defaults['admin_reply'],
    'news'              => array_key_exists('news', $prefs) ? (bool)$prefs['news'] : $defaults['news'],
    'quiet_night'       => array_key_exists('quiet_night', $prefs) ? (bool)$prefs['quiet_night'] : $defaults['quiet_night'],
    'wallet_topup'      => array_key_exists('wallet_topup', $prefs) ? (bool)$prefs['wallet_topup'] : $defaults['wallet_topup'],
];

try {
    $sql = "
        INSERT INTO user_notify_prefs (
            user_id,
            enabled,
            booking_created,
            booking_soon,
            booking_end_soon,
            booking_ended,
            booking_cancelled,
            admin_reply,
            news,
            quiet_night,
            wallet_topup,
            updated_at
        ) VALUES (
            :uid,
            :enabled,
            :booking_created,
            :booking_soon,
            :booking_end_soon,
            :booking_ended,
            :booking_cancelled,
            :admin_reply,
            :news,
            :quiet_night,
            :wallet_topup,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            enabled           = VALUES(enabled),
            booking_created   = VALUES(booking_created),
            booking_soon      = VALUES(booking_soon),
            booking_end_soon  = VALUES(booking_end_soon),
            booking_ended     = VALUES(booking_ended),
            booking_cancelled = VALUES(booking_cancelled),
            admin_reply       = VALUES(admin_reply),
            news              = VALUES(news),
            quiet_night       = VALUES(quiet_night),
            wallet_topup      = VALUES(wallet_topup),
            updated_at        = VALUES(updated_at)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uid'               => $user_id,
        ':enabled'           => (int)$cfg['enabled'],
        ':booking_created'   => (int)$cfg['booking_created'],
        ':booking_soon'      => (int)$cfg['booking_soon'],
        ':booking_end_soon'  => (int)$cfg['booking_end_soon'],
        ':booking_ended'     => (int)$cfg['booking_ended'],
        ':booking_cancelled' => (int)$cfg['booking_cancelled'],
        ':admin_reply'       => (int)$cfg['admin_reply'],
        ':news'              => (int)$cfg['news'],
        ':quiet_night'       => (int)$cfg['quiet_night'],
        ':wallet_topup'      => (int)$cfg['wallet_topup'],
    ]);

    json_ok([
        'saved' => true,
        'prefs' => $cfg,
    ]);

} catch (Throwable $e) {
    error_log('[AutoParkX] notify_prefs_save error: ' . $e->getMessage());
    json_error('save error', 500);
}