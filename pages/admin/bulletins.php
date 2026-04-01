<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$yearId = (int)(getCurrentYear()['id'] ?? 0);
$trims = Database::fetchAll('SELECT * FROM trimesters WHERE academic_year_id=? ORDER BY number', [$yearId]);
$trimId = (int)($_GET['trimester_id'] ?? ((int)($trims[0]['id'] ?? 0)));

$classes = Database::fetchAll(
  'SELECT id, name FROM classes WHERE academic_year_id=? ORDER BY level, section',
  [$yearId]
);
$classId = (int)($_GET['class_id'] ?? ((int)($classes[0]['id'] ?? 0)));

$students = [];
if ($classId) {
  $students = Database::fetchAll(
    'SELECT s.id AS student_id, s.matricule, u.first_name, u.last_name
     FROM students s
     JOIN users u ON u.id = s.user_id
     WHERE s.class_id=? AND s.academic_year_id=? AND s.status="enrolled"
     ORDER BY u.last_name, u.first_name',
    [$classId, $yearId]
  );
}

$pageTitle = 'Bulletins';
$pageIcon  = '📄';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Génération des bulletins</div>
  <form method="get" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
    <select class="form-select" name="class_id">
      <?php foreach ($classes as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===$classId?'selected':'' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" name="trimester_id">
      <?php foreach ($trims as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id']===$trimId?'selected':'' ?>><?= e($t['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit">Afficher</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Élèves</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Élève</th><th>Matricule</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($students as $s): ?>
        <tr>
          <td><?= e($s['last_name'].' '.$s['first_name']) ?></td>
          <td><?= e($s['matricule']) ?></td>
          <td>
            <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/modules/common/pages/bulletin.php?student_id=<?= (int)$s['student_id'] ?>&trimester_id=<?= (int)$trimId ?>">
              Ouvrir / Imprimer
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($students)): ?><tr><td colspan="3">Aucun élève.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

