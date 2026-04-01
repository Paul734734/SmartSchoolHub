<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin','teacher','parent','student');

$role = Auth::role();
$userId = (int)Auth::id();
$yearId = (int)(getCurrentYear()['id'] ?? 0);

$classId = null;
$studentId = null;

if ($role === 'student') {
    $studentId = (int)Database::scalar('SELECT id FROM students WHERE user_id=? AND academic_year_id=?', [$userId, $yearId]);
    $classId = (int)Database::scalar('SELECT class_id FROM students WHERE id=?', [$studentId]);
}
if ($role === 'parent') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    if (!$studentId) {
        $studentId = (int)Database::scalar(
            'SELECT s.id FROM student_parents sp
             JOIN students s ON s.id=sp.student_id
             WHERE sp.parent_id=? AND s.academic_year_id=?
             ORDER BY sp.is_primary DESC LIMIT 1',
            [$userId, $yearId]
        );
    } else {
        $ok = (int)Database::scalar(
            'SELECT COUNT(*) FROM student_parents sp
             JOIN students s ON s.id=sp.student_id
             WHERE sp.parent_id=? AND sp.student_id=? AND s.academic_year_id=?',
            [$userId, $studentId, $yearId]
        );
        if (!$ok) { http_response_code(403); die('Acces refuse.'); }
    }
    $classId = (int)Database::scalar('SELECT class_id FROM students WHERE id=?', [$studentId]);
}

$id = (int)($_GET['id'] ?? 0);
$view = null;
if ($id) {
    $view = Database::fetchOne(
      'SELECT a.*, CONCAT(u.first_name," ",u.last_name) AS author_name, c.name AS class_name
       FROM announcements a
       JOIN users u ON u.id=a.created_by
       LEFT JOIN classes c ON c.id=a.class_id
       WHERE a.id = ?
       LIMIT 1',
      [$id]
    );
}

$where = 'WHERE (a.expires_at IS NULL OR a.expires_at > NOW())';
$params = [];
$where .= ' AND (a.target_role FIND_IN_SET ? OR a.target_role = "all" OR a.target_role LIKE "%all%")';
// target_role is SET, schema default 'all' but stored like 'all' or 'parent,student' etc
$params[] = $role;
$where .= ' AND (a.class_id IS NULL ' . ($classId ? ' OR a.class_id = ?' : '') . ')';
if ($classId) $params[] = $classId;

$rows = Database::fetchAll(
  'SELECT a.id, a.title, a.body, a.is_pinned, a.created_at,
          CONCAT(u.first_name," ",u.last_name) AS author_name,
          c.name AS class_name
   FROM announcements a
   JOIN users u ON u.id=a.created_by
   LEFT JOIN classes c ON c.id=a.class_id
   ' . $where . '
   ORDER BY a.is_pinned DESC, a.created_at DESC
   LIMIT 200',
  $params
);

$pageTitle = 'Annonces';
$pageIcon  = '📢';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">Annonces</div>

  <?php if ($role === 'parent'): ?>
    <?php
      $children = Database::fetchAll(
        'SELECT s.id AS student_id, u.first_name, u.last_name, c.name AS class_name
         FROM student_parents sp
         JOIN students s ON s.id=sp.student_id
         JOIN users u ON u.id=s.user_id
         JOIN classes c ON c.id=s.class_id
         WHERE sp.parent_id=? AND s.academic_year_id=?',
        [$userId, $yearId]
      );
    ?>
    <?php if (count($children) > 1): ?>
      <div style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;">
        <?php foreach ($children as $ch): ?>
          <a class="btn btn-secondary btn-sm" href="?student_id=<?= (int)$ch['student_id'] ?>">
            <?= e($ch['first_name']) ?> (<?= e($ch['class_name']) ?>)
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($view): ?>
    <div style="padding:14px 0;border-bottom:1px solid var(--border);">
      <div style="font-weight:900;color:var(--navy);font-size:16px;"><?= e($view['title']) ?></div>
      <div style="color:var(--muted);font-size:12px;margin-top:6px;">
        <?= e(timeAgo($view['created_at'])) ?> · <?= e($view['author_name']) ?><?= $view['class_name'] ? ' · '.e($view['class_name']) : '' ?>
      </div>
      <div style="margin-top:12px;white-space:pre-wrap;line-height:1.6;color:var(--navy);"><?= e($view['body']) ?></div>
      <div style="margin-top:12px;">
        <a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/modules/common/pages/announcements.php<?= $role==='parent' && $studentId ? '?student_id='.(int)$studentId : '' ?>">← Retour</a>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ($rows as $r): ?>
    <a href="?id=<?= (int)$r['id'] ?><?= $role==='parent' && $studentId ? '&student_id='.(int)$studentId : '' ?>"
       style="text-decoration:none;display:block;padding:14px 0;border-bottom:1px solid var(--border);">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
        <div>
          <div style="font-weight:900;color:var(--navy);">
            <?= e($r['title']) ?>
            <?= (int)$r['is_pinned'] ? '<span class="badge badge-amber" style="margin-left:6px;">pin</span>' : '' ?>
          </div>
          <div style="color:var(--muted);font-size:12px;margin-top:6px;">
            <?= e(substr(strip_tags($r['body']),0,110)) ?><?= strlen($r['body'])>110?'…':'' ?>
          </div>
        </div>
        <div style="text-align:right;color:var(--muted);font-size:12px;min-width:140px;">
          <?= e(timeAgo($r['created_at'])) ?><br>
          <?= e($r['author_name']) ?><?= $r['class_name'] ? '<br>'.e($r['class_name']) : '' ?>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
  <?php if (empty($rows)): ?><div class="empty-state" style="padding:20px;"><p>Aucune annonce.</p></div><?php endif; ?>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

