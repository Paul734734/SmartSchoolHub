<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$year = getCurrentYear();
$yearId = (int)($year['id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_parent') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de securite invalide.';
    } else {
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName  = sanitize($_POST['last_name'] ?? '');
        $email     = strtolower(sanitize($_POST['email'] ?? ''));
        $phone     = sanitize($_POST['phone'] ?? '');

        if (!$firstName || !$lastName || !$email) {
            $errors[] = 'Veuillez remplir les champs obligatoires.';
        }

        $exists = (int)Database::scalar('SELECT COUNT(*) FROM users WHERE email = ?', [$email]);
        if ($exists > 0) {
            $errors[] = 'Cet email existe deja.';
        }

        if (empty($errors)) {
            try {
                $password = Auth::hashPassword('School@123');
                Database::insert(
                    'INSERT INTO users (email,password_hash,role,first_name,last_name,phone,is_active)
                     VALUES (?,?,?,?,?,?,1)',
                    [$email, $password, 'parent', $firstName, $lastName, $phone ?: null]
                );
                setFlash('success', 'Parent cree. Mot de passe initial: School@123');
                header('Location: ' . BASE_URL . '/modules/admin/pages/parents.php');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Erreur lors de la creation.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_parent') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de securite invalide.';
    } else {
        $parentId  = (int)($_POST['parent_id'] ?? 0);
        $studentId = (int)($_POST['student_id'] ?? 0);
        $rel       = sanitize($_POST['relationship'] ?? 'guardian');
        $isPrimary = !empty($_POST['is_primary']) ? 1 : 0;
        $rel = in_array($rel, ['father','mother','guardian','other'], true) ? $rel : 'guardian';

        if (!$parentId || !$studentId) {
            $errors[] = 'Selection invalide.';
        } else {
            try {
                if ($isPrimary) {
                    Database::execute('UPDATE student_parents SET is_primary = 0 WHERE student_id = ?', [$studentId]);
                }
                Database::execute(
                    'INSERT INTO student_parents (student_id,parent_id,relationship,is_primary)
                     VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE relationship=VALUES(relationship), is_primary=VALUES(is_primary)',
                    [$studentId, $parentId, $rel, $isPrimary]
                );
                setFlash('success', 'Parent associe a l eleve.');
                header('Location: ' . BASE_URL . '/modules/admin/pages/parents.php');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Erreur association.';
            }
        }
    }
}

$q = sanitize($_GET['q'] ?? '');
$params = [];
$where = 'WHERE u.role = "parent"';
if ($q !== '') {
    $where .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like];
}

$parents = Database::fetchAll(
    'SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.is_active,
            (SELECT COUNT(*) FROM student_parents sp WHERE sp.parent_id=u.id) AS children_count
     FROM users u ' . $where . '
     ORDER BY u.last_name, u.first_name
     LIMIT 300',
    $params
);

$students = Database::fetchAll(
    'SELECT s.id, s.matricule, u.first_name, u.last_name, c.name AS class_name
     FROM students s
     JOIN users u ON u.id = s.user_id
     JOIN classes c ON c.id = s.class_id
     WHERE s.academic_year_id = ? AND s.status="enrolled"
     ORDER BY u.last_name, u.first_name
     LIMIT 400',
    [$yearId]
);

$pageTitle = 'Gestion des parents';
$pageIcon  = '👨‍👧';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Creer un parent</div>
  <?php if (!empty($errors)): ?>
    <div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div>
  <?php endif; ?>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="add_parent">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <input class="form-input" name="first_name" placeholder="Prenom" required>
    <input class="form-input" name="last_name" placeholder="Nom" required>
    <input class="form-input" name="email" type="email" placeholder="Email" required>
    <input class="form-input" name="phone" placeholder="Telephone">
    <button class="btn btn-primary" type="submit" style="grid-column:span 4;">Creer parent</button>
  </form>
</div>

<div class="card mb-20">
  <div class="card-title">Associer un parent a un eleve</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="link_parent">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <select class="form-select" name="parent_id" required>
      <option value="">Parent</option>
      <?php foreach ($parents as $p): ?>
        <option value="<?= (int)$p['id'] ?>"><?= e($p['last_name'].' '.$p['first_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" name="student_id" required>
      <option value="">Eleve</option>
      <?php foreach ($students as $s): ?>
        <option value="<?= (int)$s['id'] ?>"><?= e($s['last_name'].' '.$s['first_name']) ?> (<?= e($s['class_name']) ?>)</option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" name="relationship">
      <option value="guardian">Tuteur</option>
      <option value="father">Pere</option>
      <option value="mother">Mere</option>
      <option value="other">Autre</option>
    </select>
    <label style="display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px;">
      <input type="checkbox" name="is_primary" value="1" style="accent-color:var(--blue);"> Parent principal
    </label>
    <button class="btn btn-secondary" type="submit" style="grid-column:span 4;">Associer</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Liste des parents</div>
  <form method="get" style="margin-bottom:12px;display:flex;gap:8px;">
    <input class="form-input" name="q" value="<?= e($q) ?>" placeholder="Rechercher nom, email, telephone...">
    <button class="btn btn-secondary" type="submit">Rechercher</button>
  </form>
  <div class="table-wrap">
    <table>
      <thead>
      <tr>
        <th>Nom</th><th>Email</th><th>Telephone</th><th>Enfants</th><th>Etat</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($parents as $p): ?>
        <tr>
          <td><?= e($p['last_name'].' '.$p['first_name']) ?></td>
          <td><?= e($p['email']) ?></td>
          <td><?= e($p['phone'] ?? '—') ?></td>
          <td><span class="badge badge-blue"><?= (int)$p['children_count'] ?></span></td>
          <td><span class="badge <?= (int)$p['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= (int)$p['is_active'] ? 'Actif' : 'Inactif' ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($parents)): ?>
        <tr><td colspan="5">Aucun parent.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
