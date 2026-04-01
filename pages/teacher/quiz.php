<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('teacher');

$teacherId = (int)Auth::id();
$yearId = (int)(getCurrentYear()['id'] ?? 0);
$errors = [];

$students = Database::fetchAll(
  'SELECT s.id AS student_id, u.first_name, u.last_name, c.name AS class_name
   FROM students s
   JOIN users u ON u.id = s.user_id
   JOIN classes c ON c.id = s.class_id
   WHERE s.academic_year_id=? AND s.status="enrolled"
   ORDER BY c.level, c.section, u.last_name, u.first_name
   LIMIT 800',
  [$yearId]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token invalide.';
    } else {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $subject = sanitize($_POST['subject'] ?? '');
        $chapter = sanitize($_POST['chapter'] ?? '');
        $count = (int)($_POST['count'] ?? 10);
        $count = max(5, min(20, $count));

        if (!$studentId || !$subject) $errors[] = 'Champs obligatoires.';
        if (empty($errors)) {
            try {
                Database::beginTransaction();
                $qid = (int)Database::insert(
                    'INSERT INTO quizzes (student_id,subject,chapter,created_by) VALUES (?,?,?,?)',
                    [$studentId, $subject, $chapter ?: null, $teacherId]
                );
                $bank = Database::fetchAll(
                    'SELECT * FROM quiz_bank WHERE subject LIKE ? ORDER BY RAND() LIMIT ' . $count,
                    ['%' . $subject . '%']
                );
                if (empty($bank)) $bank = Database::fetchAll('SELECT * FROM quiz_bank ORDER BY RAND() LIMIT ' . $count);
                foreach ($bank as $b) {
                    Database::insert(
                        'INSERT INTO quiz_questions (quiz_id,bank_id,question,a,b,c,d,correct,explain)
                         VALUES (?,?,?,?,?,?,?,?,?,?)',
                        [$qid,(int)$b['id'],$b['question'],$b['a'],$b['b'],$b['c'],$b['d'],$b['correct'],$b['explain']]
                    );
                }
                Database::commit();
                // notifier
                $stuUserId = (int)Database::scalar('SELECT user_id FROM students WHERE id=?', [$studentId]);
                if ($stuUserId) {
                    createNotification($stuUserId, 'message', 'Quiz IA', 'Un nouveau quiz a ete assigne.', BASE_URL . '/modules/student/pages/quiz.php?quiz_id=' . $qid);
                }
                setFlash('success','Quiz assigne.');
                header('Location: ' . BASE_URL . '/modules/teacher/pages/quiz.php');
                exit;
            } catch (Throwable $e) {
                Database::rollBack();
                $errors[] = 'Erreur assignation.';
            }
        }
    }
}

$recent = Database::fetchAll(
  'SELECT q.*, CONCAT(u.first_name," ",u.last_name) AS stu_name
   FROM quizzes q
   JOIN students s ON s.id=q.student_id
   JOIN users u ON u.id=s.user_id
   WHERE q.created_by = ?
   ORDER BY q.created_at DESC
   LIMIT 50',
  [$teacherId]
);

$pageTitle = 'Quiz IA';
$pageIcon  = '🧠';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="card mb-20">
  <div class="card-title">Assigner un quiz</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="assign">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <select class="form-select" name="student_id" required style="grid-column:span 2;">
      <option value="">Eleve</option>
      <?php foreach ($students as $s): ?>
        <option value="<?= (int)$s['student_id'] ?>"><?= e($s['class_name'].' - '.$s['last_name'].' '.$s['first_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <input class="form-input" name="subject" placeholder="Matiere (ex: Mathématiques)" required>
    <input class="form-input" name="chapter" placeholder="Chapitre (optionnel)">
    <input class="form-input" type="number" name="count" value="10" min="5" max="20">
    <button class="btn btn-primary" type="submit" style="grid-column:span 4;">Assigner</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Mes quizzes recents</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Eleve</th><th>Matiere</th><th>Chapitre</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= e(formatDateTime($r['created_at'])) ?></td>
          <td><?= e($r['stu_name']) ?></td>
          <td><?= e($r['subject']) ?></td>
          <td><?= e($r['chapter'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($recent)): ?><tr><td colspan="4">Aucun.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

