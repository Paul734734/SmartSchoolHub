<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['results' => []]);
    exit;
}

$q = sanitize($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$like = '%' . $q . '%';
$results = [];

if (Auth::role() === 'admin' || Auth::role() === 'teacher') {
    $students = Database::fetchAll(
        'SELECT s.id, s.matricule, u.first_name, u.last_name, c.name AS class_name
         FROM students s
         JOIN users u ON u.id = s.user_id
         JOIN classes c ON c.id = s.class_id
         WHERE (u.first_name LIKE ? OR u.last_name LIKE ? OR s.matricule LIKE ?)
         ORDER BY u.last_name, u.first_name
         LIMIT 8',
        [$like, $like, $like]
    );

    foreach ($students as $s) {
        $results[] = [
            'type' => 'student',
            'name' => $s['first_name'] . ' ' . $s['last_name'],
            'subtitle' => $s['class_name'] . ' - #' . $s['matricule'],
            'initials' => strtoupper(substr($s['first_name'], 0, 1) . substr($s['last_name'], 0, 1)),
            'url' => BASE_URL . '/modules/common/pages/coming-soon.php?module=Fiche%20eleve',
            'color' => 'linear-gradient(135deg,#3B82F6,#06B6D4)',
        ];
    }
}

echo json_encode(['results' => array_slice($results, 0, 10)]);
