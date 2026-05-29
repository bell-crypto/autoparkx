<?php
// api/line_webhook.php
// LINE Webhook สำหรับ AutoParkX
// - ผูกบัญชีด้วย LINK email
// - เมนู / HELP / ยอด / ช่องว่าง / จองล่าสุด / ใบเสร็จ
// - แจ้งปัญหาผ่าน LINE แล้วไปโผล่หน้า Admin · support_history.html
// - แก้ให้บันทึก LINE userId ลง users.line_user_id แล้ว

declare(strict_types=1);

require __DIR__ . '/db.php'; // มี $pdo + timezone Asia/Bangkok

// ===== CONFIG =====
$LINE_CHANNEL_ACCESS_TOKEN = (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');
$RATE_PER_HOUR = 30;
$ACTIVE_STATUS_SET = "('ACTIVE','CONFIRMED','BOOKED','PENDING','RESERVED','OCCUPIED')";

$APP_BASE_URL = 'https://autoparkx.com';
$URL_WALLET   = $APP_BASE_URL . '/wallet.html';
$URL_ACCOUNT  = $APP_BASE_URL . '/account.html';
$URL_BOOKING  = $APP_BASE_URL . '/booking.html';
$URL_MYBOOK   = $APP_BASE_URL . '/mybookings.html';
$URL_SUPPORT  = $APP_BASE_URL . '/support_history.html';

// ใส่ LINE userId / groupId ของแอดมิน ถ้าต้องการให้บอท push แจ้งปัญหาไปหาแอดมิน
$ADMIN_LINE_ID = ''; // เช่น Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

// ===== LOG RAW =====
$raw = file_get_contents('php://input') ?: '';
file_put_contents(
  __DIR__ . '/line_webhook.log',
  "===== " . date('Y-m-d H:i:s') . " =====\n$raw\n",
  FILE_APPEND
);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo "Method Not Allowed";
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['events'])) {
  http_response_code(400);
  echo "Bad Request";
  exit;
}

/* =========================
   HELPERS: HTTP LINE API
========================= */

function line_post_json(string $url, array $body): array {
  global $LINE_CHANNEL_ACCESS_TOKEN;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $LINE_CHANNEL_ACCESS_TOKEN,
    ],
    CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 15,
  ]);

  $res  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return [
    'ok'   => ($res !== false && $code >= 200 && $code < 300),
    'code' => $code,
    'raw'  => $res ?: '',
    'err'  => $err ?: '',
  ];
}

function reply_text(string $replyToken, string $text): void {
  if (!$replyToken) return;

  $url  = 'https://api.line.me/v2/bot/message/reply';
  $body = [
    'replyToken' => $replyToken,
    'messages'   => [
      ['type' => 'text', 'text' => $text],
    ],
  ];

  $r = line_post_json($url, $body);
  if (!$r['ok']) {
    error_log('LINE reply_text error: ' . $r['code'] . ' ' . $r['raw'] . ' ' . $r['err']);
  }
}

function reply_raw(string $replyToken, array $messages): void {
  if (!$replyToken) return;

  $url  = 'https://api.line.me/v2/bot/message/reply';
  $body = [
    'replyToken' => $replyToken,
    'messages'   => $messages,
  ];

  $r = line_post_json($url, $body);
  if (!$r['ok']) {
    error_log('LINE reply_raw error: ' . $r['code'] . ' ' . $r['raw'] . ' ' . $r['err']);
  }
}

function push_raw(string $to, array $messages): void {
  if (!$to) return;

  $url  = 'https://api.line.me/v2/bot/message/push';
  $body = [
    'to'       => $to,
    'messages' => $messages,
  ];

  $r = line_post_json($url, $body);
  if (!$r['ok']) {
    error_log('LINE push_raw error: ' . $r['code'] . ' ' . $r['raw'] . ' ' . $r['err']);
  }
}

function norm(string $t): string {
  $t = trim($t);
  return preg_replace('/\s+/u', ' ', $t);
}

function fmt_dt(?string $dt): string {
  if (!$dt) return '-';
  $ts = strtotime($dt);
  if ($ts === false) return (string)$dt;
  return date('d/m/Y H:i', $ts);
}

