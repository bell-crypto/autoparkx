<?php
// api/reports_hourly.php
// กราฟการใช้งานตามชั่วโมง (00:00–23:00) จากตาราง bookings (ตรงกับ DB ของคุณ)
//
// Params:
//   from=YYYY-MM-DD (required)
//   to=YYYY-MM-DD   (required)
//   tz=Asia/Bangkok (optional, default Asia/Bangkok)  // ใช้เพื่อแสดงผล/ความสอดคล้อง แต่คิวรี่ใช้เวลาที่เก็บใน DB ตรง ๆ
//   level=ALL|1|2|3|4 (optional, default ALL)         // join parking_slots.level
//   status=ALL|PENDING|CONFIRMED|CANCELLED|EXPIRED (optional, default ALL)
//   mode=ALL|BOOKING|WALKIN (optional, default ALL)   // DB ตอนนี้ไม่มีคอลัมน์ mode -> จะ ignore แบบปลอดภัย
//
// Output:
//   { ok:true, labels:["00","01",...,"23"], counts:[...], meta:{...} }

declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function json_out(array $arr, int $code = 200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function get_param(string $k, ?string $default = null): ?string {
  if (!isset($_GET[$k])) return $default;
  $v = trim((string)$_GET[$k]);
  return $v === '' ? $default : $v;
}

function norm_date(?string $s): ?string {
  if (!$s) return null;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
  return $s;
}

function safe_tz(?string $tz): string {
  $tz = trim((string)$tz);
  if ($tz === '') return 'Asia/Bangkok';
  try {
    new DateTimeZone($tz);
    return $tz;
  } catch (Throwable $e) {
    return 'Asia/Bangkok';
  }
}

function clamp_level(?string $lv): string {
  $lv = trim((string)$lv);
  if ($lv === '1' || $lv === '2' || $lv === '3' || $lv === '4') return $lv;
  return 'ALL';
}

function clamp_status(?string $st): string {
  $st = strtoupper(trim((string)$st));
  $allow = ['ALL','PENDING','CONFIRMED','CANCELLED','EXPIRED'];
  return in_array($st, $allow, true) ? $st : 'ALL';
}

function clamp_mode(?string $m): string {
  $m = strtoupper(trim((string)$m));
  if ($m === 'BOOKING') return 'BOOKING';
  if ($m === 'WALKIN' || $m === 'WALK-IN') return 'WALKIN';
  return 'ALL';
}

function normalize_status(string $st): string {
  $k = strtoupper(trim($st));
  if ($k === 'CANCELED') $k = 'CANCELLED';
  if (in_array($k, ['SUCCESS','COMPLETED','DONE','PAID'], true)) $k = 'CONFIRMED';
  return $k;
}

// ===== Params =====
$from   = norm_date(get_param('from'));
$to     = norm_date(get_param('to'));
$tz     = safe_tz(get_param('tz', 'Asia/Bangkok'));
$level  = clamp_level(get_param('level', 'ALL'));
$status = clamp_status(get_param('status', 'ALL'));
$mode   = clamp_mode(get_param('mode', 'ALL'));

if (!$from || !$to) json_out(['ok'=>false,'error'=>'missing or invalid from/to (YYYY-MM-DD)'], 400);
if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }

// ===== Build query =====
$where = [];
$bind  = [];

$where[] = "b.start_time IS NOT NULL";
$where[] = "DATE(b.start_time) BETWEEN :from AND :to";
$bind[':from'] = $from;
$bind[':to']   = $to;

$join = "";

// filter by level via parking_slots
if ($level !== 'ALL') {
  $join = "LEFT JOIN parking_slots ps ON ps.id = b.slot_id";
  $where[] = "ps.level = :level";
  $bind[':level'] = (int)$level;
}

// filter by status if requested
if ($status !== 'ALL') {
  // เราจะยอมให้ status ฝั่ง DB เป็น SUCCESS/PAID ฯลฯ แล้ว normalize -> CONFIRMED
  // แต่ถ้าผู้ใช้เลือก CONFIRMED เราจะ match ให้ครอบคลุมด้วย
  if ($status === 'CONFIRMED') {
    $where[] = "UPPER(b.status) IN ('CONFIRMED','SUCCESS','COMPLETED','DONE','PAID')";
  } else {
    $where[] = "UPPER(b.status) = :status";
    $bind[':status'] = $status;
  }
}

// mode filter (DB ไม่มีคอลัมน์ mode -> ignore ปลอดภัย)
$mode_applied = false;

// ===== Prepare empty buckets 0..23 =====
$labels = [];
$counts = [];
for ($h=0; $h<24; $h++){
  $labels[] = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
  $counts[] = 0;
}

try {
  // Group by hour(start_time)
  $sql = "
    SELECT HOUR(b.start_time) AS hr, COUNT(*) AS c
    FROM bookings b
    $join
    WHERE ".implode(" AND ", $where)."
    GROUP BY HOUR(b.start_time)
    ORDER BY hr ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute($bind);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $r){
    $hr = isset($r['hr']) ? (int)$r['hr'] : null;
    $c  = isset($r['c'])  ? (int)$r['c']  : 0;
    if ($hr !== null && $hr >= 0 && $hr <= 23){
      $counts[$hr] = $c;
    }
  }

  // Total
  $total = array_sum($counts);

  json_out([
    'ok' => true,
    'labels' => $labels,
    'counts' => $counts,
    'total'  => $total,
    'meta' => [
      'from' => $from,
      'to'   => $to,
      'tz'   => $tz,
      'level'=> $level,
      'status'=> $status,
      'mode'=> $mode,
      'mode_applied' => $mode_applied
    ]
  ]);

} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>'server error: '.$e->getMessage()], 500);
}
