<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('parent');

$parentId = Auth::id();
$yearId   = getCurrentYear()['id'] ?? 0;
$trimId   = getCurrentTrimester()['id'] ?? 0;

$children = Database::fetchAll(
    'SELECT s.id AS student_id, u.first_name, u.last_name, c.name AS class_name, c.id AS class_id
     FROM student_parents sp
     JOIN students s ON s.id = sp.student_id
     JOIN users u ON u.id = s.user_id
     JOIN classes c ON c.id = s.class_id
     WHERE sp.parent_id = ? AND s.academic_year_id = ? AND s.status="enrolled"
     ORDER BY u.last_name',
    [$parentId, $yearId]
);

$selectedChildId = (int)($_GET['child_id'] ?? ($children[0]['student_id'] ?? 0));
$child = null;
foreach ($children as $c) { if ((int)$c['student_id'] === $selectedChildId) { $child = $c; break; } }
if (!$child && !empty($children)) { $child = $children[0]; $selectedChildId = (int)$child['student_id']; }

$details = [];
$generalAvg = null;
$rank = null;
if ($child) {
    $generalAvg = computeGeneralAverage($selectedChildId, (int)$child['class_id'], $trimId);
    $rank       = getClassRank($selectedChildId, (int)$child['class_id'], $trimId);

    $details = Database::fetchAll(
        'SELECT e.date, e.label, e.max_score, g.score, g.is_absent,
                sub.name AS subject_name, sub.icon
         FROM grades g
         JOIN evaluations e ON e.id = g.evaluation_id
         JOIN class_subjects cs ON cs.id = e.class_subject_id
         JOIN subjects sub ON sub.id = cs.subject_id
         WHERE g.student_id = ? AND e.trimester_id = ?
         ORDER BY e.date DESC
         LIMIT 250',
        [$selectedChildId, $trimId]
    );
}

$pageTitle = 'Notes';
$pageIcon  = '📝';
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

<?php if ($child): ?>
<div class="stat-grid" style="grid-template-columns:repeat(3,1fr);">
  <div class="stat-card">
    <div class="stat-top"><div class="stat-icon" style="background:#D1FAE5;">📊</div></div>
    <div class="stat-num"><?= $generalAvg !== null ? number_format($generalAvg, 1) : '—' ?></div>
    <div class="stat-label">Moyenne generale</div>
  </div>
  <div class="stat-card">
    <div class="stat-top"><div class="stat-icon" style="background:#EFF6FF;">🏆</div></div>
    <div class="stat-num"><?= $rank ?: '—' ?></div>
    <div class="stat-label">Rang (classe)</div>
  </div>
  <div class="stat-card">
    <div class="stat-top"><div class="stat-icon" style="background:#FEF3C7;">📚</div></div>
    <div class="stat-num"><?= count($details) ?></div>
    <div class="stat-label">Notes saisies (trim.)</div>
  </div>
</div>

<div class="card mt-20">
  <div class="card-title">Details des notes — <?= e($child['first_name'].' '.$child['last_name']) ?></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Matiere</th><th>Evaluation</th><th>Note</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($details as $d): ?>
        <?php
          $abs = (int)$d['is_absent'] === 1;
          $badge = $abs ? ['class'=>'badge-red','label'=>'ABS'] : gradeBadge($d['score'], $d['max_score']);
        ?>
        <tr>
          <td><?= e(formatDate($d['date'])) ?></td>
          <td><?= $d['icon'] ?> <?= e($d['subject_name']) ?></td>
          <td><?= e($d['label']) ?></td>
          <td style="font-weight:800;color:<?= $abs ? 'var(--rose)' : gradeColor($d['score'], $d['max_score']) ?>;">
            <?= $abs ? 'ABS' : e(formatGrade($d['score'], $d['max_score'])) ?>
          </td>
          <td><span class="badge <?= $badge['class'] ?>"><?= e($badge['label']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($details)): ?><tr><td colspan="5">Aucune note.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
