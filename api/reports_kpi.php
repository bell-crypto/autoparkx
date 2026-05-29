<?php
// api/reports_kpi.php
// KPI สำหรับ Admin Reports (ตรงกับ DB ของคุณ)
// tables:
//  - bookings(id,user_id,slot_id,start_time,end_time,amount,status,created_at,updated_at,...)
//  - parking_slots(id,code,status,level,last_update)
//
// Query:
//  from=YYYY-MM-DD
//  to=YYYY-MM-DD
//  tz=Asia/Bangkok (รับไว้เพื่อแสดงผล แต่การคิวรี่ใช้เวลาที่เก็บใน DB ตรง ๆ)
//  level=1|2|ALL (optional)
//  mode=ALL|BOOKING|WALKIN (optional)  // DB ตอนนี้ไม่มีคอลัมน์ mode จึงจะ ignore แบบปลอดภัย

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

function clamp_mode(?string $m): string {
  $m = strtoupper(trim((string)$m));
  if ($m === 'BOOKING') return 'BOOKING';
  if ($m === 'WALKIN' || $m === 'WALK-IN') return 'WALKIN';
  return 'ALL';
}

// ===== Params =====
$from  = norm_date(get_param('from'));
$to    = norm_date(get_param('to'));
$tz    = safe_tz(get_param('tz', 'Asia/Bangkok'));
$level = clamp_level(get_param('level', 'ALL'));
$mode  = clamp_mode(get_param('mode', 'ALL'));

if (!$from || !$to) json_out(['ok'=>false,'error'=>'missing or invalid from/to (YYYY-MM-DD)'], 400);
if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }

// ===== Filters =====
$where = [];
$bind  = [];

$where[] = "DATE(b.created_at) BETWEEN :from AND :to";
$bind[':from'] = $from;
$bind[':to']   = $to;

// join level (ถ้าระบุ)
$join = "";
if ($level !== 'ALL') {
  $join = "LEFT JOIN parking_slots ps ON ps.id = b.slot_id";
  $where[] = "ps.level = :level";
  $bind[':level'] = (int)$level;
}

// mode: DB ยังไม่มีคอลัมน์ mode -> ไม่กรอง (แต่ส่งกลับ params ให้ UI รู้)
$mode_applied = false;

// ===== Query KPI =====
try {
  // 1) Count by status
  $sql = "SELECT UPPER(b.status) AS st, COUNT(*) AS c
          FROM bookings b
          $join
          WHERE " . implode(" AND ", $where) . "
          GROUP BY UPPER(b.status)";
  $st = $pdo->prepare($sql);
  $st->execute($bind);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $total = 0;
  $pending = 0; $confirmed = 0; $cancelled = 0; $expired = 0;

  foreach ($rows as $r) {
    $k = strtoupper((string)$r['st']);
    $c = (int)$r['c'];
    $total += $c;

    if ($k === 'CANCELED') $k = 'CANCELLED';
    if (in_array($k, ['SUCCESS','COMPLETED','DONE','PAID'], true)) $k = 'CONFIRMED';

    if ($k === 'PENDING') $pending += $c;
    else if ($k === 'CONFIRMED') $confirmed += $c;
    else if ($k === 'CANCELLED') $cancelled += $c;
    else if ($k === 'EXPIRED') $expired += $c;
  }

  // 2) Utilization + Avg duration (ใช้เฉพาะ CONFIRMED ที่มี start/end)
  $utilPct = null;
  $avgMin  = null;

  $whereU = $where;
  $whereU[] = "b.start_time IS NOT NULL AND b.end_time IS NOT NULL AND b.end_time >= b.start_time";
  $whereU[] = "UPPER(b.status) IN ('CONFIRMED','SUCCESS','COMPLETED','DONE','PAID')";

  $sqlU = "SELECT
            SUM(TIMESTAMPDIFF(MINUTE, b.start_time, b.end_time)) AS sum_min,
            AVG(TIMESTAMPDIFF(MINUTE, b.start_time, b.end_time)) AS avg_min
          FROM bookings b
          $join
          WHERE " . implode(" AND ", $whereU);
  $stU = $pdo->prepare($sqlU);
  $stU->execute($bind);
  $u = $stU->fetch(PDO::FETCH_ASSOC) ?: [];

  $sumMin = isset($u['sum_min']) ? (float)$u['sum_min'] : 0.0;
  $avgMin = isset($u['avg_min']) ? (float)$u['avg_min'] : null;

  // capacity minutes
  $days = (new DateTime($from))->diff(new DateTime($to))->days + 1;

  $slotsWhere = [];
  $slotsBind = [];
  if ($level !== 'ALL') {
    $slotsWhere[] = "level = :lv";
    $slotsBind[':lv'] = (int)$level;
  }
  $sqlS = "SELECT COUNT(*) FROM parking_slots" . (count($slotsWhere) ? " WHERE " . implode(" AND ", $slotsWhere) : "");
  $stS = $pdo->prepare($sqlS);
  $stS->execute($slotsBind);
  $slotsCount = (int)$stS->fetchColumn();

  if ($slotsCount > 0 && $days > 0) {
    $capacityMin = $slotsCount * $days * 1440;
    $utilPct = $capacityMin > 0 ? ($sumMin / $capacityMin) * 100.0 : null;
    if ($utilPct !== null) $utilPct = max(0.0, min(100.0, $utilPct));
  }

  // rates
  $rates = [
    'confirmed_pct' => $total ? ($confirmed / $total * 100.0) : 0.0,
    'pending_pct'   => $total ? ($pending   / $total * 100.0) : 0.0,
    'cancelled_pct' => $total ? ($cancelled / $total * 100.0) : 0.0,
    'expired_pct'   => $total ? ($expired   / $total * 100.0) : 0.0,
    'cancel_rate'   => $total ? ($cancelled / $total * 100.0) : 0.0,
  ];

  json_out([
    'ok' => true,
    'params' => [
      'from'=>$from,'to'=>$to,'tz'=>$tz,'level'=>$level,'mode'=>$mode,
      'mode_applied'=>$mode_applied
    ],
    'totals' => [
      'total'=>$total,
      'PENDING'=>$pending,
      'CONFIRMED'=>$confirmed,
      'CANCELLED'=>$cancelled,
      'EXPIRED'=>$expired,
    ],
    'rates' => $rates,
    'utilization' => [
      'slots_count' => $slotsCount,
      'days' => $days,
      'utilization_pct' => $utilPct === null ? null : round($utilPct, 2),
      'avg_duration_min'=> $avgMin === null ? null : (int)round($avgMin),
    ],
  ]);

} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>'server error: '.$e->getMessage()], 500);
}
