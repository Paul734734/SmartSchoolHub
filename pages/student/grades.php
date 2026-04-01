<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('student');

$userId  = Auth::id();
$yearId  = getCurrentYear()['id'] ?? 0;
$trimId  = getCurrentTrimester()['id'] ?? 0;

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

$studentId = (int)$student['student_id'];
$classId   = (int)$student['class_id'];

$generalAvg = computeGeneralAverage($studentId, $classId, $trimId);
$rank       = getClassRank($studentId, $classId, $trimId);

$subjectGrades = Database::fetchAll(
  'SELECT sub.name, sub.icon,
          AVG(CASE WHEN g.is_absent=0 THEN g.score ELSE NULL END) AS avg_score
   FROM class_subjects cs
   JOIN subjects sub ON sub.id = cs.subject_id
   LEFT JOIN evaluations e ON e.class_subject_id = cs.id AND e.trimester_id = ?
   LEFT JOIN grades g ON g.evaluation_id = e.id AND g.student_id = ?
   WHERE cs.class_id = ?
   GROUP BY cs.id
   ORDER BY sub.name',
  [$trimId, $studentId, $classId]
);

$details = Database::fetchAll(
  'SELECT e.date, e.label, e.max_score, g.score, g.is_absent, sub.name AS subject_name, sub.icon
   FROM grades g
   JOIN evaluations e ON e.id = g.evaluation_id
   JOIN class_subjects cs ON cs.id = e.class_subject_id
   JOIN subjects sub ON sub.id = cs.subject_id
   WHERE g.student_id = ? AND e.trimester_id = ?
   ORDER BY e.date DESC
   LIMIT 250',
  [$studentId, $trimId]
);

$pageTitle = 'Mes notes';
$pageIcon  = '📝';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="stat-grid" style="grid-template-columns:repeat(3,1fr);">
  <div class="stat-card">
    <div class="stat-top"><div class="stat-icon" style="background:#D1FAE5;">📊</div></div>
    <div class="stat-num"><?= $generalAvg !== null ? number_format($generalAvg,1) : '—' ?></div>
    <div class="stat-label">Moyenne generale</div>
  </div>
  <div class="stat-card">
    <div class="stat-top"><div class="stat-icon" style="background:#EFF6FF;">🏆</div></div>
    <div class="stat-num"><?= $rank ?: '—' ?></div>
    <div class="stat-label">Rang</div>
  </div>
  <div class="stat-card">
    <div class="stat-top"><div class="stat-icon" style="background:#FEF3C7;">📚</div></div>
    <div class="stat-num"><?= count($details) ?></div>
    <div class="stat-label">Notes (trim.)</div>
  </div>
</div>

<div class="two-col mt-20">
  <div class="card">
    <div class="card-title">Moyennes par matiere</div>
    <?php foreach ($subjectGrades as $sg):
      $avg = $sg['avg_score'] !== null ? round((float)$sg['avg_score'],1) : null;
      $color = $avg === null ? 'var(--muted)' : ($avg >= 14 ? 'var(--mint)' : ($avg >= 10 ? 'var(--amber)' : 'var(--rose)'));
      $pct = $avg !== null ? min(100, round(($avg/20)*100)) : 0;
    ?>
      <div style="padding:10px 0;border-bottom:1px solid var(--border);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
          <div style="font-weight:700;color:var(--navy);"><?= $sg['icon'] ?> <?= e($sg['name']) ?></div>
          <div style="font-weight:900;color:<?= $color ?>;"><?= $avg !== null ? $avg.'/20' : '—' ?></div>
        </div>
        <?php if ($avg !== null): ?>
        <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-title">Details</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Matiere</th><th>Evaluation</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($details as $d): ?>
          <tr>
            <td><?= e(formatDate($d['date'])) ?></td>
            <td><?= $d['icon'] ?> <?= e($d['subject_name']) ?></td>
            <td><?= e($d['label']) ?></td>
            <td style="font-weight:900;color:<?= (int)$d['is_absent'] ? 'var(--rose)' : gradeColor($d['score'], $d['max_score']) ?>;">
              <?= (int)$d['is_absent'] ? 'ABS' : e(formatGrade($d['score'], $d['max_score'])) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($details)): ?><tr><td colspan="4">Aucune note.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
