<?php
// api/wallet_balance.php — ดึงยอดคงเหลือ e-Wallet ของผู้ใช้
require __DIR__ . '/db.php';

/* ---------- JSON helpers (กันเคสที่โปรเจ็กต์ยังไม่มี) ---------- */
if (!function_exists('json_ok')) {
  function json_ok($payload = [], $code = 200){
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
  }
}
if (!function_exists('json_error')) {
  function json_error($message = 'error', $code = 400, $extra = []){
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>$message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/* ---------- รับ user_id จาก GET / POST ---------- */
$user_id = 0;

// ลองอ่านจาก GET ก่อน เช่น wallet_balance.php?user_id=1
if (isset($_GET['user_id'])) {
  $user_id = (int)$_GET['user_id'];
} elseif (isset($_POST['user_id'])) {
  // รองรับ POST ด้วย
  $user_id = (int)$_POST['user_id'];
} else {
  // รองรับ JSON body ด้วย
  $raw = file_get_contents('php://input');
  if ($raw) {
    $d = json_decode($raw, true);
    if (is_array($d) && isset($d['user_id'])) {
      $user_id = (int)$d['user_id'];
    }
  }
}

if ($user_id <= 0) {
  json_error('missing user_id', 422);
}

/* ---------- ดึงยอด wallet จากตาราง users ---------- */
try {
  // ล็อกแบบอ่านเฉย ๆ ไม่ต้อง FOR UPDATE
  $stmt = $pdo->prepare("SELECT id, wallet FROM users WHERE id = ?");
  $stmt->execute([$user_id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    json_error('user not found', 404);
  }

  $balance = (float)($row['wallet'] ?? 0);

  json_ok([
    'user_id' => (int)$row['id'],
    'balance' => $balance
  ]);

} catch (Throwable $e) {
  error_log('wallet_balance error: '.$e->getMessage());
  json_error('server error', 500);
}
