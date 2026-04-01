<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('teacher');

$teacherId = (int)Auth::id();
$yearId = (int)(getCurrentYear()['id'] ?? 0);

$rows = Database::fetchAll(
  'SELECT c.id AS class_id, c.name AS class_name,
          COUNT(DISTINCT s.id) AS students,
          GROUP_CONCAT(DISTINCT sub.name ORDER BY sub.name SEPARATOR ", ") AS subjects
   FROM class_subjects cs
   JOIN classes c ON c.id=cs.class_id
   JOIN subjects sub ON sub.id=cs.subject_id
   LEFT JOIN students s ON s.class_id=c.id AND s.status="enrolled"
   WHERE cs.teacher_id=? AND c.academic_year_id=?
   GROUP BY c.id, c.name
   ORDER BY c.level, c.section',
  [$teacherId, $yearId]
);

$pageTitle = 'Mes classes';
$pageIcon  = '📋';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">Mes classes</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Classe</th><th>Élèves</th><th>Matières</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['class_name']) ?></td>
          <td><span class="badge badge-blue"><?= (int)$r['students'] ?></span></td>
          <td><?= e($r['subjects'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="3">Aucune classe affectée.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

