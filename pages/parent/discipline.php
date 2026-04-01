<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('parent');

$parentId = Auth::id();
$yearId   = getCurrentYear()['id'] ?? 0;

$children = Database::fetchAll(
  'SELECT s.id AS student_id, u.first_name, u.last_name, c.name AS class_name
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

$rows = [];
if ($child) {
  $rows = Database::fetchAll(
    'SELECT d.*, CONCAT(rep.first_name," ",rep.last_name) AS reported_name
     FROM discipline d
     JOIN users rep ON rep.id = d.reported_by
     WHERE d.student_id = ?
     ORDER BY d.date DESC, d.created_at DESC
     LIMIT 200',
    [$selectedChildId]
  );
}

$pageTitle = 'Discipline';
$pageIcon  = '⚖️';
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
  <div class="card-title">Historique <?= $child ? '— '.e($child['first_name'].' '.$child['last_name']) : '' ?></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Type</th><th>Motif</th><th>Par</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(formatDate($r['date'])) ?></td>
          <td><span class="badge <?= $r['type']==='commendation'?'badge-green':'badge-amber' ?>"><?= e($r['type']) ?></span></td>
          <td><?= e($r['reason']) ?></td>
          <td><?= e($r['reported_name']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="4">Aucun.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

