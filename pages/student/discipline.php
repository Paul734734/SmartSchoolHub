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

$rows = Database::fetchAll(
  'SELECT d.*, CONCAT(u.first_name," ",u.last_name) AS reported_name
   FROM discipline d
   JOIN users u ON u.id = d.reported_by
   WHERE d.student_id = ?
   ORDER BY d.date DESC, d.created_at DESC
   LIMIT 200',
  [(int)$student['student_id']]
);

$pageTitle = 'Discipline';
$pageIcon  = '⚖️';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">Historique — <?= e($student['class_name']) ?></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Type</th><th>Motif</th><th>Par</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(formatDate($r['date'])) ?></td>
          <td><span class="badge <?= $r['type']==='commendation'?'badge-green':'badge-amber' ?>"><?= e($r['type']) ?></span></td>
          <td><?= e($r['reason']) ?></td>
          <td><?= e($r['reported_name']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="4">Aucun.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

