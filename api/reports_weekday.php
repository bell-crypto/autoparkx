<?php
// api/reports_weekday.php
// กราฟตามวันในสัปดาห์ (Mon–Sun) จากตาราง bookings (ตรงกับ DB ของคุณ)
// - นับจาก start_time (เวลาเริ่มใช้งาน/เริ่มจอง) เพื่อให้ “ตรง” กับการใช้งานจริง
//
// Params:
//   from=YYYY-MM-DD (required)
//   to=YYYY-MM-DD   (required)
//   tz=Asia/Bangkok (optional; ไว้แสดงผล/ความสอดคล้อง)
//   level=ALL|1|2|3|4 (optional; join parking_slots.level)
//   status=ALL|PENDING|CONFIRMED|CANCELLED|EXPIRED (optional)
//
// Output:
//   { ok:true, labels:["Mon","Tue","Wed","Thu","Fri","Sat","Sun"], counts:[...], total:n, meta:{...} }

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
function get_param(string $k, ?string $default=null): ?string {
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
  try { new DateTimeZone($tz); return $tz; } catch (Throwable $e) { return 'Asia/Bangkok'; }
}
function clamp_level(?string $lv): string {
  $lv = trim((string)$lv);
  if ($lv === '1' || $lv === '2' || $lv === '3' || $lv === '4') return $lv;
  return 'ALL';
}
function clamp_status(?string $st): string {
  $st = strtoupper(trim((string)$st));
  $allow = ['ALL','PENDING','CONFIRMED','CANCELLED','EXPIRED'];
  return in_array($st,$allow,true) ? $st : 'ALL';
}

// ===== Params =====
$from   = norm_date(get_param('from'));
$to     = norm_date(get_param('to'));
$tz     = safe_tz(get_param('tz','Asia/Bangkok'));
$level  = clamp_level(get_param('level','ALL'));
$status = clamp_status(get_param('status','ALL'));

if (!$from || !$to) json_out(['ok'=>false,'error'=>'missing or invalid from/to (YYYY-MM-DD)'], 400);
if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }

// ===== Filters =====
$where = [];
$bind  = [];

$where[] = "b.start_time IS NOT NULL";
$where[] = "DATE(b.start_time) BETWEEN :from AND :to";
$bind[':from'] = $from;
$bind[':to']   = $to;

$join = "";
if ($level !== 'ALL') {
  $join = "LEFT JOIN parking_slots ps ON ps.id = b.slot_id";
  $where[] = "ps.level = :level";
  $bind[':level'] = (int)$level;
}

if ($status !== 'ALL') {
  if ($status === 'CONFIRMED') {
    $where[] = "UPPER(b.status) IN ('CONFIRMED','SUCCESS','COMPLETED','DONE','PAID')";
  } else {
    $where[] = "UPPER(b.status) = :status";
    $bind[':status'] = $status;
  }
}

// Buckets Mon..Sun
$labels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$counts = [0,0,0,0,0,0,0];

try {
  // MySQL: DAYOFWEEK() => 1=Sun ... 7=Sat
  // We map to Mon..Sun index: Mon=0..Sun=6
  $sql = "
    SELECT DAYOFWEEK(b.start_time) AS dow, COUNT(*) AS c
    FROM bookings b
    $join
    WHERE ".implode(" AND ", $where)."
    GROUP BY DAYOFWEEK(b.start_time)
    ORDER BY dow ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute($bind);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $r){
    $dow = isset($r['dow']) ? (int)$r['dow'] : 0; // 1..7
    $c   = isset($r['c']) ? (int)$r['c'] : 0;

    // Convert:
    // Sun(1)->index 6, Mon(2)->0, Tue(3)->1, ... Sat(7)->5
    if ($dow >= 1 && $dow <= 7){
      $idx = ($dow === 1) ? 6 : ($dow - 2);
      if ($idx >=0 && $idx <=6) $counts[$idx] = $c;
    }
  }

  json_out([
    'ok'=>true,
    'labels'=>$labels,
    'counts'=>$counts,
    'total'=>array_sum($counts),
    'meta'=>[
      'from'=>$from,'to'=>$to,'tz'=>$tz,'level'=>$level,'status'=>$status,
      'basis'=>'start_time'
    ]
  ]);

} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>'server error: '.$e->getMessage()], 500);
}
