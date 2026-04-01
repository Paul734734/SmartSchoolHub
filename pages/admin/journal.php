<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$q = sanitize($_GET['q'] ?? '');
$action = sanitize($_GET['action'] ?? '');

$where = 'WHERE 1=1';
$params = [];
if ($action !== '') {
    $where .= ' AND l.action = ?';
    $params[] = $action;
}
if ($q !== '') {
    $where .= ' AND (l.details LIKE ? OR l.target LIKE ? OR u.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$rows = Database::fetchAll(
  'SELECT l.*, u.email
   FROM activity_log l
   LEFT JOIN users u ON u.id=l.user_id
   ' . $where . '
   ORDER BY l.created_at DESC
   LIMIT 300',
  $params
);
$actions = Database::fetchAll('SELECT action, COUNT(*) cnt FROM activity_log GROUP BY action ORDER BY cnt DESC LIMIT 30');

$pageTitle = 'Journal';
$pageIcon  = '📋';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Journal d’activité</div>
  <form method="get" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;">
    <input class="form-input" name="q" value="<?= e($q) ?>" placeholder="Rechercher details / cible / email">
    <select class="form-select" name="action">
      <option value="">Toutes actions</option>
      <?php foreach ($actions as $a): ?>
        <option value="<?= e($a['action']) ?>" <?= $action===$a['action']?'selected':'' ?>><?= e($a['action']) ?> (<?= (int)$a['cnt'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit">Filtrer</button>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/admin/pages/journal.php">Reset</a>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Cible</th><th>Détails</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(timeAgo($r['created_at'])) ?></td>
          <td><?= e($r['email'] ?? '—') ?></td>
          <td><span class="badge badge-blue"><?= e($r['action']) ?></span></td>
          <td><?= e(($r['target'] ?? '—') . ($r['target_id'] ? ' #' . $r['target_id'] : '')) ?></td>
          <td><?= e(substr($r['details'] ?? '', 0, 90)) ?><?= strlen($r['details'] ?? '')>90?'…':'' ?></td>
          <td><?= e($r['ip'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="6">Aucun.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

