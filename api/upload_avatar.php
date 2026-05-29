<?php
// api/upload_avatar.php
// อัปโหลดรูปโปรไฟล์ + บันทึก path ลง users.avatar_url

require __DIR__ . '/db.php';

// ---- รับ user_id จาก POST ----
$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
if ($userId <= 0) {
  json_error('missing user_id', 400);
}

// ---- เช็คไฟล์ ----
if (!isset($_FILES['avatar'])) {
  json_error('no file uploaded', 400);
}

$f = $_FILES['avatar'];

// handle error code จาก PHP ก่อน
if ($f['error'] !== UPLOAD_ERR_OK) {
  switch ($f['error']) {
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
      json_error('file too large (server limit)', 413);
    case UPLOAD_ERR_NO_FILE:
      json_error('no file uploaded', 400);
    default:
      json_error('upload error code '.$f['error'], 400);
  }
}

// จำกัดขนาดไฟล์ (10MB)
if ($f['size'] > 10 * 1024 * 1024) {
  json_error('file too large (max 10MB)', 413);
}

// อนุญาตเฉพาะ jpg / png / webp
$allowed = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
];
if (!isset($allowed[$f['type']])) {
  json_error('invalid file type', 415);
}

$ext = $allowed[$f['type']];

// ---- เตรียมโฟลเดอร์เก็บรูป ----
// public_html/api/upload_avatar.php
// -> ../uploads/avatars/ = public_html/uploads/avatars/
$uploadDir = __DIR__ . '/../uploads/avatars/';

if (!is_dir($uploadDir)) {
  if (!mkdir($uploadDir, 0777, true)) {
    json_error('cannot create upload dir', 500);
  }
}

if (!is_writable($uploadDir)) {
  json_error('upload dir not writable', 500);
}

// ตั้งชื่อไฟล์ใหม่ไม่ให้ซ้ำ
$filename = 'u' . $userId . '_' . time() . '.' . $ext;
$fullPath = $uploadDir . $filename;

if (!move_uploaded_file($f['tmp_name'], $fullPath)) {
  json_error('cannot save file', 500);
}

// path ที่ฝั่งเว็บใช้ (relative จาก root)
// => https://autoparkx.com/uploads/avatars/xxxx.jpg
$publicPath = 'uploads/avatars/' . $filename;

// ---- อัปเดต DB ----
$stmt = $pdo->prepare("UPDATE users SET avatar_url=? WHERE id=?");
$stmt->execute([$publicPath, $userId]);

json_ok([
  'avatar_url' => $publicPath,
]);
