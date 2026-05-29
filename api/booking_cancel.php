<?php
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$LINE_CHANNEL_ACCESS_TOKEN = (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');
$ADMIN_LINE_ID = '';

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
if (!function_exists('json_input')) {
  function json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
  }
}

class ApiError extends Exception {
  public int $statusCode;
  public function __construct(string $message, int $statusCode = 400) {
    parent::__construct($message);
    $this->statusCode = $statusCode;
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

function looks_like_line_id(string $s): bool {
  $s = trim($s);
  if ($s === '') return false;
  return (bool)preg_match('/^[UCR][0-9a-f]{32}$/i', $s);
}

function line_push_raw(array $body): array {
  global $LINE_CHANNEL_ACCESS_TOKEN;

  if (!$LINE_CHANNEL_ACCESS_TOKEN || empty($body['to']) || empty($body['messages'])) {
    return ['ok' => false, 'http' => 0, 'err' => 'missing token/to/messages', 'resp' => null];
  }

  $ch = curl_init('https://api.line.me/v2/bot/message/push');
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $LINE_CHANNEL_ACCESS_TOKEN,
    ],
    CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 12,
  ]);

  $resp  = curl_exec($ch);
  $errNo = curl_errno($ch);
  $errTx = $errNo ? curl_error($ch) : '';
  $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $ok = ($errNo === 0 && $http >= 200 && $http < 300);
  if (!$ok) {
    error_log("LINE push fail http={$http} err={$errNo} {$errTx} resp={$resp}");
  }

  return ['ok' => $ok, 'http' => $http, 'err' => $errTx, 'resp' => $resp];
}

