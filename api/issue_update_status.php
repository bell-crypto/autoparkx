<?php
// api/issue_update_status.php — เปลี่ยนสถานะของการแจ้งปัญหา (เฉพาะแอดมิน)

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* ===== helper ===== */
if (!function_exists('json_error')) {
  function json_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
if (!function_exists('json_ok')) {
  function json_ok($data = []) {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if (!function_exists('json_input')) {
  function json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
  }
}

/* ===== รับข้อมูล ===== */
$in = json_input() ?: $_POST;

$id     = isset($in['id']) ? (int)$in['id'] : 0;
$status = strtoupper(trim((string)($in['status'] ?? '')));

$allowed = ['NEW','IN_PROGRESS','DONE'];

if ($id <= 0) {
  json_error("id is required", 422);
}
if (!in_array($status, $allowed, true)) {
  json_error("invalid status", 422);
}

/* ===== UPDATE ===== */
try {
  $stmt = $pdo->prepare("UPDATE parking_issues
                         SET status = ?, updated_at = NOW()
                         WHERE id = ?");
  $stmt->execute([$status, $id]);

  if ($stmt->rowCount() === 0) {
    json_error("issue not found", 404);
  }

  json_ok([
    'id' => $id,
    'status' => $status
  ]);

} catch (Throwable $e) {
  json_error("db error: ".$e->getMessage(), 500);
}
