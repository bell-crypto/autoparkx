<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$u = $_SESSION['user'] ?? null;
if (!$u || ($u['role'] ?? '') !== 'admin') {
  $next = urlencode($_SERVER['REQUEST_URI'] ?? '/public/admin.php');
  header("Location: /public/login.html?next={$next}");
  exit;
}
