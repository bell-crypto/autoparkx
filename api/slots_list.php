<?php
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$now  = date('Y-m-d H:i:s');
$soon = date('Y-m-d H:i:s', strtotime($now . ' +30 minutes'));

$status = isset($_GET['status']) ? strtoupper(trim((string)$_GET['status'])) : null;
$level  = isset($_GET['level'])  ? trim((string)$_GET['level']) : null;

$allowed = ['AVAILABLE','HELD','BOOKED','OCCUPIED','MAINTENANCE'];
if ($status && !in_array($status, $allowed, true)) {
    json_error('invalid status', 422);
}

/*
 * booking ที่ห้ามนับกลับมาเป็น BOOKED
 * สำคัญ:
 * - OCCUPIED = ถูกใช้งานแล้ว
 * - COMPLETED = จบแล้ว
 * ดังนั้นหลังรถออกจะไม่ย้อนกลับ BOOKED
 */
$INACTIVE_SET = "('CANCELLED','CANCELLED_BY_ADMIN','EXPIRED','COMPLETED','OCCUPIED')";

$s_raw = $_GET['start_time'] ?? null;
$e_raw = $_GET['end_time'] ?? null;

$useRange = false;
$nowSql   = $pdo->quote($now);
$soonSql  = $pdo->quote($soon);
$sSQL = $eSQL = null;

if ($s_raw && $e_raw) {
    $tsS = strtotime((string)$s_raw);
    $tsE = strtotime((string)$e_raw);

    if ($tsS && $tsE && $tsE > $tsS) {
        $useRange = true;
        $sSQL = $pdo->quote(date('Y-m-d H:i:s', $tsS));
        $eSQL = $pdo->quote(date('Y-m-d H:i:s', $tsE));
    }
}

try {
    $sqlSlots = "
        SELECT id, code, status, level, last_update
        FROM parking_slots
        ORDER BY level, code
    ";
    $rows = $pdo->query($sqlSlots)->fetchAll(PDO::FETCH_ASSOC);

    $summary = [
        'total'       => 0,
        'available'   => 0,
        'held'        => 0,
        'booked'      => 0,
        'occupied'    => 0,
        'maintenance' => 0,
    ];

    $slots = [];

    foreach ($rows as $r) {
        $summary['total']++;

        $id   = (int)$r['id'];
        $base = strtoupper((string)($r['status'] ?? ''));
        $lvl  = $r['level'];

        if (!in_array($base, $allowed, true)) {
            $base = 'AVAILABLE';
        }

        $busy_from = null;
        $busy_to   = null;

        $booked_soon       = false;
        $next_booking_from = null;
        $next_booking_to   = null;

        if ($useRange) {
            $sqlOcc = "
                SELECT
                    COUNT(*) AS c,
                    MIN(start_time) AS s_from,
                    MAX(end_time)   AS s_to
                FROM bookings
                WHERE slot_id = {$id}
                  AND (status IS NULL OR UPPER(status) NOT IN {$INACTIVE_SET})
                  AND start_time < {$eSQL}
                  AND end_time   > {$sSQL}
            ";

            $rowOcc  = $pdo->query($sqlOcc)->fetch(PDO::FETCH_ASSOC);
            $overlap = $rowOcc && (int)($rowOcc['c'] ?? 0) > 0;

            if ($overlap) {
                $busy_from = $rowOcc['s_from'] ?? null;
                $busy_to   = $rowOcc['s_to'] ?? null;
            }

            if ($base === 'MAINTENANCE') {
                $calc = 'MAINTENANCE';
            } elseif ($base === 'HELD') {
                $calc = 'HELD';
            } elseif ($base === 'OCCUPIED') {
                $calc = 'OCCUPIED';
            } elseif ($overlap) {
                $calc = 'BOOKED';
            } else {
                $calc = 'AVAILABLE';
            }
        } else {
            /*
             * สถานะปัจจุบัน:
             * - ถ้า slot base เป็น OCCUPIED ให้ยึด OCCUPIED ก่อน
             * - ถ้าไม่มีรถ แต่มี booking active ตอนนี้ = BOOKED
             * - ถ้ามี booking ภายใน 30 นาทีข้างหน้า = BOOKED เช่นกัน
             * - ถ้า booking ถูกใช้แล้ว/จบแล้ว จะไม่นับกลับมา
             */
            $sqlNow = "
                SELECT
                    COUNT(*) AS c,
                    MIN(start_time) AS s_from,
                    MAX(end_time)   AS s_to
                FROM bookings
                WHERE slot_id = {$id}
                  AND (status IS NULL OR UPPER(status) NOT IN {$INACTIVE_SET})
                  AND start_time <= {$nowSql}
                  AND end_time   > {$nowSql}
            ";
            $rowNow    = $pdo->query($sqlNow)->fetch(PDO::FETCH_ASSOC);
            $activeNow = $rowNow && (int)($rowNow['c'] ?? 0) > 0;

            if ($activeNow) {
                $busy_from = $rowNow['s_from'] ?? null;
                $busy_to   = $rowNow['s_to'] ?? null;
            }

            $sqlSoon = "
                SELECT
                    MIN(start_time) AS s_from,
                    MAX(end_time)   AS s_to
                FROM bookings
                WHERE slot_id = {$id}
                  AND (status IS NULL OR UPPER(status) NOT IN {$INACTIVE_SET})
                  AND start_time > {$nowSql}
                  AND start_time <= {$soonSql}
            ";
            $rowSoon = $pdo->query($sqlSoon)->fetch(PDO::FETCH_ASSOC);

            if ($rowSoon && !empty($rowSoon['s_from'])) {
                $booked_soon       = true;
                $next_booking_from = $rowSoon['s_from'] ?? null;
                $next_booking_to   = $rowSoon['s_to'] ?? null;

                if (!$busy_from) $busy_from = $next_booking_from;
                if (!$busy_to)   $busy_to   = $next_booking_to;
            }

            if ($base === 'MAINTENANCE') {
                $calc = 'MAINTENANCE';
            } elseif ($base === 'HELD') {
                $calc = 'HELD';
            } elseif ($base === 'OCCUPIED') {
                $calc = 'OCCUPIED';
            } elseif ($activeNow || $booked_soon) {
                $calc = 'BOOKED';
            } else {
                $calc = 'AVAILABLE';
            }
        }

        if ($calc === 'AVAILABLE')   $summary['available']++;
        if ($calc === 'HELD')        $summary['held']++;
        if ($calc === 'BOOKED')      $summary['booked']++;
        if ($calc === 'OCCUPIED')    $summary['occupied']++;
        if ($calc === 'MAINTENANCE') $summary['maintenance']++;

        if ($status && $calc !== $status) continue;
        if ($level !== '' && $level !== null && (string)$lvl !== (string)$level) continue;

        $slots[] = [
            'id'                => $id,
            'code'              => $r['code'],
            'level'             => $lvl,
            'status'            => $calc,
            'base_status'       => $base,
            'last_update'       => $r['last_update'],
            'busy_from'         => $busy_from,
            'busy_to'           => $busy_to,
            'booked_soon'       => $booked_soon,
            'next_booking_from' => $next_booking_from,
            'next_booking_to'   => $next_booking_to,
        ];
    }

    json_ok([
        'now'     => $now,
        'soon'    => $soon,
        'filters' => [
            'status'     => $status,
            'level'      => $level,
            'use_range'  => $useRange,
            'start_time' => $useRange ? $s_raw : null,
            'end_time'   => $useRange ? $e_raw : null,
        ],
        'summary' => $summary,
        'slots'   => $slots,
    ]);

} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}