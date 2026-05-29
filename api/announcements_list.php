<?php
require __DIR__ . '/db.php';

try {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
    if ($limit <= 0)  $limit = 30;
    if ($limit > 100) $limit = 100;

    $sql = "
        SELECT
            id,
            title,
            message,
            type,
            is_active,
            start_at,
            end_at,
            created_at,
            updated_at
        FROM system_announcements
        WHERE is_active = 1
          AND (start_at IS NULL OR start_at <= NOW())
          AND (end_at   IS NULL OR end_at   >= NOW())
        ORDER BY
            CASE
                WHEN start_at IS NULL THEN 1
                ELSE 0
            END ASC,
            start_at DESC,
            created_at DESC,
            id DESC
        LIMIT :lim
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    $items = array_map(function(array $r) {
        $rawType = strtoupper(trim((string)($r['type'] ?? '')));

        // map type จาก DB -> level ที่หน้า announcements.html ใช้
        $level = match ($rawType) {
            'WARN', 'WARNING', 'ALERT', 'URGENT', 'IMPORTANT' => 'WARN',
            'MAINT', 'MAINTENANCE', 'DOWNTIME'                => 'MAINT',
            'INFO', 'NEWS', 'UPDATE', 'NOTICE'               => 'INFO',
            default                                           => 'INFO',
        };

        return [
            'id'         => (int)$r['id'],
            'title'      => (string)($r['title'] ?? ''),
            'body'       => (string)($r['message'] ?? ''),
            'level'      => $level,
            'created_at' => $r['created_at'],
            'start_at'   => $r['start_at'],
            'end_at'     => $r['end_at'],
        ];
    }, $rows);

    json_ok([
        'items' => $items,
        'count' => count($items),
    ]);

} catch (Throwable $e) {
    json_error('failed to load announcements: ' . $e->getMessage(), 500);
}