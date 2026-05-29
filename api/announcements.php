<?php
require __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
  if ($method === 'GET') {
    $mode = $_GET['mode'] ?? 'active';

    if ($mode === 'all') {
      $stmt = $pdo->query("
        SELECT *
        FROM system_announcements
        ORDER BY id DESC
      ");
      $rows = $stmt->fetchAll();
      json_ok(['items' => $rows]);
    }

    $stmt = $pdo->query("
      SELECT *
      FROM system_announcements
      WHERE is_active = 1
        AND (start_at IS NULL OR start_at <= NOW())
        AND (end_at IS NULL OR end_at >= NOW())
      ORDER BY id DESC
    ");
    $rows = $stmt->fetchAll();
    json_ok(['items' => $rows]);
  }

  if ($method === 'POST') {
    $data = json_input();
    if (!$data) json_error('invalid json body', 400);

    $action = $data['action'] ?? 'create';

    if ($action === 'create') {
      $title    = trim((string)($data['title'] ?? ''));
      $message  = trim((string)($data['message'] ?? ''));
      $type     = trim((string)($data['type'] ?? 'info'));
      $active   = isset($data['is_active']) ? (int)$data['is_active'] : 1;
      $start_at = !empty($data['start_at']) ? to_mysql_dt((string)$data['start_at']) : null;
      $end_at   = !empty($data['end_at']) ? to_mysql_dt((string)$data['end_at']) : null;

      if ($title === '' || $message === '') {
        json_error('title and message are required', 422);
      }

      $allowed = ['info','success','warning','danger'];
      if (!in_array($type, $allowed, true)) {
        $type = 'info';
      }

      $stmt = $pdo->prepare("
        INSERT INTO system_announcements
        (title, message, type, is_active, start_at, end_at)
        VALUES (?, ?, ?, ?, ?, ?)
      ");
      $stmt->execute([$title, $message, $type, $active, $start_at, $end_at]);

      json_ok([
        'message' => 'created successfully',
        'id' => (int)$pdo->lastInsertId()
      ], 201);
    }

    if ($action === 'update') {
      $id       = (int)($data['id'] ?? 0);
      $title    = trim((string)($data['title'] ?? ''));
      $message  = trim((string)($data['message'] ?? ''));
      $type     = trim((string)($data['type'] ?? 'info'));
      $active   = isset($data['is_active']) ? (int)$data['is_active'] : 1;
      $start_at = !empty($data['start_at']) ? to_mysql_dt((string)$data['start_at']) : null;
      $end_at   = !empty($data['end_at']) ? to_mysql_dt((string)$data['end_at']) : null;

      if ($id <= 0) json_error('invalid id', 422);
      if ($title === '' || $message === '') json_error('title and message are required', 422);

      $allowed = ['info','success','warning','danger'];
      if (!in_array($type, $allowed, true)) {
        $type = 'info';
      }

      $stmt = $pdo->prepare("
        UPDATE system_announcements
        SET title = ?, message = ?, type = ?, is_active = ?, start_at = ?, end_at = ?
        WHERE id = ?
      ");
      $stmt->execute([$title, $message, $type, $active, $start_at, $end_at, $id]);

      json_ok(['message' => 'updated successfully']);
    }

    if ($action === 'delete') {
      $id = (int)($data['id'] ?? 0);
      if ($id <= 0) json_error('invalid id', 422);

      $stmt = $pdo->prepare("DELETE FROM system_announcements WHERE id = ?");
      $stmt->execute([$id]);

      json_ok(['message' => 'deleted successfully']);
    }

    json_error('unknown action', 400);
  }

  json_error('method not allowed', 405);

} catch (Throwable $e) {
  json_error('server error: ' . $e->getMessage(), 500);
}