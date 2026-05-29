<?php
// api/reports_top_slots.php
// Top Slots / ความถี่การใช้งาน (จาก bookings + parking_slots) (ตรงกับ DB ของคุณ)
// - นับจาก start_time ภายในช่วงวันที่
// - จัดอันดับตามจำนวนครั้ง (COUNT)
// - ส่งกลับรายการ {code, level, count}
//
// Params:
//   from=YYYY-MM-DD (required)
//   to=YYYY-MM-DD   (required)
//   tz=Asia/Bangkok (optional)
//   level=ALL|1|2|3|4 (optional)
//   status=ALL|PENDING|CONFIRMED|CANCELLED|EXPIRED (optional)
//   limit=10 (optional; default 10; max 50)
//
// Output:
//   { ok:true, items:[{slot_id,code,level,count}], total:n, meta:{...} }

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
function clamp_int(?string $n, int $def, int $min, int $max): int {
  if ($n === null || $n === '') return $def;
  $v = (int)$n;
  if ($v < $min) $v = $min;
  if ($v > $max) $v = $max;
  return $v;
}

// ===== Params =====
$from   = norm_date(get_param('from'));
$to     = norm_date(get_param('to'));
$tz     = safe_tz(get_param('tz','Asia/Bangkok'));
$level  = clamp_level(get_param('level','ALL'));
$status = clamp_status(get_param('status','ALL'));
$limit  = clamp_int(get_param('limit','10'), 10, 1, 50);

if (!$from || !$to) json_out(['ok'=>false,'error'=>'missing or invalid from/to (YYYY-MM-DD)'], 400);
if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }

// ===== Filters =====
$where = [];
$bind  = [];

$where[] = "b.start_time IS NOT NULL";
$where[] = "DATE(b.start_time) BETWEEN :from AND :to";
$bind[':from'] = $from;
$bind[':to']   = $to;

if ($status !== 'ALL') {
  if ($status === 'CONFIRMED') {
    $where[] = "UPPER(b.status) IN ('CONFIRMED','SUCCESS','COMPLETED','DONE','PAID')";
  } else {
    $where[] = "UPPER(b.status) = :status";
    $bind[':status'] = $status;
  }
}

if ($level !== 'ALL') {
  $where[] = "ps.level = :level";
  $bind[':level'] = (int)$level;
}

try {
  // Group by slot_id, join to get code+level
  $sql = "
    SELECT
      b.slot_id AS slot_id,
      ps.code   AS code,
      ps.level  AS level,
      COUNT(*)  AS cnt
    FROM bookings b
    LEFT JOIN parking_slots ps ON ps.id = b.slot_id
    WHERE ".implode(" AND ", $where)."
    GROUP BY b.slot_id, ps.code, ps.level
    ORDER BY cnt DESC, ps.level ASC, ps.code ASC
    LIMIT $limit
  ";
  $st = $pdo->prepare($sql);
  $st->execute($bind);
  $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // total usage count for the range (all slots, for context)
  $sqlTotal = "
    SELECT COUNT(*) FROM bookings b
    LEFT JOIN parking_slots ps ON ps.id = b.slot_id
    WHERE ".implode(" AND ", $where)."
  ";
  $st2 = $pdo->prepare($sqlTotal);
  $st2->execute($bind);
  $total = (int)$st2->fetchColumn();

  // Normalize output
  $out = [];
  foreach ($items as $r){
    $out[] = [
      'slot_id' => isset($r['slot_id']) ? (int)$r['slot_id'] : null,
      'code'    => (string)($r['code'] ?? ''),
      'level'   => isset($r['level']) ? (int)$r['level'] : null,
      'count'   => isset($r['cnt']) ? (int)$r['cnt'] : 0,
    ];
  }

  json_out([
    'ok'=>true,
    'items'=>$out,
    'total'=>$total,
    'meta'=>[
      'from'=>$from,'to'=>$to,'tz'=>$tz,'level'=>$level,'status'=>$status,
      'limit'=>$limit,'basis'=>'start_time'
    ]
  ]);

} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>'server error: '.$e->getMessage()], 500);
}
