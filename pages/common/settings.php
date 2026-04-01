<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin','teacher','parent','student');

if (isset($_POST['action']) && $_POST['action'] === 'locale') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $loc = sanitize($_POST['locale'] ?? APP_DEFAULT_LOCALE);
        setAppLocale($loc);
        logActivity('settings_locale', null, null, 'locale=' . $loc);
        setFlash('success','Langue mise à jour.');
    }
    header('Location: ' . BASE_URL . '/modules/common/pages/settings.php');
    exit;
}

$pageTitle = 'Paramètres';
$pageIcon  = '⚙️';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">Paramètres</div>
  <form method="post" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="action" value="locale">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <label style="font-weight:800;color:var(--navy);">Langue</label>
    <select class="form-select" name="locale">
      <option value="fr" <?= locale()==='fr'?'selected':'' ?>>Français</option>
      <option value="en" <?= locale()==='en'?'selected':'' ?>>English</option>
    </select>
    <button class="btn btn-primary" type="submit">Enregistrer</button>
  </form>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

