<?php
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
  function json_ok($data = []) {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
  }
}
if (!function_exists('to_mysql_dt')) {
  function to_mysql_dt($s) {
    return $s;
  }
}

function table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  $key = "tbl:$table";
  if (array_key_exists($key, $cache)) return $cache[$key];

  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ");
  $stmt->execute([$table]);
  $cache[$key] = ((int)$stmt->fetchColumn() > 0);
  return $cache[$key];
}

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

function dt_or_null(?string $s, DateTimeZone $tz): ?DateTime {
  $s = trim((string)$s);
  if ($s === '' || strtoupper($s) === 'NULL' || $s === '0000-00-00 00:00:00') {
    return null;
  }
  try {
    return new DateTime($s, $tz);
  } catch (Throwable $e) {
    return null;
  }
}

function fmt_dt(?DateTime $dt): ?string {
  return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

function get_slot_info(PDO $pdo, int $slot_id): array {
  foreach (['parking_slots', 'slots'] as $table) {
    if (!table_exists($pdo, $table)) continue;

    try {
      $cols = ['id', 'code'];
      if (table_has_column($pdo, $table, 'level')) $cols[] = 'level';
      if (table_has_column($pdo, $table, 'status')) $cols[] = 'status';

      $sql = "SELECT " . implode(', ', $cols) . " FROM {$table} WHERE id = :id LIMIT 1";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([':id' => $slot_id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($row) {
        return [
          'found'  => true,
          'table'  => $table,
          'id'     => (int)$row['id'],
          'code'   => (string)($row['code'] ?? ('#' . $slot_id)),
          'level'  => (string)($row['level'] ?? ''),
          'status' => strtoupper((string)($row['status'] ?? '')),
        ];
      }
    } catch (Throwable $e) {
    }
  }

  return [
    'found'  => false,
    'table'  => '',
    'id'     => $slot_id,
    'code'   => '#' . $slot_id,
    'level'  => '',
    'status' => '',
  ];
}

function expire_stale_bookings(PDO $pdo, string $nowSql): void {
  if (!table_exists($pdo, 'bookings')) return;

  $hasUpdatedAt = table_has_column($pdo, 'bookings', 'updated_at');
  $hasHoldUntil = table_has_column($pdo, 'bookings', 'hold_until');
  $hasParkedAt  = table_has_column($pdo, 'bookings', 'parked_at');

  if ($hasHoldUntil) {
    $sql = "UPDATE bookings SET status = 'EXPIRED'";
    if ($hasUpdatedAt) $sql .= ", updated_at = NOW()";
    $sql .= " WHERE UPPER(COALESCE(status,'')) IN ('ACTIVE','CONFIRMED','BOOKED','PENDING','RESERVED')";
    $sql .= " AND hold_until IS NOT NULL AND hold_until <= :now";
    if ($hasParkedAt) $sql .= " AND parked_at IS NULL";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':now' => $nowSql]);
    return;
  }

  $sql = "UPDATE bookings SET status = 'EXPIRED'";
  if ($hasUpdatedAt) $sql .= ", updated_at = NOW()";
  $sql .= " WHERE UPPER(COALESCE(status,'')) IN ('ACTIVE','CONFIRMED','BOOKED','PENDING','RESERVED')";
  $sql .= " AND start_time IS NOT NULL AND DATE_ADD(start_time, INTERVAL 15 MINUTE) <= :now";

  if ($hasParkedAt) {
    $sql .= " AND parked_at IS NULL";
  }

  $stmt = $pdo->prepare($sql);
  $stmt->execute([':now' => $nowSql]);
}

function is_terminal_status(string $status): bool {
  return in_array($status, ['CANCELLED', 'CANCELLED_BY_ADMIN', 'COMPLETED', 'EXPIRED', 'NO_SHOW'], true);
}

function overlaps_range(?DateTime $itemStart, ?DateTime $itemEnd, ?DateTime $from, ?DateTime $to): bool {
  if (!$itemStart) return false;
  $itemEnd = $itemEnd ?: $itemStart;

  if ($from && $itemEnd <= $from) return false;
  if ($to && $itemStart >= $to) return false;
  return true;
}

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($userId <= 0) {
  json_error('user_id is required', 422);
}

$time     = isset($_GET['time']) ? strtolower(trim((string)$_GET['time'])) : 'all';
$limit    = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$offset   = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo   = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$statusQs = isset($_GET['status']) ? trim((string)$_GET['status']) : '';

if ($limit <= 0) $limit = 20;
if ($limit > 100) $limit = 100;
if ($offset < 0) $offset = 0;

$statuses = [];
if ($statusQs !== '') {
  $parts = preg_split('/\s*,\s*/', strtoupper($statusQs));
  foreach ($parts as $v) {
    $v = trim($v);
    if ($v !== '') $statuses[] = $v;
  }
  $statuses = array_values(array_unique($statuses));
}

try {
  $tz = new DateTimeZone('Asia/Bangkok');
  $nowSql = (string)$pdo->query("SELECT NOW()")->fetchColumn();
  $now = new DateTime($nowSql, $tz);

  expire_stale_bookings($pdo, $now->format('Y-m-d H:i:s'));

  $dateFromDt = $dateFrom !== '' ? dt_or_null(to_mysql_dt($dateFrom), $tz) : null;
  $dateToDt   = $dateTo !== '' ? dt_or_null(to_mysql_dt($dateTo), $tz) : null;

  $stmt = $pdo->prepare("SELECT * FROM bookings WHERE user_id = :uid ORDER BY start_time DESC, id DESC");
  $stmt->execute([':uid' => $userId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $bookings = [];

  $hasHoldUntil   = table_has_column($pdo, 'bookings', 'hold_until');
  $hasParkedAt    = table_has_column($pdo, 'bookings', 'parked_at');
  $hasEndedAt     = table_has_column($pdo, 'bookings', 'ended_at');
  $hasChargedAt   = table_has_column($pdo, 'bookings', 'charged_at');
  $hasFinalPaidAt = table_has_column($pdo, 'bookings', 'final_paid_at');
  $hasReserveFee  = table_has_column($pdo, 'bookings', 'reserve_fee');
  $hasCancelFee   = table_has_column($pdo, 'bookings', 'cancel_fee');
  $hasFinalAmount = table_has_column($pdo, 'bookings', 'final_amount');
  $hasAmount      = table_has_column($pdo, 'bookings', 'amount');
  $hasUpdatedAt   = table_has_column($pdo, 'bookings', 'updated_at');

  foreach ($rows as $b) {
    $status = strtoupper(trim((string)($b['status'] ?? '')));

    $startDt = dt_or_null((string)($b['start_time'] ?? ''), $tz);
    $endDt   = dt_or_null((string)($b['end_time'] ?? ''), $tz);

    $holdUntilDt = $hasHoldUntil ? dt_or_null((string)($b['hold_until'] ?? ''), $tz) : null;
    $parkedAtDt  = $hasParkedAt ? dt_or_null((string)($b['parked_at'] ?? ''), $tz) : null;
    $endedAtDt   = $hasEndedAt ? dt_or_null((string)($b['ended_at'] ?? ''), $tz) : null;
    $chargedAtDt = $hasChargedAt ? dt_or_null((string)($b['charged_at'] ?? ''), $tz) : null;
    $finalPaidAtDt = $hasFinalPaidAt ? dt_or_null((string)($b['final_paid_at'] ?? ''), $tz) : null;

    if (!$holdUntilDt && $startDt) {
      $holdUntilDt = (clone $startDt)->modify('+15 minutes');
    }

    $hasStartedParking = ($parkedAtDt !== null) || in_array($status, ['PARKING', 'OCCUPIED'], true);
    $hasEndedParking   = ($endedAtDt !== null) || in_array($status, ['COMPLETED'], true);

    if (in_array($status, ['CANCELLED', 'CANCELLED_BY_ADMIN'], true)) {
      $computed = $status;
    } elseif (in_array($status, ['COMPLETED'], true) || $hasEndedParking || $finalPaidAtDt !== null) {
      $computed = 'COMPLETED';
    } elseif (in_array($status, ['EXPIRED', 'NO_SHOW'], true)) {
      $computed = 'EXPIRED';
    } elseif ($hasStartedParking) {
      $computed = 'OCCUPIED';
    } elseif ($holdUntilDt && $now > $holdUntilDt) {
      $computed = 'EXPIRED';
    } else {
      $computed = 'BOOKED';
    }

    $canCancel = (!is_terminal_status($computed) && !$hasStartedParking && $holdUntilDt && $now <= $holdUntilDt) ? 1 : 0;
    $canFinish = (!is_terminal_status($computed) && $hasStartedParking && !$hasEndedParking) ? 1 : 0;

    $slot = get_slot_info($pdo, (int)($b['slot_id'] ?? 0));
    $displayEndDt = $endedAtDt ?: $endDt ?: $holdUntilDt ?: $startDt;

    $reserveFee = $hasReserveFee ? (float)($b['reserve_fee'] ?? 30.0) : 30.0;
    if ($reserveFee <= 0) $reserveFee = 30.0;

    $refundPreview = round($reserveFee * 0.80, 2);
    $cancelFeePreview = round($reserveFee * 0.20, 2);

    $item = [
      'id'                 => (int)($b['id'] ?? 0),
      'user_id'            => (int)($b['user_id'] ?? 0),
      'slot_id'            => (int)($b['slot_id'] ?? 0),
      'slot_code'          => (string)$slot['code'],
      'slot_level'         => (string)$slot['level'],
      'slot_status'        => (string)$slot['status'],
      'start_time'         => fmt_dt($startDt),
      'end_time'           => fmt_dt($displayEndDt),
      'hold_until'         => fmt_dt($holdUntilDt),
      'cancel_deadline'    => fmt_dt($holdUntilDt),
      'parked_at'          => fmt_dt($parkedAtDt),
      'ended_at'           => fmt_dt($endedAtDt),
      'charged_at'         => fmt_dt($chargedAtDt),
      'final_paid_at'      => fmt_dt($finalPaidAtDt),
      'status'             => $status,
      'computed_status'    => $computed,
      'amount'             => $hasAmount ? (float)($b['amount'] ?? 0) : 0.0,
      'reserve_fee'        => $reserveFee,
      'cancel_fee'         => $hasCancelFee ? (float)($b['cancel_fee'] ?? 0) : 0.0,
      'final_amount'       => $hasFinalAmount ? (float)($b['final_amount'] ?? 0) : 0.0,
      'refund_preview'     => $refundPreview,
      'cancel_fee_preview' => $cancelFeePreview,
      'can_cancel'         => $canCancel,
      'can_finish'         => $canFinish,
      'created_at'         => (string)($b['created_at'] ?? ''),
      'updated_at'         => $hasUpdatedAt ? (string)($b['updated_at'] ?? '') : '',
    ];

    if ($time === 'upcoming' && !in_array($computed, ['BOOKED', 'OCCUPIED'], true)) {
      continue;
    }

    if ($time === 'past' && !in_array($computed, ['CANCELLED', 'CANCELLED_BY_ADMIN', 'EXPIRED', 'COMPLETED'], true)) {
      continue;
    }

    if (!empty($statuses) && !in_array($computed, $statuses, true)) {
      continue;
    }

    if (($dateFromDt || $dateToDt) && !overlaps_range($startDt, $displayEndDt, $dateFromDt, $dateToDt)) {
      continue;
    }

    $bookings[] = $item;
  }

  $total = count($bookings);
  $paged = array_slice($bookings, $offset, $limit);

  json_ok([
    'bookings' => array_values($paged),
    'items'    => array_values($paged),
    'total'    => $total,
    'limit'    => $limit,
    'offset'   => $offset,
    'time'     => $time,
    'now'      => $now->format('Y-m-d H:i:s'),
    'paging'   => [
      'total'  => $total,
      'limit'  => $limit,
      'offset' => $offset,
    ],
  ]);

} catch (Throwable $e) {
  error_log('[AutoParkX] booking_list_my error: ' . $e->getMessage());
  json_error('server error', 500);
}