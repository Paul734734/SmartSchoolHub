<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('student');

$userId = (int)Auth::id();
$yearId = (int)(getCurrentYear()['id'] ?? 0);

$student = Database::fetchOne(
  'SELECT s.id AS student_id, s.class_id, c.name AS class_name
   FROM students s
   JOIN classes c ON c.id=s.class_id
   WHERE s.user_id=? AND s.academic_year_id=?',
  [$userId, $yearId]
);
if (!$student) { setFlash('error','Profil eleve introuvable.'); header('Location: '.BASE_URL.'/login.php'); exit; }
$studentId = (int)$student['student_id'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'justify') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token invalide.';
    } else {
        $attId = (int)($_POST['attendance_id'] ?? 0);
        $just = trim($_POST['justification'] ?? '');
        if (!$attId || !$just) $errors[] = 'Motif obligatoire.';
        if (empty($errors)) {
            Database::execute(
              'UPDATE attendance SET justification=? WHERE id=? AND student_id=?',
              [$just, $attId, $studentId]
            );
            setFlash('success','Justification envoyee.');
            header('Location: ' . BASE_URL . '/modules/student/pages/attendance.php');
            exit;
        }
    }
}

$rows = Database::fetchAll(
  'SELECT a.*, sub.name AS subject_name, sub.icon, tt.room
   FROM attendance a
   JOIN timetable tt ON tt.class_subject_id=a.class_subject_id
   JOIN class_subjects cs ON cs.id=tt.class_subject_id
   JOIN subjects sub ON sub.id=cs.subject_id
   WHERE a.student_id=?
   ORDER BY a.date DESC
   LIMIT 200',
  [$studentId]
);

$pageTitle = 'Mes absences';
$pageIcon  = '🚫';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="card">
  <div class="card-title">Absences & retards — <?= e($student['class_name']) ?></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Cours</th><th>Statut</th><th>Salle</th><th>Justification</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(formatDate($r['date'])) ?></td>
          <td><?= e($r['icon'].' '.$r['subject_name']) ?></td>
          <td><span class="badge <?= $r['status']==='present'?'badge-green':($r['status']==='late'?'badge-amber':'badge-red') ?>"><?= e($r['status']) ?></span></td>
          <td><?= e($r['room'] ?: '—') ?></td>
          <td>
            <?php if (!empty($r['justification'])): ?>
              <span class="badge badge-blue">justifie</span>
            <?php else: ?>
              <form method="post" style="display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="action" value="justify">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="attendance_id" value="<?= (int)$r['id'] ?>">
                <input class="form-input" name="justification" placeholder="Motif..." style="max-width:240px;">
                <button class="btn btn-secondary btn-sm" type="submit">Envoyer</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="5">Aucun enregistrement.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

