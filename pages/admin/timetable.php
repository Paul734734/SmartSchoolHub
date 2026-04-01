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

$classes = Database::fetchAll(
    'SELECT id, name, level, section FROM classes WHERE academic_year_id = ? ORDER BY level, section, name',
    [$yearId]
);

$classSubjects = Database::fetchAll(
    'SELECT cs.id, c.name AS class_name, sub.name AS subject_name, sub.icon,
            CONCAT(u.first_name," ",u.last_name) AS teacher_name
     FROM class_subjects cs
     JOIN classes c ON c.id = cs.class_id
     JOIN subjects sub ON sub.id = cs.subject_id
     JOIN users u ON u.id = cs.teacher_id
     WHERE c.academic_year_id = ?
     ORDER BY c.level, c.section, sub.name',
    [$yearId]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_slot') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de securite invalide.';
    } else {
        $csId = (int)($_POST['class_subject_id'] ?? 0);
        $dow  = (int)($_POST['day_of_week'] ?? 1);
        $start = sanitize($_POST['start_time'] ?? '');
        $end   = sanitize($_POST['end_time'] ?? '');
        $room  = sanitize($_POST['room'] ?? '');

        if (!$csId || $dow < 1 || $dow > 6 || !$start || !$end || !$yearId) {
            $errors[] = 'Champs invalides.';
        } else {
            try {
                Database::insert(
                    'INSERT INTO timetable (academic_year_id,class_subject_id,day_of_week,start_time,end_time,room)
                     VALUES (?,?,?,?,?,?)',
                    [$yearId, $csId, $dow, $start, $end, $room ?: null]
                );
                setFlash('success', 'Creneau ajoute.');
                header('Location: ' . BASE_URL . '/modules/admin/pages/timetable.php');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Erreur (conflit ou doublon possible).';
            }
        }
    }
}

$selectedClassId = (int)($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
$slots = [];
if ($selectedClassId) {
    $slots = Database::fetchAll(
        'SELECT tt.*, sub.name AS subject_name, sub.icon, c.name AS class_name,
                CONCAT(u.first_name," ",u.last_name) AS teacher_name
         FROM timetable tt
         JOIN class_subjects cs ON cs.id = tt.class_subject_id
         JOIN classes c ON c.id = cs.class_id
         JOIN subjects sub ON sub.id = cs.subject_id
         JOIN users u ON u.id = cs.teacher_id
         WHERE tt.academic_year_id = ? AND cs.class_id = ?
         ORDER BY tt.day_of_week, tt.start_time',
        [$yearId, $selectedClassId]
    );
}

$pageTitle = 'Emploi du temps';
$pageIcon  = '📅';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Ajouter un creneau</div>
  <?php if (!empty($errors)): ?>
    <div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div>
  <?php endif; ?>
  <form method="post" style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="add_slot">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <select class="form-select" name="class_subject_id" required style="grid-column:span 2;">
      <option value="">Classe / Matiere / Prof</option>
      <?php foreach ($classSubjects as $cs): ?>
        <option value="<?= (int)$cs['id'] ?>">
          <?= $cs['icon'] ?> <?= e($cs['class_name']) ?> — <?= e($cs['subject_name']) ?> (<?= e($cs['teacher_name']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" name="day_of_week" required>
      <option value="1">Lundi</option>
      <option value="2">Mardi</option>
      <option value="3">Mercredi</option>
      <option value="4">Jeudi</option>
      <option value="5">Vendredi</option>
      <option value="6">Samedi</option>
    </select>
    <input class="form-input" type="time" name="start_time" required>
    <input class="form-input" type="time" name="end_time" required>
    <input class="form-input" name="room" placeholder="Salle">
    <button class="btn btn-primary" type="submit" style="grid-column:span 6;">Ajouter</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Voir l EDT par classe</div>
  <form method="get" style="display:flex;gap:10px;margin-bottom:12px;">
    <select class="form-select" name="class_id" onchange="this.form.submit()">
      <?php foreach ($classes as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $selectedClassId ? 'selected' : '' ?>>
          <?= e($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <div class="table-wrap">
    <table>
      <thead>
      <tr>
        <th>Jour</th><th>Heure</th><th>Matiere</th><th>Prof</th><th>Salle</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($slots as $s): ?>
        <tr>
          <td><?= e(dayName((int)$s['day_of_week'])) ?></td>
          <td><?= e(substr($s['start_time'],0,5)) ?> - <?= e(substr($s['end_time'],0,5)) ?></td>
          <td><?= $s['icon'] ?> <?= e($s['subject_name']) ?></td>
          <td><?= e($s['teacher_name']) ?></td>
          <td><?= e($s['room'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($slots)): ?>
        <tr><td colspan="5">Aucun creneau.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
