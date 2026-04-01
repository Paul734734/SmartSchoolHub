<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('parent');

$parentId = (int)Auth::id();
$yearId   = (int)(getCurrentYear()['id'] ?? 0);

$children = Database::fetchAll(
  'SELECT s.id AS student_id, s.matricule, u.first_name, u.last_name, c.name AS class_name, c.id AS class_id
   FROM student_parents sp
   JOIN students s ON s.id=sp.student_id
   JOIN users u ON u.id=s.user_id
   JOIN classes c ON c.id=s.class_id
   WHERE sp.parent_id=? AND s.academic_year_id=? AND s.status="enrolled"
   ORDER BY sp.is_primary DESC, u.last_name',
  [$parentId, $yearId]
);
$studentId = (int)($_GET['child_id'] ?? ($children[0]['student_id'] ?? 0));
$child = null;
foreach ($children as $c) { if ((int)$c['student_id'] === $studentId) { $child = $c; break; } }
if (!$child && !empty($children)) { $child = $children[0]; $studentId = (int)$child['student_id']; }
if (!$child) { setFlash('error','Aucun enfant lié à ce compte.'); header('Location: '.BASE_URL.'/modules/parent/pages/dashboard.php'); exit; }

$trim = getCurrentTrimester();
$trimId = (int)($_GET['trimester_id'] ?? ($trim['id'] ?? 0));

$avg = computeGeneralAverage($studentId, (int)$child['class_id'], $trimId);
$rank = getClassRank($studentId, (int)$child['class_id'], $trimId);
$abs = (int)Database::scalar(
  'SELECT COUNT(*) FROM attendance WHERE student_id=? AND status IN ("absent","late")',
  [$studentId]
);
$disc = (int)Database::scalar(
  'SELECT COUNT(*) FROM discipline WHERE student_id=? AND type NOT IN ("commendation")',
  [$studentId]
);
$hwPending = (int)Database::scalar(
  'SELECT COUNT(*)
   FROM gradebook gb
   JOIN class_subjects cs ON cs.id=gb.class_subject_id
   LEFT JOIN homework_submissions hs ON hs.gradebook_id=gb.id AND hs.student_id=?
   WHERE cs.class_id=? AND gb.homework IS NOT NULL AND gb.homework<>"" AND (hs.id IS NULL OR hs.status IN ("submitted","rejected"))',
  [$studentId, (int)$child['class_id']]
);

$pageTitle = 'Fiche enfant';
$pageIcon  = '👦';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (count($children) > 1): ?>
<div class="card mb-20">
  <div class="card-title">Choisir un enfant</div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php foreach ($children as $ch): ?>
      <a class="btn btn-secondary" href="?child_id=<?= (int)$ch['student_id'] ?>">
        <?= e($ch['first_name']) ?> (<?= e($ch['class_name']) ?>)
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card mb-20">
  <div class="card-title"><?= e($child['first_name'].' '.$child['last_name']) ?> — <?= e($child['class_name']) ?></div>
  <div style="color:var(--muted);font-size:12px;">Matricule: <?= e($child['matricule']) ?></div>

  <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px;">
    <div class="mini-stat"><div class="mini-stat-label">Moyenne</div><div class="mini-stat-value"><?= $avg!==null?e(number_format($avg,2)):'—' ?></div></div>
    <div class="mini-stat"><div class="mini-stat-label">Rang</div><div class="mini-stat-value"><?= $rank?e((string)$rank):'—' ?></div></div>
    <div class="mini-stat"><div class="mini-stat-label">Absences/retards</div><div class="mini-stat-value"><?= (int)$abs ?></div></div>
    <div class="mini-stat"><div class="mini-stat-label">Devoirs à suivre</div><div class="mini-stat-value"><?= (int)$hwPending ?></div></div>
  </div>
</div>

<div class="card">
  <div class="card-title">Accès rapide</div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/parent/pages/grades.php?child_id=<?= (int)$studentId ?>">Notes</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/parent/pages/attendance.php?child_id=<?= (int)$studentId ?>">Absences</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/parent/pages/discipline.php?child_id=<?= (int)$studentId ?>">Discipline (<?= (int)$disc ?>)</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/parent/pages/homework.php?child_id=<?= (int)$studentId ?>">Devoirs</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/parent/pages/timetable.php?child_id=<?= (int)$studentId ?>">EDT</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/common/pages/bulletin.php?student_id=<?= (int)$studentId ?>">Bulletin</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/common/pages/announcements.php?student_id=<?= (int)$studentId ?>">Annonces</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/common/pages/messages.php">Messagerie</a>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

