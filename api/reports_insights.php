<?php
// api/reports_insights.php
// Insight / แจ้งเตือนความผิดปกติ (อิง DB ของคุณ: bookings + parking_slots + wallet_history)
//
// เป้าหมาย: ให้หน้า admin_reports.html “มี Insight ใช้งานจริง”
// - ตรวจช่วงเวลาตามตัวกรอง (from/to)
// - กรอง level/status ได้ (เผื่อหน้าเรียก)
// - ให้ผลลัพธ์เป็นรายการการ์ด insight พร้อมระดับความสำคัญ
//
// Params:
//   from=YYYY-MM-DD (required)
//   to=YYYY-MM-DD   (required)
//   tz=Asia/Bangkok (optional)
//   level=ALL|1|2|3|4 (optional)
//   status=ALL|PENDING|CONFIRMED|CANCELLED|EXPIRED (optional)
//   mode=ALL|BOOKING|WALKIN (optional)   // DB ตอนนี้ไม่มีคอลัมน์ mode -> ignore
//
// Output:
//   { ok:true, insights:[ {key, severity, title, detail, value, hint, data? }... ], meta:{} }

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
function clamp_mode(?string $m): string {
  $m = strtoupper(trim((string)$m));
  if ($m === 'BOOKING') return 'BOOKING';
  if ($m === 'WALKIN' || $m === 'WALK-IN') return 'WALKIN';
  return 'ALL';
}

function norm_status(string $st): string {
  $k = strtoupper(trim($st));
  if ($k === 'CANCELED') $k = 'CANCELLED';
  if (in_array($k, ['SUCCESS','COMPLETED','DONE','PAID'], true)) $k = 'CONFIRMED';
  return $k;
}

$from   = norm_date(get_param('from'));
$to     = norm_date(get_param('to'));
$tz     = safe_tz(get_param('tz','Asia/Bangkok'));
$level  = clamp_level(get_param('level','ALL'));
$status = clamp_status(get_param('status','ALL'));
$mode   = clamp_mode(get_param('mode','ALL'));

if (!$from || !$to) json_out(['ok'=>false,'error'=>'missing or invalid from/to (YYYY-MM-DD)'], 400);
if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }

$insights = [];
$meta = [
  'from'=>$from,'to'=>$to,'tz'=>$tz,'level'=>$level,'status'=>$status,'mode'=>$mode,
  'mode_applied'=>false
];

