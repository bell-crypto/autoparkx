<?php
// api/issue_create.php — บันทึกการแจ้งปัญหาจากผู้ใช้ + รองรับอัปโหลดสูงสุด 3 รูป

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

function normalize_upload_error_message($code) {
  switch ((int)$code) {
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
      return 'ไฟล์มีขนาดใหญ่เกินกำหนด';
    case UPLOAD_ERR_PARTIAL:
      return 'อัปโหลดไฟล์ไม่สมบูรณ์';
    case UPLOAD_ERR_NO_FILE:
      return '';
    case UPLOAD_ERR_NO_TMP_DIR:
      return 'ไม่พบโฟลเดอร์ชั่วคราวของระบบ';
    case UPLOAD_ERR_CANT_WRITE:
      return 'ไม่สามารถบันทึกไฟล์ลงเซิร์ฟเวอร์ได้';
    case UPLOAD_ERR_EXTENSION:
      return 'การอัปโหลดถูกหยุดโดยส่วนขยายของเซิร์ฟเวอร์';
    default:
      return 'อัปโหลดไฟล์ไม่สำเร็จ';
  }
}

function ensure_dir($dir) {
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
      json_error('ไม่สามารถสร้างโฟลเดอร์เก็บรูปได้', 500);
    }
  }
}

