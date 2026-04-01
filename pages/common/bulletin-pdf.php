<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/modules/shared/pdf/PdfService.php';

Auth::check('admin','teacher','parent','student');

$role = Auth::role();
$userId = (int)Auth::id();
$year = getCurrentYear();
$trim = getCurrentTrimester();
$yearId = (int)($year['id'] ?? 0);
$trimId = (int)($_GET['trimester_id'] ?? ($trim['id'] ?? 0));
$studentId = (int)($_GET['student_id'] ?? 0);

if ($role === 'student') {
    $studentId = (int)Database::scalar('SELECT id FROM students WHERE user_id=? AND academic_year_id=?', [$userId, $yearId]);
}
if ($role === 'parent' && !$studentId) {
    $studentId = (int)Database::scalar(
        'SELECT s.id FROM student_parents sp JOIN students s ON s.id=sp.student_id
         WHERE sp.parent_id=? AND s.academic_year_id=? ORDER BY sp.is_primary DESC LIMIT 1',
        [$userId, $yearId]
    );
}
if ($role === 'parent' && $studentId) {
    $ok = (int)Database::scalar(
        'SELECT COUNT(*) FROM student_parents sp
         JOIN students s ON s.id=sp.student_id
         WHERE sp.parent_id=? AND sp.student_id=? AND s.academic_year_id=?',
        [$userId, $studentId, $yearId]
    );
    if (!$ok) { http_response_code(403); die('Acces refuse.'); }
}

$stu = Database::fetchOne(
  'SELECT s.id AS student_id, s.matricule, s.class_id, u.first_name, u.last_name, c.name AS class_name
   FROM students s
   JOIN users u ON u.id=s.user_id
   JOIN classes c ON c.id=s.class_id
   WHERE s.id=? AND s.academic_year_id=?',
  [$studentId, $yearId]
);
if (!$stu) { http_response_code(404); die('Eleve introuvable.'); }

$classId = (int)$stu['class_id'];
$trimRow = Database::fetchOne('SELECT * FROM trimesters WHERE id=?', [$trimId]) ?: getCurrentTrimester();
$trimId = (int)($trimRow['id'] ?? $trimId);

$subjects = Database::fetchAll(
  'SELECT cs.id AS cs_id, cs.coefficient, sub.name AS subject_name
   FROM class_subjects cs
   JOIN subjects sub ON sub.id=cs.subject_id
   WHERE cs.class_id=? ORDER BY sub.name',
  [$classId]
);

$lines = [];
foreach ($subjects as $s) {
    $avg = computeSubjectAverage($studentId, (int)$s['cs_id'], $trimId);
    $lines[] = ['subject'=>$s['subject_name'], 'coeff'=>(float)$s['coefficient'], 'avg'=>$avg];
}

$generalAvg = computeGeneralAverage($studentId, $classId, $trimId);
$rank = getClassRank($studentId, $classId, $trimId);
$absences = getStudentAbsenceCount($studentId, $trimId);
$school = getSchool();

$html = '<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:12px;color:#0b1f3a;}
h1{font-size:18px;margin:0;}
.muted{color:#64748b;}
table{width:100%;border-collapse:collapse;margin-top:12px;}
th,td{border:1px solid #e5e7eb;padding:8px;text-align:left;}
th{background:#f8fafc;}
.row{display:flex;justify-content:space-between;gap:10px;}
.box{border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-top:12px;}
</style></head><body>';

$html .= '<div class="row"><div><h1>'.e($school['name'] ?? APP_NAME).'</h1><div class="muted">Bulletin — '.e($trimRow['label'] ?? 'Trimestre').' · '.e($year['label'] ?? '').'</div></div>';
$html .= '<div style="text-align:right;"><b>'.e($stu['last_name'].' '.$stu['first_name']).'</b><div class="muted">Matricule: '.e($stu['matricule']).' · Classe: '.e($stu['class_name']).'</div></div></div>';

$html .= '<div class="box"><b>Moyenne générale:</b> '.($generalAvg!==null?e(number_format($generalAvg,2)):'—').' &nbsp; <b>Rang:</b> '.($rank?e((string)$rank):'—').' &nbsp; <b>Absences/retards:</b> '.(int)$absences.'</div>';

$html .= '<table><thead><tr><th>Matière</th><th>Coeff.</th><th>Moyenne</th></tr></thead><tbody>';
foreach ($lines as $l) {
    $html .= '<tr><td>'.e($l['subject']).'</td><td>'.e(number_format($l['coeff'],1)).'</td><td>'.($l['avg']!==null?e(number_format($l['avg'],2)):'—').'</td></tr>';
}
if (empty($lines)) $html .= '<tr><td colspan="3">Aucune matière</td></tr>';
$html .= '</tbody></table>';
$html .= '<div style="margin-top:18px;" class="muted">Signatures: Prof principal ____________________  Direction ____________________</div>';
$html .= '</body></html>';

$stored = PdfService::generateAndStore($html, 'bulletin', $studentId, $trimId, $userId, 'bulletin');
if (!$stored) {
    setFlash('error', 'PDF serveur indisponible. Utilise le bouton Imprimer dans le bulletin.');
    header('Location: ' . BASE_URL . '/modules/common/pages/bulletin.php?student_id=' . $studentId . '&trimester_id=' . $trimId);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="bulletin-' . $studentId . '-' . $trimId . '.pdf"');
readfile(BASE_PATH . '/' . $stored['file_path']);
exit;

