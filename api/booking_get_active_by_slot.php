<?php
// api/booking_get_active_by_slot.php
// คืนรายการจองที่ "ยังมีผล" ของช่องนี้ (รองรับทั้ง slot_id และ code)
// ✅ FIX: ยืดหยุ่นตาม schema จริง + กัน collation 1267

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!function_exists('json_error')) {
  function json_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
if (!function_exists('json_ok')) {
  function json_ok($arr = []) {
    echo json_encode(['ok' => true] + $arr, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

function column_exists(PDO $pdo, string $table, string $col): bool {
  $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  $stmt->execute([$table, $col]);
  return (bool)$stmt->fetchColumn();
}

$slotId = isset($_GET['slot_id']) ? (int)$_GET['slot_id'] : 0;
$code   = isset($_GET['code']) ? trim((string)$_GET['code']) : '';

if ($slotId <= 0 && $code === '') json_error('slot_id or code is required', 422);

$now = date('Y-m-d H:i:s');

try {
  // ===== เลือกคอลัมน์ที่ใช้ผูกช่อง =====
  $slotWhere = [];
  $bind = [];

  // 1) แบบ ID
  foreach (['slot_id', 'parking_slot_id'] as $c) {
    if ($slotId > 0 && column_exists($pdo, 'bookings', $c)) {
      $slotWhere[] = "b.$c = ?";
      $bind[] = $slotId;
      break;
    }
  }

  // 2) แบบ CODE
  if ($code !== '') {
    foreach (['slot_code', 'code', 'slot', 'slot_name'] as $c) {
      if (column_exists($pdo, 'bookings', $c)) {
        // ✅ กัน collation ผสม
        $slotWhere[] = "(b.$c COLLATE utf8mb4_unicode_ci) = (? COLLATE utf8mb4_unicode_ci)";
        $bind[] = $code;
        break;
      }
    }
  }

  if (empty($slotWhere)) {
    json_ok(['booking' => null, 'hint' => 'bookings table has no slot_id/slot_code compatible column']);
  }

  $slotCond = '(' . implode(' OR ', $slotWhere) . ')';

  // ===== นิยาม “ยังมีผล” (active-ish) =====
  // - ถ้ามี start_time/end_time -> ใช้ช่วงเวลาเป็นหลัก (รองรับจองล่วงหน้า)
  // - ถ้าไม่มี -> ใช้ status แบบกว้าง (ไม่เอา cancelled/expired/completed)
  $hasStart = column_exists($pdo, 'bookings', 'start_time');
  $hasEnd   = column_exists($pdo, 'bookings', 'end_time');
  $hasStatus = column_exists($pdo, 'bookings', 'status');

  $conds = [];
  $conds[] = $slotCond;

  if ($hasStart && $hasEnd) {
    // จองล่วงหน้า: ถ้า end_time ยังไม่จบ ถือว่า “ยังมีผล”
    $conds[] = "(b.end_time IS NULL OR b.end_time > ?)";
    $bind[] = $now;
  }

  if ($hasStatus) {
    // ✅ กัน collation: บังคับ COLLATE ก่อนเทียบ
    // กรองออก: CANCELLED/EXPIRED/COMPLETED/DONE/FINISHED
    $conds[] = "(
      UPPER(b.status COLLATE utf8mb4_unicode_ci) NOT IN (
        'CANCELLED','CANCELED','EXPIRED','COMPLETED','DONE','FINISHED'
      )
    )";
  }

  $where = implode(' AND ', $conds);

  // ===== เลือก booking ล่าสุดที่เข้าเงื่อนไข =====
  $sql = "
    SELECT b.*
    FROM bookings b
    WHERE $where
    ORDER BY b.id DESC
    LIMIT 1
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($bind);
  $b = $stmt->fetch(PDO::FETCH_ASSOC);

  json_ok(['booking' => $b ?: null]);

} catch (Throwable $e) {
  json_error('server error: ' . $e->getMessage(), 500);
}
