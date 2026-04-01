<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/modules/shared/pdf/PdfService.php';

Auth::check('admin');

$checks = [];

// DB
try {
    Database::scalar('SELECT 1');
    $checks[] = ['label'=>'Base de données', 'ok'=>true, 'detail'=>'Connexion OK'];
} catch (Throwable $e) {
    $checks[] = ['label'=>'Base de données', 'ok'=>false, 'detail'=>$e->getMessage()];
}

// Tables essentielles
$tables = ['users','students','classes','attendance','grades','evaluations','messages','notifications','announcements','payments','ai_alerts','gradebook'];
$missing = [];
foreach ($tables as $t) {
    try {
        Database::scalar("SELECT 1 FROM `$t` LIMIT 1");
    } catch (Throwable $e) {
        $missing[] = $t;
    }
}
$checks[] = ['label'=>'Tables essentielles', 'ok'=>empty($missing), 'detail'=>empty($missing)?'OK':('Manquantes: '.implode(', ', $missing))];

// Uploads
$w = is_dir(UPLOAD_PATH) && is_writable(UPLOAD_PATH);
$checks[] = ['label'=>'Uploads', 'ok'=>$w, 'detail'=>$w ? ('Writable: '.UPLOAD_PATH) : ('Non writable: '.UPLOAD_PATH)];

// PDF
$pdfOk = PdfService::dompdfAvailable();
$checks[] = ['label'=>'PDF serveur (Dompdf)', 'ok'=>$pdfOk, 'detail'=>$pdfOk ? 'OK (vendor/autoload.php)' : 'Non installé (fallback print)'];

// Extensions
$checks[] = ['label'=>'Extension PDO', 'ok'=>extension_loaded('pdo'), 'detail'=>extension_loaded('pdo')?'OK':'Manquante'];
$checks[] = ['label'=>'Extension PDO MySQL', 'ok'=>extension_loaded('pdo_mysql'), 'detail'=>extension_loaded('pdo_mysql')?'OK':'Manquante'];
$checks[] = ['label'=>'Extension Zip', 'ok'=>class_exists('ZipArchive'), 'detail'=>class_exists('ZipArchive')?'OK':'Optionnel (zip export)'];

$pageTitle = 'Health check';
$pageIcon  = '🩺';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">État du système</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Check</th><th>Statut</th><th>Détail</th></tr></thead>
      <tbody>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td style="font-weight:800;color:var(--navy);"><?= e($c['label']) ?></td>
          <td><?= $c['ok'] ? '<span class="badge badge-green">OK</span>' : '<span class="badge badge-red">KO</span>' ?></td>
          <td><?= e($c['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

