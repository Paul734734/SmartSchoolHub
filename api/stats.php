<?php
/**
 * SmartSchool Hub — API : statistiques publiques pour la page de connexion
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Charger la config seulement si elle existe
$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    echo json_encode(['students' => 0, 'teachers' => 0]);
    exit;
}

try {
    require_once $configFile;
    require_once __DIR__ . '/../config/database.php';

    $students = (int) Database::scalar(
        "SELECT COUNT(*) FROM students s
         JOIN academic_years y ON y.id = s.academic_year_id
         WHERE y.is_current = 1 AND s.status = 'enrolled'"
    );

    $teachers = (int) Database::scalar(
        "SELECT COUNT(DISTINCT u.id) FROM users u
         WHERE u.role = 'teacher' AND u.is_active = 1"
    );

    echo json_encode(['students' => $students, 'teachers' => $teachers]);

} catch (Throwable $e) {
    echo json_encode(['students' => 0, 'teachers' => 0]);
}
