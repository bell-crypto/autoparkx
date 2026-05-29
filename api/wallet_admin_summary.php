<?php
// api/wallet_admin_summary.php
require __DIR__ . '/db.php';

try {
    // ✅ รองรับ summary แบบรายคน
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

    // =========================================================
    // MODE A) รายคน (สำหรับ User Card แบบ 3)
    // =========================================================
    if ($user_id > 0) {
        // ✅ ใช้คอลัมน์ users.wallet (ของจริงใน DB คุณ)
        $sqlUser = "SELECT id,
                           COALESCE(wallet,0) AS wallet_balance,
                           COALESCE(name,'')  AS name,
                           COALESCE(email,'') AS email
                    FROM users
                    WHERE id = :uid
                    LIMIT 1";
        $stmt = $pdo->prepare($sqlUser);
        $stmt->execute([':uid' => $user_id]);
        $u = $stmt->fetch();

        if (!$u) {
            json_error('ไม่พบผู้ใช้ (user_id='.$user_id.')', 404);
        }

        $wallet_now = (float)($u['wallet_balance'] ?? 0);

        // สรุปสะสมทั้งหมด
        $sqlAll = "
            SELECT type,
                   SUM(
                       CASE
                           WHEN type = 'charge' THEN ABS(amount)
                           ELSE amount
                       END
                   ) AS amt,
                   COUNT(*) AS cnt
            FROM wallet_history
            WHERE user_id = :uid
              AND type IN ('topup','charge','refund')
            GROUP BY type
        ";
        $stmt = $pdo->prepare($sqlAll);
        $stmt->execute([':uid' => $user_id]);

        $topup_total  = 0.0;
        $charge_total = 0.0;
        $refund_total = 0.0;
        $tx_count     = 0;

        foreach ($stmt as $r) {
            $t = strtolower((string)$r['type']);
            $v = (float)$r['amt'];
            $c = (int)($r['cnt'] ?? 0);
            $tx_count += $c;

            if ($t === 'topup') $topup_total = $v;
            elseif ($t === 'charge') $charge_total = $v;
            elseif ($t === 'refund') $refund_total = $v;
        }

        // สรุปวันนี้
        $sqlToday = "
            SELECT type,
                   SUM(
                       CASE
                           WHEN type = 'charge' THEN ABS(amount)
                           ELSE amount
                       END
                   ) AS amt
            FROM wallet_history
            WHERE user_id = :uid
              AND DATE(created_at) = CURDATE()
              AND type IN ('topup','charge','refund')
            GROUP BY type
        ";
        $stmt = $pdo->prepare($sqlToday);
        $stmt->execute([':uid' => $user_id]);

        $topup_today  = 0.0;
        $charge_today = 0.0;
        $refund_today = 0.0;

        foreach ($stmt as $r) {
            $t = strtolower((string)$r['type']);
            $v = (float)$r['amt'];
            if ($t === 'topup') $topup_today = $v;
            elseif ($t === 'charge') $charge_today = $v;
            elseif ($t === 'refund') $refund_today = $v;
        }

        json_ok([
            'user_id'        => (int)$user_id,
            'wallet'         => $wallet_now,
            'wallet_balance' => $wallet_now,
            'user_name'      => (string)($u['name'] ?? ''),
            'name'           => (string)($u['name'] ?? ''),
            'email'          => (string)($u['email'] ?? ''),

            'topup_total'    => $topup_total,
            'charge_total'   => $charge_total,
            'refund_total'   => $refund_total,
            'tx_count'       => $tx_count,

            'topup_today'    => $topup_today,
            'charge_today'   => $charge_today,
            'refund_today'   => $refund_today,
        ]);
    }

    // =========================================================
    // MODE B) รวมทั้งระบบ (เหมือนเดิม แต่แก้ SUM เป็น users.wallet)
    // =========================================================
    $stmt = $pdo->query("SELECT COALESCE(SUM(wallet),0) AS total_in_system FROM users");
    $row   = $stmt->fetch() ?: ['total_in_system' => 0];
    $total = (float)$row['total_in_system'];

    $sql = "
        SELECT type,
               SUM(
                   CASE
                       WHEN type = 'charge' THEN ABS(amount)
                       ELSE amount
                   END
               ) AS amt
        FROM wallet_history
        WHERE DATE(created_at) = CURDATE()
          AND type IN ('topup','charge','refund')
        GROUP BY type
    ";
    $stmt = $pdo->query($sql);

    $topup_today  = 0.0;
    $charge_today = 0.0;
    $refund_today = 0.0;

    foreach ($stmt as $r) {
        $t = strtolower((string)$r['type']);
        $v = (float)$r['amt'];
        if ($t === 'topup') $topup_today = $v;
        elseif ($t === 'charge') $charge_today = $v;
        elseif ($t === 'refund') $refund_today = $v;
    }

    json_ok([
        'total_in_system' => $total,
        'total'           => $total,
        'topup_today'     => $topup_today,
        'charge_today'    => $charge_today,
        'refund_today'    => $refund_today,
    ]);

} catch (Throwable $e) {
    json_error('DB error: '.$e->getMessage(), 500);
}
