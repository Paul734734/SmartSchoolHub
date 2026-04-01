<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = Auth::id();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
    $rows = Database::fetchAll(
        'SELECT id, type, title, body, is_read, link, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT ' . $limit,
        [$userId]
    );

    $notifications = array_map(function ($n) {
        $n['time_ago'] = timeAgo($n['created_at']);
        return $n;
    }, $rows);

    echo json_encode(['success' => true, 'notifications' => $notifications]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    $action = $data['action'] ?? '';

    if ($action === 'read' && !empty($data['id'])) {
        Database::execute(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?',
            [(int)$data['id'], $userId]
        );
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'read_all') {
        Database::execute(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
        echo json_encode(['success' => true]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Bad request']);
