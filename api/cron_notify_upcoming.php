<?php
/**
 * api/cron_notify_upcoming.php
 * เตือนล่วงหน้าก่อนเริ่มจอง (LINE Messaging API)
 *
 * จุดเด่นเวอร์ชันนี้:
 * - ไม่พึ่ง CREATE TABLE (กันสิทธิ์ DB ไม่พอแล้วล่ม 500)
 * - กันส่งซ้ำด้วยไฟล์ cache ในโฟลเดอร์ api (ล็อคไฟล์)
 * - ส่งแบบ text ผ่าน LINE pushMessage (เหมือน booking_create.php)
 *
 * ทดสอบ:
 *   https://autoparkx.com/api/cron_notify_upcoming.php?debug=1
 *
 * Cron ตัวอย่าง (ทุก 1 นาที):
 *   ทุก 1 นาที: /usr/bin/php -q /home/USER/public_html/api/cron_notify_upcoming.php >/dev/null 2>&1
 */

declare(strict_types=1);

/* ===== debug toggle ===== */
$DEBUG = (isset($_GET['debug']) && $_GET['debug'] == '1');
if ($DEBUG) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', '0');
}

/* ===== กัน db.php อ้าง REQUEST_METHOD ตอน CLI ===== */
if (!isset($_SERVER['REQUEST_METHOD'])) $_SERVER['REQUEST_METHOD'] = 'GET';

/* ===== โหลดฐานกลาง (มี $pdo + json_ok/json_error) ===== */
require __DIR__ . '/db.php';

/* ===== ใส่ token ของจริง (คัดจาก booking_create.php ของคุณ) ===== */
$LINE_CHANNEL_ACCESS_TOKEN = 'PUT_YOUR_LINE_CHANNEL_ACCESS_TOKEN_HERE';

/* ===== กติกาเตือน ===== */
$RULES = [
  ['key' => '30m', 'minutes' => 30],
  ['key' => '10m', 'minutes' => 10],
];

// หน้าต่างเวลา กัน cron ดีเลย์ (วินาที)
$WINDOW_SECONDS = 120;

// quiet hours ถ้าผู้ใช้เปิด quiet_night = true
$QUIET_FROM_HOUR = 22; // 22:00
$QUIET_TO_HOUR   = 7;  // 07:00

/* =========================================================
 * Helper: ตรวจตาราง/คอลัมน์
 * ========================================================= */
function table_exists(PDO $pdo, string $table): bool {
  try {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function detect_column(PDO $pdo, string $table, array $candidates): ?string {
  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    $cols = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $cols[strtolower((string)$r['Field'])] = true;
    }
    foreach ($candidates as $c) {
      if (isset($cols[strtolower($c)])) return $c;
    }
    return null;
  } catch (Throwable $e) {
    return null;
  }
}

/* =========================================================
 * Helper: prefs
 * ========================================================= */
function get_notify_prefs(PDO $pdo, int $user_id): array {
  $defaults = [
    'enabled'           => true,
    'booking_created'   => true,
    'booking_soon'      => true,
    'booking_cancelled' => true,
    'news'              => false,
    'quiet_night'       => false,
  ];

  if ($user_id <= 0) return $defaults;

  // ถ้าไม่มีตาราง prefs ก็คืน default
  if (!table_exists($pdo, 'user_notify_prefs')) return $defaults;

  try {
    $stmt = $pdo->prepare("
      SELECT enabled, booking_created, booking_soon,
             booking_cancelled, news, quiet_night
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

/* =========================================================
 * Helper: LINE id + quiet hour
 * ========================================================= */
function looks_like_line_id(string $s): bool {
  $s = trim($s);
  if ($s === '') return false;
  return (bool)preg_match('/^[UCR][0-9a-f]{32}$/i', $s);
}

function is_quiet_now(DateTime $now, int $fromHour, int $toHour): bool {
  $h = (int)$now->format('G'); // 0-23
  if ($fromHour > $toHour) { // ข้ามวัน
    return ($h >= $fromHour || $h < $toHour);
  }
  return ($h >= $fromHour && $h < $toHour);
}

/* =========================================================
 * Helper: ส่ง LINE push text (เหมือน booking_create.php)
 * ========================================================= */
function line_push_text(string $to, string $text, string $token): array {
  if ($token === '' || $to === '' || $text === '') {
    return ['ok'=>false,'http'=>0,'err'=>'missing token/to/text','resp'=>null];
  }

  $body = [
    'to' => $to,
    'messages' => [
      ['type' => 'text', 'text' => $text]
    ]
  ];

  $ch = curl_init('https://api.line.me/v2/bot/message/push');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $token,
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 12,
  ]);

  $resp = curl_exec($ch);
  $errNo = curl_errno($ch);
  $errTx = $errNo ? curl_error($ch) : '';
  $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $ok = ($errNo === 0 && $http >= 200 && $http < 300);
  if(!$ok){
    error_log("LINE push fail http={$http} err={$errNo} {$errTx} respei={$resp}");
  }
  return ['ok'=>$ok,'http'=>$http,'err'=>$errTx,'resp'=>$resp];
}

/* =========================================================
 * Helper: กันส่งซ้ำด้วยไฟล์ cache
 * ========================================================= */
function cache_path(): string {
  // อยู่ในโฟลเดอร์เดียวกับไฟล์นี้ (api/)
  return __DIR__ . '/.notify_cache.json';
}

/**
 * อ่าน cache: return [ "bookingId|key" => sentAtEpoch, ... ]
 */
function cache_read(string $path): array {
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path);
  if ($raw === false || $raw === '') return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

/**
 * เขียน cache แบบ atomic + lock
 */
function cache_write(string $path, array $data): bool {
  $tmp = $path . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE);
  if ($json === false) return false;

  $ok = @file_put_contents($tmp, $json, LOCK_EX);
  if ($ok === false) return false;

  return @rename($tmp, $path);
}

