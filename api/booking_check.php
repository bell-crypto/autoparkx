<?php
require __DIR__ . '/db.php';

function table_has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = "col:$table:$column";
  if (array_key_exists($key, $cache)) return $cache[$key];

  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ");
  $stmt->execute([$table, $column]);
  $cache[$key] = ((int)$stmt->fetchColumn() > 0);
  return $cache[$key];
}

function active_status_sql(string $alias, bool $hasEndedAt): string {
  $endedPart = $hasEndedAt ? " AND {$alias}.ended_at IS NULL" : "";
  return "UPPER(COALESCE({$alias}.status,'')) IN ('ACTIVE','CONFIRMED','BOOKED','PENDING','RESERVED','PARKING','OCCUPIED'){$endedPart}";
}

function overlap_end_expr(PDO $pdo, string $alias): string {
  $hasHoldUntil = table_has_column($pdo, 'bookings', 'hold_until');
  if ($hasHoldUntil) {
    return "COALESCE({$alias}.hold_until, {$alias}.end_time, DATE_ADD({$alias}.start_time, INTERVAL 15 MINUTE))";
  }
  return "COALESCE({$alias}.end_time, DATE_ADD({$alias}.start_time, INTERVAL 15 MINUTE))";
}

try {
  $in = json_input();
  if ($in === null) $in = $_POST ?: [];

  $user_id = (int)($in['user_id'] ?? 0);
  $slot_id = (int)($in['slot_id'] ?? 0);
  $s_raw   = (string)($in['start_time'] ?? '');

  if ($user_id <= 0 || $slot_id <= 0 || $s_raw === '') {
    json_error('missing fields', 422);
  }

  $sSQL = to_mysql_dt($s_raw);

  $qPast = $pdo->prepare("
    SELECT
      DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i') AS now_min,
      DATE_FORMAT(?,    '%Y-%m-%d %H:%i') AS start_min
  ");
  $qPast->execute([$sSQL]);
  $row = $qPast->fetch(PDO::FETCH_ASSOC);

  $nowMin   = (string)($row['now_min'] ?? '');
  $startMin = (string)($row['start_min'] ?? '');

  if ($startMin < $nowMin) {
    json_ok([
      'available'  => false,
      'reason'     => 'past_time',
      'server_now' => $nowMin
    ]);
  }

  $hasEndedAt = table_has_column($pdo, 'bookings', 'ended_at');
  $statusSql  = active_status_sql('b', $hasEndedAt);
  $endExpr    = overlap_end_expr($pdo, 'b');

  // ระบบนี้จองแบบเริ่มตอนนี้ + hold 15 นาที
  $newStart = $sSQL;
  $newEnd   = date('Y-m-d H:i:s', strtotime($sSQL . ' +15 minutes'));

  $q1 = $pdo->prepare("
    SELECT COUNT(*)
    FROM bookings b
    WHERE b.slot_id = :slot_id
      AND {$statusSql}
      AND b.start_time < :new_end
      AND {$endExpr} > :new_start
  ");
  $q1->execute([
    ':slot_id'   => $slot_id,
    ':new_start' => $newStart,
    ':new_end'   => $newEnd,
  ]);
  if ((int)$q1->fetchColumn() > 0) {
    json_ok(['available' => false, 'reason' => 'slot_overlap']);
  }

  $q2 = $pdo->prepare("
    SELECT COUNT(*)
    FROM bookings b
    WHERE b.user_id = :user_id
      AND {$statusSql}
      AND b.start_time < :new_end
      AND {$endExpr} > :new_start
  ");
  $q2->execute([
    ':user_id'   => $user_id,
    ':new_start' => $newStart,
    ':new_end'   => $newEnd,
  ]);
  if ((int)$q2->fetchColumn() > 0) {
    json_ok(['available' => false, 'reason' => 'user_overlap']);
  }

  json_ok([
    'available'  => true,
    'server_now' => $nowMin
  ]);

} catch (Throwable $e) {
  error_log('[AutoParkX] booking_check error: ' . $e->getMessage());
  json_error('server error', 500);
}