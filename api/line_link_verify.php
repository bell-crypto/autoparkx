<?php
// api/line_link_verify.php
// ใช้เช็กว่าผู้ใช้เชื่อม LINE กับบัญชี AutoParkX แล้วหรือยัง
// ให้ frontend เรียกผ่าน AJAX/Fetch แล้วเอาผลไปแสดงว่า "เชื่อมแล้ว / ยังไม่เชื่อม"

require __DIR__ . '/db.php'; // มี $pdo + json_input + json_ok + json_error

try {
    // รับ input ได้ทั้ง JSON และ form-data
    $in = json_input();
    if ($in === null) {
        $in = $_POST ?: [];
    }

    $user_id = (int)($in['user_id'] ?? 0);

    if ($user_id <= 0) {
        json_error('missing user_id', 422);
    }

    // ดึงข้อมูลจาก users
    $stmt = $pdo->prepare("
        SELECT email, push_token
        FROM users
        WHERE id = :uid
        LIMIT 1
    ");
    $stmt->execute([':uid' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_error('user not found', 404);
    }

    $email      = (string)($row['email'] ?? '');
    $push_token = (string)($row['push_token'] ?? '');

    $linked = $push_token !== '';

    // ทำ mask ให้ดูนิดหน่อย (กันโชว์ userId เต็ม ๆ)
    $push_mask = null;
    if ($linked) {
        $len = strlen($push_token);
        if ($len <= 7) {
            $push_mask = $push_token;
        } else {
            // ตัวอย่าง: U1234***xyz
            $push_mask = substr($push_token, 0, 5) . '***' . substr($push_token, -3);
        }
    }

    json_ok([
        'user_id'        => $user_id,
        'email'          => $email,
        'linked'         => $linked,       // true = ผูก LINE แล้ว, false = ยังไม่ผูก
        'push_token_mask'=> $push_mask,    // ไว้ debug/โชว์เฉย ๆ
    ]);

} catch (Throwable $e) {
    error_log('line_link_verify error: ' . $e->getMessage());
    json_error('server error', 500, ['detail' => $e->getMessage()]);
}
