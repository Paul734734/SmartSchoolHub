<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('student');

$userId  = Auth::id();
$yearId  = getCurrentYear()['id'] ?? 0;

$student = Database::fetchOne(
  'SELECT s.id AS student_id, s.class_id, u.first_name, u.last_name, c.name AS class_name
   FROM students s
   JOIN users u ON u.id = s.user_id
   JOIN classes c ON c.id = s.class_id
   WHERE s.user_id = ? AND s.academic_year_id = ?',
  [$userId, $yearId]
);

if (!$student) {
  setFlash('error', 'Profil eleve introuvable.');
  header('Location: ' . BASE_URL . '/login.php'); exit;
}

$classId = (int)$student['class_id'];

$rows = Database::fetchAll(
  'SELECT tt.day_of_week, tt.start_time, tt.end_time, tt.room,
          sub.name AS subject_name, sub.icon, sub.color,
          CONCAT(u.first_name," ",u.last_name) AS teacher_name
   FROM timetable tt
   JOIN class_subjects cs ON cs.id = tt.class_subject_id
   JOIN subjects sub ON sub.id = cs.subject_id
   JOIN users u ON u.id = cs.teacher_id
   WHERE tt.academic_year_id = ? AND cs.class_id = ?
   ORDER BY tt.day_of_week, tt.start_time',
  [$yearId, $classId]
);

$byDay = [];
foreach ($rows as $r) { $byDay[(int)$r['day_of_week']][] = $r; }

$pageTitle = 'Mon emploi du temps';
$pageIcon  = '📅';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">Classe <?= e($student['class_name']) ?></div>
  <?php if (empty($rows)): ?>
    <div class="empty-state" style="padding:20px;"><p>Aucun emploi du temps publie.</p></div>
  <?php else: ?>
    <?php for ($d=1; $d<=6; $d++): ?>
      <div style="margin-bottom:14px;">
        <div style="font-weight:800;color:var(--navy);margin-bottom:8px;"><?= e(dayName($d)) ?></div>
        <?php if (empty($byDay[$d])): ?>
          <div style="color:var(--muted);font-size:13px;">—</div>
        <?php else: ?>
          <?php foreach ($byDay[$d] as $s): ?>
            <div class="schedule-row">
              <div class="schedule-time"><?= e(substr($s['start_time'],0,5)) ?> – <?= e(substr($s['end_time'],0,5)) ?></div>
              <div class="schedule-block" style="background:#EFF6FF;border-left-color:var(--blue);">
                <div class="schedule-course"><?= $s['icon'] ?> <?= e($s['subject_name']) ?></div>
                <div class="schedule-info">
                  <?= e($s['teacher_name']) ?>
                  <?= $s['room'] ? ' · Salle ' . e($s['room']) : '' ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  <?php endif; ?>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
