<?php
// api/wallet_admin_list.php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    // ---- อ่าน query string ----
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    if ($limit <= 0)  $limit = 100;
    if ($limit > 500) $limit = 500;

    $search  = trim((string)($_GET['search'] ?? ''));
    $type    = trim((string)($_GET['type'] ?? ''));
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

    $where  = [];
    $params = [];

    // filter ตาม user_id
    if ($user_id > 0) {
        $where[] = 'wh.user_id = :uid';
        $params[':uid'] = $user_id;
    }

    // filter ตาม type
    // รองรับ type เดิม + type จาก admin adjust
    if ($type !== '') {
        $t = strtolower($type);

        $allowedTypes = [
            'topup',
            'charge',
            'refund',
            'admin_plus',
            'admin_minus',
        ];

        if (in_array($t, $allowedTypes, true)) {
            $where[] = 'LOWER(wh.type) = :type';
            $params[':type'] = $t;
        }
    }

    // filter ตาม search
    if ($search !== '') {
        $where[] = '('
                 . 'CAST(wh.user_id AS CHAR) LIKE :kw '
                 . 'OR COALESCE(wh.note, "") LIKE :kw '
                 . 'OR COALESCE(wh.ref, "") LIKE :kw '
                 . 'OR CAST(wh.id AS CHAR) LIKE :kw '
                 . 'OR CAST(COALESCE(wh.booking_id, 0) AS CHAR) LIKE :kw'
                 . ')';
        $params[':kw'] = '%' . $search . '%';
    }

    // ------------------------------------------------------------
    // ดึงรายการ + คำนวณ "balance_after" จริง
    //
    // หลักการ:
    // users.wallet = ยอดล่าสุดปัจจุบัน
    // balance_after ของแต่ละรายการ
    // = wallet ปัจจุบัน - ผลรวมธุรกรรมที่ "ใหม่กว่า" รายการนั้น ของ user เดียวกัน
    //
    // ใช้ signed amount ตรง ๆ:
    //  + topup / refund / admin_plus   => บวก
    //  - charge / admin_minus          => ลบ
    // ------------------------------------------------------------
    $sql = "
        SELECT
            wh.*,
            wh.id AS tx_id,
            wh.created_at AS timestamp,
            COALESCE(u.wallet, 0) AS wallet_current,

            (
                COALESCE(u.wallet, 0) - COALESCE(
                    (
                        SELECT SUM(COALESCE(wh2.amount, 0))
                        FROM wallet_history wh2
                        WHERE wh2.user_id = wh.user_id
                          AND (
                                wh2.created_at > wh.created_at
                                OR (wh2.created_at = wh.created_at AND wh2.id > wh.id)
                              )
                    ),
                    0
                )
            ) AS balance_after_calc

        FROM wallet_history wh
        LEFT JOIN users u
            ON u.id = wh.user_id
    ";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= " ORDER BY wh.created_at DESC, wh.id DESC LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ---- เตรียม field ให้ตรงกับ frontend ----
    foreach ($rows as &$r) {
        // timestamp สำรอง
        if (!isset($r['timestamp']) && isset($r['created_at'])) {
            $r['timestamp'] = $r['created_at'];
        }

        // tx_id สำรอง
        if (!isset($r['tx_id']) && isset($r['id'])) {
            $r['tx_id'] = $r['id'];
        }

        // ถ้ามี balance_after จริงใน DB ให้ใช้ก่อน
        // ถ้าไม่มี ให้ใช้ค่าที่คำนวณได้
        if (
            !array_key_exists('balance_after', $r) ||
            $r['balance_after'] === null ||
            $r['balance_after'] === ''
        ) {
            $r['balance_after'] = isset($r['balance_after_calc'])
                ? (float)$r['balance_after_calc']
                : null;
        } else {
            $r['balance_after'] = (float)$r['balance_after'];
        }

        // ให้ frontend อ่านได้ทั้ง balance_after และ balance
        $r['balance'] = $r['balance_after'];

        // amount เป็นตัวเลข
        if (isset($r['amount'])) {
            $r['amount'] = (float)$r['amount'];
        }

        // wallet_current เผื่อใช้ debug / แสดงผลอนาคต
        if (isset($r['wallet_current'])) {
            $r['wallet_current'] = (float)$r['wallet_current'];
        }

        // ล้าง field ชั่วคราวถ้าไม่อยากส่งเกินจำเป็น
        unset($r['balance_after_calc']);
    }
    unset($r);

    json_ok([
        'transactions' => $rows,
        'user_id'      => $user_id,
        'count'        => count($rows),
    ]);

} catch (Throwable $e) {
    json_error('DB error: ' . $e->getMessage(), 500);
}