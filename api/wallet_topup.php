<?php
// api/wallet_topup.php
// เติมเงินเข้าวอลเล็ต + บันทึกประวัติ + แจ้งเตือนผ่าน LINE Messaging API (Flex)

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ⚠️ ใช้ Channel access token ตัวเดียวกับ booking_create.php
$LINE_CHANNEL_ACCESS_TOKEN = (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');

// ถ้าอยากให้แอดมินได้แจ้งเตือนทุกครั้งที่มีคนเติมเงิน ใส่ userId/groupId ตรงนี้
$ADMIN_LINE_ID = ''; // เช่น 'Uxxxx' หรือ 'Cxxxx'

/**
 * ส่ง push message แบบ raw (รองรับ text/flex) + คืนค่า debug
 */
function line_push_raw(array $body): array {
  global $LINE_CHANNEL_ACCESS_TOKEN;

  if (!$LINE_CHANNEL_ACCESS_TOKEN || empty($body['to']) || empty($body['messages'])) {
    return ['ok'=>false,'http'=>0,'err'=>'missing token/to/messages','resp'=>null];
  }

  $url = 'https://api.line.me/v2/bot/message/push';

  $ch = curl_init($url);
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

  $resp = curl_exec($ch);
  $errNo = curl_errno($ch);
  $errTx = $errNo ? curl_error($ch) : '';
  $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $ok = ($errNo === 0 && $http >= 200 && $http < 300);
  if(!$ok){
    error_log("LINE push fail http={$http} err={$errNo} {$errTx} resp={$resp}");
  }

  return ['ok'=>$ok,'http'=>$http,'err'=>$errTx,'resp'=>$resp];
}

/** ตรวจว่า string ดูเหมือน LINE user/group/room id ไหม */
function looks_like_line_id(string $s): bool {
  $s = trim($s);
  if ($s === '') return false;
  return (bool)preg_match('/^[UCR][0-9a-f]{32}$/i', $s);
}

/**
 * ดึงค่าตั้งค่าการแจ้งเตือนของ user จากตาราง user_notify_prefs
 * ถ้าไม่มี record / error → คืนค่า default
 */
function get_notify_prefs(PDO $pdo, int $user_id): array {
  $defaults = [
    'enabled'           => true,
    'booking_created'   => true,
    'booking_soon'      => true,
    'booking_cancelled' => true,
    'news'              => false,
    'quiet_night'       => false,
    'wallet_topup'      => true,
  ];

  if ($user_id <= 0) return $defaults;

  try {
    $stmt = $pdo->prepare("
      SELECT enabled, booking_created, booking_soon,
             booking_cancelled, news, quiet_night, wallet_topup
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
      'wallet_topup'      => isset($row['wallet_topup']) ? (bool)$row['wallet_topup'] : true,
    ];
  } catch (Throwable $e) {
    error_log('[AutoParkX] get_notify_prefs (topup) error: ' . $e->getMessage());
    return $defaults;
  }
}

try {
  // ===== 1) รับอินพุต =====
  $in = json_input();
  if ($in === null) $in = $_POST ?: [];

  $user_id = (int)($in['user_id'] ?? 0);
  $amount  = (float)($in['amount'] ?? 0);
  $debug   = (int)($in['debug'] ?? 0);

  if ($user_id <= 0 || $amount <= 0) {
    json_error('ข้อมูลไม่ถูกต้อง', 422);
  }

  $pdo->beginTransaction();

  // ===== 2) ดึงข้อมูลผู้ใช้ + ล็อก wallet =====
  $stmt = $pdo->prepare("
    SELECT wallet, email, name, line_user_id, push_token
    FROM users
    WHERE id = :uid
    FOR UPDATE
  ");
  $stmt->execute([':uid' => $user_id]);
  $user = $stmt->fetch();

  if (!$user) {
    $pdo->rollBack();
    json_error('ไม่พบผู้ใช้', 404);
  }

  $wallet_before = (float)($user['wallet'] ?? 0);
  $wallet_after  = $wallet_before + $amount;

  $user_email = (string)($user['email'] ?? '');
  $user_name  = (string)($user['name'] ?? '');

  // ===== 3) อัปเดตยอด wallet =====
  $stmt = $pdo->prepare("UPDATE users SET wallet = :after WHERE id = :uid");
  $stmt->execute([':after' => $wallet_after, ':uid' => $user_id]);

  // ===== 4) บันทึกประวัติ wallet_history =====
  $ref = 'TOPUP-' . date('YmdHis');
  $stmt = $pdo->prepare("
    INSERT INTO wallet_history (user_id, type, amount, note, ref, created_at)
    VALUES (:uid, 'topup', :amt, :note, :ref, NOW())
  ");
  $stmt->execute([
    ':uid'  => $user_id,
    ':amt'  => $amount,
    ':note' => 'เติมเงินเข้าระบบ',
    ':ref'  => $ref,
  ]);

  $pdo->commit();

  // ===== 5) ส่งแจ้งเตือน LINE (Flex) =====
  $prefs = get_notify_prefs($pdo, $user_id);

  // เลือกผู้รับ: line_user_id ก่อน -> fallback push_token (กรณีเก็บผิดฟิลด์)
  $line_to = '';
  $cand1 = trim((string)($user['line_user_id'] ?? ''));
  $cand2 = trim((string)($user['push_token'] ?? ''));

  if (looks_like_line_id($cand1)) $line_to = $cand1;
  elseif (looks_like_line_id($cand2)) $line_to = $cand2;

  $who = $user_email ?: ($user_name ?: ("UID {$user_id}"));
  $amountLabel = number_format($amount, 2) . " ฿";
  $walletLabel = number_format($wallet_after, 2) . " ฿";
  $whenLabel   = date('d/m/Y H:i');

  $url = 'https://autoparkx.com/wallet.html';

  $flex = [
    'type'    => 'flex',
    'altText' => "AutoParkX: เติมเงินสำเร็จ +{$amountLabel}",
    'contents'=> [
      'type' => 'bubble',
      'size' => 'mega',
      'header' => [
        'type' => 'box',
        'layout' => 'vertical',
        'paddingAll' => '16px',
        'backgroundColor' => '#0a84ff',
        'contents' => [
          ['type'=>'text','text'=>'เติมเงินสำเร็จ','weight'=>'bold','size'=>'md','color'=>'#ffffff'],
          ['type'=>'text','text'=>"REF : {$ref}",'size'=>'xs','color'=>'#e0f2fe','margin'=>'sm'],
        ],
      ],
      'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'paddingAll' => '16px',
        'backgroundColor' => '#ffffff',
        'spacing' => 'md',
        'contents' => [
          ['type'=>'text','text'=>'สรุปการเติมเงิน','weight'=>'bold','color'=>'#111827','size'=>'sm'],
          [
            'type'=>'box','layout'=>'vertical','spacing'=>'xs',
            'contents'=>[
              [
                'type'=>'box','layout'=>'baseline',
                'contents'=>[
                  ['type'=>'text','text'=>'วันที่ :','size'=>'xs','color'=>'#6b7280','flex'=>3],
                  ['type'=>'text','text'=>$whenLabel,'size'=>'xs','color'=>'#111827','flex'=>6,'wrap'=>true],
                ]
              ],
              [
                'type'=>'box','layout'=>'baseline',
                'contents'=>[
                  ['type'=>'text','text'=>'ผู้ใช้ :','size'=>'xs','color'=>'#6b7280','flex'=>3],
                  ['type'=>'text','text'=>$who,'size'=>'xs','color'=>'#111827','flex'=>6,'wrap'=>true],
                ]
              ],
              [
                'type'=>'box','layout'=>'baseline',
                'contents'=>[
                  ['type'=>'text','text'=>'จำนวนที่เติม :','size'=>'xs','color'=>'#6b7280','flex'=>3],
                  ['type'=>'text','text'=>$amountLabel,'size'=>'xs','color'=>'#111827','flex'=>6],
                ]
              ],
              [
                'type'=>'box','layout'=>'baseline',
                'contents'=>[
                  ['type'=>'text','text'=>'ยอดคงเหลือ :','size'=>'xs','color'=>'#6b7280','flex'=>3],
                  ['type'=>'text','text'=>$walletLabel,'size'=>'xs','color'=>'#111827','flex'=>6],
                ]
              ],
            ]
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
            'color' => '#0a84ff',
            'height' => 'sm',
            'action' => ['type'=>'uri','label'=>'ดูวอลเล็ตในเว็บ','uri'=>$url],
          ],
          [
            'type' => 'text',
            'text' => 'AutoParkX • Wallet',
            'size' => 'xxs',
            'color' => '#6b7280',
            'align' => 'center',
            'margin' => 'sm',
          ],
        ],
      ],
    ],
  ];

  $sent_user  = ['ok'=>false];
  $sent_admin = ['ok'=>false];

  try {
    // ส่งให้ user เฉพาะเมื่อเปิดแจ้งเตือน + เปิด wallet_topup + มีปลายทางที่ส่งได้
    if ($prefs['enabled'] && $prefs['wallet_topup'] && $line_to !== '') {
      $sent_user = line_push_raw(['to'=>$line_to, 'messages'=>[$flex]]);
    }

    // ส่งให้ ADMIN เสมอถ้าตั้งไว้
    if (!empty($ADMIN_LINE_ID)) {
      $sent_admin = line_push_raw(['to'=>$ADMIN_LINE_ID, 'messages'=>[$flex]]);
    }
  } catch (Throwable $ex) {
    error_log('[AutoParkX] LINE push topup error: '.$ex->getMessage());
  }

  // ===== 6) ส่ง JSON กลับไปให้ frontend =====
  $out = [
    'user_id'       => $user_id,
    'amount'        => $amount,
    'wallet_before' => $wallet_before,
    'wallet_after'  => $wallet_after,
    'ref'           => $ref,
    'message'       => 'เติมเงินสำเร็จ',
  ];

  if ($debug === 1) {
    $out['line_debug'] = [
      'user_to' => $line_to ? substr($line_to,0,4).'***' : '',
      'sent_user' => $sent_user,
      'sent_admin'=> $sent_admin,
    ];
  }

  json_ok($out);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log('wallet_topup error: '.$e->getMessage());
  json_error('server error', 500);
}