/**
 * prune record เก่าเกิน N วินาที
 */
function cache_prune(array $data, int $maxAgeSec): array {
  $now = time();
  foreach ($data as $k => $ts) {
    if (!is_int($ts)) {
      unset($data[$k]);
      continue;
    }
    if (($now - $ts) > $maxAgeSec) unset($data[$k]);
  }
  return $data;
}

/* =========================================================
 * MAIN
 * ========================================================= */
try {
  /** @var PDO $pdo */
  $pdo = $GLOBALS['pdo'];

  $tz  = new DateTimeZone('Asia/Bangkok');
  $now = new DateTime('now', $tz);

  // เลือกตาราง slots ให้ตรงกับโปรเจกต์ (คุณใช้ "slots" ใน booking_create.php)
  $tblBookings = 'bookings';
  $tblUsers    = 'users';
  $tblSlots    = table_exists($pdo, 'slots') ? 'slots' : (table_exists($pdo, 'parking_slots') ? 'parking_slots' : 'slots');

  // detect columns
  $colBookingId = detect_column($pdo, $tblBookings, ['id', 'booking_id']) ?? 'id';
  $colUserId    = detect_column($pdo, $tblBookings, ['user_id', 'uid']) ?? 'user_id';
  $colSlotId    = detect_column($pdo, $tblBookings, ['slot_id', 'parking_slot_id']) ?? 'slot_id';
  $colStart     = detect_column($pdo, $tblBookings, ['start_time', 'start_at', 'start_datetime']) ?? 'start_time';
  $colEnd       = detect_column($pdo, $tblBookings, ['end_time', 'end_at', 'end_datetime']) ?? 'end_time';
  $colStatus    = detect_column($pdo, $tblBookings, ['status', 'booking_status', 'state']); // อาจไม่มี

  $colSlotPk    = detect_column($pdo, $tblSlots, ['id','slot_id']) ?? 'id';
  $colSlotCode  = detect_column($pdo, $tblSlots, ['code','slot_code']) ?? 'code';
  $colSlotLevel = detect_column($pdo, $tblSlots, ['level','floor']) ?? 'level';

  // โหลด cache
  $cpath = cache_path();
  $cache = cache_read($cpath);
  $cache = cache_prune($cache, 3 * 24 * 3600); // เก็บ 3 วันพอ
  $cacheWritable = true;
  // test write permission แบบเบา ๆ
  if (!is_file($cpath)) {
    @file_put_contents($cpath, json_encode([], JSON_UNESCAPED_UNICODE));
  }
  if (!is_writable(dirname($cpath))) $cacheWritable = false;

  $totalFound = 0;
  $totalSent  = 0;
  $totalSkip  = 0;
  $totalFail  = 0;
  $details    = [];

  foreach ($RULES as $rule) {
    $key = (string)$rule['key'];
    $m   = (int)$rule['minutes'];

    $from = clone $now; $from->modify("+{$m} minutes");
    $to   = clone $from; $to->modify("+{$WINDOW_SECONDS} seconds");

    // เงื่อนไข status: เอาเฉพาะ active set ถ้ามีคอลัมน์ status
    $statusWhere = '';
    if ($colStatus !== null) {
      $ACTIVE_SET = "('ACTIVE','CONFIRMED','BOOKED','PENDING','RESERVED','OCCUPIED')";
      $statusWhere = "AND UPPER(b.`$colStatus`) IN $ACTIVE_SET";
    }

    $sql = "
      SELECT
        b.`$colBookingId` AS booking_id,
        b.`$colUserId`    AS user_id,
        b.`$colSlotId`    AS slot_id,
        b.`$colStart`     AS start_time,
        b.`$colEnd`       AS end_time,
        s.`$colSlotCode`  AS slot_code,
        s.`$colSlotLevel` AS slot_level
      FROM `$tblBookings` b
      LEFT JOIN `$tblSlots` s ON s.`$colSlotPk` = b.`$colSlotId`
      WHERE b.`$colStart` >= :from_dt
        AND b.`$colStart` <  :to_dt
        $statusWhere
      ORDER BY b.`$colStart` ASC
      LIMIT 200
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':from_dt' => $from->format('Y-m-d H:i:s'),
      ':to_dt'   => $to->format('Y-m-d H:i:s'),
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalFound += count($rows);

    foreach ($rows as $r) {
      $bookingId = (int)$r['booking_id'];
      $userId    = (int)$r['user_id'];

      // กันส่งซ้ำด้วย cache (ถ้าใช้ได้)
      $cacheKey = $bookingId . '|' . $key;
      if ($cacheWritable && isset($cache[$cacheKey])) {
        $totalSkip++;
        continue;
      }

      // prefs
      $prefs = get_notify_prefs($pdo, $userId);
      if (!$prefs['enabled'] || !$prefs['booking_soon']) {
        $totalSkip++;
        continue;
      }
      if ($prefs['quiet_night'] && is_quiet_now($now, $QUIET_FROM_HOUR, $QUIET_TO_HOUR)) {
        $totalSkip++;
        continue;
      }

      // ผู้รับ LINE
      $qU = $pdo->prepare("SELECT line_user_id, push_token FROM `$tblUsers` WHERE id = ? LIMIT 1");
      $qU->execute([$userId]);
      $u = $qU->fetch(PDO::FETCH_ASSOC) ?: [];

      $cand1 = trim((string)($u['line_user_id'] ?? ''));
      $cand2 = trim((string)($u['push_token'] ?? '')); // fallback เผื่อเคยเก็บผิดช่อง
      $toLine = looks_like_line_id($cand1) ? $cand1 : (looks_like_line_id($cand2) ? $cand2 : '');

      if ($toLine === '') {
        $totalFail++;
        if ($DEBUG) $details[] = ['booking_id'=>$bookingId,'rule'=>$key,'sent'=>false,'reason'=>'no_line_id'];
        continue;
      }

      // ข้อความ
      $startDT = new DateTime((string)$r['start_time'], $tz);
      $endDT   = new DateTime((string)$r['end_time'],   $tz);

      $slotCode  = trim((string)($r['slot_code'] ?? ''));
      $slotLevel = trim((string)($r['slot_level'] ?? ''));
      $slotLabel = $slotCode !== '' ? $slotCode : ('Slot #' . (int)$r['slot_id']);
      if ($slotLevel !== '') $slotLabel .= " (ชั้น {$slotLevel})";

      $startLabel = $startDT->format('d/m/Y H:i');
      $endLabel   = $endDT->format('H:i');

      $msg =
        "⏰ AutoParkX เตือนก่อนเริ่มจอง {$m} นาที\n" .
        "ช่อง: {$slotLabel}\n" .
        "เวลา: {$startLabel} - {$endLabel}\n" .
        "กรุณามาถึงก่อนเวลาเล็กน้อย 🙏\n" .
        "ดูรายการจอง: https://autoparkx.com/mybookings.html";

      // ส่ง
      $sent = line_push_text($toLine, $msg, $GLOBALS['LINE_CHANNEL_ACCESS_TOKEN']);

      if ($sent['ok']) {
        $totalSent++;
        if ($cacheWritable) {
          $cache[$cacheKey] = time();
        }
        if ($DEBUG) $details[] = ['booking_id'=>$bookingId,'rule'=>$key,'sent'=>true];
      } else {
        $totalFail++;
        if ($DEBUG) $details[] = ['booking_id'=>$bookingId,'rule'=>$key,'sent'=>false,'http'=>$sent['http']];
      }
    }
  }

  // เขียน cache กลับ
  $cacheSaved = false;
  if ($cacheWritable) {
    $cacheSaved = cache_write($cpath, $cache);
  }

  json_ok([
    'cron' => 'notify_upcoming',
    'now' => $now->format('Y-m-d H:i:s'),
    'rules' => $RULES,
    'window_seconds' => $WINDOW_SECONDS,
    'found' => $totalFound,
    'sent' => $totalSent,
    'skipped' => $totalSkip,
    'failed' => $totalFail,
    'cache' => [
      'path' => basename($cpath),
      'writable' => $cacheWritable,
      'saved' => $cacheSaved,
    ],
    'details' => $DEBUG ? $details : null,
  ]);

} catch (Throwable $e) {
  // กัน 500 แบบเงียบ: คืน JSON error
  json_error('cron error: ' . $e->getMessage(), 500);
}