try{
  // ---- Base booking filter ----
  $whereB = [];
  $bindB  = [':from'=>$from,':to'=>$to];

  $whereB[] = "b.created_at IS NOT NULL";
  $whereB[] = "DATE(b.created_at) BETWEEN :from AND :to";

  $join = "";
  if ($level !== 'ALL') {
    $join = "LEFT JOIN parking_slots ps ON ps.id = b.slot_id";
    $whereB[] = "ps.level = :level";
    $bindB[':level'] = (int)$level;
  }

  if ($status !== 'ALL') {
    if ($status === 'CONFIRMED') {
      $whereB[] = "UPPER(b.status) IN ('CONFIRMED','SUCCESS','COMPLETED','DONE','PAID')";
    } else {
      $whereB[] = "UPPER(b.status) = :status";
      $bindB[':status'] = $status;
    }
  }

  // ---- (1) Cancel rate high? ----
  $sql1 = "
    SELECT
      SUM(CASE WHEN UPPER(b.status)='CANCELLED' OR UPPER(b.status)='CANCELED' THEN 1 ELSE 0 END) AS cancelled,
      COUNT(*) AS total
    FROM bookings b
    $join
    WHERE ".implode(" AND ", $whereB)."
  ";
  $st = $pdo->prepare($sql1);
  $st->execute($bindB);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['cancelled'=>0,'total'=>0];
  $total = (int)($r['total'] ?? 0);
  $cancelled = (int)($r['cancelled'] ?? 0);
  $cancelRate = $total > 0 ? ($cancelled / $total) * 100.0 : 0.0;

  if ($total >= 10 && $cancelRate >= 25.0){
    $insights[] = [
      'key'=>'cancel_rate_high',
      'severity'=> $cancelRate >= 40 ? 'high' : 'medium',
      'title'=>'อัตรายกเลิกสูงผิดปกติ',
      'detail'=>"ช่วงที่เลือกมีการยกเลิก $cancelled จากทั้งหมด $total รายการ",
      'value'=> round($cancelRate,2),
      'hint'=>'ลองดูสาเหตุ: ราคาหรือเงื่อนไขคืนเงิน / UX การจอง / ช่วงเวลาที่คนจองผิด'
    ];
  } else {
    $insights[] = [
      'key'=>'cancel_rate',
      'severity'=>'info',
      'title'=>'อัตรายกเลิก',
      'detail'=>"ยกเลิก $cancelled จาก $total รายการ",
      'value'=> round($cancelRate,2),
      'hint'=>'เป็นตัวชี้วัดความสับสน/คุณภาพการจอง'
    ];
  }

  // ---- (2) Expired suspicious? ----
  $sql2 = "
    SELECT
      SUM(CASE WHEN UPPER(b.status)='EXPIRED' THEN 1 ELSE 0 END) AS expired,
      COUNT(*) AS total
    FROM bookings b
    $join
    WHERE ".implode(" AND ", $whereB)."
  ";
  $st = $pdo->prepare($sql2);
  $st->execute($bindB);
  $r2 = $st->fetch(PDO::FETCH_ASSOC) ?: ['expired'=>0,'total'=>0];
  $expired = (int)($r2['expired'] ?? 0);
  $total2  = (int)($r2['total'] ?? 0);
  $expRate = $total2 ? ($expired / $total2) * 100.0 : 0.0;

  if ($total2 >= 10 && $expRate >= 20.0){
    $insights[] = [
      'key'=>'expired_high',
      'severity'=> $expRate >= 35 ? 'high' : 'medium',
      'title'=>'บิลหมดอายุเยอะ',
      'detail'=>"EXPIRED $expired จาก $total2 รายการ",
      'value'=> round($expRate,2),
      'hint'=>'เช็ก logic หมดอายุ/cron/เวลาระบบ และการแจ้งเตือนก่อนหมดเวลา'
    ];
  } else if ($expired > 0) {
    $insights[] = [
      'key'=>'expired',
      'severity'=>'info',
      'title'=>'บิลหมดอายุ',
      'detail'=>"EXPIRED $expired รายการ",
      'value'=> round($expRate,2),
      'hint'=>'ถ้าเริ่มสูงขึ้น อาจมีปัญหา UX หรือระบบเวลา'
    ];
  }

  // ---- (3) Slots in MAINTENANCE too long (parking_slots.last_update) ----
  // เงื่อนไข: status=MAINTENANCE และ last_update เก่ามาก (เช่น > 24 ชม.)
  $sql3 = "
    SELECT
      COUNT(*) AS n,
      MIN(last_update) AS oldest,
      MAX(last_update) AS newest
    FROM parking_slots
    WHERE UPPER(status)='MAINTENANCE'
  ";
  $st = $pdo->query($sql3);
  $m = $st->fetch(PDO::FETCH_ASSOC) ?: ['n'=>0,'oldest'=>null,'newest'=>null];
  $mN = (int)($m['n'] ?? 0);

  if ($mN > 0 && !empty($m['oldest'])) {
    $oldest = new DateTime((string)$m['oldest']);
    $now = new DateTime('now');
    $hours = (int)floor(($now->getTimestamp() - $oldest->getTimestamp()) / 3600);

    $insights[] = [
      'key'=>'maintenance_slots',
      'severity'=> $hours >= 72 ? 'high' : ($hours >= 24 ? 'medium' : 'info'),
      'title'=>'มีช่องปิดปรับปรุง (MAINTENANCE)',
      'detail'=>"มี $mN ช่อง อยู่ใน MAINTENANCE · เก่าสุดประมาณ $hours ชม.",
      'value'=>$mN,
      'hint'=>'ถ้าค้างนาน ลองตรวจ ESP32/การอัปเดตสถานะ หรือเปิดใช้งานกลับ'
    ];
  } else {
    $insights[] = [
      'key'=>'maintenance_ok',
      'severity'=>'info',
      'title'=>'สถานะ MAINTENANCE',
      'detail'=>'ตอนนี้ไม่มีช่องที่อยู่ใน MAINTENANCE',
      'value'=>0,
      'hint'=>'ปกติ'
    ];
  }

  // ---- (4) Wallet anomalies (wallet_history): net too high vs topup/refund? ----
  // สรุปพื้นฐานเพื่อโชว์เป็น insight
  $sqlW = "
    SELECT
      COALESCE(SUM(CASE WHEN type='topup' THEN amount ELSE 0 END),0) AS income,
      COALESCE(SUM(CASE WHEN type='refund' THEN amount ELSE 0 END),0) AS refund,
      COALESCE(SUM(amount),0) AS net,
      COUNT(*) AS tx
    FROM wallet_history
    WHERE DATE(created_at) BETWEEN :from AND :to
  ";
  $stw = $pdo->prepare($sqlW);
  $stw->execute([':from'=>$from,':to'=>$to]);
  $w = $stw->fetch(PDO::FETCH_ASSOC) ?: ['income'=>0,'refund'=>0,'net'=>0,'tx'=>0];

  $income = (float)$w['income'];
  $refund = (float)$w['refund'];
  $net    = (float)$w['net'];
  $tx     = (int)$w['tx'];

  // ถ้า net บวกมหาศาลทั้งที่ income ไม่เยอะ -> ส่อว่า data amount ผิดสเกล
  if ($tx > 0 && abs($net) > 0 && abs($net) > max(1000.0, ($income * 5.0 + 1000.0))) {
    $insights[] = [
      'key'=>'wallet_net_suspicious',
      'severity'=>'high',
      'title'=>'สุทธิ Wallet ผิดสังเกต',
      'detail'=>"ช่วงที่เลือก Net = ".number_format($net,2)." (tx: $tx, topup: ".number_format($income,2).")",
      'value'=>round($net,2),
      'hint'=>'เช็กว่ามีบันทึก amount ผิดหน่วย/ซ้ำ/หรือมีการ import ข้อมูลผิด'
    ];
  } else {
    $insights[] = [
      'key'=>'wallet_summary',
      'severity'=>'info',
      'title'=>'ภาพรวม Wallet (ช่วงที่เลือก)',
      'detail'=>"Topup ".number_format($income,2)." · Refund ".number_format($refund,2)." · Net ".number_format($net,2)." · Tx $tx",
      'value'=>round($net,2),
      'hint'=>'เป็นสรุปเบื้องต้นสำหรับตรวจสุขภาพระบบ'
    ];
  }

  json_out(['ok'=>true,'insights'=>$insights,'meta'=>$meta]);

} catch (Throwable $e){
  json_out(['ok'=>false,'error'=>'server error: '.$e->getMessage()], 500);
}
