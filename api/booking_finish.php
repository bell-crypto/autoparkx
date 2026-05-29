<?php
require __DIR__ . '/db.php';

$RATE_PER_HOUR = 30.00;
$DEFAULT_RESERVE_FEE = 30.00;

$LINE_CHANNEL_ACCESS_TOKEN = (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');
$ADMIN_LINE_ID = '';

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
    $row = $stmt->fetch();
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
    error_log('[AutoParkX] get_notify_prefs finish error: ' . $e->getMessage());
    return $defaults;
  }
}

function get_slot_label(PDO $pdo, int $slot_id): string {
  foreach (['parking_slots', 'slots'] as $table) {
    if (!table_exists($pdo, $table)) continue;
    try {
      $stmt = $pdo->prepare("SELECT code, level FROM {$table} WHERE id = :id LIMIT 1");
      $stmt->execute([':id' => $slot_id]);
      $row = $stmt->fetch();
      if ($row) {
        $code = (string)($row['code'] ?? ('#' . $slot_id));
        $level = trim((string)($row['level'] ?? ''));
        return $level !== '' ? sprintf('%s (ชั้น %s)', $code, $level) : $code;
      }
    } catch (Throwable $e) {
    }
  }
  return 'Slot #' . $slot_id;
}

function is_terminal_status(string $status): bool {
  return in_array($status, ['CANCELLED', 'CANCELLED_BY_ADMIN', 'COMPLETED', 'EXPIRED', 'NO_SHOW'], true);
}

function expire_stale_bookings(PDO $pdo, string $nowSql): void {
  $hasUpdatedAt = table_has_column($pdo, 'bookings', 'updated_at');
  $hasHoldUntil = table_has_column($pdo, 'bookings', 'hold_until');
  $hasParkedAt  = table_has_column($pdo, 'bookings', 'parked_at');

  if (!$hasHoldUntil) return;

  $sql = "UPDATE bookings SET status = 'EXPIRED'";
  if ($hasUpdatedAt) $sql .= ", updated_at = NOW()";
  $sql .= " WHERE UPPER(COALESCE(status,'')) IN ('ACTIVE','CONFIRMED','BOOKED','PENDING','RESERVED')";
  $sql .= " AND hold_until IS NOT NULL AND hold_until <= :now";
  if ($hasParkedAt) $sql .= " AND parked_at IS NULL";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([':now' => $nowSql]);
}

