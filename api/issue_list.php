<?php
// api/issue_list.php — ดึงรายการแจ้งปัญหาสำหรับแอดมินดู + รองรับคืนข้อมูลรูป

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* ===== helper เผื่อโปรเจกต์เก่าไม่มีฟังก์ชันพวกนี้ ===== */
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

function table_exists(PDO $pdo, string $tableName): bool {
  try {
    $stmt = $pdo->prepare("
      SELECT COUNT(*) 
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function build_public_url(string $path): string {
  $path = trim($path);
  if ($path === '') return '';

  if (preg_match('~^https?://~i', $path)) {
    return $path;
  }

  $https = false;

  if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
    $https = true;
  }
  if (!$https && isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
    $https = true;
  }
  if (!$https && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $https = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
  }

  $scheme = $https ? 'https' : 'http';
  $host   = (string)($_SERVER['HTTP_HOST'] ?? '');

  if ($host === '') {
    return $path;
  }

  return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

/* ===== อ่านพารามิเตอร์จาก GET ===== */
$allowedStatus = ['NEW', 'IN_PROGRESS', 'DONE'];

$statusParam = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$statuses = [];
if ($statusParam !== '') {
  foreach (explode(',', $statusParam) as $s) {
    $u = strtoupper(trim($s));
    if (in_array($u, $allowedStatus, true)) {
      $statuses[] = $u;
    }
  }
}

$user_id    = isset($_GET['user_id'])    ? (int)$_GET['user_id']    : 0;
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$slot_id    = isset($_GET['slot_id'])    ? (int)$_GET['slot_id']    : 0;

$search     = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$date_from  = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$date_to    = isset($_GET['date_to'])   ? trim((string)$_GET['date_to'])   : '';

$limit  = isset($_GET['limit'])  ? (int)$_GET['limit']  : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

if ($limit <= 0)  $limit = 50;
if ($limit > 200) $limit = 200;
if ($offset < 0)  $offset = 0;

/* ===== สร้างเงื่อนไข SQL ===== */
$cond = [];
$args = [];

if (!empty($statuses)) {
  $place = implode(',', array_fill(0, count($statuses), '?'));
  $cond[] = "pi.status IN ($place)";
  $args = array_merge($args, $statuses);
}

if ($user_id > 0) {
  $cond[] = "pi.user_id = ?";
  $args[] = $user_id;
}
if ($booking_id > 0) {
  $cond[] = "pi.booking_id = ?";
  $args[] = $booking_id;
}
if ($slot_id > 0) {
  $cond[] = "pi.slot_id = ?";
  $args[] = $slot_id;
}

if ($search !== '') {
  $like = '%' . $search . '%';
  $cond[] = "(
    CAST(pi.id AS CHAR) LIKE ?
    OR CAST(pi.user_id AS CHAR) LIKE ?
    OR CAST(pi.booking_id AS CHAR) LIKE ?
    OR CAST(pi.slot_id AS CHAR) LIKE ?
    OR pi.issue_type LIKE ?
    OR pi.detail LIKE ?
    OR pi.status LIKE ?
  )";
  $args[] = $like;
  $args[] = $like;
  $args[] = $like;
  $args[] = $like;
  $args[] = $like;
  $args[] = $like;
  $args[] = $like;
}

if ($date_from !== '') {
  $cond[] = "pi.created_at >= ?";
  $args[] = $date_from;
}
if ($date_to !== '') {
  $dateToFinal = $date_to;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $dateToFinal .= ' 23:59:59';
  }
  $cond[] = "pi.created_at <= ?";
  $args[] = $dateToFinal;
}

$where = '';
if ($cond) {
  $where = 'WHERE ' . implode(' AND ', $cond);
}

/* ===== ดึงข้อมูลจากฐานข้อมูล ===== */
try {
  // นับจำนวนทั้งหมด (สำหรับ paging)
  $sqlCount = "SELECT COUNT(*) AS c FROM parking_issues pi $where";
  $stmt = $pdo->prepare($sqlCount);
  $stmt->execute($args);
  $total = (int)($stmt->fetchColumn() ?: 0);

  // ดึงรายการจริง
  $sqlList = "SELECT
                pi.id,
                pi.user_id,
                pi.booking_id,
                pi.slot_id,
                pi.issue_type,
                pi.detail,
                pi.image_path,
                pi.status,
                pi.created_at,
                pi.updated_at
              FROM parking_issues pi
              $where
              ORDER BY pi.created_at DESC, pi.id DESC
              LIMIT ? OFFSET ?";
  $argsList = $args;
  $argsList[] = $limit;
  $argsList[] = $offset;

  $stmt2 = $pdo->prepare($sqlList);
  $stmt2->execute($argsList);
  $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

  // เตรียมข้อมูลรูปหลายรูปจากตาราง parking_issue_images (ถ้ามี)
  $hasMultiImageTable = table_exists($pdo, 'parking_issue_images');
  $imagesMap = [];

  if ($hasMultiImageTable && !empty($rows)) {
    $issueIds = array_values(array_filter(array_map(static function ($r) {
      return (int)($r['id'] ?? 0);
    }, $rows)));

    if (!empty($issueIds)) {
      $placeholders = implode(',', array_fill(0, count($issueIds), '?'));

      $sqlImg = "SELECT issue_id, image_path, sort_order, id
                 FROM parking_issue_images
                 WHERE issue_id IN ($placeholders)
                 ORDER BY issue_id ASC, sort_order ASC, id ASC";
      $stmtImg = $pdo->prepare($sqlImg);
      $stmtImg->execute($issueIds);
      $imgRows = $stmtImg->fetchAll(PDO::FETCH_ASSOC);

      foreach ($imgRows as $img) {
        $iid  = (int)($img['issue_id'] ?? 0);
        $path = trim((string)($img['image_path'] ?? ''));
        if ($iid > 0 && $path !== '') {
          if (!isset($imagesMap[$iid])) {
            $imagesMap[$iid] = [];
          }
          $imagesMap[$iid][] = $path;
        }
      }
    }
  }

  // ผูกข้อมูลรูปกลับเข้าแต่ละรายการ
  foreach ($rows as &$row) {
    $issueId = (int)($row['id'] ?? 0);
    $single  = trim((string)($row['image_path'] ?? ''));
    $paths   = [];

    if ($issueId > 0 && isset($imagesMap[$issueId]) && is_array($imagesMap[$issueId])) {
      $paths = $imagesMap[$issueId];
    }

    if ($single !== '' && !in_array($single, $paths, true)) {
      array_unshift($paths, $single);
    }

    $paths = array_values(array_unique(array_filter($paths, static function ($v) {
      return trim((string)$v) !== '';
    })));

    $row['image_path']  = $paths[0] ?? ($single !== '' ? $single : null);
    $row['image_paths'] = $paths;
    $row['image_urls']  = array_values(array_filter(array_map('build_public_url', $paths)));
    $row['image_count'] = count($paths);
  }
  unset($row);

  json_ok([
    'issues' => $rows,
    'paging' => [
      'total'  => $total,
      'limit'  => $limit,
      'offset' => $offset,
    ],
    'supports_images' => true,
  ]);

} catch (Throwable $e) {
  json_error('db error: ' . $e->getMessage(), 500);
}