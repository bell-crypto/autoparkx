<?php
/**
 * api/auth_logout.php
 * 🚪 ออกจากระบบ — เคลียร์ session ปัจจุบันของผู้ใช้
 */

require __DIR__ . '/db.php';  // มี session_start() อยู่ใน db.php แล้ว

// ล้างตัวแปรเซสชันทั้งหมด
$_SESSION = [];

// ล้าง cookie ของ session (ถ้ามี)
if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $p['path'], $p['domain'], $p['secure'], $p['httponly']
  );
}

// ทำลาย session จริง ๆ
session_destroy();

// ส่งผลลัพธ์กลับแบบ JSON
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
