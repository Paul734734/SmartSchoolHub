<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('teacher');

$teacherId = (int)Auth::id();
$yearId = (int)(getCurrentYear()['id'] ?? 0);

$slots = Database::fetchAll(
  'SELECT tt.day_of_week, tt.start_time, tt.end_time, tt.room,
          c.name AS class_name, sub.name AS subject_name, sub.icon
   FROM timetable tt
   JOIN class_subjects cs ON cs.id=tt.class_subject_id
   JOIN classes c ON c.id=cs.class_id
   JOIN subjects sub ON sub.id=cs.subject_id
   WHERE cs.teacher_id=? AND tt.academic_year_id=?
   ORDER BY tt.day_of_week ASC, tt.start_time ASC',
  [$teacherId, $yearId]
);
$byDay = [];
foreach ($slots as $s) { $byDay[(int)$s['day_of_week']][] = $s; }

$pageTitle = 'Emploi du temps';
$pageIcon  = '📅';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">Mon emploi du temps</div>
  <?php for ($d=1; $d<=6; $d++): ?>
    <div style="padding:14px 0;border-bottom:1px solid var(--border);">
      <div style="font-weight:900;color:var(--navy);margin-bottom:10px;"><?= e(dayName($d)) ?></div>
      <?php foreach (($byDay[$d] ?? []) as $sl): ?>
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;padding:10px 12px;border:1px solid var(--border);border-radius:12px;margin:8px 0;background:#fff;">
          <div>
            <div style="font-weight:900;color:var(--navy);"><?= $sl['icon'] ?> <?= e($sl['subject_name']) ?> — <?= e($sl['class_name']) ?></div>
            <div style="color:var(--muted);font-size:12px;margin-top:4px;">Salle: <?= e($sl['room'] ?: '—') ?></div>
          </div>
          <div class="badge badge-blue">
            <?= e(substr($sl['start_time'],0,5)) ?>–<?= e(substr($sl['end_time'],0,5)) ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($byDay[$d] ?? [])): ?><div style="color:var(--muted);font-size:13px;">Aucun cours.</div><?php endif; ?>
    </div>
  <?php endfor; ?>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

