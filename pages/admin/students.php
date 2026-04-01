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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_student') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de securite invalide.';
    } else {
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $email = strtolower(sanitize($_POST['email'] ?? ''));
        $phone = sanitize($_POST['phone'] ?? '');
        $classId = (int)($_POST['class_id'] ?? 0);
        $gender = sanitize($_POST['gender'] ?? '');
        $dob = sanitize($_POST['date_of_birth'] ?? '');
        $status = sanitize($_POST['status'] ?? 'enrolled');

        if (!$firstName || !$lastName || !$email || !$classId || !$yearId) {
            $errors[] = 'Veuillez remplir les champs obligatoires.';
        }
        $exists = (int)Database::scalar('SELECT COUNT(*) FROM users WHERE email = ?', [$email]);
        if ($exists > 0) {
            $errors[] = 'Cet email existe deja.';
        }

        if (empty($errors)) {
            try {
                Database::beginTransaction();
                $password = Auth::hashPassword('School@123');
                $userId = (int)Database::insert(
                    'INSERT INTO users (email,password_hash,role,first_name,last_name,phone,gender,date_of_birth,is_active)
                     VALUES (?,?,?,?,?,?,?,?,1)',
                    [$email, $password, 'student', $firstName, $lastName, $phone ?: null, $gender ?: null, $dob ?: null]
                );

                $matricule = generateMatricule((string)date('Y'));
                Database::insert(
                    'INSERT INTO students (user_id,matricule,class_id,academic_year_id,date_of_birth,status)
                     VALUES (?,?,?,?,?,?)',
                    [$userId, $matricule, $classId, $yearId, $dob ?: null, $status ?: 'enrolled']
                );

                Database::commit();
                setFlash('success', 'Eleve ajoute avec succes. Mot de passe initial: School@123');
                header('Location: ' . BASE_URL . '/modules/admin/pages/students.php');
                exit;
            } catch (Throwable $e) {
                Database::rollBack();
                $errors[] = 'Erreur lors de la creation de l eleve.';
            }
        }
    }
}

$q = sanitize($_GET['q'] ?? '');
$params = [$yearId];
$whereQ = '';
if ($q !== '') {
    $whereQ = ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.matricule LIKE ? OR c.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$students = Database::fetchAll(
    'SELECT s.id, s.matricule, s.status, s.enrollment_date, u.first_name, u.last_name, u.email, u.phone, c.name AS class_name
     FROM students s
     JOIN users u ON u.id = s.user_id
     JOIN classes c ON c.id = s.class_id
     WHERE s.academic_year_id = ?' . $whereQ . '
     ORDER BY u.last_name, u.first_name
     LIMIT 200',
    $params
);

$classes = Database::fetchAll(
    'SELECT id, name, level, section FROM classes WHERE academic_year_id = ? ORDER BY level, section, name',
    [$yearId]
);

$pageTitle = 'Gestion des eleves';
$pageIcon = '👨‍🎓';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Ajouter un eleve</div>
  <?php if (!empty($errors)): ?>
    <div class="flash flash-error">
      <?= e(implode(' ', $errors)) ?>
    </div>
  <?php endif; ?>
  <form method="post" class="form-grid" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="add_student">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <input class="form-input" name="first_name" placeholder="Prenom" required>
    <input class="form-input" name="last_name" placeholder="Nom" required>
    <input class="form-input" name="email" type="email" placeholder="Email" required>
    <input class="form-input" name="phone" placeholder="Telephone">
    <input class="form-input" name="date_of_birth" type="date">
    <select class="form-select" name="gender">
      <option value="">Genre</option>
      <option value="M">Masculin</option>
      <option value="F">Feminin</option>
    </select>
    <select class="form-select" name="class_id" required>
      <option value="">Classe</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['level']) ?>)</option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" name="status">
      <option value="enrolled">Inscrit</option>
      <option value="pending">En attente</option>
      <option value="suspended">Suspendu</option>
    </select>
    <button class="btn btn-primary" type="submit">Creer eleve</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Liste des eleves</div>
  <form method="get" style="margin-bottom:12px;display:flex;gap:8px;">
    <input class="form-input" name="q" value="<?= e($q) ?>" placeholder="Rechercher nom, matricule, classe...">
    <button class="btn btn-secondary" type="submit">Rechercher</button>
  </form>
  <div class="table-wrap">
    <table>
      <thead>
      <tr>
        <th>Matricule</th><th>Eleve</th><th>Classe</th><th>Statut</th><th>Contact</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($students as $s): ?>
        <tr>
          <td><?= e($s['matricule']) ?></td>
          <td><?= e($s['last_name'] . ' ' . $s['first_name']) ?></td>
          <td><?= e($s['class_name']) ?></td>
          <td><span class="badge <?= $s['status'] === 'enrolled' ? 'badge-green' : 'badge-amber' ?>"><?= e($s['status']) ?></span></td>
          <td><?= e($s['email']) ?><?= $s['phone'] ? ' / ' . e($s['phone']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($students)): ?>
        <tr><td colspan="5">Aucun eleve trouve.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
