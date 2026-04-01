<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$yearId = (int)(getCurrentYear()['id'] ?? 0);
$q = sanitize($_GET['q'] ?? '');
$status = sanitize($_GET['status'] ?? '');
$date = sanitize($_GET['date'] ?? '');

$where = 'WHERE 1=1';
$params = [];
if ($status !== '' && in_array($status, ['present','absent','late','excused','excluded'], true)) {
    $where .= ' AND a.status = ?';
    $params[] = $status;
}
if ($date !== '') {
    $where .= ' AND a.date = ?';
    $params[] = $date;
}
if ($q !== '') {
    $where .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.matricule LIKE ? OR c.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = Database::fetchAll(
      'SELECT a.date, a.status, s.matricule, u.first_name, u.last_name, c.name AS class_name
       FROM attendance a
       JOIN students s ON s.id=a.student_id
       JOIN users u ON u.id=s.user_id
       JOIN classes c ON c.id=s.class_id
       ' . $where . '
       ORDER BY a.date DESC
       LIMIT 5000',
      $params
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="absences.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['date','status','matricule','first_name','last_name','class']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['date'],$r['status'],$r['matricule'],$r['first_name'],$r['last_name'],$r['class_name']]);
    }
    fclose($out);
    exit;
}

$rows = Database::fetchAll(
  'SELECT a.*, s.matricule, u.first_name, u.last_name, c.name AS class_name
   FROM attendance a
   JOIN students s ON s.id=a.student_id
   JOIN users u ON u.id=s.user_id
   JOIN classes c ON c.id=s.class_id
   ' . $where . '
   ORDER BY a.date DESC, a.id DESC
   LIMIT 300',
  $params
);

$pageTitle = 'Absences';
$pageIcon  = '🚫';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Registre des présences</div>
  <form method="get" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;">
    <input class="form-input" name="q" value="<?= e($q) ?>" placeholder="Élève / matricule / classe">
    <select class="form-select" name="status">
      <option value="">Tous statuts</option>
      <?php foreach (['present','absent','late','excused','excluded'] as $st): ?>
        <option value="<?= e($st) ?>" <?= $status===$st?'selected':'' ?>><?= e($st) ?></option>
      <?php endforeach; ?>
    </select>
    <input class="form-input" type="date" name="date" value="<?= e($date) ?>">
    <button class="btn btn-secondary" type="submit">Filtrer</button>
    <a class="btn btn-secondary" href="?export=csv&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>&date=<?= urlencode($date) ?>">Export CSV</a>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Élève</th><th>Classe</th><th>Statut</th><th>Justification</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(formatDate($r['date'])) ?></td>
          <td><?= e($r['last_name'].' '.$r['first_name'].' (#'.$r['matricule'].')') ?></td>
          <td><?= e($r['class_name']) ?></td>
          <td><span class="badge <?= $r['status']==='present'?'badge-green':($r['status']==='late'?'badge-amber':'badge-red') ?>"><?= e($r['status']) ?></span></td>
          <td><?= e($r['justification'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="5">Aucun.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

