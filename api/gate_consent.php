<?php
// gate_consent.php — บังคับ consent ก่อนเข้าใช้งาน
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php'; // ต้องมี $pdo (PDO)

$CONSENT_VERSION = 'v1';
$LOGIN_URL   = 'login.php';    // ปรับตามไฟล์จริงของคุณ
$CONSENT_URL = 'consent.html'; // จะเป็น consent.php ก็ได้

function redirect_to(string $url): void {
  header('Location: ' . $url, true, 302);
  exit;
}

// ต้องล็อกอินก่อน
$user_id = (int)($_SESSION['user_id'] ?? 0); // ถ้าของคุณใช้ $_SESSION['id'] ให้แก้ตรงนี้
if ($user_id <= 0) {
  $next = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
  redirect_to($LOGIN_URL . '?next=' . $next);
}

// เช็ค consent จาก users table
try {
  $stmt = $pdo->prepare("
    SELECT consent_accepted, consent_version
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$user_id]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  $ok = $u
    && (int)$u['consent_accepted'] === 1
    && (string)$u['consent_version'] === $CONSENT_VERSION;

  if (!$ok) {
    $next = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
    redirect_to($CONSENT_URL . '?next=' . $next);
  }
} catch (Throwable $e) {
  // ถ้า DB มีปัญหา: กันไว้ก่อนให้ไป consent
  $next = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
  redirect_to($CONSENT_URL . '?next=' . $next);
}