<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$q = sanitize($_GET['q'] ?? '');
$params = [];
$where = 'WHERE 1=1';
if ($q !== '') {
  $where .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR c.name LIKE ? OR s.matricule LIKE ?)';
  $like = '%' . $q . '%';
  $params = [$like,$like,$like,$like];
}

$rows = Database::fetchAll(
  'SELECT d.*, u.first_name, u.last_name, s.matricule, c.name AS class_name,
          CONCAT(rep.first_name," ",rep.last_name) AS reported_name
   FROM discipline d
   JOIN students s ON s.id = d.student_id
   JOIN users u ON u.id = s.user_id
   JOIN classes c ON c.id = s.class_id
   JOIN users rep ON rep.id = d.reported_by
   ' . $where . '
   ORDER BY d.created_at DESC
   LIMIT 300',
  $params
);

$pageTitle = 'Discipline';
$pageIcon  = '⚖️';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">Registre disciplinaire</div>
  <form method="get" style="margin-bottom:12px;display:flex;gap:8px;">
    <input class="form-input" name="q" value="<?= e($q) ?>" placeholder="Rechercher eleve, matricule, classe...">
    <button class="btn btn-secondary" type="submit">Rechercher</button>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Eleve</th><th>Classe</th><th>Type</th><th>Motif</th><th>Par</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(formatDate($r['date'])) ?></td>
          <td><?= e($r['last_name'].' '.$r['first_name'].' (#'.$r['matricule'].')') ?></td>
          <td><?= e($r['class_name']) ?></td>
          <td><span class="badge <?= $r['type']==='commendation'?'badge-green':'badge-amber' ?>"><?= e($r['type']) ?></span></td>
          <td><?= e(substr($r['reason'],0,80)) ?><?= strlen($r['reason'])>80?'…':'' ?></td>
          <td><?= e($r['reported_name']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="6">Aucun.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

