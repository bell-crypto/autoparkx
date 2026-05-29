<?php
// api/issue_list_my.php — ดึงประวัติการแจ้งปัญหาของผู้ใช้ + รูปแนบหลายรูป

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function current_origin(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

  return $scheme . '://' . $host;
}

function normalize_upload_path(string $path): string {
  $path = trim($path);
  if ($path === '') return '';

  // แก้เคสตัวพิมพ์ใหญ่/เล็กของโฟลเดอร์ uploads/issues
  $path = str_replace('\\', '/', $path);
  $path = preg_replace('~^https?://[^/]+~i', '', $path);

  $path = str_replace('/uploads/Issues/', '/uploads/issues/', $path);
  $path = str_replace('uploads/Issues/', 'uploads/issues/', $path);
  $path = str_replace('/Uploads/Issues/', '/uploads/issues/', $path);
  $path = str_replace('Uploads/Issues/', 'uploads/issues/', $path);

  return $path;
}

function to_abs_url(string $path): string {
  $path = normalize_upload_path($path);
  if ($path === '') return '';

  if (preg_match('~^https?://~i', $path)) return $path;

  if (strpos($path, '//') === 0) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    return ($https ? 'https:' : 'http:') . $path;
  }

  $origin = current_origin();

  if ($path[0] === '/') return $origin . $path;

  return $origin . '/' . ltrim($path, '/');
}

function push_image(array &$bucket, string $value): void {
  $value = trim($value);
  if ($value === '') return;

  $url = to_abs_url($value);
  if ($url === '') return;

  if (!in_array($url, $bucket, true)) {
    $bucket[] = $url;
  }
}

function extract_image_values($value): array {
  $out = [];

  if ($value === null) return $out;

  if (is_string($value)) {
    $s = trim($value);
    if ($s === '') return $out;

    if (($s[0] === '[' || $s[0] === '{')) {
      $decoded = json_decode($s, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        return extract_image_values($decoded);
      }
    }

    $parts = preg_split('/[\r\n,]+/', $s) ?: [];
    foreach ($parts as $part) {
      $part = trim($part);
      if ($part !== '') $out[] = $part;
    }
    return $out;
  }

  if (is_array($value)) {
    foreach ($value as $item) {
      if (is_string($item)) {
        $item = trim($item);
        if ($item !== '') $out[] = $item;
        continue;
      }

      if (is_array($item)) {
        foreach (['url', 'path', 'src', 'image', 'image_url', 'image_path', 'file', 'file_path'] as $k) {
          if (!empty($item[$k]) && is_string($item[$k])) {
            $out[] = trim($item[$k]);
            break;
          }
        }
      }
    }
  }

  return $out;
}

$in = json_input();
if ($in === null) $in = $_GET ?: ($_POST ?: []);

$user_id = (int)($in['user_id'] ?? 0);
$limit   = (int)($in['limit'] ?? 50);
$offset  = (int)($in['offset'] ?? 0);

if ($user_id <= 0) {
  json_error('user_id is required', 422);
}
if ($limit <= 0 || $limit > 200) $limit = 50;
if ($offset < 0) $offset = 0;

try {
  $sql = "
    SELECT
      i.id,
      i.user_id,
      i.booking_id,
      i.slot_id,
      i.issue_type,
      i.detail,
      i.status,
      i.created_at,
      i.updated_at,
      i.admin_reply,
      i.reply_at,
      s.code AS slot_code
    FROM parking_issues i
    LEFT JOIN parking_slots s ON s.id = i.slot_id
    WHERE i.user_id = :uid
    ORDER BY i.id DESC
    LIMIT :lim OFFSET :ofs
  ";

  $st = $pdo->prepare($sql);
  $st->bindValue(':uid', $user_id, PDO::PARAM_INT);
  $st->bindValue(':lim', $limit, PDO::PARAM_INT);
  $st->bindValue(':ofs', $offset, PDO::PARAM_INT);
  $st->execute();

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $issueIds = array_values(array_filter(array_map(function($r) {
    return (int)($r['id'] ?? 0);
  }, $rows)));

  $imageMap = [];

  if ($issueIds) {
    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));

    $sqlImg = "
      SELECT issue_id, image_path
      FROM parking_issue_images
      WHERE issue_id IN ({$placeholders})
      ORDER BY issue_id ASC, sort_order ASC, id ASC
    ";

    $stImg = $pdo->prepare($sqlImg);
    foreach ($issueIds as $i => $id) {
      $stImg->bindValue($i + 1, $id, PDO::PARAM_INT);
    }
    $stImg->execute();

    while ($img = $stImg->fetch(PDO::FETCH_ASSOC)) {
      $iid  = (int)($img['issue_id'] ?? 0);
      $path = (string)($img['image_path'] ?? '');
      if ($iid <= 0 || $path === '') continue;

      if (!isset($imageMap[$iid])) $imageMap[$iid] = [];
      push_image($imageMap[$iid], $path);
    }
  }

  $issues = array_map(function($r) use ($imageMap) {
    $id = (int)($r['id'] ?? 0);
    $images = $imageMap[$id] ?? [];

    return [
      'id'          => $id,
      'user_id'     => (int)($r['user_id'] ?? 0),
      'booking_id'  => isset($r['booking_id']) && $r['booking_id'] !== null ? (int)$r['booking_id'] : null,
      'slot_id'     => isset($r['slot_id']) && $r['slot_id'] !== null ? (int)$r['slot_id'] : null,
      'slot_code'   => (string)($r['slot_code'] ?? ''),
      'issue_type'  => (string)($r['issue_type'] ?? ''),
      'detail'      => (string)($r['detail'] ?? ''),
      'status'      => (string)($r['status'] ?? 'NEW'),
      'created_at'  => (string)($r['created_at'] ?? ''),
      'updated_at'  => (string)($r['updated_at'] ?? ''),
      'admin_reply' => (string)($r['admin_reply'] ?? ''),
      'reply_at'    => (string)($r['reply_at'] ?? ''),
      'image_url'   => $images[0] ?? '',
      'image_path'  => $images[0] ?? '',
      'images'      => $images,
      'image_urls'  => $images,
      'image_paths' => $images,
      'image_count' => count($images),
    ];
  }, $rows);

  json_ok([
    'issues' => $issues,
    'count'  => count($issues),
    'limit'  => $limit,
    'offset' => $offset,
    'ts'     => time(),
    'mark'   => 'issue_list_my_fixed_v5'
  ]);

} catch (Throwable $e) {
  json_error('query failed: ' . $e->getMessage(), 500);
}