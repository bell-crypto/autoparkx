<?php
require __DIR__ . '/db.php';

$RESERVE_FEE = 30.00;
$CANCEL_GRACE_MINUTES = 15;
$FUTURE_WINDOW_MINUTES = 30;

$LINE_CHANNEL_ACCESS_TOKEN = (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');

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

  if (!$LINE_CHANNEL_ACCESS_TOKEN || $LINE_CHANNEL_ACCESS_TOKEN === 'PASTE_YOUR_LINE_CHANNEL_ACCESS_TOKEN_HERE') {
    return ['ok' => false, 'http' => 0, 'err' => 'missing LINE token', 'resp' => null];
  }

  if (empty($body['to']) || empty($body['messages'])) {
    return ['ok' => false, 'http' => 0, 'err' => 'missing to/messages', 'resp' => null];
  }

  if (!function_exists('curl_init')) {
    return ['ok' => false, 'http' => 0, 'err' => 'curl not available', 'resp' => null];
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
      error_log('[AutoParkX] get_slot_info error: ' . $e->getMessage());
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

function expire_stale_bookings(PDO $pdo, string $nowSql): void {
  if (!table_has_column($pdo, 'bookings', 'hold_until')) return;

  $set = "status = 'EXPIRED'";
  if (table_has_column($pdo, 'bookings', 'updated_at')) {
    $set .= ", updated_at = NOW()";
  }

  $where = "
    UPPER(COALESCE(status,'')) IN ('','ACTIVE','CONFIRMED','BOOKED','PENDING','RESERVED')
    AND hold_until IS NOT NULL
    AND hold_until <= :now
  ";

  if (table_has_column($pdo, 'bookings', 'parked_at')) {
    $where .= " AND parked_at IS NULL";
  }

  $stmt = $pdo->prepare("UPDATE bookings SET {$set} WHERE {$where}");
  $stmt->execute([':now' => $nowSql]);
}

function active_status_sql(PDO $pdo, string $alias): string {
  $endedPart = table_has_column($pdo, 'bookings', 'ended_at')
    ? " AND {$alias}.ended_at IS NULL"
    : "";

  return "UPPER(COALESCE({$alias}.status,'')) IN ('','ACTIVE','CONFIRMED','BOOKED','PENDING','RESERVED','PARKING','OCCUPIED'){$endedPart}";
}

function overlap_end_expr(PDO $pdo, string $alias): string {
  if (table_has_column($pdo, 'bookings', 'hold_until')) {
    return "COALESCE({$alias}.hold_until, {$alias}.end_time, DATE_ADD({$alias}.start_time, INTERVAL 15 MINUTE))";
  }

  return "COALESCE({$alias}.end_time, DATE_ADD({$alias}.start_time, INTERVAL 15 MINUTE))";
}

function is_duplicate_key_error(Throwable $e): bool {
  if (!($e instanceof PDOException)) return false;

  $sqlState = (string)$e->getCode();
  $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
  $msg = strtolower((string)$e->getMessage());

  return $sqlState === '23000'
    || $driverCode === 1062
    || strpos($msg, 'duplicate entry') !== false;
}

function is_overlap_trigger_error(Throwable $e): bool {
  if (!($e instanceof PDOException)) return false;

  $sqlState = (string)$e->getCode();
  $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
  $msg = strtolower((string)$e->getMessage());

  return $sqlState === '45000'
    || $driverCode === 1644
    || strpos($msg, 'overlap booking for this slot') !== false;
}

function flex_kv_row_booking(string $label, string $value, bool $wrap = false): array {
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

function build_booking_created_flex(
  int $bookingId,
  string $userEmail,
  string $slotLabel,
  string $startLabel,
  string $endLabel,
  string $amountLabel,
  string $url
): array {
  return [
    'type' => 'flex',
    'altText' => "AutoParkX: มีการจองที่จอดใหม่ #{$bookingId}",
    'contents' => [
      'type' => 'bubble',
      'size' => 'mega',
      'header' => [
        'type' => 'box',
        'layout' => 'vertical',
        'paddingAll' => '16px',
        'backgroundColor' => '#1d7ff2',
        'contents' => [
          ['type' => 'text', 'text' => 'มีการจองที่จอดใหม่', 'weight' => 'bold', 'size' => 'md', 'color' => '#ffffff'],
          ['type' => 'text', 'text' => "Booking ID: #{$bookingId}", 'size' => 'xs', 'color' => '#dbeafe', 'margin' => 'sm'],
          ['type' => 'text', 'text' => 'สถานะ: จองแล้ว', 'size' => 'xs', 'color' => '#dbeafe', 'margin' => 'sm'],
        ],
      ],
      'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'paddingAll' => '16px',
        'backgroundColor' => '#ffffff',
        'spacing' => 'md',
        'contents' => [
          ['type' => 'text', 'text' => 'รายละเอียดการจอง', 'weight' => 'bold', 'color' => '#111827', 'size' => 'sm'],
          [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'xs',
            'contents' => [
              flex_kv_row_booking('ผู้ใช้ :', $userEmail !== '' ? $userEmail : '-', true),
              flex_kv_row_booking('ช่องจอด :', $slotLabel, true),
              flex_kv_row_booking('เริ่ม :', $startLabel),
              flex_kv_row_booking('สิ้นสุด :', $endLabel),
              flex_kv_row_booking('ค่าจอง :', $amountLabel . ' บาท'),
            ],
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
            'color' => '#1d7ff2',
            'height' => 'sm',
            'action' => [
              'type' => 'uri',
              'label' => 'ดูประวัติการจอง',
              'uri' => $url,
            ],
          ],
          ['type' => 'text', 'text' => 'AutoParkX • Booking', 'size' => 'xxs', 'color' => '#6b7280', 'align' => 'center', 'margin' => 'sm'],
        ],
      ],
    ],
  ];
}

try {
  $in = json_input();
  if ($in === null) $in = $_POST ?: [];

  $user_id   = (int)arr_get($in, 'user_id', 0);
  $slot_id   = (int)arr_get($in, 'slot_id', 0);
  $start_raw = (string)arr_get($in, 'start_time', '');
  $debug     = (int)arr_get($in, 'debug', 0);

  if ($user_id <= 0 || $slot_id <= 0 || $start_raw === '') {
    json_error('ข้อมูลไม่ครบถ้วน', 422);
  }

  $tz        = new DateTimeZone('Asia/Bangkok');
  $start_str = to_mysql_dt($start_raw);
  $start     = new DateTime($start_str, $tz);

  $nowDbStr = (string)$pdo->query("SELECT NOW()")->fetchColumn();
  $now      = new DateTime($nowDbStr, $tz);

  $minPast = (clone $now)->modify('-1 minute');
  $maxNext = (clone $now)->modify('+' . $FUTURE_WINDOW_MINUTES . ' minutes');

  if ($start < $minPast) {
    json_error('เวลาที่เลือกผ่านมาแล้ว', 422);
  }

  if ($start > $maxNext) {
    json_error("ระบบรองรับเริ่มจอดใกล้เวลาปัจจุบันเท่านั้น (ไม่เกิน {$FUTURE_WINDOW_MINUTES} นาที)", 422);
  }

  $holdUntil = (clone $start)->modify('+' . $CANCEL_GRACE_MINUTES . ' minutes');

  $newStart = $start->format('Y-m-d H:i:s');
  $newEnd   = $holdUntil->format('Y-m-d H:i:s');

  $pdo->beginTransaction();

  expire_stale_bookings($pdo, $now->format('Y-m-d H:i:s'));

  $stmt = $pdo->prepare("
    SELECT id, email, wallet, role, status, line_user_id, push_token
    FROM users
    WHERE id = :uid
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([':uid' => $user_id]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    $pdo->rollBack();
    json_error('ไม่พบผู้ใช้', 404);
  }

  $userStatus = strtolower(trim((string)($user['status'] ?? 'active')));
  if ($userStatus !== '' && $userStatus !== 'active') {
    $pdo->rollBack();
    json_error('บัญชีผู้ใช้ไม่พร้อมใช้งาน', 403);
  }

  $walletBefore = (float)($user['wallet'] ?? 0);
  if ($walletBefore < $RESERVE_FEE) {
    $pdo->rollBack();
    json_error('ยอดเงินในวอลเล็ตไม่เพียงพอสำหรับค่าจอง 30 บาท', 422);
  }

  $stmt = $pdo->prepare("
    SELECT id
    FROM parking_slots
    WHERE id = :sid
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([':sid' => $slot_id]);
  $lockedSlotId = $stmt->fetchColumn();

  if (!$lockedSlotId) {
    $pdo->rollBack();
    json_error('ไม่พบช่องจอด', 404);
  }

  $slot = get_slot_info($pdo, $slot_id);

  if (empty($slot['found'])) {
    $pdo->rollBack();
    json_error('ไม่พบข้อมูลช่องจอด', 404);
  }

  $statusSql = active_status_sql($pdo, 'b');
  $endExpr   = overlap_end_expr($pdo, 'b');

  $stmt = $pdo->prepare("
    SELECT b.id
    FROM bookings b
    WHERE b.slot_id = :slot_id
      AND {$statusSql}
      AND b.start_time < :new_end
      AND {$endExpr} > :new_start
    ORDER BY b.id DESC
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([
    ':slot_id'   => $slot_id,
    ':new_start' => $newStart,
    ':new_end'   => $newEnd,
  ]);
  $slotOverlap = $stmt->fetchColumn();

  if ($slotOverlap) {
    $pdo->rollBack();
    json_error('ช่องนี้มีการจองหรือกำลังใช้งานอยู่แล้ว', 409);
  }

  $stmt = $pdo->prepare("
    SELECT b.id
    FROM bookings b
    WHERE b.user_id = :user_id
      AND {$statusSql}
      AND b.start_time < :new_end
      AND {$endExpr} > :new_start
    ORDER BY b.id DESC
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([
    ':user_id'   => $user_id,
    ':new_start' => $newStart,
    ':new_end'   => $newEnd,
  ]);
  $userOverlap = $stmt->fetchColumn();

  if ($userOverlap) {
    $pdo->rollBack();
    json_error('คุณมีการจองหรือกำลังใช้งานช่องจอดอื่นอยู่แล้ว', 409);
  }

  $columns = ['user_id', 'slot_id', 'start_time', 'end_time', 'amount', 'status', 'created_at'];
  $values  = [':uid', ':sid', ':start_time', ':end_time', ':amount', ':status', 'NOW()'];
  $params  = [
    ':uid'        => $user_id,
    ':sid'        => $slot_id,
    ':start_time' => $newStart,
    ':end_time'   => $newEnd,
    ':amount'     => 0.00,
    ':status'     => '',
  ];

  if (table_has_column($pdo, 'bookings', 'updated_at')) {
    $columns[] = 'updated_at';
    $values[]  = 'NOW()';
  }

  if (table_has_column($pdo, 'bookings', 'hold_until')) {
    $columns[] = 'hold_until';
    $values[]  = ':hold_until';
    $params[':hold_until'] = $holdUntil->format('Y-m-d H:i:s');
  }

  if (table_has_column($pdo, 'bookings', 'reserve_fee')) {
    $columns[] = 'reserve_fee';
    $values[]  = ':reserve_fee';
    $params[':reserve_fee'] = $RESERVE_FEE;
  }

  if (table_has_column($pdo, 'bookings', 'cancel_fee')) {
    $columns[] = 'cancel_fee';
    $values[]  = ':cancel_fee';
    $params[':cancel_fee'] = 0.00;
  }

  if (table_has_column($pdo, 'bookings', 'final_amount')) {
    $columns[] = 'final_amount';
    $values[]  = ':final_amount';
    $params[':final_amount'] = 0.00;
  }

  if (table_has_column($pdo, 'bookings', 'charged_at')) {
    $columns[] = 'charged_at';
    $values[]  = 'NOW()';
  }

  $sql = "INSERT INTO bookings (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";

  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
  } catch (Throwable $e) {
    if (is_duplicate_key_error($e) || is_overlap_trigger_error($e)) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      json_error('ช่องนี้มีการจองทับช่วงเวลาแล้ว กรุณาเลือกใหม่', 409);
    }

    throw $e;
  }

  $booking_id = (int)$pdo->lastInsertId();

  $stmt = $pdo->prepare("
    UPDATE users
    SET wallet = wallet - :amt
    WHERE id = :uid
  ");
  $stmt->execute([
    ':amt' => $RESERVE_FEE,
    ':uid' => $user_id,
  ]);

  if (table_exists($pdo, 'wallet_history')) {
    $stmt = $pdo->prepare("
      INSERT INTO wallet_history (user_id, type, amount, note, ref, created_at)
      VALUES (:uid, :type, :amount, :note, :ref, NOW())
    ");
    $stmt->execute([
      ':uid'    => $user_id,
      ':type'   => '',
      ':amount' => -$RESERVE_FEE,
      ':note'   => 'ตัดค่าจองที่จอด 30 บาท',
      ':ref'    => 'BOOKING-API',
    ]);
  }

  $walletAfter = $walletBefore - $RESERVE_FEE;

  $pdo->commit();

  $sent_user  = ['ok' => false];
  $sent_admin = ['ok' => false];

  try {
    $prefs = get_notify_prefs($pdo, $user_id);

    $lineTo = '';
    $cand1 = trim((string)($user['line_user_id'] ?? ''));
    $cand2 = trim((string)($user['push_token'] ?? ''));

    if (looks_like_line_id($cand1)) {
      $lineTo = $cand1;
    } elseif (looks_like_line_id($cand2)) {
      $lineTo = $cand2;
    }

    $slotLabel   = $slot['code'] . (($slot['level'] !== '') ? " (ชั้น {$slot['level']})" : '');
    $userEmail   = trim((string)($user['email'] ?? ''));
    $startLabel  = $start->format('d/m/Y H:i');
    $endLabel    = $holdUntil->format('d/m/Y H:i');
    $amountLabel = number_format($RESERVE_FEE, 2);
    $url         = 'https://autoparkx.com/mybookings.html';

    $flex = build_booking_created_flex(
      $booking_id,
      $userEmail,
      $slotLabel,
      $startLabel,
      $endLabel,
      $amountLabel,
      $url
    );

    if (!empty($prefs['enabled']) && !empty($prefs['booking_created']) && $lineTo !== '') {
      $sent_user = line_push_raw([
        'to' => $lineTo,
        'messages' => [$flex],
      ]);
    }

    $adminLineId = '';
    if (defined('ADMIN_LINE_ID')) {
      $adminLineId = trim((string)constant('ADMIN_LINE_ID'));
    } elseif (isset($GLOBALS['ADMIN_LINE_ID'])) {
      $adminLineId = trim((string)$GLOBALS['ADMIN_LINE_ID']);
    }

    if ($adminLineId !== '' && looks_like_line_id($adminLineId)) {
      $sent_admin = line_push_raw([
        'to' => $adminLineId,
        'messages' => [$flex],
      ]);
    }
  } catch (Throwable $e) {
    error_log('[AutoParkX] booking_create notify error: ' . $e->getMessage());
  }

  $out = [
    'booking_id'    => $booking_id,
    'slot_id'       => $slot_id,
    'slot_code'     => (string)$slot['code'],
    'status'        => 'ACTIVE',
    'amount'        => 0.00,
    'reserve_fee'   => $RESERVE_FEE,
    'charged_now'   => true,
    'start_time'    => $newStart,
    'end_time'      => $newEnd,
    'hold_until'    => $holdUntil->format('Y-m-d H:i:s'),
    'wallet_before' => $walletBefore,
    'wallet_after'  => $walletAfter,
    'message'       => 'จองสำเร็จและหักค่าจอง 30 บาทแล้ว',
  ];

  if ($debug === 1) {
    $out['line_debug'] = [
      'user'  => $sent_user,
      'admin' => $sent_admin,
    ];
  }

  json_ok($out);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }

  if (is_duplicate_key_error($e) || is_overlap_trigger_error($e)) {
    json_error('ช่องนี้มีการจองทับช่วงเวลาแล้ว กรุณาเลือกใหม่', 409);
  }

  error_log('[AutoParkX] booking_create error: ' . $e->getMessage());
  json_error($e->getMessage(), 500);
}