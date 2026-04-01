<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin','teacher','parent','student');

$userId = (int)Auth::id();
$filter = sanitize($_GET['filter'] ?? 'all'); // all|unread
$type   = sanitize($_GET['type'] ?? '');

if (isset($_GET['mark']) && $_GET['mark'] === 'all') {
    Database::execute('UPDATE notifications SET is_read=1 WHERE user_id=?', [$userId]);
    setFlash('success','Notifications marquées comme lues.');
    header('Location: ' . BASE_URL . '/modules/common/pages/notifications.php');
    exit;
}

if (isset($_GET['read']) && ctype_digit((string)$_GET['read'])) {
    $id = (int)$_GET['read'];
    Database::execute('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?', [$id, $userId]);
    header('Location: ' . BASE_URL . '/modules/common/pages/notifications.php');
    exit;
}

$where = 'WHERE n.user_id = ?';
$params = [$userId];
if ($filter === 'unread') {
    $where .= ' AND n.is_read = 0';
}
if ($type !== '') {
    $where .= ' AND n.type = ?';
    $params[] = $type;
}

$rows = Database::fetchAll(
  'SELECT n.*
   FROM notifications n
   ' . $where . '
   ORDER BY n.created_at DESC
   LIMIT 200',
  $params
);

$types = Database::fetchAll(
  'SELECT type, COUNT(*) AS cnt
   FROM notifications
   WHERE user_id = ?
   GROUP BY type
   ORDER BY cnt DESC',
  [$userId]
);

$pageTitle = 'Notifications';
$pageIcon  = '🔔';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Notifications</div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <a class="btn btn-secondary btn-sm" href="?filter=all">Toutes</a>
    <a class="btn btn-secondary btn-sm" href="?filter=unread">Non lues</a>
    <a class="btn btn-secondary btn-sm" href="?mark=all" onclick="return confirm('Marquer tout comme lu ?')">Tout marquer lu</a>
    <form method="get" style="margin-left:auto;display:flex;gap:8px;align-items:center;">
      <input type="hidden" name="filter" value="<?= e($filter) ?>">
      <select class="form-select" name="type" onchange="this.form.submit()">
        <option value="">Tous types</option>
        <?php foreach ($types as $t): ?>
          <option value="<?= e($t['type']) ?>" <?= $type===$t['type']?'selected':'' ?>><?= e($t['type']) ?> (<?= (int)$t['cnt'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th></th><th>Titre</th><th>Message</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $n): ?>
        <tr style="<?= (int)$n['is_read']===0 ? 'background:#FFFBEB;' : '' ?>">
          <td><?= (int)$n['is_read']===0 ? '<span class="badge badge-amber">new</span>' : '' ?></td>
          <td style="font-weight:800;color:var(--navy);"><?= e($n['title']) ?></td>
          <td><?= e(substr($n['body'],0,90)) ?><?= strlen($n['body'])>90?'…':'' ?></td>
          <td><?= e(timeAgo($n['created_at'])) ?></td>
          <td style="text-align:right;">
            <?php if (!empty($n['link'])): ?>
              <a class="btn btn-primary btn-sm" href="<?= e($n['link']) ?>">Ouvrir</a>
            <?php endif; ?>
            <?php if ((int)$n['is_read']===0): ?>
              <a class="btn btn-secondary btn-sm" href="?read=<?= (int)$n['id'] ?>">Lu</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="5">Aucune notification.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

