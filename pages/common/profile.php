<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin','teacher','parent','student');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token invalide.';
    } else {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['new_password2'] ?? '');
        if ($new !== $new2) $errors[] = 'Les mots de passe ne correspondent pas.';
        if (strlen($new) < 8) $errors[] = 'Mot de passe trop court (8+).';
        if (empty($errors)) {
            $u = Database::fetchOne('SELECT password_hash FROM users WHERE id=?', [Auth::id()]);
            if (!$u || !password_verify($old, $u['password_hash'])) {
                $errors[] = 'Ancien mot de passe incorrect.';
            } else {
                Database::execute('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), Auth::id()]);
                logActivity('password_changed', 'users', (int)Auth::id(), 'User changed password');
                setFlash('success','Mot de passe mis à jour.');
                header('Location: ' . BASE_URL . '/modules/common/pages/profile.php');
                exit;
            }
        }
    }
}

$pageTitle = 'Profil';
$pageIcon  = '👤';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="card mb-20">
  <div class="card-title">Mon profil</div>
  <div style="color:var(--muted);font-size:13px;line-height:1.8;">
    <b>Nom:</b> <?= e(Auth::name()) ?><br>
    <b>Email:</b> <?= e(Auth::email()) ?><br>
    <b>Rôle:</b> <?= e(Auth::role()) ?>
  </div>
</div>

<div class="card">
  <div class="card-title">Changer le mot de passe</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="password">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <input class="form-input" type="password" name="old_password" placeholder="Ancien mot de passe" required>
    <input class="form-input" type="password" name="new_password" placeholder="Nouveau mot de passe" required>
    <input class="form-input" type="password" name="new_password2" placeholder="Confirmer" required>
    <button class="btn btn-primary" type="submit" style="grid-column:span 3;">Mettre à jour</button>
  </form>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

