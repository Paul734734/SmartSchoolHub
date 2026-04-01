<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('teacher');

$teacherId = Auth::id();
$yearId    = getCurrentYear()['id'] ?? 0;

$classSubjects = Database::fetchAll(
  'SELECT cs.id, c.name AS class_name, sub.name AS subject_name, sub.icon
   FROM class_subjects cs
   JOIN classes c ON c.id = cs.class_id
   JOIN subjects sub ON sub.id = cs.subject_id
   WHERE cs.teacher_id = ? AND c.academic_year_id = ?
   ORDER BY c.level, c.section, sub.name',
  [$teacherId, $yearId]
);

$selectedCsId = (int)($_GET['cs_id'] ?? ($classSubjects[0]['id'] ?? 0));
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_entry') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token invalide.';
    } else {
        $csId    = (int)($_POST['cs_id'] ?? 0);
        $date    = sanitize($_POST['date'] ?? date('Y-m-d'));
        $title   = sanitize($_POST['lesson_title'] ?? '');
        $content = trim($_POST['lesson_content'] ?? '');
        $hw      = trim($_POST['homework'] ?? '');
        $due     = sanitize($_POST['homework_due'] ?? '');

        $owns = (int)Database::scalar('SELECT COUNT(*) FROM class_subjects WHERE id=? AND teacher_id=?', [$csId, $teacherId]);
        if (!$owns || !$title) {
            $errors[] = 'Donnees invalides.';
        } else {
            try {
                Database::beginTransaction();
                $gbId = (int)Database::insert(
                    'INSERT INTO gradebook (class_subject_id,date,lesson_title,lesson_content,homework,homework_due,created_by)
                     VALUES (?,?,?,?,?,?,?)',
                    [$csId, $date, $title, $content ?: null, $hw ?: null, $due ?: null, $teacherId]
                );

                // Ressource jointe
                $resTitle = sanitize($_POST['res_title'] ?? '');
                $resUrl   = sanitize($_POST['res_url'] ?? '');
                $filePath = null;
                if (!empty($_FILES['res_file']['name'] ?? '')) {
                    $filePath = uploadFile($_FILES['res_file'], 'resources');
                }
                if ($resTitle && ($filePath || $resUrl)) {
                    Database::insert(
                        'INSERT INTO gradebook_resources (gradebook_id,title,file_path,url) VALUES (?,?,?,?)',
                        [$gbId, $resTitle, $filePath, $resUrl ?: null]
                    );
                }

                Database::commit();
                setFlash('success', 'Cahier de texte publie.');
                header('Location: ' . BASE_URL . '/modules/teacher/pages/gradebook.php?cs_id=' . $csId);
                exit;
            } catch (Throwable $e) {
                Database::rollBack();
                $errors[] = 'Erreur lors de la publication.';
            }
        }
    }
}

$entries = [];
if ($selectedCsId) {
    $entries = Database::fetchAll(
        'SELECT gb.*,
                (SELECT COUNT(*) FROM homework_submissions hs WHERE hs.gradebook_id=gb.id) AS submissions
         FROM gradebook gb
         WHERE gb.class_subject_id = ?
         ORDER BY gb.date DESC, gb.created_at DESC
         LIMIT 200',
        [$selectedCsId]
    );
}

$pageTitle = 'Cahier de texte';
$pageIcon  = '📚';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Publier un cours / devoir</div>
  <?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="add_entry">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

    <select class="form-select" name="cs_id" required style="grid-column:span 2;">
      <?php foreach ($classSubjects as $cs): ?>
        <option value="<?= (int)$cs['id'] ?>" <?= (int)$cs['id'] === $selectedCsId ? 'selected' : '' ?>>
          <?= $cs['icon'] ?> <?= e($cs['subject_name']) ?> — <?= e($cs['class_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <input class="form-input" type="date" name="date" value="<?= e(date('Y-m-d')) ?>" required>
    <input class="form-input" type="date" name="homework_due" placeholder="Echeance devoir">

    <input class="form-input" name="lesson_title" placeholder="Titre du cours" required style="grid-column:span 4;">
    <textarea class="form-input" name="lesson_content" placeholder="Contenu du cours (optionnel)" style="grid-column:span 4;min-height:90px;"></textarea>
    <textarea class="form-input" name="homework" placeholder="TAF / Devoir (optionnel)" style="grid-column:span 4;min-height:70px;"></textarea>

    <div style="grid-column:span 4;border-top:1px solid var(--border);padding-top:10px;">
      <div style="font-weight:800;color:var(--navy);margin-bottom:6px;">Support / Ressource (PDF, image, lien)</div>
      <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
        <input class="form-input" name="res_title" placeholder="Titre ressource (ex: Cours PDF)">
        <input class="form-input" name="res_url" placeholder="Lien (optionnel)">
        <input class="form-input" type="file" name="res_file">
      </div>
    </div>

    <button class="btn btn-primary" type="submit" style="grid-column:span 4;">Publier</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Historique</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Titre</th><th>Devoir</th><th>Echeance</th><th>Soumissions</th></tr></thead>
      <tbody>
      <?php foreach ($entries as $eRow): ?>
        <tr>
          <td><?= e(formatDate($eRow['date'])) ?></td>
          <td><?= e($eRow['lesson_title']) ?></td>
          <td><?= $eRow['homework'] ? '✓' : '—' ?></td>
          <td><?= $eRow['homework_due'] ? e(formatDate($eRow['homework_due'])) : '—' ?></td>
          <td><span class="badge badge-blue"><?= (int)$eRow['submissions'] ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($entries)): ?><tr><td colspan="5">Aucune publication.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

