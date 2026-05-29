<?php
require __DIR__ . '/db.php';

$in = json_input();
$user_id = (int)($in['user_id'] ?? 0);
$token   = trim((string)($in['token'] ?? ''));

if ($user_id > 0 && $token !== '') {
    $stmt = $pdo->prepare("UPDATE users SET push_token = :t WHERE id = :id");
    $stmt->execute([':t' => $token, ':id' => $user_id]);
    json_ok(['saved' => true]);
}

json_error('invalid', 400);
