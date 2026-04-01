<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('teacher');

$teacherId = (int)Auth::id();
$yearId    = (int)(getCurrentYear()['id'] ?? 0);
$errors = [];

$classSubjects = Database::fetchAll(
  'SELECT cs.id, c.name AS class_name, sub.name AS subject_name, sub.icon
   FROM class_subjects cs
   JOIN classes c ON c.id=cs.class_id
   JOIN subjects sub ON sub.id=cs.subject_id
   WHERE cs.teacher_id=? AND c.academic_year_id=?
   ORDER BY c.level, c.section, sub.name',
  [$teacherId, $yearId]
);
$csId = (int)($_GET['cs_id'] ?? ($classSubjects[0]['id'] ?? 0));

$homeworks = [];
if ($csId) {
  $homeworks = Database::fetchAll(
    'SELECT gb.*,
            (SELECT COUNT(*) FROM homework_submissions hs WHERE hs.gradebook_id=gb.id) AS submissions
     FROM gradebook gb
     WHERE gb.class_subject_id=? AND gb.homework IS NOT NULL AND gb.homework<>""
     ORDER BY gb.homework_due DESC, gb.date DESC
     LIMIT 100',
    [$csId]
  );
}
$gbId = (int)($_GET['gb_id'] ?? ($homeworks[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'decide') {
  if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Token invalide.';
  } else {
    $subId = (int)($_POST['submission_id'] ?? 0);
    $decision = sanitize($_POST['decision'] ?? 'validated'); // validated|rejected
    $note = sanitize($_POST['note'] ?? '');
    $decision = in_array($decision, ['validated','rejected'], true) ? $decision : 'validated';
    try {
      $sub = Database::fetchOne(
        'SELECT hs.*, s.user_id AS student_user_id
         FROM homework_submissions hs
         JOIN students s ON s.id=hs.student_id
         JOIN gradebook gb ON gb.id=hs.gradebook_id
         WHERE hs.id=? AND gb.class_subject_id IN (SELECT id FROM class_subjects WHERE id=? AND teacher_id=?)',
        [$subId, $csId, $teacherId]
      );
      if (!$sub) throw new RuntimeException('Not found');

      Database::execute(
        'UPDATE homework_submissions
         SET status=?, note=?, validated_by=?, validated_at=NOW()
         WHERE id=?',
        [$decision, $note ?: null, $teacherId, $subId]
      );
      createNotification((int)$sub['student_user_id'], 'announcement', 'Devoir', 'Votre devoir a ete ' . ($decision==='validated'?'valide':'rejete') . '.', BASE_URL . '/modules/student/pages/homework.php');
      setFlash('success','Decision enregistree.');
      header('Location: ' . BASE_URL . '/modules/teacher/pages/homework.php?cs_id=' . $csId . '&gb_id=' . (int)$sub['gradebook_id']);
      exit;
    } catch (Throwable $e) {
      $errors[] = 'Erreur validation.';
    }
  }
}

$subs = [];
if ($gbId) {
  $subs = Database::fetchAll(
    'SELECT hs.*, s.matricule, u.first_name, u.last_name
     FROM homework_submissions hs
     JOIN students s ON s.id=hs.student_id
     JOIN users u ON u.id=s.user_id
     WHERE hs.gradebook_id=?
     ORDER BY hs.submitted_at DESC',
    [$gbId]
  );
}

$pageTitle = 'Devoirs (validation)';
$pageIcon  = '🧾';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="card mb-20">
  <div class="card-title">Filtrer</div>
  <form method="get" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
    <select class="form-select" name="cs_id" onchange="this.form.submit()">
      <?php foreach ($classSubjects as $cs): ?>
        <option value="<?= (int)$cs['id'] ?>" <?= (int)$cs['id']===$csId?'selected':'' ?>>
          <?= $cs['icon'] ?> <?= e($cs['subject_name']) ?> — <?= e($cs['class_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" name="gb_id" onchange="this.form.submit()">
      <?php foreach ($homeworks as $hw): ?>
        <option value="<?= (int)$hw['id'] ?>" <?= (int)$hw['id']===$gbId?'selected':'' ?>>
          <?= e(formatDate($hw['homework_due'] ?: $hw['date'])) ?> — <?= e($hw['lesson_title']) ?> (<?= (int)$hw['submissions'] ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit">Afficher</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Soumissions</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Élève</th><th>Soumis</th><th>Fichier</th><th>Statut</th><th>Décision</th></tr></thead>
      <tbody>
      <?php foreach ($subs as $s): ?>
        <tr>
          <td><?= e($s['last_name'].' '.$s['first_name'].' (#'.$s['matricule'].')') ?></td>
          <td><?= e(timeAgo($s['submitted_at'])) ?></td>
          <td>
            <?php if (!empty($s['file_path'])): ?>
              <a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/<?= e($s['file_path']) ?>" target="_blank">Télécharger</a>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td><span class="badge <?= $s['status']==='validated'?'badge-green':($s['status']==='rejected'?'badge-red':'badge-amber') ?>"><?= e($s['status']) ?></span></td>
          <td style="min-width:320px;">
            <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <input type="hidden" name="action" value="decide">
              <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
              <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
              <select class="form-select" name="decision" style="max-width:150px;">
                <option value="validated">Valider</option>
                <option value="rejected">Rejeter</option>
              </select>
              <input class="form-input" name="note" placeholder="Commentaire (optionnel)" value="<?= e($s['note'] ?? '') ?>" style="max-width:220px;">
              <button class="btn btn-primary btn-sm" type="submit">OK</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($subs)): ?><tr><td colspan="5">Aucune soumission.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

