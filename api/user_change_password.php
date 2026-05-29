<?php
/**
 * api/user_change_password.php
 * เปลี่ยนรหัสผ่านของผู้ใช้
 *
 * Request JSON:
 *   {
 *     "user_id": 2,
 *     "old_password": "123456",
 *     "new_password": "654321"
 *   }
 *
 * Response JSON (สำเร็จ):
 *   { "ok": true }
 */

require __DIR__ . '/db.php';

$in = json_input();
if (!$in) {
  json_error('invalid json body', 400);
}

$userId      = (int)($in['user_id'] ?? 0);
$oldPassword = (string)($in['old_password'] ?? '');
$newPassword = (string)($in['new_password'] ?? '');

if ($userId <= 0) {
  json_error('user_id is required', 422);
}
if ($oldPassword === '' || $newPassword === '') {
  json_error('old_password and new_password are required', 422);
}
if (strlen($newPassword) < 6) {
  json_error('new_password must be at least 6 characters', 422);
}

try {
  $q = $pdo->prepare("
    SELECT id, password_hash, status
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $q->execute([$userId]);
  $user = $q->fetch();

  if (!$user) {
    json_error('user not found', 404);
  }

  if (isset($user['status']) && strtolower((string)$user['status']) !== 'active') {
    json_error('บัญชีนี้ถูกระงับการใช้งาน', 403);
  }

  if (!password_verify($oldPassword, $user['password_hash'])) {
    json_error('old_password_incorrect', 401);
  }

  if (password_verify($newPassword, $user['password_hash'])) {
    json_error('new_password_same_as_old', 422);
  }

  $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

  $u = $pdo->prepare("
    UPDATE users
    SET password_hash = ?
    WHERE id = ?
    LIMIT 1
  ");
  $u->execute([$newHash, $userId]);

  json_ok(['ok' => true]);
} catch (Throwable $e) {
  json_error('server error', 500);
}