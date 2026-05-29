<?php
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!function_exists('json_ok')) {
  function json_ok($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
}
if (!function_exists('json_error')) {
  function json_error($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }
}

function read_input(): array {
  $raw = file_get_contents('php://input');
  $j = [];
  if ($raw) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $j = $tmp;
  }
  // merge with form post
  if (!empty($_POST)) $j = array_merge($j, $_POST);
  return $j;
}

$in = read_input();

// ===== allow statuses (ต้องตรงกับ DB) =====
$allowed = ['AVAILABLE','HELD','BOOKED','OCCUPIED','MAINTENANCE'];

// รองรับ 2 แบบ
// 1) single: {code:"A03", status:"MAINTENANCE"} หรือ {slot_id:3, status:"OCCUPIED"}
// 2) bulk:   {items:[{code:"A03",status:"..."}, {...}]}

$status = strtoupper(trim((string)($in['status'] ?? '')));
$items  = $in['items'] ?? null;

try {
  if ($items && is_array($items)) {
    // ===== BULK =====
    $pdo->beginTransaction();

    $stmtByCode = $pdo->prepare("UPDATE parking_slots SET status=?, last_update=NOW() WHERE code=?");
    $stmtById   = $pdo->prepare("UPDATE parking_slots SET status=?, last_update=NOW() WHERE id=?");
    $sel = $pdo->prepare("SELECT id, code, level, status, last_update FROM parking_slots WHERE code=? LIMIT 1");

    $updated = [];
    foreach ($items as $it) {
      if (!is_array($it)) continue;

      $st = strtoupper(trim((string)($it['status'] ?? '')));
      if (!in_array($st, $allowed, true)) {
        $pdo->rollBack();
        json_error("invalid status in items: $st", 422);
      }

      $code = isset($it['code']) ? strtoupper(trim((string)$it['code'])) : '';
      $id   = isset($it['slot_id']) ? (int)$it['slot_id'] : 0;

      if ($code !== '') {
        $stmtByCode->execute([$st, $code]);
        $sel->execute([$code]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if ($row) $updated[] = $row;
      } elseif ($id > 0) {
        $stmtById->execute([$st, $id]);
        // ดึงกลับด้วย id
        $row = $pdo->query("SELECT id, code, level, status, last_update FROM parking_slots WHERE id=".(int)$id." LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) $updated[] = $row;
      } else {
        $pdo->rollBack();
        json_error("each item must have code or slot_id", 422);
      }
    }

    $pdo->commit();
    json_ok(['ok'=>true, 'updated'=>$updated]);
  }

  // ===== SINGLE =====
  if (!in_array($status, $allowed, true)) json_error('invalid status', 422);

  $code = isset($in['code']) ? strtoupper(trim((string)$in['code'])) : '';
  $id   = isset($in['slot_id']) ? (int)$in['slot_id'] : 0;

  if ($code === '' && $id <= 0) json_error('code or slot_id is required', 422);

  if ($code !== '') {
    $u = $pdo->prepare("UPDATE parking_slots SET status=?, last_update=NOW() WHERE code=?");
    $u->execute([$status, $code]);

    $row = $pdo->prepare("SELECT id, code, level, status, last_update FROM parking_slots WHERE code=? LIMIT 1");
    $row->execute([$code]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    json_ok(['ok'=>true, 'slot'=>$r]);
  } else {
    $u = $pdo->prepare("UPDATE parking_slots SET status=?, last_update=NOW() WHERE id=?");
    $u->execute([$status, $id]);

    $r = $pdo->query("SELECT id, code, level, status, last_update FROM parking_slots WHERE id=".(int)$id." LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    json_ok(['ok'=>true, 'slot'=>$r]);
  }

} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  json_error($e->getMessage(), 500);
}
