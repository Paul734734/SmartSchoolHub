<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin', 'teacher', 'parent', 'student');

$module = sanitize($_GET['module'] ?? 'Module');
$pageTitle = $module;
$pageIcon = '🚧';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card" style="max-width:780px;margin:20px auto;">
  <div class="empty-state" style="padding:28px;">
    <div class="empty-state-icon">🚀</div>
    <h3><?= e(tr('Module en cours de finalisation', 'Module under finalization')) ?></h3>
    <p style="max-width:560px;margin:10px auto 16px;">
      <?= e(tr(
        'Le module "' . $module . '" est prevu dans la feuille de route MVP et sera active dans les prochaines etapes.',
        'The "' . $module . '" module is part of the MVP roadmap and will be activated in upcoming steps.'
      )) ?>
    </p>
    <a class="btn btn-primary" href="<?= e(Auth::getDashboardUrl(Auth::role() ?? '')) ?>">
      <?= e(tr('Retour au tableau de bord', 'Back to dashboard')) ?>
    </a>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
