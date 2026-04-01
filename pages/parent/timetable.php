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
  'SELECT s.id AS student_id, u.first_name, u.last_name, c.name AS class_name, c.id AS class_id
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

$classId = (int)$child['class_id'];

$slots = Database::fetchAll(
  'SELECT tt.day_of_week, tt.start_time, tt.end_time, tt.room,
          sub.name AS subject_name, sub.icon,
          CONCAT(u.first_name," ",u.last_name) AS teacher_name
   FROM timetable tt
   JOIN class_subjects cs ON cs.id=tt.class_subject_id
   JOIN subjects sub ON sub.id=cs.subject_id
   JOIN users u ON u.id=cs.teacher_id
   WHERE tt.academic_year_id=? AND cs.class_id=?
   ORDER BY tt.day_of_week ASC, tt.start_time ASC',
  [$yearId, $classId]
);

$byDay = [];
foreach ($slots as $s) { $byDay[(int)$s['day_of_week']][] = $s; }

$pageTitle = 'Emploi du temps';
$pageIcon  = '📅';
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

<div class="card">
  <div class="card-title">EDT — <?= e($child['first_name'].' '.$child['last_name']) ?> (<?= e($child['class_name']) ?>)</div>

  <?php for ($d=1; $d<=6; $d++): ?>
    <div style="padding:14px 0;border-bottom:1px solid var(--border);">
      <div style="font-weight:900;color:var(--navy);margin-bottom:10px;"><?= e(dayName($d)) ?></div>
      <?php foreach (($byDay[$d] ?? []) as $sl): ?>
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;padding:10px 12px;border:1px solid var(--border);border-radius:12px;margin:8px 0;background:#fff;">
          <div>
            <div style="font-weight:900;color:var(--navy);"><?= $sl['icon'] ?> <?= e($sl['subject_name']) ?></div>
            <div style="color:var(--muted);font-size:12px;margin-top:4px;">
              Prof: <?= e($sl['teacher_name']) ?> · Salle: <?= e($sl['room'] ?: '—') ?>
            </div>
          </div>
          <div class="badge badge-blue">
            <?= e(substr($sl['start_time'],0,5)) ?>–<?= e(substr($sl['end_time'],0,5)) ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($byDay[$d] ?? [])): ?>
        <div style="color:var(--muted);font-size:13px;">Aucun cours.</div>
      <?php endif; ?>
    </div>
  <?php endfor; ?>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

