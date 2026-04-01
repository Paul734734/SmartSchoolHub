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
if ($selectedChildId) {
    $rows = Database::fetchAll(
        'SELECT a.date, a.period, a.status, a.justification, a.notified_parent,
                sub.name AS subject_name, sub.icon
         FROM attendance a
         JOIN class_subjects cs ON cs.id = a.class_subject_id
         JOIN subjects sub ON sub.id = cs.subject_id
         WHERE a.student_id = ?
         ORDER BY a.date DESC, a.period
         LIMIT 200',
        [$selectedChildId]
    );
}

$pageTitle = 'Absences';
$pageIcon  = '🚫';
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
  <div class="card-title">Historique des absences <?= $child ? '— ' . e($child['first_name'] . ' ' . $child['last_name']) : '' ?></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Periode</th><th>Matiere</th><th>Statut</th><th>Justification</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(formatDate($r['date'])) ?></td>
          <td><?= e($r['period']) ?></td>
          <td><?= $r['icon'] ?> <?= e($r['subject_name']) ?></td>
          <td><span class="badge <?= $r['status']==='absent'?'badge-red':($r['status']==='late'?'badge-amber':'badge-green') ?>"><?= e($r['status']) ?></span></td>
          <td><?= $r['justification'] ? '✓' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="5">Aucune absence.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
