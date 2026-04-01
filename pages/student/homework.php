<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('student');

$userId = Auth::id();
$yearId = getCurrentYear()['id'] ?? 0;

$student = Database::fetchOne(
  'SELECT s.id AS student_id, s.class_id, c.name AS class_name
   FROM students s
   JOIN classes c ON c.id = s.class_id
   WHERE s.user_id = ? AND s.academic_year_id = ?',
  [$userId, $yearId]
);
if (!$student) { setFlash('error','Profil eleve introuvable.'); header('Location: '.BASE_URL.'/login.php'); exit; }

$studentId = (int)$student['student_id'];
$classId   = (int)$student['class_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_hw') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token invalide.';
    } else {
        $gbId = (int)($_POST['gradebook_id'] ?? 0);
        $filePath = null;
        if (!empty($_FILES['file']['name'] ?? '')) {
            $filePath = uploadFile($_FILES['file'], 'submissions');
        }
        try {
            Database::execute(
                'INSERT INTO homework_submissions (gradebook_id,student_id,status,file_path)
                 VALUES (?,?, "submitted", ?)
                 ON DUPLICATE KEY UPDATE status="submitted", file_path=VALUES(file_path), submitted_at=NOW()',
                [$gbId, $studentId, $filePath]
            );
            setFlash('success','Devoir marque comme rendu.');
            header('Location: ' . BASE_URL . '/modules/student/pages/homework.php');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Erreur soumission.';
        }
    }
}

$rows = Database::fetchAll(
  'SELECT gb.id, gb.date, gb.lesson_title, gb.homework, gb.homework_due,
          sub.name AS subject_name, sub.icon,
          hs.status AS sub_status, hs.submitted_at, hs.validated_at
   FROM gradebook gb
   JOIN class_subjects cs ON cs.id = gb.class_subject_id
   JOIN subjects sub ON sub.id = cs.subject_id
   LEFT JOIN homework_submissions hs ON hs.gradebook_id = gb.id AND hs.student_id = ?
   WHERE cs.class_id = ? AND gb.homework IS NOT NULL AND gb.homework <> ""
   ORDER BY (gb.homework_due IS NULL) ASC, gb.homework_due ASC, gb.date DESC
   LIMIT 200',
  [$studentId, $classId]
);

$resources = [];
if (!empty($rows)) {
  $ids = array_map(fn($r) => (int)$r['id'], $rows);
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $res = Database::fetchAll(
    'SELECT * FROM gradebook_resources WHERE gradebook_id IN (' . $in . ') ORDER BY created_at DESC',
    $ids
  );
  foreach ($res as $r) { $resources[(int)$r['gradebook_id']][] = $r; }
}

$pageTitle = 'Mes devoirs';
$pageIcon  = '🧾';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="card">
  <div class="card-title">Devoirs — <?= e($student['class_name']) ?></div>
  <?php foreach ($rows as $r):
    $due = $r['homework_due'] ? strtotime($r['homework_due']) : null;
    $days = $due ? (int)floor(($due - time())/86400) : null;
    $urgentColor = $days !== null && $days <= 1 ? 'var(--rose)' : ($days !== null && $days <= 3 ? 'var(--amber)' : 'var(--blue)');
    $status = $r['sub_status'] ?? '';
  ?>
    <div style="padding:14px 0;border-bottom:1px solid var(--border);">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
        <div>
          <div style="font-weight:800;color:var(--navy);"><?= $r['icon'] ?> <?= e($r['subject_name']) ?> — <?= e($r['lesson_title']) ?></div>
          <div style="color:var(--muted);font-size:12px;margin-top:4px;max-width:820px;">
            <?= e(substr(strip_tags($r['homework']), 0, 220)) ?><?= strlen($r['homework'])>220 ? '…' : '' ?>
          </div>
          <div style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($r['homework_due']): ?>
              <span class="badge" style="background:#EFF6FF;color:<?= $urgentColor ?>;"><?= e('Echeance: '.formatDate($r['homework_due'])) ?></span>
            <?php endif; ?>
            <?php if ($status): ?>
              <span class="badge <?= $status==='validated'?'badge-green':($status==='rejected'?'badge-red':'badge-amber') ?>">
                <?= e($status) ?>
              </span>
            <?php else: ?>
              <span class="badge badge-gray">non rendu</span>
            <?php endif; ?>
          </div>
        </div>

        <div style="min-width:220px;">
          <?php if (!empty($resources[(int)$r['id']] ?? [])): ?>
            <div style="margin-bottom:10px;">
              <?php foreach (($resources[(int)$r['id']] ?? []) as $res): ?>
                <div style="font-size:12px;margin:4px 0;">
                  📎 <?= e($res['title']) ?>:
                  <?php if (!empty($res['file_path'])): ?>
                    <a href="<?= BASE_URL ?>/<?= e($res['file_path']) ?>" target="_blank">Télécharger</a>
                  <?php endif; ?>
                  <?php if (!empty($res['url'])): ?>
                    <a href="<?= e($res['url']) ?>" target="_blank">Lien</a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="submit_hw">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="gradebook_id" value="<?= (int)$r['id'] ?>">
            <input class="form-input" type="file" name="file" style="margin-bottom:8px;">
            <button class="btn btn-secondary btn-sm" type="submit">Marquer rendu</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (empty($rows)): ?>
    <div class="empty-state" style="padding:20px;"><p>Aucun devoir pour le moment.</p></div>
  <?php endif; ?>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