try {
  $in = json_input();
  if ($in === null) $in = $_POST ?: [];

  $booking_id = (int)($in['booking_id'] ?? 0);
  $user_id    = (int)($in['user_id'] ?? 0);
  $debug      = (int)($in['debug'] ?? 0);

  if ($booking_id <= 0 || $user_id <= 0) {
    json_error('missing booking_id or user_id', 422);
  }

  $tz = new DateTimeZone('Asia/Bangkok');
  $nowSql = (string)$pdo->query("SELECT NOW()")->fetchColumn();
  $now = new DateTime($nowSql, $tz);

  $pdo->beginTransaction();

  expire_stale_bookings($pdo, $now->format('Y-m-d H:i:s'));

  $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = :id LIMIT 1 FOR UPDATE");
  $stmt->execute([':id' => $booking_id]);
  $bk = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$bk) {
    $pdo->rollBack();
    json_error('booking not found', 404);
  }

  $owner_id = (int)$bk['user_id'];
  $slot_id  = (int)$bk['slot_id'];
  $status   = strtoupper(trim((string)($bk['status'] ?? '')));

  $isOwner = ($owner_id === $user_id);
  $isAdmin = false;

  try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :uid LIMIT 1");
    $stmt->execute([':uid' => $user_id]);
    $u = $stmt->fetch();
    if ($u) {
      $role = strtoupper(trim((string)($u['role'] ?? '')));
      $isAdmin = in_array($role, ['ADMIN', 'MANAGER'], true);
    }
  } catch (Throwable $e) {
  }

  if (!$isOwner && !$isAdmin) {
    $pdo->rollBack();
    json_error('permission denied', 403);
  }

  if ($status === 'COMPLETED') {
    $pdo->commit();
    json_ok([
      'booking_id'   => $booking_id,
      'status'       => 'COMPLETED',
      'noop'         => true,
      'final_amount' => isset($bk['final_amount']) ? (float)$bk['final_amount'] : (float)($bk['amount'] ?? 0),
      'message'      => 'already completed',
    ]);
  }

  if (is_terminal_status($status)) {
    $pdo->rollBack();
    json_error('รายการนี้ปิดไปแล้ว', 409);
  }

  $startDt = dt_or_null((string)($bk['start_time'] ?? ''), $tz);
  $parkedAtDt = table_has_column($pdo, 'bookings', 'parked_at')
    ? dt_or_null((string)($bk['parked_at'] ?? ''), $tz)
    : null;
  $endedAtDt = table_has_column($pdo, 'bookings', 'ended_at')
    ? dt_or_null((string)($bk['ended_at'] ?? ''), $tz)
    : null;
  $chargedAtDt = table_has_column($pdo, 'bookings', 'charged_at')
    ? dt_or_null((string)($bk['charged_at'] ?? ''), $tz)
    : null;

  if (!$startDt) {
    $pdo->rollBack();
    json_error('invalid booking start_time', 422);
  }

  if ($endedAtDt) {
    $pdo->rollBack();
    json_error('รายการนี้ถูกสิ้นสุดไปแล้ว', 409);
  }

  $hasStartedParking = ($parkedAtDt !== null) || in_array($status, ['PARKING', 'OCCUPIED'], true);
  if (!$hasStartedParking) {
    $pdo->rollBack();
    json_error('ยังไม่มีการจอดจริง จึงยังสิ้นสุดและชำระเงินไม่ได้', 409);
  }

  if ($now <= $startDt) {
    $pdo->rollBack();
    json_error('เวลาใช้งานยังไม่ถูกต้อง', 422);
  }

  $diffSec   = $now->getTimestamp() - $startDt->getTimestamp();
  $minsTotal = (int)max(1, ceil($diffSec / 60));
  $billHours = max(1, (int)ceil($minsTotal / 60));

  $reserveFee = isset($bk['reserve_fee']) ? (float)$bk['reserve_fee'] : $DEFAULT_RESERVE_FEE;
  if ($reserveFee < 0) $reserveFee = 0.0;

  $parkingCharge = round($billHours * $RATE_PER_HOUR, 2);
  $finalAmount   = round($reserveFee + $parkingCharge, 2);

  $reserveAlreadyCharged = ($chargedAtDt !== null);
  $payableNow = $reserveAlreadyCharged
    ? round(max(0, $finalAmount - $reserveFee), 2)
    : $finalAmount;

  $walletBefore = 0.0;
  $walletAfter  = 0.0;
  $userEmail = '';
  $lineTo = '';

  $stmt = $pdo->prepare("
    SELECT wallet, email, line_user_id, push_token
    FROM users
    WHERE id = :uid
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([':uid' => $owner_id]);
  $userRow = $stmt->fetch();

  if (!$userRow) {
    $pdo->rollBack();
    json_error('user not found', 404);
  }

  $walletBefore = (float)$userRow['wallet'];
  $userEmail    = (string)($userRow['email'] ?? '');

  $cand1 = trim((string)($userRow['line_user_id'] ?? ''));
  $cand2 = trim((string)($userRow['push_token'] ?? ''));
  if (looks_like_line_id($cand1)) $lineTo = $cand1;
  elseif (looks_like_line_id($cand2)) $lineTo = $cand2;

  if ($walletBefore < $payableNow) {
    $pdo->rollBack();
    json_error('ยอดเงินในวอลเล็ตไม่เพียงพอสำหรับการชำระเงิน', 422);
  }

  if ($payableNow > 0) {
    $stmt = $pdo->prepare("UPDATE users SET wallet = wallet - :amt WHERE id = :uid");
    $stmt->execute([
      ':amt' => $payableNow,
      ':uid' => $owner_id
    ]);
    $walletAfter = $walletBefore - $payableNow;

    if (table_exists($pdo, 'wallet_history')) {
      $note = $reserveAlreadyCharged
        ? "ชำระค่าจอดเพิ่มเติม {$payableNow} บาท หลังหักค่าจอง 30 บาทไปแล้ว"
        : "ชำระค่าจอดรวม {$finalAmount} บาท";

      $stmt = $pdo->prepare("
        INSERT INTO wallet_history (user_id, type, amount, note, ref, created_at)
        VALUES (:uid, 'parking_finish', :amt, :note, :ref, NOW())
      ");
      $stmt->execute([
        ':uid'  => $owner_id,
        ':amt'  => -$payableNow,
        ':note' => $note,
        ':ref'  => 'FINISH-' . $booking_id,
      ]);
    }
  } else {
    $walletAfter = $walletBefore;
  }

  $sets = [
    "status = 'COMPLETED'",
    "amount = :amount"
  ];
  $params = [
    ':amount' => $parkingCharge,
    ':id'     => $booking_id,
  ];

  if (table_has_column($pdo, 'bookings', 'ended_at')) {
    $sets[] = "ended_at = NOW()";
  }
  if (table_has_column($pdo, 'bookings', 'final_amount')) {
    $sets[] = "final_amount = :final_amount";
    $params[':final_amount'] = $finalAmount;
  }
  if (table_has_column($pdo, 'bookings', 'final_paid_at')) {
    $sets[] = "final_paid_at = NOW()";
  }
  if (table_has_column($pdo, 'bookings', 'updated_at')) {
    $sets[] = "updated_at = NOW()";
  }
  if (table_has_column($pdo, 'bookings', 'charged_at') && !$reserveAlreadyCharged) {
    $sets[] = "charged_at = NOW()";
  }

  $sqlUpdate = "UPDATE bookings SET " . implode(', ', $sets) . " WHERE id = :id";
  $stmt = $pdo->prepare($sqlUpdate);
  $stmt->execute($params);

  $pdo->commit();

  $slotLabel = get_slot_label($pdo, $slot_id);
  $startLabel = $startDt->format('d/m/Y H:i');
  $endLabel   = $now->format('d/m/Y H:i');

  $sentUser = ['ok' => false];
  $sentAdmin = ['ok' => false];

  try {
    $prefs = get_notify_prefs($pdo, $owner_id);

    $message = implode("\n", [
      '✅ สิ้นสุดการจอดและชำระเงินสำเร็จ',
      "Booking ID: #{$booking_id}",
      "ช่องจอด: {$slotLabel}",
      "เริ่มจอง: {$startLabel}",
      "สิ้นสุด: {$endLabel}",
      "จำนวนชั่วโมงคิดเงิน: {$billHours} ชม.",
      "ค่าจอง: " . number_format($reserveFee, 2) . " บาท",
      "ค่าจอด: " . number_format($parkingCharge, 2) . " บาท",
      "ยอดรวม: " . number_format($finalAmount, 2) . " บาท",
      "ยอดที่หักครั้งนี้: " . number_format($payableNow, 2) . " บาท",
    ]);

    if (!empty($prefs['enabled']) && $lineTo !== '') {
      $sentUser = line_push_raw([
        'to' => $lineTo,
        'messages' => [
          ['type' => 'text', 'text' => $message]
        ]
      ]);
    }

    if ($ADMIN_LINE_ID !== '' && looks_like_line_id($ADMIN_LINE_ID)) {
      $sentAdmin = line_push_raw([
        'to' => $ADMIN_LINE_ID,
        'messages' => [
          ['type' => 'text', 'text' => "[ADMIN]\n" . $message . "\nowner: " . ($userEmail ?: ('UID ' . $owner_id))]
        ]
      ]);
    }
  } catch (Throwable $e) {
    error_log('[AutoParkX] booking_finish notify error: ' . $e->getMessage());
  }

  $out = [
    'booking_id'      => $booking_id,
    'status'          => 'COMPLETED',
    'bill_hours'      => $billHours,
    'reserve_fee'     => $reserveFee,
    'parking_charge'  => $parkingCharge,
    'final_amount'    => $finalAmount,
    'payable_now'     => $payableNow,
    'wallet_before'   => $walletBefore,
    'wallet_after'    => $walletAfter,
    'reserve_charged' => $reserveAlreadyCharged,
    'ended_at'        => $now->format('Y-m-d H:i:s'),
    'message'         => 'สิ้นสุดการจอดและชำระเงินสำเร็จ',
  ];

  if ($debug === 1) {
    $out['line_debug'] = [
      'user'  => $sentUser,
      'admin' => $sentAdmin,
    ];
  }

  json_ok($out);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log('[AutoParkX] booking_finish error: ' . $e->getMessage());
  json_error('server error', 500);
}