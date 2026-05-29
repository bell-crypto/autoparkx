<?php
require __DIR__ . '/db.php';

try {
  $in = json_input();
  if ($in === null) $in = $_POST ?: $_GET ?: [];

  $user_id = (int)arr_get($in, 'user_id', 0);
  if ($user_id <= 0) {
    json_error('missing user_id', 422);
  }

  $stmt = $pdo->prepare("
    SELECT 
      id,
      consent_accepted,
      consent_version,
      consent_accepted_at
    FROM users
    WHERE id = :uid
    LIMIT 1
  ");
  $stmt->execute([':uid' => $user_id]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    json_error('user not found', 404);
  }

  json_ok([
    'user_id' => (int)$user['id'],
    'consent_accepted' => (int)($user['consent_accepted'] ?? 0),
    'consent_version' => (string)($user['consent_version'] ?? ''),
    'consent_accepted_at' => $user['consent_accepted_at'] ?? null
  ]);
} catch (Throwable $e) {
  json_error('server error', 500);
}