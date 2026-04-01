<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$yearId = (int)(getCurrentYear()['id'] ?? 0);
$classes = Database::fetchAll('SELECT id, name FROM classes WHERE academic_year_id=? ORDER BY level, section', [$yearId]);
$errors = [];

$action = sanitize($_POST['action'] ?? ($_GET['action'] ?? ''));
$editId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token invalide.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $title = sanitize($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $target = sanitize($_POST['target_role'] ?? 'all');
        $classId = (int)($_POST['class_id'] ?? 0);
        $pinned = isset($_POST['is_pinned']) ? 1 : 0;
        $expires = sanitize($_POST['expires_at'] ?? '');

        $allowedTargets = ['all','admin','teacher','parent','student'];
        if (!in_array($target, $allowedTargets, true)) $target = 'all';

        if (!$title || !$body) $errors[] = 'Titre et contenu obligatoires.';

        if (empty($errors)) {
            if ($id) {
                Database::execute(
                    'UPDATE announcements
                     SET title=?, body=?, target_role=?, class_id=?, is_pinned=?, expires_at=?
                     WHERE id=?',
                    [$title, $body, $target, $classId ?: null, $pinned, $expires ?: null, $id]
                );
                setFlash('success','Annonce mise à jour.');
            } else {
                $newId = (int)Database::insert(
                    'INSERT INTO announcements (title,body,target_role,class_id,is_pinned,created_by,expires_at)
                     VALUES (?,?,?,?,?,?,?)',
                    [$title, $body, $target, $classId ?: null, $pinned, (int)Auth::id(), $expires ?: null]
                );

                // Notifier utilisateurs ciblés (simple)
                $roleWhere = $target === 'all' ? '' : ' AND u.role = ?';
                $params = [];
                if ($target !== 'all') $params[] = $target;
                if ($classId) {
                    $users = Database::fetchAll(
                        'SELECT DISTINCT u.id
                         FROM users u
                         LEFT JOIN students s ON s.user_id=u.id
                         LEFT JOIN student_parents sp ON sp.parent_id=u.id
                         WHERE u.is_active=1
                           AND (u.role IN ("admin","teacher") OR (s.class_id = ? AND s.academic_year_id = ?) OR (sp.student_id IN (SELECT id FROM students WHERE class_id=? AND academic_year_id=?)))
                         ' . $roleWhere,
                        array_merge([$classId,$yearId,$classId,$yearId], $params)
                    );
                } else {
                    $users = Database::fetchAll(
                        'SELECT id FROM users u WHERE u.is_active=1' . ($target==='all' ? '' : ' AND u.role=?'),
                        $target==='all' ? [] : [$target]
                    );
                }
                foreach ($users as $u) {
                    createNotification((int)$u['id'], 'announcement', 'Annonce', $title, BASE_URL . '/modules/common/pages/announcements.php?id=' . $newId);
                }

                setFlash('success','Annonce publiée.');
            }
            header('Location: ' . BASE_URL . '/modules/admin/pages/announcements.php');
            exit;
        }
    }
}

if ($action === 'delete' && $editId) {
    Database::execute('DELETE FROM announcements WHERE id=?', [$editId]);
    setFlash('success','Annonce supprimée.');
    header('Location: ' . BASE_URL . '/modules/admin/pages/announcements.php');
    exit;
}

$editing = null;
if ($editId) {
    $editing = Database::fetchOne('SELECT * FROM announcements WHERE id=?', [$editId]);
}

$rows = Database::fetchAll(
  'SELECT a.*, CONCAT(u.first_name," ",u.last_name) AS author_name, c.name AS class_name
   FROM announcements a
   JOIN users u ON u.id=a.created_by
   LEFT JOIN classes c ON c.id=a.class_id
   ORDER BY a.is_pinned DESC, a.created_at DESC
   LIMIT 200'
);

$pageTitle = 'Annonces';
$pageIcon  = '📢';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="card mb-20">
  <div class="card-title"><?= $editing ? 'Modifier annonce' : 'Nouvelle annonce' ?></div>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <input class="form-input" name="title" placeholder="Titre" value="<?= e($editing['title'] ?? '') ?>" required style="grid-column:span 4;">
    <select class="form-select" name="target_role">
      <?php $t = $editing['target_role'] ?? 'all'; ?>
      <option value="all"     <?= $t==='all'?'selected':'' ?>>Tous</option>
      <option value="admin"   <?= $t==='admin'?'selected':'' ?>>Admins</option>
      <option value="teacher" <?= $t==='teacher'?'selected':'' ?>>Profs</option>
      <option value="parent"  <?= $t==='parent'?'selected':'' ?>>Parents</option>
      <option value="student" <?= $t==='student'?'selected':'' ?>>Élèves</option>
    </select>
    <select class="form-select" name="class_id">
      <option value="0">Toutes classes</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)($editing['class_id'] ?? 0)===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <input class="form-input" type="datetime-local" name="expires_at" value="<?= !empty($editing['expires_at']) ? e(date('Y-m-d\TH:i', strtotime($editing['expires_at']))) : '' ?>">
    <label style="display:flex;align-items:center;gap:8px;color:var(--navy);font-weight:700;">
      <input type="checkbox" name="is_pinned" <?= !empty($editing['is_pinned']) ? 'checked' : '' ?>> Épingler
    </label>
    <textarea class="form-input" name="body" placeholder="Contenu" required style="grid-column:span 4;min-height:120px;"><?= e($editing['body'] ?? '') ?></textarea>
    <button class="btn btn-primary" type="submit" style="grid-column:span 4;"><?= $editing ? 'Mettre à jour' : 'Publier' ?></button>
  </form>
</div>

<div class="card">
  <div class="card-title">Historique</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Titre</th><th>Cible</th><th>Classe</th><th>Auteur</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(timeAgo($r['created_at'])) ?></td>
          <td style="font-weight:800;color:var(--navy);"><?= e($r['title']) ?> <?= (int)$r['is_pinned']?'<span class="badge badge-amber">pin</span>':'' ?></td>
          <td><?= e($r['target_role']) ?></td>
          <td><?= e($r['class_name'] ?? '—') ?></td>
          <td><?= e($r['author_name']) ?></td>
          <td style="text-align:right;">
            <a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/modules/common/pages/announcements.php?id=<?= (int)$r['id'] ?>">Voir</a>
            <a class="btn btn-secondary btn-sm" href="?id=<?= (int)$r['id'] ?>">Éditer</a>
            <a class="btn btn-danger btn-sm" href="?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Supprimer ?')">Supprimer</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="6">Aucune annonce.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

