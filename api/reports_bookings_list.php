<?php
// api/reports_bookings_list.php
// ตารางรายการ (Drill-down) สำหรับหน้า Admin Reports (ตรงกับ DB ของคุณ)
//
// ดึงรายการจาก bookings + users + parking_slots + (ตัวช่วยสรุป wallet_history ต่อแถวแบบเบา ๆ)
// - รองรับ pagination + filter
//
// Params:
//   from=YYYY-MM-DD (required)
//   to=YYYY-MM-DD   (required)
//   tz=Asia/Bangkok (optional)
//   q=... (optional search: email/name/slot code)
//   level=ALL|1|2|3|4 (optional)
//   status=ALL|PENDING|CONFIRMED|CANCELLED|EXPIRED (optional)
//   type=ALL|BOOKING|WALKIN (optional)   // DB bookings ไม่มีคอลัมน์ type -> ignore
//   page=1 (optional; default 1)
//   per_page=10 (optional; default 10; max 50)
//   sort=created_desc|created_asc|start_desc|start_asc|amount_desc|amount_asc (optional)
//
// Output:
//   { ok:true, page, per_page, total, items:[...], meta:{} }

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
function clamp_sort(?string $s): string {
  $s = strtolower(trim((string)$s));
  $allow = [
    'created_desc','created_asc',
    'start_desc','start_asc',
    'amount_desc','amount_asc'
  ];
  return in_array($s,$allow,true) ? $s : 'created_desc';
}

function sort_sql(string $sort): string {
  return match($sort){
    'created_asc' => 'b.created_at ASC',
    'start_desc'  => 'b.start_time DESC',
    'start_asc'   => 'b.start_time ASC',
    'amount_desc' => 'b.amount DESC',
    'amount_asc'  => 'b.amount ASC',
    default       => 'b.created_at DESC',
  };
}

// ===== Params =====
$from   = norm_date(get_param('from'));
$to     = norm_date(get_param('to'));
$tz     = safe_tz(get_param('tz','Asia/Bangkok'));
$q      = get_param('q', '');
$level  = clamp_level(get_param('level','ALL'));
$status = clamp_status(get_param('status','ALL'));
$type   = strtoupper(trim((string)get_param('type','ALL'))); // ignore
$page   = clamp_int(get_param('page','1'), 1, 1, 100000);
$per    = clamp_int(get_param('per_page','10'), 10, 1, 50);
$sort   = clamp_sort(get_param('sort','created_desc'));

if (!$from || !$to) json_out(['ok'=>false,'error'=>'missing or invalid from/to (YYYY-MM-DD)'], 400);
if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }

// ===== Filters =====
$where = [];
$bind  = [];

$where[] = "DATE(b.created_at) BETWEEN :from AND :to";
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

if ($q !== '') {
  // ค้นจาก user email/name และ slot code
  $where[] = "(u.email LIKE :q OR u.name LIKE :q OR ps.code LIKE :q)";
  $bind[':q'] = '%'.$q.'%';
}

$offset = ($page - 1) * $per;

try{
  // Total count
  $sqlTotal = "
    SELECT COUNT(*)
    FROM bookings b
    LEFT JOIN users u ON u.id = b.user_id
    LEFT JOIN parking_slots ps ON ps.id = b.slot_id
    WHERE ".implode(" AND ", $where)."
  ";
  $st = $pdo->prepare($sqlTotal);
  $st->execute($bind);
  $total = (int)$st->fetchColumn();

  // Data rows
  $orderBy = sort_sql($sort);

  $sql = "
    SELECT
      b.id,
      b.user_id,
      u.name AS user_name,
      u.email AS user_email,
      b.slot_id,
      ps.code AS slot_code,
      ps.level AS slot_level,
      b.start_time,
      b.end_time,
      b.amount,
      b.status,
      b.created_at,
      b.updated_at
    FROM bookings b
    LEFT JOIN users u ON u.id = b.user_id
    LEFT JOIN parking_slots ps ON ps.id = b.slot_id
    WHERE ".implode(" AND ", $where)."
    ORDER BY $orderBy
    LIMIT :lim OFFSET :off
  ";
  $st2 = $pdo->prepare($sql);

  // bind all + limit/offset
  foreach ($bind as $k=>$v) $st2->bindValue($k, $v);
  $st2->bindValue(':lim', $per, PDO::PARAM_INT);
  $st2->bindValue(':off', $offset, PDO::PARAM_INT);

  $st2->execute();
  $rows = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Optional: คำนวณ duration นาที + สรุปการเคลื่อนไหว wallet ที่อ้าง ref BOOKING-API / CANCEL-REFUND ต่อ booking (ถ้ามี)
  // แต่ใน wallet_history ไม่มี booking_id จึงทำได้แค่ “เดา” จากเวลาใกล้เคียง ซึ่งไม่แน่นอน -> ไม่ทำให้มั่ว
  $items = [];
  foreach ($rows as $r){
    $durMin = null;
    if (!empty($r['start_time']) && !empty($r['end_time'])) {
      $stt = strtotime((string)$r['start_time']);
      $edt = strtotime((string)$r['end_time']);
      if ($stt !== false && $edt !== false && $edt >= $stt) {
        $durMin = (int)round(($edt - $stt) / 60);
      }
    }

    $items[] = [
      'id' => (int)$r['id'],
      'time' => (string)$r['created_at'],
      'user' => [
        'id' => (int)$r['user_id'],
        'name' => (string)($r['user_name'] ?? ''),
        'email' => (string)($r['user_email'] ?? ''),
      ],
      'slot' => [
        'id' => (int)$r['slot_id'],
        'code' => (string)($r['slot_code'] ?? ''),
        'level'=> isset($r['slot_level']) ? (int)$r['slot_level'] : null,
      ],
      'type' => 'BOOKING', // DB ไม่มี type จริง จึงใส่เป็น label เฉย ๆ
      'status' => (string)$r['status'],
      'amount' => isset($r['amount']) ? (float)$r['amount'] : 0.0,
      'start_time' => (string)($r['start_time'] ?? ''),
      'end_time'   => (string)($r['end_time'] ?? ''),
      'duration_min' => $durMin,
      'created_at' => (string)$r['created_at'],
      'updated_at' => (string)($r['updated_at'] ?? ''),
    ];
  }

  json_out([
    'ok'=>true,
    'page'=>$page,
    'per_page'=>$per,
    'total'=>$total,
    'items'=>$items,
    'meta'=>[
      'from'=>$from,'to'=>$to,'tz'=>$tz,'q'=>$q,'level'=>$level,'status'=>$status,'type'=>$type,
      'sort'=>$sort
    ]
  ]);

}catch(Throwable $e){
  json_out(['ok'=>false,'error'=>'server error: '.$e->getMessage()], 500);
}