function money(float $n): string {
  return number_format($n, 2) . " ฿";
}

/* =========================
   HELPERS: DB
========================= */

function getUserByLINE(PDO $pdo, string $lineUserId): ?array {
  $st = $pdo->prepare("SELECT id, email, wallet FROM users WHERE line_user_id = :u LIMIT 1");
  $st->execute([':u' => $lineUserId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function getLatestActiveBooking(PDO $pdo, int $uid, string $ACTIVE_STATUS_SET): ?array {
  $sql = "SELECT * FROM bookings WHERE user_id = :u AND status IN $ACTIVE_STATUS_SET ORDER BY start_time DESC LIMIT 1";
  $st  = $pdo->prepare($sql);
  $st->execute([':u' => $uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function getLatestBookingAnyStatus(PDO $pdo, int $uid): ?array {
  $sql = "SELECT * FROM bookings WHERE user_id = :u ORDER BY start_time DESC LIMIT 1";
  $st  = $pdo->prepare($sql);
  $st->execute([':u' => $uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function getSlotById(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare("SELECT * FROM slots WHERE id = :id LIMIT 1");
  $st->execute([':id' => $id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function getSlotCode(?array $slot = null): string {
  if (!$slot) return 'ไม่ทราบช่อง';
  if (!empty($slot['slot_code'])) return (string)$slot['slot_code'];
  if (!empty($slot['code'])) return (string)$slot['code'];
  return '#' . ($slot['id'] ?? '?');
}

/* =========================
   FLEX BUILDER
========================= */

function flex_button(string $label, string $uri, string $style = 'primary'): array {
  return [
    'type'   => 'button',
    'style'  => $style,
    'height' => 'sm',
    'action' => [
      'type'  => 'uri',
      'label' => $label,
      'uri'   => $uri,
    ]
  ];
}

function flex_kv_row(string $k, string $v): array {
  return [
    'type' => 'box',
    'layout' => 'baseline',
    'spacing' => 'sm',
    'contents' => [
      [
        'type' => 'text',
        'text' => $k,
        'size' => 'sm',
        'color' => '#6b7280',
        'flex' => 3,
        'wrap' => true
      ],
      [
        'type' => 'text',
        'text' => $v,
        'size' => 'sm',
        'color' => '#111827',
        'flex' => 6,
        'wrap' => true
      ],
    ],
  ];
}

function flex_card(string $title, string $subtitle, array $rows = [], array $buttons = [], string $badgeText = ''): array {
  $headerContents = [
    [
      'type' => 'text',
      'text' => $title,
      'weight' => 'bold',
      'size' => 'md',
      'color' => '#ffffff',
      'wrap' => true,
    ],
    [
      'type' => 'text',
      'text' => $subtitle,
      'size' => 'xs',
      'color' => '#dbeafe',
      'wrap' => true,
      'margin' => 'sm',
    ],
  ];

  if ($badgeText !== '') {
    $headerContents[] = [
      'type' => 'text',
      'text' => $badgeText,
      'size' => 'xs',
      'color' => '#dbeafe',
      'wrap' => true,
      'margin' => 'sm',
    ];
  }

  $bodyContents = [];
  if (!empty($rows)) {
    $bodyContents[] = [
      'type' => 'text',
      'text' => 'สรุป',
      'weight' => 'bold',
      'size' => 'sm',
      'color' => '#111827',
    ];
    $bodyContents[] = [
      'type' => 'separator',
      'margin' => 'md'
    ];
    $bodyContents[] = [
      'type' => 'box',
      'layout' => 'vertical',
      'margin' => 'md',
      'spacing' => 'sm',
      'contents' => $rows
    ];
  }

  $footerContents = [];
  if (!empty($buttons)) {
    $footerContents = $buttons;
  }

  return [
    'type' => 'flex',
    'altText' => $title,
    'contents' => [
      'type' => 'bubble',
      'size' => 'mega',
      'styles' => [
        'header' => ['backgroundColor' => '#0a84ff'],
        'body'   => ['backgroundColor' => '#ffffff'],
        'footer' => ['backgroundColor' => '#ffffff'],
      ],
      'header' => [
        'type' => 'box',
        'layout' => 'vertical',
        'spacing' => 'sm',
        'contents' => $headerContents,
      ],
      'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'spacing' => 'md',
        'contents' => $bodyContents ?: [[
          'type' => 'text',
          'text' => ' ',
          'size' => 'xs',
          'color' => '#ffffff'
        ]],
      ],
      'footer' => [
        'type' => 'box',
        'layout' => 'vertical',
        'spacing' => 'sm',
        'contents' => $footerContents ?: [[
          'type' => 'text',
          'text' => 'AutoParkX • Notify',
          'size' => 'xs',
          'color' => '#9ca3af',
          'align' => 'center'
        ]]
      ],
    ],
  ];
}

function flex_menu_card(): array {
  global $URL_WALLET, $URL_MYBOOK;

  $rows = [
    flex_kv_row('คำสั่ง', 'เมนู / help'),
    flex_kv_row('ผูกบัญชี', 'LINK yourname@gmail.com'),
    flex_kv_row('ยอด', 'พิมพ์: ยอด'),
    flex_kv_row('จองล่าสุด', 'พิมพ์: จองล่าสุด'),
    flex_kv_row('ใบเสร็จ', 'พิมพ์: ใบเสร็จ'),
    flex_kv_row('ช่องว่าง', 'พิมพ์: ช่องว่าง'),
    flex_kv_row('แจ้งปัญหา', 'พิมพ์: แจ้งปัญหา ข้อความ'),
  ];

  $btns = [
    flex_button('ไปหน้า Wallet/บัญชี', $URL_WALLET, 'primary'),
    flex_button('ดูประวัติการจอง', $URL_MYBOOK, 'secondary'),
  ];

  return flex_card(
    'เมนู AutoParkX',
    'พิมพ์คำสั่ง หรือกดปุ่มลัดด้านล่างได้เลย',
    $rows,
    $btns,
    'AutoParkX Notify'
  );
}

function flex_help_card(): array {
  global $APP_BASE_URL, $URL_ACCOUNT;

  $rows = [
    flex_kv_row('1) สมัคร/ล็อกอิน', $APP_BASE_URL),
    flex_kv_row('2) ผูกบัญชี', 'พิมพ์: LINK อีเมลที่ใช้สมัคร'),
    flex_kv_row('ตัวอย่าง', 'LINK yourname@gmail.com'),
    flex_kv_row('หลังผูกสำเร็จ', 'จะได้รับแจ้งเตือนการจอง/ยกเลิก/เติมเงิน'),
  ];

  $btns = [
    flex_button('ไปหน้า Account', $URL_ACCOUNT, 'primary'),
    flex_button('ดูเมนูทั้งหมด', $APP_BASE_URL, 'secondary'),
  ];

  return flex_card(
    'คู่มือการผูกบัญชี',
    'ทำครั้งเดียว แล้วรับแจ้งเตือนผ่าน LINE ได้เลย',
    $rows,
    $btns,
    'Help'
  );
}

function flex_link_success_card(string $email): array {
  global $URL_WALLET, $URL_MYBOOK;

  $rows = [
    flex_kv_row('สถานะ', 'เชื่อมบัญชีเรียบร้อย ✅'),
    flex_kv_row('อีเมล', $email),
    flex_kv_row('ต่อไปนี้จะได้รับ', 'แจ้งเตือนการจอง / ยกเลิก / เติมเงิน'),
    flex_kv_row('คำสั่งลัด', 'พิมพ์ “เมนู”'),
  ];

  $btns = [
    flex_button('ไปหน้า Wallet/บัญชี', $URL_WALLET, 'primary'),
    flex_button('ดูประวัติการจอง', $URL_MYBOOK, 'secondary'),
  ];

  return flex_card(
    'ผูกบัญชีสำเร็จแล้ว',
    'พร้อมรับการแจ้งเตือนจาก AutoParkX',
    $rows,
    $btns,
    'Linked'
  );
}

function flex_wallet_card(string $email, float $wallet): array {
  global $URL_WALLET;

  $rows = [
    flex_kv_row('อีเมล', $email),
    flex_kv_row('ยอดคงเหลือ', money($wallet)),
  ];

  $btns = [
    flex_button('ไปหน้า Wallet/บัญชี', $URL_WALLET, 'primary'),
  ];

  return flex_card('ยอด e-Wallet', 'ตรวจสอบยอดเงินของคุณ', $rows, $btns, 'Wallet');
}

function flex_booking_status_card(array $booking, string $slotCode, string $remainText): array {
  global $URL_MYBOOK;

  $start  = fmt_dt($booking['start_time'] ?? null);
  $end    = fmt_dt($booking['end_time'] ?? null);
  $status = (string)($booking['status'] ?? '-');

  $rows = [
    flex_kv_row('ช่องจอด', $slotCode),
    flex_kv_row('เวลา', $start . ' - ' . $end),
    flex_kv_row('สถานะ', $status),
    flex_kv_row('เหลือเวลา', $remainText),
  ];

  $btns = [
    flex_button('ดูประวัติการจอง', $URL_MYBOOK, 'primary'),
  ];

  return flex_card('สถานะการจองล่าสุด', 'ข้อมูลการจองที่กำลังใช้งาน', $rows, $btns, 'Booking');
}

function flex_receipt_card(array $booking, string $slotCode, float $price): array {
  global $URL_MYBOOK;

  $start  = fmt_dt($booking['start_time'] ?? null);
  $end    = fmt_dt($booking['end_time'] ?? null);
  $status = (string)($booking['status'] ?? '-');

  $rows = [
    flex_kv_row('ช่องจอด', $slotCode),
    flex_kv_row('วันเวลา', $start . ' - ' . $end),
    flex_kv_row('สถานะ', $status),
    flex_kv_row('ยอด (ประมาณ)', money($price)),
  ];

  $btns = [
    flex_button('ดูรายละเอียดในเว็บ', $URL_MYBOOK, 'primary'),
  ];

  return flex_card('ใบเสร็จล่าสุด', 'สรุปรายการล่าสุดของคุณ', $rows, $btns, 'Receipt');
}

function flex_issue_user_ack_card(int $issueId, string $detail): array {
  global $URL_SUPPORT;

  $rows = [
    flex_kv_row('หมายเลขเคส', $issueId > 0 ? "#{$issueId}" : '-'),
    flex_kv_row('สถานะ', 'รับเรื่องแล้ว ✅'),
    flex_kv_row('รายละเอียด', $detail),
  ];

  $btns = [
    flex_button('ประวัติการแจ้งปัญหา', $URL_SUPPORT . ($issueId > 0 ? ('?id=' . $issueId) : ''), 'secondary'),
  ];

  return flex_card('รับเรื่องแจ้งปัญหาแล้ว', 'ทีมงานจะตรวจสอบและอัปเดตให้เร็วที่สุด', $rows, $btns, 'Issue');
}

function flex_issue_admin_card(int $issueId, string $detail, string $email, string $lineUID): array {
  global $URL_SUPPORT;

  $rows = [
    flex_kv_row('เคส', $issueId > 0 ? "#{$issueId}" : '-'),
    flex_kv_row('ผู้ใช้', $email !== '' ? $email : '(ยังไม่ LINK)'),
    flex_kv_row('LINE UID', $lineUID),
    flex_kv_row('รายละเอียด', $detail),
  ];

  $btns = [
    flex_button('เปิดหน้า Support History', $URL_SUPPORT . ($issueId > 0 ? ('?id=' . $issueId) : ''), 'primary'),
  ];

  return flex_card('📩 แจ้งปัญหาใหม่', 'AutoParkX • Admin Notify', $rows, $btns, 'NEW ISSUE');
}

/* =========================
   MAIN LOOP
========================= */

foreach ($data['events'] as $ev) {
  try {
    $type       = $ev['type'] ?? '';
    $replyToken = $ev['replyToken'] ?? '';
    $source     = $ev['source'] ?? [];
    $lineUID    = $source['userId'] ?? '';

    if (!$lineUID) {
      continue;
    }

    // unfollow -> ล้าง line_user_id
    if ($type === 'unfollow') {
      try {
        $pdo->prepare("UPDATE users SET line_user_id = NULL WHERE line_user_id = :t")
          ->execute([':t' => $lineUID]);
      } catch (Throwable $e) {
        error_log('unfollow clear line_user_id error: ' . $e->getMessage());
      }
      continue;
    }

    // follow ครั้งแรก
    if ($type === 'follow') {
      reply_raw($replyToken, [
        [
          'type' => 'text',
          'text' =>
            "สวัสดีจาก AutoParkX 👋\n" .
            "บัญชีนี้ใช้สำหรับแจ้งเตือนการจอง / ยกเลิก / เติมเงินวอลเล็ตของคุณ\n\n" .
            "เชื่อมบัญชี: พิมพ์\nLINK อีเมลที่ใช้สมัครในเว็บ\n" .
            "ตัวอย่าง: LINK yourname@gmail.com\n\n" .
            "พิมพ์ “เมนู” เพื่อดูคำสั่งทั้งหมด"
        ],
        flex_help_card(),
      ]);
      continue;
    }

    if (($ev['message']['type'] ?? '') !== 'text') {
      continue;
    }

    $text = norm((string)$ev['message']['text']);
    $low  = mb_strtolower($text, 'UTF-8');

    /* ===== 1) แจ้งปัญหา ===== */
    if (preg_match('/^แจ้งปัญหา(.*)$/u', $text, $m)) {
      $detail = trim($m[1] ?? '');
      if ($detail === '') $detail = '(ไม่มีรายละเอียดเพิ่มเติม)';

      $issueId = 0;
      $email = '';
      $uid = null;

      try {
        $user  = getUserByLINE($pdo, $lineUID);
        $uid   = $user ? (int)$user['id'] : null;
        $email = $user ? (string)$user['email'] : '';

        $stmt = $pdo->prepare("
          INSERT INTO parking_issues (user_id, title, detail, status, created_at)
          VALUES (:uid, :title, :detail, 'NEW', NOW())
        ");
        $stmt->execute([
          ':uid'    => $uid,
          ':title'  => 'แจ้งปัญหาจาก LINE',
          ':detail' => $detail,
        ]);

        $issueId = (int)$pdo->lastInsertId();
      } catch (Throwable $e) {
        error_log('line_webhook issues insert error: ' . $e->getMessage());
      }

      reply_raw($replyToken, [
        [
          'type' => 'text',
          'text' =>
            "รับเรื่องแจ้งปัญหาเรียบร้อยแล้วครับ 🙏\n" .
            "หมายเลขเคส: #" . ($issueId > 0 ? $issueId : '-') . "\n" .
            "รายละเอียด:\n" . $detail . "\n\n" .
            "ทีมงานจะตรวจสอบและอัปเดตสถานะให้เร็วที่สุด"
        ],
        flex_issue_user_ack_card($issueId, $detail),
      ]);

      if (!empty($GLOBALS['ADMIN_LINE_ID'])) {
        $adminLink = $GLOBALS['URL_SUPPORT'] . ($issueId > 0 ? ('?id=' . $issueId) : '');

        push_raw($GLOBALS['ADMIN_LINE_ID'], [
          flex_issue_admin_card($issueId, $detail, $email, $lineUID),
          [
            'type' => 'text',
            'text' =>
              "ลิงก์เปิดหน้าแอดมิน:\n" . $adminLink . "\n\n" .
              "ถ้าเปิดใน LINE แล้วไม่เข้า ให้กด (…) แล้วเลือกเปิดใน Chrome/Safari"
          ]
        ]);
      }

      continue;
    }

    /* ===== 2) เมนู ===== */
    if (preg_match('/^(เมนู|menu)$/iu', $text)) {
      reply_raw($replyToken, [flex_menu_card()]);
      continue;
    }

    /* ===== 3) HELP ===== */
    if (preg_match('/^(help|ช่วยเหลือ)$/iu', $text)) {
      reply_raw($replyToken, [flex_help_card()]);
      continue;
    }

    /* ===== 4) LINK email ===== */
    if (preg_match('/^link\s+(.+@.+)$/i', $text, $m)) {
      $email = trim($m[1]);

      try {
        file_put_contents(
          __DIR__ . '/line_link_debug.log',
          date('Y-m-d H:i:s') . " | lineUID={$lineUID} | email={$email}\n",
          FILE_APPEND
        );

        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE LOWER(email) = LOWER(:em) LIMIT 1");
        $stmt->execute([':em' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
          reply_text(
            $replyToken,
            "ไม่พบบัญชีอีเมลนี้ในระบบ AutoParkX ❌\n" .
            "กรุณาตรวจสอบว่าใช้อีเมลเดียวกับที่ใช้สมัครในเว็บ\n\n" .
            "ตัวอย่าง:\nLINK yourname@gmail.com"
          );
          continue;
        }

        $upd = $pdo->prepare("UPDATE users SET line_user_id = :line_uid WHERE id = :id");
        $ok = $upd->execute([
          ':line_uid' => $lineUID,
          ':id'       => (int)$user['id'],
        ]);

        file_put_contents(
          __DIR__ . '/line_link_debug.log',
          date('Y-m-d H:i:s') . " | update_ok=" . ($ok ? '1' : '0') . " | user_id=" . (int)$user['id'] . "\n",
          FILE_APPEND
        );

        reply_raw($replyToken, [
          flex_link_success_card((string)$user['email']),
        ]);
      } catch (Throwable $e) {
        error_log('LINK error: ' . $e->getMessage());
        reply_text($replyToken, "เกิดข้อผิดพลาดระหว่างเชื่อมบัญชีครับ ลองใหม่อีกครั้ง");
      }

      continue;
    }

    /* ===== 5) เช็คยอดวอลเล็ต ===== */
    if (preg_match('/^(ยอด|เช็คยอด)$/u', $low)) {
      $user = getUserByLINE($pdo, $lineUID);
      if (!$user) {
        reply_raw($replyToken, [
          [
            'type' => 'text',
            'text' => "ยังไม่ได้ผูกบัญชี LINE กับ AutoParkX ❌\nพิมพ์: LINK อีเมลที่ใช้สมัครบนเว็บ"
          ],
          flex_help_card(),
        ]);
        continue;
      }

      reply_raw($replyToken, [
        flex_wallet_card((string)$user['email'], (float)$user['wallet'])
      ]);
      continue;
    }

    /* ===== 6) วิธีจอง ===== */
    if ($low === 'วิธีจอง') {
      reply_raw($replyToken, [
        [
          'type' => 'text',
          'text' =>
            "วิธีจอง AutoParkX 🚗\n\n" .
            "1) เข้าเว็บ: autoparkx.com แล้วเข้าสู่ระบบ\n" .
            "2) เลือกเมนู “จองที่จอด”\n" .
            "3) เลือกช่วงวัน-เวลาที่ต้องการ\n" .
            "4) เลือกช่องจอดที่ว่าง แล้วกดยืนยัน\n" .
            "5) ระบบตัดเงินจาก e-Wallet และแจ้งเตือนผ่าน LINE นี้"
        ],
        flex_menu_card(),
      ]);
      continue;
    }

    /* ===== 7) ติดต่อแอดมิน ===== */
    if ($low === 'ติดต่อแอดมิน') {
      reply_text(
        $replyToken,
        "ติดต่อแอดมิน AutoParkX 👨‍💻\n\n" .
        "• LINE: @autoparkx\n" .
        "• หรือแจ้งปัญหาผ่านคำสั่ง: แจ้งปัญหา ข้อความ"
      );
      continue;
    }

    /* ===== 8) ใบเสร็จล่าสุด ===== */
    if (preg_match('/^(ใบเสร็จ|receipt)$/u', $low)) {
      $user = getUserByLINE($pdo, $lineUID);
      if (!$user) {
        reply_raw($replyToken, [
          ['type' => 'text', 'text' => "ยังไม่ได้ผูกบัญชี LINE กับ AutoParkX ❌"],
          flex_help_card(),
        ]);
        continue;
      }

      $booking = getLatestBookingAnyStatus($pdo, (int)$user['id']);
      if (!$booking) {
        reply_text($replyToken, "ยังไม่มีประวัติการจองในระบบของคุณเลยครับ 💤");
        continue;
      }

      $slot  = !empty($booking['slot_id']) ? getSlotById($pdo, (int)$booking['slot_id']) : null;
      $code  = getSlotCode($slot);
      $price = 0.0;

      if (isset($booking['total_price'])) {
        $price = (float)$booking['total_price'];
      } elseif (isset($booking['price'])) {
        $price = (float)$booking['price'];
      } elseif (isset($booking['amount'])) {
        $price = (float)$booking['amount'];
      } else {
        $s = new DateTime($booking['start_time'] ?? 'now');
        $e = new DateTime($booking['end_time'] ?? 'now');
        $mins  = max(1, (int)round(($e->getTimestamp() - $s->getTimestamp()) / 60));
        $hours = (int)ceil($mins / 60);
        $price = $hours * (float)$GLOBALS['RATE_PER_HOUR'];
      }

      reply_raw($replyToken, [
        flex_receipt_card($booking, $code, $price),
      ]);
      continue;
    }

    /* ===== 9) ช่องว่าง / สถานะช่อง ===== */
    if (preg_match('/(ช่องว่าง|สถานะช่อง|ที่ว่าง)/u', $low)) {
      try {
        $rows = $pdo->query("SELECT * FROM slots ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
          reply_text($replyToken, "ยังไม่มีข้อมูลช่องจอดในระบบเลยครับ 😅");
          continue;
        }

        $free = [];
        foreach ($rows as $r) {
          $st = strtoupper((string)($r['status'] ?? ''));
          if (in_array($st, ['FREE', 'AVAILABLE', 'EMPTY'], true)) {
            $free[] = getSlotCode($r);
          }
        }

        $msg  = "สถานะช่องจอดปัจจุบัน 🅿️\n\n";
        $msg .= $free ? ("ช่องว่าง: " . implode(", ", $free) . "\n\n") : "ตอนนี้ไม่มีช่องว่างในระบบ 😥\n\n";
        $msg .= "จำนวนช่องทั้งหมด: " . count($rows) . " ช่อง";

        reply_raw($replyToken, [
          ['type' => 'text', 'text' => $msg],
          flex_menu_card(),
        ]);
      } catch (Throwable $e) {
        error_log('slots status error: ' . $e->getMessage());
        reply_text($replyToken, "ไม่สามารถดึงสถานะช่องจอดได้ในขณะนี้ครับ 😢");
      }
      continue;
    }

    /* ===== 10) จองล่าสุด / สถานะจอง ===== */
    if (preg_match('/(จองล่าสุด|สถานะจอง|การจองล่าสุด)/u', $low)) {
      $user = getUserByLINE($pdo, $lineUID);
      if (!$user) {
        reply_raw($replyToken, [
          ['type' => 'text', 'text' => "ยังไม่ได้ผูกบัญชี LINE กับ AutoParkX ❌"],
          flex_help_card(),
        ]);
        continue;
      }

      $booking = getLatestActiveBooking($pdo, (int)$user['id'], $ACTIVE_STATUS_SET);
      if (!$booking) {
        reply_text($replyToken, "ตอนนี้คุณไม่มีการจองที่กำลังใช้งานครับ ✅");
        continue;
      }

      $slot = !empty($booking['slot_id']) ? getSlotById($pdo, (int)$booking['slot_id']) : null;
      $code = getSlotCode($slot);

      $now   = new DateTime();
      $endDt = new DateTime($booking['end_time'] ?? 'now');
      if ($now < $endDt) {
        $diff   = $now->diff($endDt);
        $remain = ($diff->days * 24 + $diff->h) . " ชม. " . $diff->i . " นาที";
      } else {
        $remain = "หมดเวลาตามที่จองแล้ว";
      }

      reply_raw($replyToken, [
        flex_booking_status_card($booking, $code, $remain),
      ]);
      continue;
    }

    /* ===== 11) ภาษาคน: ช่องไหน / ช่องอะไร ===== */
    if ((mb_strpos($low, 'ช่อง') !== false) && (mb_strpos($low, 'ไหน') !== false || mb_strpos($low, 'อะไร') !== false)) {
      $user = getUserByLINE($pdo, $lineUID);
      if (!$user) {
        reply_raw($replyToken, [
          ['type' => 'text', 'text' => "ยังไม่ได้ผูกบัญชี LINE กับ AutoParkX ❌"],
          flex_help_card(),
        ]);
        continue;
      }

      $booking = getLatestActiveBooking($pdo, (int)$user['id'], $ACTIVE_STATUS_SET);
      if (!$booking) {
        reply_text($replyToken, "ตอนนี้คุณไม่มีการจองที่กำลังใช้งานครับ ✅");
        continue;
      }

      $slot = !empty($booking['slot_id']) ? getSlotById($pdo, (int)$booking['slot_id']) : null;
      $code = getSlotCode($slot);

      reply_text($replyToken, "ตอนนี้การจองล่าสุดของคุณอยู่ที่ช่อง: {$code} 🅿️");
      continue;
    }

    /* ===== 12) ภาษาคน: ถึงกี่โมง / หมดเวลา ===== */
    if (
      mb_strpos($low, 'ถึงกี่โมง') !== false ||
      mb_strpos($low, 'หมดเวลา') !== false ||
      mb_strpos($low, 'ถึงเมื่อไหร่') !== false
    ) {
      $user = getUserByLINE($pdo, $lineUID);
      if (!$user) {
        reply_raw($replyToken, [
          ['type' => 'text', 'text' => "ยังไม่ได้ผูกบัญชี LINE กับ AutoParkX ❌"],
          flex_help_card(),
        ]);
        continue;
      }

      $booking = getLatestActiveBooking($pdo, (int)$user['id'], $ACTIVE_STATUS_SET);
      if (!$booking) {
        reply_text($replyToken, "ตอนนี้คุณไม่มีการจองที่กำลังใช้งานครับ ✅");
        continue;
      }

      $end = $booking['end_time'] ?? null;
      if (!$end) {
        reply_text($replyToken, "ไม่พบเวลาสิ้นสุดการจองในระบบครับ โปรดตรวจสอบที่หน้าเว็บอีกครั้ง");
        continue;
      }

      $endDt = new DateTime($end);
      $now   = new DateTime('now');
      $endTx = fmt_dt($end);

      if ($now < $endDt) {
        $diff   = $now->diff($endDt);
        $remain = ($diff->days * 24 + $diff->h) . " ชม. " . $diff->i . " นาที";
        $msg  = "การจองของคุณใช้ได้ถึงเวลา: {$endTx} ⏰\n";
        $msg .= "เหลือเวลาอีกประมาณ {$remain}";
      } else {
        $msg  = "เวลาการจองของคุณได้สิ้นสุดลงแล้วที่เวลา: {$endTx}\n";
        $msg .= "หากยังจอดอยู่ โปรดตรวจสอบกติกา/ค่าปรับจากเจ้าหน้าที่";
      }

      reply_raw($replyToken, [
        ['type' => 'text', 'text' => $msg],
        flex_menu_card(),
      ]);
      continue;
    }

    /* ===== 13) ทักทาย ===== */
    if (mb_strpos($low, 'สวัสดี') !== false) {
      reply_raw($replyToken, [
        ['type' => 'text', 'text' => "สวัสดีครับจาก AutoParkX 👋\nพิมพ์ “เมนู” เพื่อดูคำสั่งทั้งหมดได้เลยครับ"],
        flex_menu_card(),
      ]);
      continue;
    }

    /* ===== DEFAULT ===== */
    reply_raw($replyToken, [
      ['type' => 'text', 'text' => "พิมพ์ “เมนู” เพื่อดูคำสั่งทั้งหมดได้เลยครับ 🙂"],
      flex_menu_card(),
    ]);

  } catch (Throwable $e) {
    error_log('line_webhook event error: ' . $e->getMessage());
  }
}

http_response_code(200);
echo "OK";