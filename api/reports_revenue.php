<?php
// api/reports_revenue.php
// รายงานการเงิน (ยึด wallet_history เป็นหลัก)

declare(strict_types=1);
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function json_out($arr, $code = 200){
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

$from = $_GET['from'] ?? null;
$to   = $_GET['to']   ?? null;

if(!$from || !$to){
  json_out(['ok'=>false,'error'=>'missing from/to'],400);
}

try{
  // รายรับ (topup)
  $sqlIncome = "
    SELECT COALESCE(SUM(amount),0)
    FROM wallet_history
    WHERE type='topup'
      AND DATE(created_at) BETWEEN :from AND :to
  ";
  $st = $pdo->prepare($sqlIncome);
  $st->execute([':from'=>$from,':to'=>$to]);
  $income = (float)$st->fetchColumn();

  // คืนเงิน
  $sqlRefund = "
    SELECT COALESCE(SUM(amount),0)
    FROM wallet_history
    WHERE type='refund'
      AND DATE(created_at) BETWEEN :from AND :to
  ";
  $st = $pdo->prepare($sqlRefund);
  $st->execute([':from'=>$from,':to'=>$to]);
  $refund = (float)$st->fetchColumn();

  // เงินออก (จ่ายค่าจอง)
  $sqlSpend = "
    SELECT COALESCE(SUM(amount),0)
    FROM wallet_history
    WHERE amount < 0
      AND DATE(created_at) BETWEEN :from AND :to
  ";
  $st = $pdo->prepare($sqlSpend);
  $st->execute([':from'=>$from,':to'=>$to]);
  $spent = (float)$st->fetchColumn();

  // สุทธิ
  $sqlNet = "
    SELECT COALESCE(SUM(amount),0)
    FROM wallet_history
    WHERE DATE(created_at) BETWEEN :from AND :to
  ";
  $st = $pdo->prepare($sqlNet);
  $st->execute([':from'=>$from,':to'=>$to]);
  $net = (float)$st->fetchColumn();

  // จำนวนธุรกรรม
  $sqlCnt = "
    SELECT COUNT(*)
    FROM wallet_history
    WHERE DATE(created_at) BETWEEN :from AND :to
  ";
  $st = $pdo->prepare($sqlCnt);
  $st->execute([':from'=>$from,':to'=>$to]);
  $tx = (int)$st->fetchColumn();

  json_out([
    'ok'=>true,
    'from'=>$from,
    'to'=>$to,
    'income'=>round($income,2),
    'refund'=>round($refund,2),
    'spent'=>round($spent,2),
    'net'=>round($net,2),
    'transactions'=>$tx
  ]);

}catch(Throwable $e){
  json_out(['ok'=>false,'error'=>$e->getMessage()],500);
}
