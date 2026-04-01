<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/modules/shared/ai/RiskService.php';

Auth::check('student');

$userId = (int)Auth::id();
$yearId = (int)(getCurrentYear()['id'] ?? 0);

$student = Database::fetchOne(
  'SELECT s.id AS student_id, s.class_id
   FROM students s
   WHERE s.user_id=? AND s.academic_year_id=?',
  [$userId, $yearId]
);
if (!$student) { setFlash('error','Profil eleve introuvable.'); header('Location: '.BASE_URL.'/login.php'); exit; }
$studentId = (int)$student['student_id'];

$errors = [];
$action = sanitize($_POST['action'] ?? ($_GET['action'] ?? ''));

// Récupérer quiz actif (dernier)
$quiz = Database::fetchOne(
  'SELECT * FROM quizzes WHERE student_id=? ORDER BY created_at DESC LIMIT 1',
  [$studentId]
);

if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) $errors[] = 'Token invalide.';
    $subject = sanitize($_POST['subject'] ?? '');
    $chapter = sanitize($_POST['chapter'] ?? '');
    if (!$subject) $errors[] = 'Matiere obligatoire.';
    if (empty($errors)) {
        try {
            Database::beginTransaction();
            $qid = (int)Database::insert(
                'INSERT INTO quizzes (student_id,subject,chapter,created_by) VALUES (?,?,?,NULL)',
                [$studentId, $subject, $chapter ?: null]
            );

            $bank = Database::fetchAll(
                'SELECT * FROM quiz_bank WHERE subject LIKE ? ORDER BY RAND() LIMIT 10',
                ['%' . $subject . '%']
            );
            if (empty($bank)) {
                // fallback: tout
                $bank = Database::fetchAll('SELECT * FROM quiz_bank ORDER BY RAND() LIMIT 10');
            }
            foreach ($bank as $b) {
                Database::insert(
                    'INSERT INTO quiz_questions (quiz_id,bank_id,question,a,b,c,d,correct,explain)
                     VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$qid,(int)$b['id'],$b['question'],$b['a'],$b['b'],$b['c'],$b['d'],$b['correct'],$b['explain']]
                );
            }
            Database::commit();
            header('Location: ' . BASE_URL . '/modules/student/pages/quiz.php?quiz_id=' . $qid);
            exit;
        } catch (Throwable $e) {
            Database::rollBack();
            $errors[] = 'Erreur generation quiz.';
        }
    }
}

$quizId = (int)($_GET['quiz_id'] ?? ($quiz['id'] ?? 0));
$quiz = $quizId ? Database::fetchOne('SELECT * FROM quizzes WHERE id=? AND student_id=?', [$quizId, $studentId]) : null;
$questions = $quiz ? Database::fetchAll('SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY id ASC', [$quizId]) : [];

if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) $errors[] = 'Token invalide.';
    $quizIdPost = (int)($_POST['quiz_id'] ?? 0);
    if (!$quizIdPost) $errors[] = 'Quiz invalide.';
    if (empty($errors)) {
        $qRows = Database::fetchAll('SELECT id, correct FROM quiz_questions WHERE quiz_id=?', [$quizIdPost]);
        $answers = [];
        $score = 0;
        foreach ($qRows as $qr) {
            $qid = (int)$qr['id'];
            $ans = sanitize($_POST['q_' . $qid] ?? '');
            if (!in_array($ans, ['a','b','c','d'], true)) $ans = '';
            $answers[$qid] = $ans;
            if ($ans && $ans === $qr['correct']) $score++;
        }
        $total = count($qRows);
        Database::insert(
            'INSERT INTO quiz_attempts (quiz_id,student_id,score,total,answers) VALUES (?,?,?,?,?)',
            [$quizIdPost, $studentId, $score, $total, json_encode($answers, JSON_UNESCAPED_UNICODE)]
        );

        // Remédiation: si score < 60%, créer alerte IA
        $pct = $total > 0 ? ($score / $total) * 100 : 0;
        if ($pct < 60) {
            $msg = 'Quiz faible (' . round($pct) . '%). Recommandation: refaire un quiz + revoir les notions.';
            Database::insert(
                'INSERT INTO ai_alerts (student_id, level, category, message, score) VALUES (?,?,?,?,?)',
                [$studentId, $pct < 40 ? 'critical' : 'warning', 'grades', $msg, round($pct,2)]
            );
        }

        setFlash('success', 'Quiz soumis. Score: ' . $score . '/' . $total);
        header('Location: ' . BASE_URL . '/modules/student/pages/quiz.php?quiz_id=' . $quizIdPost);
        exit;
    }
}

$attempts = $quiz ? Database::fetchAll(
  'SELECT * FROM quiz_attempts WHERE quiz_id=? AND student_id=? ORDER BY created_at DESC LIMIT 5',
  [$quizId, $studentId]
) : [];

$pageTitle = 'Quiz IA';
$pageIcon  = '🧠';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="card mb-20">
  <div class="card-title">Nouveau quiz</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="start">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <input class="form-input" name="subject" placeholder="Matiere (ex: Mathématiques)" required>
    <input class="form-input" name="chapter" placeholder="Chapitre (optionnel)">
    <button class="btn btn-primary" type="submit">Generer</button>
  </form>
</div>

<?php if ($quiz): ?>
<div class="card">
  <div class="card-title">Quiz: <?= e($quiz['subject']) ?> <?= $quiz['chapter'] ? '— '.e($quiz['chapter']) : '' ?></div>

  <?php if (!empty($attempts)): ?>
    <div style="margin-bottom:10px;color:var(--muted);font-size:12px;">
      Dernier score: <b><?= (int)$attempts[0]['score'] ?>/<?= (int)$attempts[0]['total'] ?></b> (<?= e(timeAgo($attempts[0]['created_at'])) ?>)
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="submit">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <input type="hidden" name="quiz_id" value="<?= (int)$quizId ?>">
    <?php foreach ($questions as $idx => $q): ?>
      <div style="padding:12px 0;border-bottom:1px solid var(--border);">
        <div style="font-weight:800;color:var(--navy);margin-bottom:8px;"><?= ($idx+1) ?>. <?= e($q['question']) ?></div>
        <?php foreach (['a','b','c','d'] as $k): ?>
          <label style="display:block;margin:6px 0;color:var(--navy);">
            <input type="radio" name="q_<?= (int)$q['id'] ?>" value="<?= $k ?>" style="margin-right:6px;">
            <?= e($q[$k]) ?>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <?php if (empty($questions)): ?><div class="empty-state" style="padding:20px;"><p>Aucune question.</p></div><?php endif; ?>
    <button class="btn btn-primary btn-full" type="submit" style="margin-top:12px;">Soumettre</button>
  </form>
</div>
<?php endif; ?>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

