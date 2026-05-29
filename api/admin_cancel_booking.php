<?php
// api/admin_cancel_booking.php
// ยกเลิกการจองโดยแอดมิน + คืนเงินเข้าวอลเล็ต + ปรับช่องเป็น AVAILABLE

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
  function json_ok($arr = []) {
    echo json_encode(['ok' => true] + $arr, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if (!function_exists('json_input')) {
  function json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
  }
}

if (!function_exists('arr_get')) {
  function arr_get($arr, $k, $d = null) {
    return isset($arr[$k]) ? $arr[$k] : $d;
  }
}

function table_exists(PDO $pdo, string $table): bool {
  $stmt = $pdo->prepare("
    SELECT 1
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
    LIMIT 1
  ");
  $stmt->execute([$table]);
  return (bool)$stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $col): bool {
  $stmt = $pdo->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $stmt->execute([$table, $col]);
  return (bool)$stmt->fetchColumn();
}

function extract_amount(PDO $pdo, array $b): array {
  $cands = [
    'reserve_fee',
    'final_amount',
    'amount',
    'total_amount',
    'total_price',
    'price',
    'cost',
    'fee',
    'paid_amount',
    'charge',
    'payment_amount'
  ];

  foreach ($cands as $c) {
    if (isset($b[$c]) && $b[$c] !== '' && $b[$c] !== null) {
      $v = (float)$b[$c];
      if ($v > 0) return [$v, "bookings.$c"];
    }
  }

  $bookingId = (int)($b['id'] ?? 0);
  $userId = (int)($b['user_id'] ?? 0);

  if ($bookingId > 0 && table_exists($pdo, 'payments') && column_exists($pdo, 'payments', 'amount')) {
    $st = $pdo->prepare("
      SELECT amount
      FROM payments
      WHERE booking_id = ?
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute([$bookingId]);
    $v = $st->fetchColumn();

    if ($v !== false && $v !== null && (float)$v > 0) {
      return [(float)$v, 'payments.amount'];
    }
  }

  if (
    $userId > 0 &&
    table_exists($pdo, 'wallet_transactions') &&
    column_exists($pdo, 'wallet_transactions', 'amount') &&
    column_exists($pdo, 'wallet_transactions', 'type')
  ) {
    $st = $pdo->prepare("
      SELECT ABS(amount)
      FROM wallet_transactions
      WHERE user_id = ?
        AND UPPER(type) = 'PAY'
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute([$userId]);
    $v = $st->fetchColumn();

    if ($v !== false && $v !== null && (float)$v > 0) {
      return [(float)$v, 'wallet_transactions.amount'];
    }
  }

  if (
    $userId > 0 &&
    table_exists($pdo, 'wallet_history') &&
    column_exists($pdo, 'wallet_history', 'amount')
  ) {
    $st = $pdo->prepare("
      SELECT ABS(amount)
      FROM wallet_history
      WHERE user_id = ?
        AND amount < 0
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute([$userId]);
    $v = $st->fetchColumn();

    if ($v !== false && $v !== null && (float)$v > 0) {
      return [(float)$v, 'wallet_history.amount'];
    }
  }

  return [0.0, '(none)'];
}

function insert_flexible(PDO $pdo, string $table, array $map): bool {
  if (!table_exists($pdo, $table)) return false;

  $cols = [];
  $vals = [];
  $bind = [];

  foreach ($map as $c => $v) {
    if (column_exists($pdo, $table, $c)) {
      $cols[] = "`$c`";
      $vals[] = '?';
      $bind[] = $v;
    }
  }

  if (!$cols) return false;

  $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
  $pdo->prepare($sql)->execute($bind);

  return true;
}

$in = json_input() ?? [];
$in = array_merge($_POST ?? [], $in);

$bookingId  = (int)arr_get($in, 'booking_id', 0);
$slotIdIn   = (int)arr_get($in, 'slot_id', 0);
$reason     = trim((string)arr_get($in, 'reason', 'ยกเลิกโดยแอดมิน'));
$refundMode = strtoupper(trim((string)arr_get($in, 'refund_mode', 'FULL')));

if ($bookingId <= 0) json_error('booking_id is required', 422);
if ($reason === '') $reason = 'ยกเลิกโดยแอดมิน';
if (!in_array($refundMode, ['FULL', 'NONE'], true)) $refundMode = 'FULL';

$now = date('Y-m-d H:i:s');

try {
  $pdo->beginTransaction();

  $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
  $stmt->execute([$bookingId]);
  $b = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$b) {
    $pdo->rollBack();
    json_error('booking not found', 404);
  }

  $userId = (int)($b['user_id'] ?? 0);
  $slotId = (int)($slotIdIn ?: ($b['slot_id'] ?? 0));
  $status = strtoupper((string)($b['status'] ?? ''));

  [$amount, $amountCol] = extract_amount($pdo, $b);

  $alreadyRefunded = false;

  if (table_exists($pdo, 'wallet_history')) {
    if (column_exists($pdo, 'wallet_history', 'ref')) {
      $st = $pdo->prepare("
        SELECT id
        FROM wallet_history
        WHERE ref = ?
        LIMIT 1
      ");
      $st->execute(['CANCEL-REFUND-' . $bookingId]);
      $alreadyRefunded = (bool)$st->fetchColumn();
    }

    if (!$alreadyRefunded && column_exists($pdo, 'wallet_history', 'ref_id')) {
      $st = $pdo->prepare("
        SELECT id
        FROM wallet_history
        WHERE ref_id = ?
        LIMIT 1
      ");
      $st->execute([$bookingId]);
      $alreadyRefunded = (bool)$st->fetchColumn();
    }
  }

  if (
    !$alreadyRefunded &&
    table_exists($pdo, 'wallet_transactions') &&
    column_exists($pdo, 'wallet_transactions', 'note') &&
    column_exists($pdo, 'wallet_transactions', 'type')
  ) {
    $st = $pdo->prepare("
      SELECT id
      FROM wallet_transactions
      WHERE user_id = ?
        AND UPPER(type) = 'REFUND'
        AND note LIKE ?
      LIMIT 1
    ");
    $st->execute([$userId, '%booking #' . $bookingId . '%']);
    $alreadyRefunded = (bool)$st->fetchColumn();
  }

  $refund = 0.0;

  if ($refundMode === 'FULL' && !$alreadyRefunded) {
    $refund = max(0.0, (float)$amount);
  }

  $didUsers = false;
  $didWallets = false;
  $didWalletHistory = false;
  $didWalletTransactions = false;

  if ($refund > 0 && $userId > 0) {
    if (table_exists($pdo, 'users')) {
      foreach (['wallet_balance', 'balance', 'wallet'] as $col) {
        if (column_exists($pdo, 'users', $col)) {
          $pdo->prepare("UPDATE users SET `$col` = COALESCE(`$col`, 0) + ? WHERE id = ?")
              ->execute([$refund, $userId]);
          $didUsers = true;
          break;
        }
      }
    }

    if (
      table_exists($pdo, 'wallets') &&
      column_exists($pdo, 'wallets', 'user_id') &&
      column_exists($pdo, 'wallets', 'balance')
    ) {
      $upd = $pdo->prepare("UPDATE wallets SET balance = COALESCE(balance, 0) + ? WHERE user_id = ?");
      $upd->execute([$refund, $userId]);

      if ($upd->rowCount() === 0) {
        $pdo->prepare("INSERT INTO wallets(user_id, balance) VALUES(?, ?)")
            ->execute([$userId, $refund]);
      }

      $didWallets = true;
    }

    $didWalletTransactions = insert_flexible($pdo, 'wallet_transactions', [
      'user_id' => $userId,
      'type' => 'REFUND',
      'amount' => $refund,
      'note' => "Admin refund booking #{$bookingId}: {$reason}",
      'created_at' => $now,
    ]);

    $didWalletHistory = insert_flexible($pdo, 'wallet_history', [
      'user_id' => $userId,
      'type' => 'refund',
      'amount' => $refund,
      'note' => "คืนเงินจากการยกเลิก booking #{$bookingId}: {$reason}",
      'ref' => 'CANCEL-REFUND-' . $bookingId,
      'created_at' => $now,
      'ref_type' => 'BOOKING_REFUND',
      'ref_id' => $bookingId,
    ]);
  }

  $set = ["status = 'CANCELLED'"];
  $bind = [];

  if (column_exists($pdo, 'bookings', 'canceled_at')) {
    $set[] = "canceled_at = ?";
    $bind[] = $now;
  }

  if (column_exists($pdo, 'bookings', 'cancel_reason')) {
    $set[] = "cancel_reason = ?";
    $bind[] = $reason;
  }

  if (column_exists($pdo, 'bookings', 'updated_at')) {
    $set[] = "updated_at = ?";
    $bind[] = $now;
  }

  if (column_exists($pdo, 'bookings', 'refunded_amount')) {
    $set[] = "refunded_amount = ?";
    $bind[] = $refund;
  }

  if (column_exists($pdo, 'bookings', 'refund_mode')) {
    $set[] = "refund_mode = ?";
    $bind[] = $refundMode;
  }

  $bind[] = $bookingId;

  $pdo->prepare("UPDATE bookings SET " . implode(', ', $set) . " WHERE id = ?")
      ->execute($bind);

  if (
    $slotId > 0 &&
    table_exists($pdo, 'parking_slots') &&
    column_exists($pdo, 'parking_slots', 'status')
  ) {
    if (column_exists($pdo, 'parking_slots', 'last_update')) {
      $pdo->prepare("UPDATE parking_slots SET status = 'AVAILABLE', last_update = NOW() WHERE id = ?")
          ->execute([$slotId]);
    } else {
      $pdo->prepare("UPDATE parking_slots SET status = 'AVAILABLE' WHERE id = ?")
          ->execute([$slotId]);
    }
  }

  $pdo->commit();

  json_ok([
    'message' => 'cancelled',
    'booking_id' => $bookingId,
    'slot_id' => $slotId,
    'refunded' => $refund,
    'already_refunded' => $alreadyRefunded,
    'debug' => [
      'user_id' => $userId,
      'amount' => $amount,
      'amount_col' => $amountCol,
      'refund_mode' => $refundMode,
      'booking_status_before' => $status,
      'did_update_users' => $didUsers,
      'did_update_wallets' => $didWallets,
      'wallet_history_inserted' => $didWalletHistory,
      'wallet_transactions_inserted' => $didWalletTransactions
    ]
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  json_error('server error: ' . $e->getMessage(), 500);
}