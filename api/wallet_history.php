<?php
require __DIR__ . '/db.php';

$user_id = intval($_GET['user_id'] ?? 0);

if ($user_id <= 0) {
    json_error('missing user_id');
}

// ดึงประวัติทั้งหมดเรียงจากใหม่สุดลงไปเก่าสุด
$stmt = $pdo->prepare("
    SELECT id, user_id, type, amount, note, ref, created_at
    FROM wallet_history
    WHERE user_id = ?
    ORDER BY id DESC
");
$stmt->execute([$user_id]);
$rows = $stmt->fetchAll();

json_ok([
    'history' => $rows
]);
