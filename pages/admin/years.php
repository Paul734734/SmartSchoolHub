<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$errors = [];
$action = sanitize($_POST['action'] ?? ($_GET['action'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_year') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) $errors[] = 'Token invalide.';
    $label = sanitize($_POST['label'] ?? '');
    $start = sanitize($_POST['start_date'] ?? '');
    $end   = sanitize($_POST['end_date'] ?? '');
    if (!$label || !$start || !$end) $errors[] = 'Champs obligatoires.';
    if (empty($errors)) {
        Database::insert('INSERT INTO academic_years (label,start_date,end_date,is_current) VALUES (?,?,?,0)', [$label,$start,$end]);
        setFlash('success','Année ajoutée.');
        header('Location: ' . BASE_URL . '/modules/admin/pages/years.php');
        exit;
    }
}

if ($action === 'set_current_year' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    Database::execute('UPDATE academic_years SET is_current=0');
    Database::execute('UPDATE academic_years SET is_current=1 WHERE id=?', [$id]);
    setFlash('success','Année courante mise à jour.');
    header('Location: ' . BASE_URL . '/modules/admin/pages/years.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_trim') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) $errors[] = 'Token invalide.';
    $yearId = (int)($_POST['academic_year_id'] ?? 0);
    $label = sanitize($_POST['label'] ?? '');
    $num = (int)($_POST['number'] ?? 1);
    $start = sanitize($_POST['start_date'] ?? '');
    $end = sanitize($_POST['end_date'] ?? '');
    if (!$yearId || !$label || !$start || !$end) $errors[] = 'Champs obligatoires.';
    if (empty($errors)) {
        Database::insert('INSERT INTO trimesters (academic_year_id,label,number,start_date,end_date,is_current) VALUES (?,?,?,?,?,0)', [$yearId,$label,$num,$start,$end]);
        setFlash('success','Trimestre ajouté.');
        header('Location: ' . BASE_URL . '/modules/admin/pages/years.php');
        exit;
    }
}

if ($action === 'set_current_trim' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    Database::execute('UPDATE trimesters SET is_current=0');
    Database::execute('UPDATE trimesters SET is_current=1 WHERE id=?', [$id]);
    setFlash('success','Trimestre courant mis à jour.');
    header('Location: ' . BASE_URL . '/modules/admin/pages/years.php');
    exit;
}

$years = Database::fetchAll('SELECT * FROM academic_years ORDER BY start_date DESC');
$trims = Database::fetchAll(
  'SELECT t.*, y.label AS year_label
   FROM trimesters t JOIN academic_years y ON y.id=t.academic_year_id
   ORDER BY y.start_date DESC, t.number ASC'
);

$pageTitle = 'Année scolaire';
$pageIcon  = '🗓️';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="card mb-20">
  <div class="card-title">Ajouter une année</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="add_year">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <input class="form-input" name="label" placeholder="2025-2026" required>
    <input class="form-input" type="date" name="start_date" required>
    <input class="form-input" type="date" name="end_date" required>
    <button class="btn btn-primary" type="submit">Ajouter</button>
  </form>
</div>

<div class="card mb-20">
  <div class="card-title">Années</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Label</th><th>Dates</th><th>Courante</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($years as $y): ?>
        <tr>
          <td><?= e($y['label']) ?></td>
          <td><?= e($y['start_date'].' → '.$y['end_date']) ?></td>
          <td><?= (int)$y['is_current'] ? '<span class="badge badge-green">oui</span>' : '—' ?></td>
          <td style="text-align:right;">
            <a class="btn btn-secondary btn-sm" href="?action=set_current_year&id=<?= (int)$y['id'] ?>">Définir courante</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-20">
  <div class="card-title">Ajouter un trimestre</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="add_trim">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <select class="form-select" name="academic_year_id" required style="grid-column:span 2;">
      <option value="">Année</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= (int)$y['id'] ?>"><?= e($y['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <input class="form-input" name="label" placeholder="Trimestre 1" required>
    <input class="form-input" type="number" name="number" value="1" min="1" max="4" required>
    <input class="form-input" type="date" name="start_date" required>
    <input class="form-input" type="date" name="end_date" required>
    <button class="btn btn-primary" type="submit" style="grid-column:span 6;">Ajouter</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Trimestres</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Année</th><th>Label</th><th>Dates</th><th>Courant</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($trims as $t): ?>
        <tr>
          <td><?= e($t['year_label']) ?></td>
          <td><?= e($t['label']) ?></td>
          <td><?= e($t['start_date'].' → '.$t['end_date']) ?></td>
          <td><?= (int)$t['is_current'] ? '<span class="badge badge-green">oui</span>' : '—' ?></td>
          <td style="text-align:right;">
            <a class="btn btn-secondary btn-sm" href="?action=set_current_trim&id=<?= (int)$t['id'] ?>">Définir courant</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

