<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_teacher') {
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
                    [$email, $password, 'teacher', $firstName, $lastName, $phone ?: null]
                );
                setFlash('success', 'Enseignant ajoute. Mot de passe initial: School@123');
                header('Location: ' . BASE_URL . '/modules/admin/pages/teachers.php');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Erreur lors de la creation.';
            }
        }
    }
}

$q = sanitize($_GET['q'] ?? '');
$params = [];
$where = 'WHERE role = "teacher"';
if ($q !== '') {
    $where .= ' AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}

$teachers = Database::fetchAll(
    'SELECT id, first_name, last_name, email, phone, is_active, last_login, created_at
     FROM users ' . $where . '
     ORDER BY last_name, first_name
     LIMIT 300',
    $params
);

$pageTitle = 'Gestion des enseignants';
$pageIcon  = '👩‍🏫';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Ajouter un enseignant</div>
  <?php if (!empty($errors)): ?>
    <div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div>
  <?php endif; ?>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="add_teacher">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <input class="form-input" name="first_name" placeholder="Prenom" required>
    <input class="form-input" name="last_name" placeholder="Nom" required>
    <input class="form-input" name="email" type="email" placeholder="Email" required>
    <input class="form-input" name="phone" placeholder="Telephone">
    <button class="btn btn-primary" type="submit" style="grid-column:span 4;">Creer enseignant</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Liste des enseignants</div>
  <form method="get" style="margin-bottom:12px;display:flex;gap:8px;">
    <input class="form-input" name="q" value="<?= e($q) ?>" placeholder="Rechercher nom, email...">
    <button class="btn btn-secondary" type="submit">Rechercher</button>
  </form>
  <div class="table-wrap">
    <table>
      <thead>
      <tr>
        <th>Nom</th><th>Email</th><th>Telephone</th><th>Etat</th><th>Derniere connexion</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($teachers as $t): ?>
        <tr>
          <td><?= e($t['last_name'] . ' ' . $t['first_name']) ?></td>
          <td><?= e($t['email']) ?></td>
          <td><?= e($t['phone'] ?? '—') ?></td>
          <td>
            <span class="badge <?= (int)$t['is_active'] === 1 ? 'badge-green' : 'badge-red' ?>">
              <?= (int)$t['is_active'] === 1 ? 'Actif' : 'Inactif' ?>
            </span>
          </td>
          <td><?= $t['last_login'] ? e(formatDateTime($t['last_login'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($teachers)): ?>
        <tr><td colspan="5">Aucun enseignant.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
