<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$errors = [];
$action = sanitize($_POST['action'] ?? ($_GET['action'] ?? ''));
$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token invalide.';
    } else {
        $name = sanitize($_POST['name'] ?? '');
        $short = sanitize($_POST['short_name'] ?? '');
        $icon = sanitize($_POST['icon'] ?? '📘');
        $color = sanitize($_POST['color'] ?? '#1A56DB');
        if (!$name) $errors[] = 'Nom obligatoire.';
        if (empty($errors)) {
            if ($id) {
                Database::execute('UPDATE subjects SET name=?, short_name=?, icon=?, color=? WHERE id=?', [$name, $short, $icon, $color, $id]);
                setFlash('success','Matière mise à jour.');
            } else {
                Database::insert('INSERT INTO subjects (name, short_name, icon, color) VALUES (?,?,?,?)', [$name, $short, $icon, $color]);
                setFlash('success','Matière ajoutée.');
            }
            header('Location: ' . BASE_URL . '/modules/admin/pages/subjects.php');
            exit;
        }
    }
}

if ($action === 'delete' && $id) {
    Database::execute('DELETE FROM subjects WHERE id=?', [$id]);
    setFlash('success','Matière supprimée.');
    header('Location: ' . BASE_URL . '/modules/admin/pages/subjects.php');
    exit;
}

$editing = $id ? Database::fetchOne('SELECT * FROM subjects WHERE id=?', [$id]) : null;
$rows = Database::fetchAll('SELECT * FROM subjects ORDER BY name LIMIT 300');

$pageTitle = 'Matières';
$pageIcon  = '📚';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="card mb-20">
  <div class="card-title"><?= $editing ? 'Modifier' : 'Ajouter' ?> une matière</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <input class="form-input" name="name" placeholder="Nom" value="<?= e($editing['name'] ?? '') ?>" required style="grid-column:span 2;">
    <input class="form-input" name="short_name" placeholder="Abréviation" value="<?= e($editing['short_name'] ?? '') ?>">
    <input class="form-input" name="icon" placeholder="Icon" value="<?= e($editing['icon'] ?? '📘') ?>">
    <input class="form-input" name="color" placeholder="#1A56DB" value="<?= e($editing['color'] ?? '#1A56DB') ?>">
    <button class="btn btn-primary" type="submit" style="grid-column:span 4;"><?= $editing ? 'Mettre à jour' : 'Ajouter' ?></button>
  </form>
</div>

<div class="card">
  <div class="card-title">Liste</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Icon</th><th>Nom</th><th>Code</th><th>Couleur</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['icon']) ?></td>
          <td><?= e($r['name']) ?></td>
          <td><?= e($r['short_name']) ?></td>
          <td><span class="badge badge-blue"><?= e($r['color']) ?></span></td>
          <td style="text-align:right;">
            <a class="btn btn-secondary btn-sm" href="?id=<?= (int)$r['id'] ?>">Éditer</a>
            <a class="btn btn-danger btn-sm" href="?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Supprimer ?')">Supprimer</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

