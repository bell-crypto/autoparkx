<?php
/**
 * api/consent_accept.php
 * บันทึกการยินยอมลงตาราง users ตาม user_id
 * expects JSON: { user_id: number, consent_version?: string }
 */

require __DIR__ . '/db.php';

try {
  $in = json_input();
  if ($in === null) $in = $_POST ?: [];

  $user_id = (int)arr_get($in, 'user_id', 0);
  $consent_version = (string)arr_get($in, 'consent_version', 'v1');

  if ($user_id <= 0) {
    json_error('missing user_id', 422);
  }

  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

  $sql = "UPDATE users
          SET consent_version     = :ver,
              consent_accepted    = 1,
              consent_accepted_at = NOW(),
              consent_ip          = :ip,
              consent_user_agent  = :ua
          WHERE id = :uid
          LIMIT 1";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':ver' => $consent_version,
    ':ip'  => $ip,
    ':ua'  => $ua,
    ':uid' => $user_id
  ]);

  if ($stmt->rowCount() <= 0) {
    $chk = $pdo->prepare("SELECT id FROM users WHERE id = :uid LIMIT 1");
    $chk->execute([':uid' => $user_id]);
    if (!$chk->fetch()) json_error('user not found', 404);
  }

  json_ok([
    'user_id' => $user_id,
    'consent_version' => $consent_version,
    'saved' => true
  ]);
} catch (Throwable $e) {
  json_error('server error', 500);
}