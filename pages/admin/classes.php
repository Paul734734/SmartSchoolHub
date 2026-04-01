<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$year = getCurrentYear();
$yearId = (int)($year['id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_class') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de securite invalide.';
    } else {
        $name     = sanitize($_POST['name'] ?? '');
        $level    = sanitize($_POST['level'] ?? '');
        $section  = sanitize($_POST['section'] ?? '');
        $capacity = (int)($_POST['capacity'] ?? 45);
        $room     = sanitize($_POST['room'] ?? '');
        $headId   = (int)($_POST['head_teacher_id'] ?? 0);

        if (!$name || !$level || !$section || !$yearId) {
            $errors[] = 'Veuillez remplir les champs obligatoires.';
        }

        if (empty($errors)) {
            try {
                Database::insert(
                    'INSERT INTO classes (academic_year_id,name,level,section,capacity,room,head_teacher_id)
                     VALUES (?,?,?,?,?,?,?)',
                    [$yearId, $name, $level, $section, max(1, $capacity), $room ?: null, $headId ?: null]
                );
                setFlash('success', 'Classe creee.');
                header('Location: ' . BASE_URL . '/modules/admin/pages/classes.php');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Erreur lors de la creation (verifie doublon nom/annee).';
            }
        }
    }
}

$classes = Database::fetchAll(
    'SELECT c.*, 
            (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status="enrolled") AS student_count,
            CONCAT(u.first_name," ",u.last_name) AS head_name
     FROM classes c
     LEFT JOIN users u ON u.id = c.head_teacher_id
     WHERE c.academic_year_id = ?
     ORDER BY c.level, c.section, c.name',
    [$yearId]
);

$teachers = Database::fetchAll(
    'SELECT id, first_name, last_name FROM users WHERE role="teacher" AND is_active=1 ORDER BY last_name, first_name'
);

$pageTitle = 'Gestion des classes';
$pageIcon  = '🏛️';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Creer une classe</div>
  <?php if (!empty($errors)): ?>
    <div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div>
  <?php endif; ?>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="add_class">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <input class="form-input" name="name" placeholder="Nom (ex: 3e A)" required>
    <input class="form-input" name="level" placeholder="Niveau (ex: 3e)" required>
    <input class="form-input" name="section" placeholder="Section (ex: A)" required>
    <input class="form-input" name="room" placeholder="Salle (optionnel)">
    <input class="form-input" name="capacity" type="number" min="1" max="80" value="45">
    <select class="form-select" name="head_teacher_id">
      <option value="">Titulaire (optionnel)</option>
      <?php foreach ($teachers as $t): ?>
        <option value="<?= (int)$t['id'] ?>"><?= e($t['last_name'] . ' ' . $t['first_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit" style="grid-column:span 4;">Creer la classe</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Classes (annee <?= e($year['label'] ?? '—') ?>)</div>
  <div class="table-wrap">
    <table>
      <thead>
      <tr>
        <th>Classe</th><th>Niveau</th><th>Section</th><th>Salle</th><th>Capacite</th><th>Eleves</th><th>Titulaire</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($classes as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><?= e($c['level']) ?></td>
          <td><?= e($c['section']) ?></td>
          <td><?= e($c['room'] ?? '—') ?></td>
          <td><?= (int)$c['capacity'] ?></td>
          <td><span class="badge badge-blue"><?= (int)$c['student_count'] ?></span></td>
          <td><?= e($c['head_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($classes)): ?>
        <tr><td colspan="7">Aucune classe.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