function ensure_issue_images_table(PDO $pdo) {
  $sql = "
    CREATE TABLE IF NOT EXISTS parking_issue_images (
      id INT NOT NULL AUTO_INCREMENT,
      issue_id INT NOT NULL,
      image_path VARCHAR(255) NOT NULL,
      sort_order INT NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_issue_id (issue_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ";
  $pdo->exec($sql);
}

function collect_uploaded_images() {
  $files = [];

  // แบบใหม่: issue_images[]
  if (isset($_FILES['issue_images']) && is_array($_FILES['issue_images'])) {
    $raw = $_FILES['issue_images'];

    if (isset($raw['name']) && is_array($raw['name'])) {
      $count = count($raw['name']);
      for ($i = 0; $i < $count; $i++) {
        $err = isset($raw['error'][$i]) ? (int)$raw['error'][$i] : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;

        $files[] = [
          'name'     => $raw['name'][$i] ?? '',
          'type'     => $raw['type'][$i] ?? '',
          'tmp_name' => $raw['tmp_name'][$i] ?? '',
          'error'    => $err,
          'size'     => isset($raw['size'][$i]) ? (int)$raw['size'][$i] : 0,
        ];
      }
    }
  }

  // fallback แบบเก่า: issue_image
  if (!$files && isset($_FILES['issue_image']) && is_array($_FILES['issue_image'])) {
    $file = $_FILES['issue_image'];
    $err = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;

    if ($err !== UPLOAD_ERR_NO_FILE) {
      $files[] = [
        'name'     => $file['name'] ?? '',
        'type'     => $file['type'] ?? '',
        'tmp_name' => $file['tmp_name'] ?? '',
        'error'    => $err,
        'size'     => isset($file['size']) ? (int)$file['size'] : 0,
      ];
    }
  }

  return $files;
}

function validate_uploaded_image(array $file) {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $msg = normalize_upload_error_message($file['error'] ?? 0);
    json_error($msg !== '' ? $msg : 'อัปโหลดไฟล์ไม่สำเร็จ', 422);
  }

  if (!isset($file['tmp_name']) || $file['tmp_name'] === '' || !is_uploaded_file($file['tmp_name'])) {
    json_error('ไม่พบไฟล์อัปโหลดที่ถูกต้อง', 422);
  }

  $size = (int)($file['size'] ?? 0);
  $maxBytes = 5 * 1024 * 1024;

  if ($size <= 0) {
    json_error('ไฟล์รูปไม่ถูกต้อง', 422);
  }
  if ($size > $maxBytes) {
    json_error('รูปต้องมีขนาดไม่เกิน 5MB', 422);
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
  if ($finfo) finfo_close($finfo);

  $allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
  ];

  if (!isset($allowed[$mime])) {
    json_error('รองรับเฉพาะไฟล์ JPG, PNG, WEBP', 422);
  }

  return $allowed[$mime];
}

function save_uploaded_image(array $file, int $user_id, string $ext, string $uploadDirFs) {
  $safeName = 'issue_' . $user_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
  $targetFs = rtrim($uploadDirFs, '/\\') . DIRECTORY_SEPARATOR . $safeName;

  if (!move_uploaded_file($file['tmp_name'], $targetFs)) {
    json_error('บันทึกรูปไม่สำเร็จ', 500);
  }

  @chmod($targetFs, 0644);

  return [
    'fs_path' => $targetFs,
    'web_path' => 'uploads/issues/' . $safeName
  ];
}

/* ===== รับข้อมูลจาก client ===== */
$in = json_input();
if ($in === null) {
  $in = $_POST ?: [];
}

$user_id    = isset($in['user_id']) ? (int)$in['user_id'] : 0;
$booking_id = isset($in['booking_id']) && $in['booking_id'] !== '' ? (int)$in['booking_id'] : null;
$issue_type = trim((string)($in['issue_type'] ?? ''));
$detail     = trim((string)($in['detail'] ?? ''));

if ($user_id <= 0) {
  json_error('user_id is required', 422);
}
if ($issue_type === '') {
  json_error('issue_type is required', 422);
}
if ($detail === '') {
  json_error('detail is required', 422);
}

/* ===== เตรียมตารางรูปแยกก่อนเปิด transaction ===== */
try {
  ensure_issue_images_table($pdo);
} catch (Throwable $e) {
  json_error('ไม่สามารถเตรียมตารางรูปได้: ' . $e->getMessage(), 500);
}

/* ===== อัปโหลดรูป ===== */
$uploadedFiles = collect_uploaded_images();
if (count($uploadedFiles) > 3) {
  json_error('แนบรูปได้สูงสุด 3 รูป', 422);
}

$uploadDirFs = dirname(__DIR__) . '/uploads/issues';
$savedFsPaths = [];
$imagePaths = [];

if ($uploadedFiles) {
  ensure_dir($uploadDirFs);

  $exts = [];
  foreach ($uploadedFiles as $file) {
    $exts[] = validate_uploaded_image($file);
  }

  foreach ($uploadedFiles as $i => $file) {
    $saved = save_uploaded_image($file, $user_id, $exts[$i], $uploadDirFs);
    $savedFsPaths[] = $saved['fs_path'];
    $imagePaths[]   = $saved['web_path'];
  }
}

$image_path = $imagePaths[0] ?? null;

/* ===== หา slot_id จาก booking ถ้ามี ===== */
$slot_id = null;
if ($booking_id) {
  try {
    $stmt = $pdo->prepare("SELECT slot_id FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['slot_id'])) {
      $slot_id = (int)$row['slot_id'];
    }
  } catch (Throwable $e) {
    $slot_id = null;
  }
}

/* ===== บันทึก DB ===== */
try {
  $pdo->beginTransaction();

  $stmt = $pdo->prepare("
    INSERT INTO parking_issues
      (user_id, booking_id, slot_id, issue_type, detail, image_path, status, created_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?, ?, 'NEW', NOW(), NOW())
  ");
  $stmt->execute([
    $user_id,
    $booking_id ?: null,
    $slot_id,
    $issue_type,
    $detail,
    $image_path
  ]);

  $issueId = (int)$pdo->lastInsertId();

  if (!empty($imagePaths)) {
    $imgStmt = $pdo->prepare("
      INSERT INTO parking_issue_images (issue_id, image_path, sort_order)
      VALUES (?, ?, ?)
    ");

    foreach ($imagePaths as $i => $path) {
      $imgStmt->execute([
        $issueId,
        $path,
        $i + 1
      ]);
    }
  }

  $pdo->commit();

  json_ok([
    'id' => $issueId,
    'image_path' => $image_path,
    'image_paths' => $imagePaths
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  foreach ($savedFsPaths as $fsPath) {
    if (is_string($fsPath) && $fsPath !== '' && is_file($fsPath)) {
      @unlink($fsPath);
    }
  }

  json_error('db error: ' . $e->getMessage(), 500);
}