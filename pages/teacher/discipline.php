<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('teacher');

$teacherId = Auth::id();
$yearId    = getCurrentYear()['id'] ?? 0;
$errors = [];

$students = Database::fetchAll(
  'SELECT s.id, s.matricule, u.first_name, u.last_name, c.name AS class_name
   FROM students s
   JOIN users u ON u.id = s.user_id
   JOIN classes c ON c.id = s.class_id
   WHERE s.academic_year_id = ? AND s.status="enrolled"
   ORDER BY c.level, c.section, u.last_name, u.first_name
   LIMIT 600',
  [$yearId]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Token invalide.';
  } else {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $type = sanitize($_POST['type'] ?? 'warning');
    $reason = trim($_POST['reason'] ?? '');
    $date = sanitize($_POST['date'] ?? date('Y-m-d'));
    $duration = sanitize($_POST['duration'] ?? '');
    $type = in_array($type, ['warning','detention','suspension','exclusion','commendation'], true) ? $type : 'warning';

    if (!$studentId || !$reason) $errors[] = 'Champs obligatoires.';
    if (empty($errors)) {
      try {
        Database::insert(
          'INSERT INTO discipline (student_id,type,reason,date,duration,reported_by) VALUES (?,?,?,?,?,?)',
          [$studentId, $type, $reason, $date, $duration ?: null, $teacherId]
        );
        // notifier parent principal
        $parents = Database::fetchAll(
          'SELECT u.id AS parent_id
           FROM student_parents sp
           JOIN users u ON u.id = sp.parent_id
           WHERE sp.student_id = ? AND sp.is_primary = 1',
          [$studentId]
        );
        foreach ($parents as $p) {
          createNotification((int)$p['parent_id'], 'announcement', 'Discipline', 'Un evenement disciplinaire a ete enregistre.', BASE_URL . '/modules/common/pages/coming-soon.php?module=Discipline');
        }
        setFlash('success','Enregistrement discipline OK.');
        header('Location: ' . BASE_URL . '/modules/teacher/pages/discipline.php');
        exit;
      } catch (Throwable $e) {
        $errors[] = 'Erreur insertion.';
      }
    }
  }
}

$recent = Database::fetchAll(
  'SELECT d.*, u.first_name, u.last_name, s.matricule, c.name AS class_name
   FROM discipline d
   JOIN students s ON s.id = d.student_id
   JOIN users u ON u.id = s.user_id
   JOIN classes c ON c.id = s.class_id
   ORDER BY d.created_at DESC
   LIMIT 120'
);

$pageTitle = 'Discipline';
$pageIcon  = '⚖️';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="card mb-20">
  <div class="card-title">Nouvel evenement</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <select class="form-select" name="student_id" required style="grid-column:span 2;">
      <option value="">Eleve</option>
      <?php foreach ($students as $s): ?>
        <option value="<?= (int)$s['id'] ?>"><?= e($s['class_name'].' - '.$s['last_name'].' '.$s['first_name'].' (#'.$s['matricule'].')') ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" name="type">
      <option value="warning">Avertissement</option>
      <option value="detention">Retenue</option>
      <option value="suspension">Suspension</option>
      <option value="exclusion">Exclusion</option>
      <option value="commendation">Felicitation</option>
    </select>
    <input class="form-input" type="date" name="date" value="<?= e(date('Y-m-d')) ?>">
    <input class="form-input" name="duration" placeholder="Duree (optionnel)" style="grid-column:span 2;">
    <textarea class="form-input" name="reason" placeholder="Motif / description" required style="grid-column:span 4;min-height:90px;"></textarea>
    <button class="btn btn-primary" type="submit" style="grid-column:span 4;">Enregistrer</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Historique recent</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Eleve</th><th>Classe</th><th>Type</th><th>Motif</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= e(formatDate($r['date'])) ?></td>
          <td><?= e($r['last_name'].' '.$r['first_name'].' (#'.$r['matricule'].')') ?></td>
          <td><?= e($r['class_name']) ?></td>
          <td><span class="badge <?= $r['type']==='commendation'?'badge-green':'badge-amber' ?>"><?= e($r['type']) ?></span></td>
          <td><?= e(substr($r['reason'],0,80)) ?><?= strlen($r['reason'])>80?'…':'' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($recent)): ?><tr><td colspan="5">Aucun.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

