<?php
require __DIR__ . '/db.php';

$issue_id = (int)($_GET['issue_id'] ?? 0);
if ($issue_id <= 0) {
    json_error("invalid issue_id", 422);
}

$stmt = $pdo->prepare("
    SELECT 
        m.id,
        m.sender_type,
        m.sender_id,
        m.message,
        m.created_at
    FROM parking_issue_messages m
    WHERE m.issue_id = :iid
    ORDER BY m.created_at ASC
");
$stmt->execute([':iid' => $issue_id]);

json_ok([
    'issue_id' => $issue_id,
    'messages' => $stmt->fetchAll()
]);
