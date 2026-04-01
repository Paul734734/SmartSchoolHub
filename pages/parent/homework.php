<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('parent');

$parentId = Auth::id();
$yearId   = getCurrentYear()['id'] ?? 0;

$children = Database::fetchAll(
  'SELECT s.id AS student_id, u.first_name, u.last_name, c.name AS class_name, c.id AS class_id
   FROM student_parents sp
   JOIN students s ON s.id = sp.student_id
   JOIN users u ON u.id = s.user_id
   JOIN classes c ON c.id = s.class_id
   WHERE sp.parent_id = ? AND s.academic_year_id = ? AND s.status="enrolled"
   ORDER BY u.last_name',
  [$parentId, $yearId]
);
$selectedChildId = (int)($_GET['child_id'] ?? ($children[0]['student_id'] ?? 0));
$child = null;
foreach ($children as $c) { if ((int)$c['student_id'] === $selectedChildId) { $child = $c; break; } }
if (!$child && !empty($children)) { $child = $children[0]; $selectedChildId = (int)$child['student_id']; }

$rows = [];
if ($child) {
  $rows = Database::fetchAll(
    'SELECT gb.lesson_title, gb.homework, gb.homework_due, gb.date,
            sub.name AS subject_name, sub.icon,
            hs.status AS sub_status, hs.submitted_at,
            gb.id AS gradebook_id
     FROM gradebook gb
     JOIN class_subjects cs ON cs.id = gb.class_subject_id
     JOIN subjects sub ON sub.id = cs.subject_id
     LEFT JOIN homework_submissions hs ON hs.gradebook_id = gb.id AND hs.student_id = ?
     WHERE cs.class_id = ? AND gb.homework IS NOT NULL AND gb.homework <> ""
     ORDER BY (gb.homework_due IS NULL) ASC, gb.homework_due ASC, gb.date DESC
     LIMIT 200',
    [$selectedChildId, (int)$child['class_id']]
  );
}

$resources = [];
if (!empty($rows)) {
  $ids = array_map(fn($r) => (int)$r['gradebook_id'], $rows);
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $res = Database::fetchAll(
    'SELECT * FROM gradebook_resources WHERE gradebook_id IN (' . $in . ') ORDER BY created_at DESC',
    $ids
  );
  foreach ($res as $r) { $resources[(int)$r['gradebook_id']][] = $r; }
}

$pageTitle = 'Devoirs';
$pageIcon  = '🧾';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (count($children) > 1): ?>
<div class="card mb-20">
  <div class="card-title">Choisir un enfant</div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php foreach ($children as $ch): ?>
      <a class="btn btn-secondary" href="?child_id=<?= (int)$ch['student_id'] ?>">
        <?= e($ch['first_name']) ?> (<?= e($ch['class_name']) ?>)
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-title">Devoirs <?= $child ? '— '.e($child['first_name'].' '.$child['last_name']) : '' ?></div>
  <?php foreach ($rows as $r): ?>
    <div style="padding:14px 0;border-bottom:1px solid var(--border);">
      <div style="display:flex;justify-content:space-between;gap:12px;">
        <div>
          <div style="font-weight:800;color:var(--navy);"><?= $r['icon'] ?> <?= e($r['subject_name']) ?> — <?= e($r['lesson_title']) ?></div>
          <div style="color:var(--muted);font-size:12px;margin-top:4px;max-width:820px;">
            <?= e(substr(strip_tags($r['homework']), 0, 220)) ?><?= strlen($r['homework'])>220 ? '…' : '' ?>
          </div>
          <?php if (!empty($resources[(int)$r['gradebook_id']] ?? [])): ?>
            <div style="margin-top:8px;">
              <?php foreach (($resources[(int)$r['gradebook_id']] ?? []) as $res): ?>
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
        </div>
        <div style="text-align:right;min-width:200px;">
          <?php if ($r['homework_due']): ?>
            <div class="badge badge-blue"><?= e(formatDate($r['homework_due'])) ?></div>
          <?php endif; ?>
          <div style="margin-top:6px;">
            <span class="badge <?= ($r['sub_status']??'')==='submitted'?'badge-amber':(($r['sub_status']??'')==='validated'?'badge-green':'badge-gray') ?>">
              <?= e($r['sub_status'] ?? 'non rendu') ?>
            </span>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (empty($rows)): ?><div class="empty-state" style="padding:20px;"><p>Aucun devoir.</p></div><?php endif; ?>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