function get_notify_prefs(PDO $pdo, int $user_id): array {
  $defaults = [
    'enabled'           => true,
    'booking_created'   => true,
    'booking_soon'      => true,
    'booking_cancelled' => true,
    'news'              => false,
    'quiet_night'       => false,
  ];

  if ($user_id <= 0 || !table_exists($pdo, 'user_notify_prefs')) return $defaults;

  try {
    $stmt = $pdo->prepare("
      SELECT enabled, booking_created, booking_soon, booking_cancelled, news, quiet_night
      FROM user_notify_prefs
      WHERE user_id = :uid
      LIMIT 1
    ");
    $stmt->execute([':uid' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $defaults;

    return [
      'enabled'           => (bool)$row['enabled'],
      'booking_created'   => (bool)$row['booking_created'],
      'booking_soon'      => (bool)$row['booking_soon'],
      'booking_cancelled' => (bool)$row['booking_cancelled'],
      'news'              => (bool)$row['news'],
      'quiet_night'       => (bool)$row['quiet_night'],
    ];
  } catch (Throwable $e) {
    error_log('[AutoParkX] get_notify_prefs error: ' . $e->getMessage());
    return $defaults;
  }
}

function get_slot_info(PDO $pdo, int $slot_id): array {
  foreach (['parking_slots', 'slots'] as $table) {
    if (!table_exists($pdo, $table)) continue;

    try {
      $stmt = $pdo->prepare("
        SELECT id, code, level, status
        FROM {$table}
        WHERE id = :id
        LIMIT 1
      ");
      $stmt->execute([':id' => $slot_id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($row) {
        return [
          'found'  => true,
          'table'  => $table,
          'id'     => (int)$row['id'],
          'code'   => (string)($row['code'] ?? ('Slot #' . $slot_id)),
          'level'  => (string)($row['level'] ?? ''),
          'status' => strtoupper((string)($row['status'] ?? '')),
        ];
      }
    } catch (Throwable $e) {
      // ignore
    }
  }

  return [
    'found'  => false,
    'table'  => '',
    'id'     => $slot_id,
    'code'   => 'Slot #' . $slot_id,
    'level'  => '',
    'status' => '',
  ];
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

function is_terminal_status(string $status): bool {
  return in_array($status, ['CANCELLED', 'CANCELLED_BY_ADMIN', 'COMPLETED', 'EXPIRED', 'NO_SHOW'], true);
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

function find_first_existing_column(PDO $pdo, string $table, array $columns): ?string {
  foreach ($columns as $col) {
    if (table_has_column($pdo, $table, $col)) return $col;
  }
  return null;
}

function add_wallet_balance(PDO $pdo, int $userId, float $amount): array {
  if ($amount <= 0) {
    return [
      'updated' => false,
      'column' => null,
      'before' => null,
      'after' => null,
    ];
  }

  if (!table_exists($pdo, 'users')) {
    return [
      'updated' => false,
      'column' => null,
      'before' => null,
      'after' => null,
    ];
  }

  $walletColumn = find_first_existing_column($pdo, 'users', [
    'wallet_balance',
    'balance',
    'wallet',
    'credit',
    'credits'
  ]);

  if (!$walletColumn) {
    return [
      'updated' => false,
      'column' => null,
      'before' => null,
      'after' => null,
    ];
  }

  $st = $pdo->prepare("SELECT {$walletColumn} AS wallet_value FROM users WHERE id = :uid LIMIT 1 FOR UPDATE");
  $st->execute([':uid' => $userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    throw new ApiError('user not found', 404);
  }

  $before = (float)($row['wallet_value'] ?? 0);
  $after  = $before + $amount;

  $up = $pdo->prepare("UPDATE users SET {$walletColumn} = :after WHERE id = :uid");
  $up->execute([
    ':after' => $after,
    ':uid'   => $userId,
  ]);

  return [
    'updated' => true,
    'column' => $walletColumn,
    'before' => $before,
    'after' => $after,
  ];
}

function insert_wallet_transaction(PDO $pdo, array $data): void {
  if (!table_exists($pdo, 'wallet_transactions')) return;

  $fields = [];
  $params = [];

  $map = [
    'user_id'        => $data['user_id'] ?? null,
    'booking_id'     => $data['booking_id'] ?? null,
    'type'           => $data['type'] ?? 'BOOKING_CANCEL_REFUND',
    'direction'      => $data['direction'] ?? 'IN',
    'amount'         => $data['amount'] ?? 0,
    'balance_before' => $data['balance_before'] ?? null,
    'balance_after'  => $data['balance_after'] ?? null,
    'note'           => $data['note'] ?? null,
    'description'    => $data['description'] ?? null,
    'status'         => $data['status'] ?? 'SUCCESS',
    'source'         => $data['source'] ?? 'booking_cancel',
    'reference_type' => $data['reference_type'] ?? 'booking',
    'reference_id'   => $data['reference_id'] ?? null,
    'created_at'     => $data['created_at'] ?? date('Y-m-d H:i:s'),
    'updated_at'     => $data['updated_at'] ?? date('Y-m-d H:i:s'),
  ];

  foreach ($map as $column => $value) {
    if (table_has_column($pdo, 'wallet_transactions', $column)) {
      $fields[] = $column;
      $params[":$column"] = $value;
    }
  }

  if (empty($fields)) return;

  $sql = "INSERT INTO wallet_transactions (" . implode(', ', $fields) . ") VALUES (" . implode(', ', array_keys($params)) . ")";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
}

function flex_kv_row_cancel(string $label, string $value, bool $wrap = false): array {
  return [
    'type' => 'box',
    'layout' => 'baseline',
    'spacing' => 'sm',
    'contents' => [
      [
        'type' => 'text',
        'text' => $label,
        'size' => 'xs',
        'color' => '#6b7280',
        'flex' => 3,
      ],
      [
        'type' => 'text',
        'text' => $value,
        'size' => 'xs',
        'color' => '#111827',
        'flex' => 6,
        'wrap' => $wrap,
      ],
    ],
  ];
}

function build_booking_cancelled_flex(
  int $bookingId,
  string $slotLabel,
  string $startLabel,
  string $endLabel,
  string $refundLabel,
  string $feeLabel,
  string $reason,
  string $url
): array {
  return [
    'type' => 'flex',
    'altText' => "AutoParkX: ยกเลิกการจองสำเร็จ #{$bookingId}",
    'contents' => [
      'type' => 'bubble',
      'size' => 'mega',
      'header' => [
        'type' => 'box',
        'layout' => 'vertical',
        'paddingAll' => '16px',
        'backgroundColor' => '#ef4444',
        'contents' => [
          [
            'type' => 'text',
            'text' => 'ยกเลิกการจองสำเร็จ',
            'weight' => 'bold',
            'size' => 'md',
            'color' => '#ffffff',
          ],
          [
            'type' => 'text',
            'text' => "Booking ID : #{$bookingId}",
            'size' => 'xs',
            'color' => '#fee2e2',
            'margin' => 'sm',
          ],
          [
            'type' => 'text',
            'text' => "คืนเงินแล้ว : {$refundLabel} บาท",
            'size' => 'xs',
            'color' => '#fee2e2',
            'margin' => 'sm',
          ],
        ],
      ],
      'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'paddingAll' => '16px',
        'backgroundColor' => '#ffffff',
        'spacing' => 'md',
        'contents' => [
          [
            'type' => 'text',
            'text' => 'รายละเอียดการยกเลิก',
            'weight' => 'bold',
            'color' => '#111827',
            'size' => 'sm',
          ],
          [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'xs',
            'contents' => [
              flex_kv_row_cancel('ช่องจอด :', $slotLabel, true),
              flex_kv_row_cancel('เริ่ม :', $startLabel),
              flex_kv_row_cancel('สิ้นสุด :', $endLabel),
              flex_kv_row_cancel('สถานะ :', 'ยกเลิกแล้ว'),
              flex_kv_row_cancel('คืนเงิน :', $refundLabel . ' บาท'),
              flex_kv_row_cancel('ค่าธรรมเนียม :', $feeLabel . ' บาท'),
              flex_kv_row_cancel('เหตุผล :', $reason !== '' ? $reason : '-', true),
            ],
          ],
          [
            'type' => 'separator',
            'margin' => 'md',
          ],
          [
            'type' => 'text',
            'text' => 'ระบบได้คืนเงินเข้าวอลเล็ตของคุณเรียบร้อยแล้ว',
            'size' => 'xs',
            'color' => '#374151',
            'wrap' => true,
            'margin' => 'md',
          ],
        ],
      ],
      'footer' => [
        'type' => 'box',
        'layout' => 'vertical',
        'backgroundColor' => '#ffffff',
        'paddingAll' => '12px',
        'contents' => [
          [
            'type' => 'button',
            'style' => 'primary',
            'color' => '#ef4444',
            'height' => 'sm',
            'action' => [
              'type' => 'uri',
              'label' => 'ดูประวัติการจอง',
              'uri' => $url,
            ],
          ],
          [
            'type' => 'text',
            'text' => 'AutoParkX • Booking Cancelled',
            'size' => 'xxs',
            'color' => '#6b7280',
            'align' => 'center',
            'margin' => 'sm',
          ],
        ],
      ],
    ],
  ];
}

try {
  $in = json_input();
  if ($in === null) $in = $_POST ?: [];

  $bookingId = (int)($in['booking_id'] ?? $in['id'] ?? 0);
  $userId    = (int)($in['user_id'] ?? 0);
  $reason    = trim((string)($in['reason'] ?? $in['cancel_reason'] ?? 'ยกเลิกโดยผู้ใช้'));
  $debug     = (int)($in['debug'] ?? 0);

  if ($bookingId <= 0) {
    throw new ApiError('booking_id is required', 422);
  }
  if ($userId <= 0) {
    throw new ApiError('user_id is required', 422);
  }

  $tz = new DateTimeZone('Asia/Bangkok');
  $nowSql = (string)$pdo->query("SELECT NOW()")->fetchColumn();
  $now = new DateTime($nowSql, $tz);

  expire_stale_bookings($pdo, $now->format('Y-m-d H:i:s'));

  $pdo->beginTransaction();

  $stmt = $pdo->prepare("
    SELECT b.*, u.line_user_id, u.push_token, u.email
    FROM bookings b
    LEFT JOIN users u ON u.id = b.user_id
    WHERE b.id = :id AND b.user_id = :uid
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([
    ':id'  => $bookingId,
    ':uid' => $userId,
  ]);
  $b = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$b) {
    throw new ApiError('booking not found', 404);
  }

  $status = strtoupper(trim((string)($b['status'] ?? '')));

  $hasHoldUntil      = table_has_column($pdo, 'bookings', 'hold_until');
  $hasParkedAt       = table_has_column($pdo, 'bookings', 'parked_at');
  $hasEndedAt        = table_has_column($pdo, 'bookings', 'ended_at');
  $hasChargedAt      = table_has_column($pdo, 'bookings', 'charged_at');
  $hasFinalPaidAt    = table_has_column($pdo, 'bookings', 'final_paid_at');
  $hasReserveFee     = table_has_column($pdo, 'bookings', 'reserve_fee');
  $hasCancelFee      = table_has_column($pdo, 'bookings', 'cancel_fee');
  $hasFinalAmount    = table_has_column($pdo, 'bookings', 'final_amount');
  $hasUpdatedAt      = table_has_column($pdo, 'bookings', 'updated_at');
  $hasCancelledAt    = table_has_column($pdo, 'bookings', 'cancelled_at');
  $hasCancelReason   = table_has_column($pdo, 'bookings', 'cancel_reason');
  $hasRefundAmount   = table_has_column($pdo, 'bookings', 'refund_amount');
  $hasRefundedAmount = table_has_column($pdo, 'bookings', 'refunded_amount');
  $hasRefundedAt     = table_has_column($pdo, 'bookings', 'refunded_at');

  $startDt       = dt_or_null((string)($b['start_time'] ?? ''), $tz);
  $endDt         = dt_or_null((string)($b['end_time'] ?? ''), $tz);
  $holdUntilDt   = $hasHoldUntil ? dt_or_null((string)($b['hold_until'] ?? ''), $tz) : null;
  $parkedAtDt    = $hasParkedAt ? dt_or_null((string)($b['parked_at'] ?? ''), $tz) : null;
  $endedAtDt     = $hasEndedAt ? dt_or_null((string)($b['ended_at'] ?? ''), $tz) : null;
  $chargedAtDt   = $hasChargedAt ? dt_or_null((string)($b['charged_at'] ?? ''), $tz) : null;
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

  $canCancel = (!is_terminal_status($computed) && !$hasStartedParking && $holdUntilDt && $now <= $holdUntilDt);

  if (!$canCancel) {
    throw new ApiError('booking cannot be cancelled', 409);
  }

  $reserveFee = $hasReserveFee ? (float)($b['reserve_fee'] ?? 30.0) : 30.0;
  if ($reserveFee <= 0) $reserveFee = 30.0;

  $refundAmount = round($reserveFee * 0.80, 2);
  $cancelFee    = round($reserveFee * 0.20, 2);

  $wallet = add_wallet_balance($pdo, $userId, $refundAmount);

  $set = ["status = 'CANCELLED'"];
  $params = [];

  if ($hasCancelFee) {
    $set[] = "cancel_fee = :cancel_fee";
    $params[':cancel_fee'] = $cancelFee;
  }

  if ($hasFinalAmount) {
    $set[] = "final_amount = :final_amount";
    $params[':final_amount'] = 0;
  }

  if ($hasRefundAmount) {
    $set[] = "refund_amount = :refund_amount";
    $params[':refund_amount'] = $refundAmount;
  }

  if ($hasRefundedAmount) {
    $set[] = "refunded_amount = :refunded_amount";
    $params[':refunded_amount'] = $refundAmount;
  }

  if ($hasCancelledAt) {
    $set[] = "cancelled_at = NOW()";
  }

  if ($hasRefundedAt) {
    $set[] = "refunded_at = NOW()";
  }

  if ($hasCancelReason) {
    $set[] = "cancel_reason = :cancel_reason";
    $params[':cancel_reason'] = $reason;
  }

  if ($hasUpdatedAt) {
    $set[] = "updated_at = NOW()";
  }

  $params[':id']  = $bookingId;
  $params[':uid'] = $userId;

  $sql = "UPDATE bookings SET " . implode(', ', $set) . " WHERE id = :id AND user_id = :uid LIMIT 1";
  $up = $pdo->prepare($sql);
  $up->execute($params);

  insert_wallet_transaction($pdo, [
    'user_id'        => $userId,
    'booking_id'     => $bookingId,
    'type'           => 'BOOKING_CANCEL_REFUND',
    'direction'      => 'IN',
    'amount'         => $refundAmount,
    'balance_before' => $wallet['before'],
    'balance_after'  => $wallet['after'],
    'note'           => 'Refund 80% from booking cancellation',
    'description'    => 'คืนเงิน 80% จากการยกเลิกการจอง #' . $bookingId,
    'status'         => 'SUCCESS',
    'source'         => 'booking_cancel',
    'reference_type' => 'booking',
    'reference_id'   => $bookingId,
    'created_at'     => $now->format('Y-m-d H:i:s'),
    'updated_at'     => $now->format('Y-m-d H:i:s'),
  ]);

  $pdo->commit();

  $sent_user  = ['ok' => false];
  $sent_admin = ['ok' => false];

  try {
    $prefs = get_notify_prefs($pdo, $userId);
    $slot  = get_slot_info($pdo, (int)($b['slot_id'] ?? 0));

    $lineTo = '';
    $cand1 = trim((string)($b['line_user_id'] ?? ''));
    $cand2 = trim((string)($b['push_token'] ?? ''));

    if (looks_like_line_id($cand1)) $lineTo = $cand1;
    elseif (looks_like_line_id($cand2)) $lineTo = $cand2;

    $slotLabel   = $slot['code'] . (($slot['level'] !== '') ? " (ชั้น {$slot['level']})" : '');
    $startLabel  = $startDt ? $startDt->format('d/m/Y H:i') : '-';
    $endLabel    = $endDt ? $endDt->format('d/m/Y H:i') : '-';
    $refundLabel = number_format($refundAmount, 2);
    $feeLabel    = number_format($cancelFee, 2);
    $url         = 'https://autoparkx.com/mybookings.html';

    $flex = build_booking_cancelled_flex(
      $bookingId,
      $slotLabel,
      $startLabel,
      $endLabel,
      $refundLabel,
      $feeLabel,
      $reason,
      $url
    );

    if (!empty($prefs['enabled']) && !empty($prefs['booking_cancelled']) && $lineTo !== '') {
      $sent_user = line_push_raw([
        'to' => $lineTo,
        'messages' => [$flex]
      ]);
    }

    if ($ADMIN_LINE_ID !== '' && looks_like_line_id($ADMIN_LINE_ID)) {
      $sent_admin = line_push_raw([
        'to' => $ADMIN_LINE_ID,
        'messages' => [$flex]
      ]);
    }

  } catch (Throwable $e) {
    error_log('[AutoParkX] booking_cancel notify error: ' . $e->getMessage());
  }

  $out = [
    'message' => 'cancelled successfully',
    'booking' => [
      'id'                => (int)$bookingId,
      'user_id'           => (int)$userId,
      'slot_id'           => (int)($b['slot_id'] ?? 0),
      'status'            => 'CANCELLED',
      'computed_status'   => 'CANCELLED',
      'start_time'        => fmt_dt($startDt),
      'end_time'          => fmt_dt($endDt),
      'hold_until'        => fmt_dt($holdUntilDt),
      'cancel_deadline'   => fmt_dt($holdUntilDt),
      'charged_at'        => fmt_dt($chargedAtDt),
      'cancelled_at'      => $now->format('Y-m-d H:i:s'),
      'reserve_fee'       => $reserveFee,
      'refund_amount'     => $refundAmount,
      'cancel_fee'        => $cancelFee,
      'can_cancel'        => 0,
    ],
    'wallet' => [
      'updated'         => (bool)$wallet['updated'],
      'wallet_column'   => $wallet['column'],
      'balance_before'  => $wallet['before'],
      'balance_after'   => $wallet['after'],
      'refund_amount'   => $refundAmount,
    ],
  ];

  if ($debug === 1) {
    $out['line_debug'] = [
      'user'  => $sent_user,
      'admin' => $sent_admin,
    ];
  }

  json_ok($out);

} catch (ApiError $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_error($e->getMessage(), $e->statusCode);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[AutoParkX] booking_cancel error: ' . $e->getMessage());
  json_error($e->getMessage(), 500);
}