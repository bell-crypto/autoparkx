<?php
/**
 * Copy this file to api/db.php and fill in your real credentials.
 * Do NOT commit api/db.php to GitHub.
 */
declare(strict_types=1);

$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'your_database_name';
$DB_USER = getenv('DB_USER') ?: 'your_database_user';
$DB_PASS = getenv('DB_PASS') ?: 'your_database_password';

date_default_timezone_set('Asia/Bangkok');

$DSN = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  $pdo = new PDO($DSN, $DB_USER, $DB_PASS, $options);
  $pdo->exec("SET NAMES utf8mb4");
  $pdo->exec("SET CHARACTER SET utf8mb4");
  $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
  $pdo->exec("SET time_zone = '+07:00'");
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(['ok' => false, 'error' => 'Database connection failed'], JSON_UNESCAPED_UNICODE);
  exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function json_input(): ?array {
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') return null;
  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
}

function json_ok(array $data = [], int $status = 200): void {
  http_response_code($status);
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function json_error(string $message = 'bad request', int $status = 400): void {
  http_response_code($status);
  echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
  exit;
}

function to_mysql_dt(string $s): string {
  $s = str_replace('T', ' ', trim($s));
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
  return $s;
}

function arr_get(array $a, string|int $k, mixed $default = null): mixed {
  return array_key_exists($k, $a) ? $a[$k] : $default;
}
