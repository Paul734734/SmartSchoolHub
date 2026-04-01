<?php
/**
 * SmartSchool Hub — Web Installer (version corrigée)
 * Ouvrir : http://votre-domaine.com/SmartSchoolHub/install.php
 * Supprimer ce fichier APRÈS installation réussie.
 */

define('INSTALLER_VERSION', '1.0.0');
define('REQUIRED_PHP',      '8.0.0');
define('INSTALL_LOCK',      __DIR__ . '/logs/install.lock');

// ── Si déjà installé ──────────────────────────────────────
if (file_exists(INSTALL_LOCK)) {
    // Permettre la réinstallation propre via ?reset=1
    if (isset($_GET['reset']) && $_GET['reset'] === '1') {
        unlink(INSTALL_LOCK);
        header('Location: install.php');
        exit;
    }
    die('<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<title>SmartSchool Hub</title>
<style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;
height:100vh;margin:0;background:#f0f4ff}
.box{background:#fff;padding:2rem 3rem;border-radius:12px;
box-shadow:0 4px 24px rgba(0,0,0,.1);text-align:center}
h2{color:#16a34a}p{color:#555}
.btn{display:inline-block;margin:.4rem;padding:.6rem 1.4rem;border-radius:8px;text-decoration:none;font-weight:600}
.btn-blue{background:#4f46e5;color:#fff}.btn-red{background:#dc2626;color:#fff;font-size:.85rem}
</style></head><body>
<div class="box"><h2>✅ Déjà installé</h2>
<p>SmartSchool Hub est déjà configuré.<br>
Supprimez <code>install.php</code> après mise en production.</p>
<a href="index.php" class="btn btn-blue">→ Accéder à l\'application</a>
<br><br><hr style="margin:1rem 0;border-color:#eee">
<small style="color:#aaa">Besoin de recommencer l\'installation ?</small><br>
<a href="install.php?reset=1" class="btn btn-red"
   onclick="return confirm(\'Réinitialiser ? Cela relancera l\\\'installeur.\')">↺ Réinstaller</a>
</div></body></html>');
}

session_start();
$step  = (int)($_GET['step'] ?? 1);
$error = '';

// ════════════════════════════════════════════════════════════
// ÉTAPE 2 — Traitement du formulaire
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {

    $fields = ['db_host','db_port','db_name','db_user','db_pass',
               'base_url','app_name','admin_email','admin_pass','admin_pass2','secret_key'];
    foreach ($fields as $f) {
        $_SESSION['install'][$f] = trim($_POST[$f] ?? '');
    }
    $d = $_SESSION['install'];

    // Validations
    if ($d['admin_pass'] !== $d['admin_pass2']) {
        $error = 'Les mots de passe administrateur ne correspondent pas.';
    } elseif (strlen($d['admin_pass']) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif (empty($d['db_name']) || empty($d['db_user'])) {
        $error = 'Le nom de la base et l\'utilisateur sont obligatoires.';
    } elseif (empty($d['admin_email'])) {
        $error = 'L\'email administrateur est obligatoire.';
    } elseif (empty($d['app_name'])) {
        $error = 'Le nom de l\'établissement est obligatoire.';
    } else {

        try {
            // ── Connexion MySQL (sans base précisée) ──
            $dsn = "mysql:host={$d['db_host']};port={$d['db_port']};charset=utf8mb4";
            $pdo = new PDO($dsn, $d['db_user'], $d['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // ── Créer la base si elle n'existe pas ──
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$d['db_name']}`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$d['db_name']}`");

            // ── Importer le schéma SQL (core + add-ons) ──
            $schemaFiles = [
                __DIR__ . '/config/schema.sql',
                __DIR__ . '/config/schema_addons.sql',
            ];

            $sql = '';
            foreach ($schemaFiles as $sf) {
                if (file_exists($sf)) {
                    $chunk = file_get_contents($sf);
                    if ($chunk) $sql .= "\n" . $chunk . "\n";
                }
            }
            if (!$sql) {
                throw new RuntimeException('Impossible de lire les schemas SQL.');
            }

            // Découper proprement : ignorer commentaires, lignes vides, COMMIT, SET
            $queries = [];
            $current = '';
            foreach (explode("\n", $sql) as $line) {
                $trimmed = trim($line);
                // Ignorer les commentaires SQL purs et les lignes de contrôle
                if (str_starts_with($trimmed, '--') || $trimmed === '') continue;
                $current .= $line . "\n";
                if (str_ends_with($trimmed, ';')) {
                    $q = trim($current);
                    if ($q) $queries[] = $q;
                    $current = '';
                }
            }

            foreach ($queries as $q) {
                try {
                    $pdo->exec($q);
                } catch (PDOException $e) {
                    // 42S01 = table existe déjà → OK
                    // 1062  = duplicate entry INSERT IGNORE → OK
                    if (!in_array($e->getCode(), ['42S01', '23000'])) {
                        throw $e;
                    }
                }
            }

            // ── Supprimer l'admin par défaut ET l'email saisi (réinstallation propre) ──
            $stmtDel = $pdo->prepare(
                "DELETE FROM `users` WHERE `email` IN ('admin@smartschoolhub.cm', ?)"
            );
            $stmtDel->execute([$d['admin_email']]);

            // ── Insérer le nouvel admin avec mot de passe choisi ──
            $hash = password_hash($d['admin_pass'], PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare(
                "INSERT INTO `users`
                    (`email`, `password_hash`, `role`, `first_name`, `last_name`, `is_active`)
                 VALUES (?, ?, 'admin', 'Super', 'Admin', 1)"
            );
            $stmt->execute([$d['admin_email'], $hash]);

            // ── Insérer ou mettre à jour les paramètres école ──
            $schoolName  = $d['app_name'];
            $schoolShort = mb_strtoupper(mb_substr($schoolName, 0, 10));
            $existing = $pdo->query("SELECT id FROM `school` LIMIT 1")->fetch();
            if ($existing) {
                $pdo->prepare("UPDATE `school` SET `name`=?, `short_name`=? WHERE id=?")
                    ->execute([$schoolName, $schoolShort, $existing['id']]);
            } else {
                $pdo->prepare("INSERT INTO `school` (`name`, `short_name`) VALUES (?, ?)")
                    ->execute([$schoolName, $schoolShort]);
            }

            // ── Écrire config/config.php ──
            $secret  = $d['secret_key'] ?: bin2hex(random_bytes(32));
            $baseUrl = rtrim($d['base_url'], '/');
            $dbPass  = addslashes($d['db_pass']);
            $generatedAt = date('Y-m-d H:i:s');
            $configContent = <<<PHP
<?php
/**
 * SmartSchool Hub — Configuration (générée par l'installeur)
 * Générée le : {$generatedAt}
 */

define('APP_VERSION', '1.0.0');
define('APP_NAME',    '{$schoolName}');
define('SECRET_KEY',  '{$secret}');

define('DB_HOST',    '{$d['db_host']}');
define('DB_PORT',    {$d['db_port']});
define('DB_NAME',    '{$d['db_name']}');
define('DB_USER',    '{$d['db_user']}');
define('DB_PASS',    '{$dbPass}');
define('DB_CHARSET', 'utf8mb4');

define('BASE_PATH',   dirname(__FILE__));
define('BASE_URL',    '{$baseUrl}');
define('ASSETS_URL',  BASE_URL . '/assets');
define('UPLOAD_PATH', BASE_PATH . '/uploads');
define('UPLOAD_URL',  BASE_URL  . '/uploads');

define('SESSION_NAME',     'SSH_SESSION');
define('SESSION_LIFETIME', 3600 * 8);

define('MAX_UPLOAD_SIZE',   5 * 1024 * 1024);
define('ALLOWED_IMG_TYPES', ['image/jpeg', 'image/png', 'image/webp']);
define('ALLOWED_DOC_TYPES', ['application/pdf']);

define('SMS_ENABLED', false);
define('SMS_API_URL', '');
define('SMS_API_KEY', '');
define('SMS_SENDER',  'SmartSchool');

define('MAIL_ENABLED',   false);
define('MAIL_HOST',      'smtp.gmail.com');
define('MAIL_PORT',      587);
define('MAIL_USER',      '');
define('MAIL_PASS',      '');
define('MAIL_FROM',      'noreply@smartschoolhub.cm');
define('MAIL_FROM_NAME', APP_NAME);

define('ITEMS_PER_PAGE', 20);
define('APP_ENV',   'production');
define('APP_DEBUG', false);

date_default_timezone_set('Africa/Douala');
setlocale(LC_ALL, 'fr_FR.UTF-8', 'fr_FR', 'fr');

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', BASE_PATH . '/logs/error.log');
}

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
PHP;
            file_put_contents(__DIR__ . '/config/config.php', $configContent);

            // ── Créer le verrou ──
            file_put_contents(INSTALL_LOCK,
                date('Y-m-d H:i:s') . ' — Installation réussie pour ' . $d['admin_email']);

            header('Location: install.php?step=3');
            exit;

        } catch (PDOException $e) {
            $error = '❌ Erreur base de données : ' . $e->getMessage();
        } catch (RuntimeException $e) {
            $error = '❌ Erreur : ' . $e->getMessage();
        }
    }
}

// ════════════════════════════════════════════════════════════
// VÉRIFICATIONS SYSTÈME (étape 1)
// ════════════════════════════════════════════════════════════
$checks = [
    'PHP ≥ ' . REQUIRED_PHP       => version_compare(PHP_VERSION, REQUIRED_PHP, '>='),
    'Extension PDO'               => extension_loaded('pdo'),
    'Extension PDO MySQL'         => extension_loaded('pdo_mysql'),
    'Extension mbstring'          => extension_loaded('mbstring'),
    'Extension json'              => extension_loaded('json'),
    'Extension openssl'           => extension_loaded('openssl'),
    'Dossier config/ accessible'  => is_writable(__DIR__ . '/config'),
    'Dossier logs/ accessible'    => is_writable(__DIR__ . '/logs'),
    'Dossier uploads/ accessible' => is_writable(__DIR__ . '/uploads'),
];
$allOk = !in_array(false, $checks, true);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SmartSchool Hub — Installation</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh; padding: 2rem 1rem;
    display: flex; flex-direction: column; align-items: center;
  }
  .card {
    background: #fff; border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
    width: 100%; max-width: 680px; overflow: hidden;
  }
  .card-header {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff; padding: 2rem 2.5rem;
  }
  .card-header h1 { font-size: 1.6rem; font-weight: 700; }
  .card-header p  { opacity: .8; margin-top: .3rem; font-size: .95rem; }
  .steps { display: flex; border-bottom: 1px solid #e5e7eb; }
  .step-item {
    flex: 1; padding: .8rem .5rem; text-align: center;
    font-size: .8rem; font-weight: 600; color: #9ca3af;
    border-bottom: 3px solid transparent; transition: all .2s;
  }
  .step-item.active { color: #4f46e5; border-color: #4f46e5; }
  .step-item.done   { color: #16a34a; border-color: #16a34a; }
  .card-body { padding: 2rem 2.5rem; }
  .check-list { list-style: none; display: flex; flex-direction: column; gap: .6rem; }
  .check-list li {
    display: flex; align-items: center; gap: .75rem;
    padding: .6rem 1rem; border-radius: 8px; font-size: .9rem; background: #f9fafb;
  }
  .check-list li.pass { background: #f0fdf4; color: #15803d; }
  .check-list li.fail { background: #fef2f2; color: #b91c1c; }
  .form-section { margin-top: 1.5rem; }
  .form-section h3 {
    font-size: .75rem; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; color: #6b7280; margin-bottom: .8rem;
    padding-bottom: .4rem; border-bottom: 1px solid #e5e7eb;
  }
  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .form-grid .full { grid-column: 1 / -1; }
  label { display: block; font-size: .82rem; font-weight: 600; color: #374151; margin-bottom: .3rem; }
  input[type=text], input[type=email], input[type=password], input[type=number], input[type=url] {
    width: 100%; padding: .55rem .85rem;
    border: 1.5px solid #d1d5db; border-radius: 8px;
    font-size: .9rem; transition: border-color .2s; background: #fff;
  }
  input:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.12); }
  .hint { font-size: .75rem; color: #9ca3af; margin-top: .25rem; }
  .alert {
    padding: .9rem 1.1rem; border-radius: 8px; margin-bottom: 1.2rem;
    font-size: .9rem; display: flex; gap: .6rem; align-items: flex-start;
  }
  .alert.danger  { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .alert.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
  .btn {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .75rem 1.8rem; border-radius: 10px; font-size: 1rem;
    font-weight: 600; cursor: pointer; border: none; transition: all .2s; text-decoration: none;
  }
  .btn-primary { background: #4f46e5; color: #fff; }
  .btn-primary:hover { background: #4338ca; }
  .btn-success { background: #16a34a; color: #fff; }
  .btn-success:hover { background: #15803d; }
  .btn-group { margin-top: 1.8rem; display: flex; justify-content: flex-end; }
  .success-icon { font-size: 4rem; text-align: center; margin: 1rem 0; }
  .info-box {
    background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px;
    padding: 1rem 1.2rem; margin-top: 1.2rem; font-size: .88rem; color: #92400e;
  }
  .cred-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: .88rem; }
  .cred-table td { padding: .5rem .8rem; border: 1px solid #e5e7eb; }
  .cred-table tr:nth-child(even) td { background: #f9fafb; }
</style>
</head>
<body>

<div class="card">
  <div class="card-header">
    <h1>🎓 SmartSchool Hub</h1>
    <p>Assistant d'installation — v<?= INSTALLER_VERSION ?></p>
  </div>

  <div class="steps">
    <div class="step-item <?= $step===1?'active':($step>1?'done':'') ?>">① Vérifications</div>
    <div class="step-item <?= $step===2?'active':($step>2?'done':'') ?>">② Configuration</div>
    <div class="step-item <?= $step===3?'active':'' ?>">③ Terminé</div>
  </div>

  <div class="card-body">

<?php if ($step === 1): ?>
  <p style="color:#6b7280;margin-bottom:1.2rem;font-size:.9rem;">
    Vérification de la compatibilité de votre serveur avant l'installation.
  </p>
  <ul class="check-list">
    <?php foreach ($checks as $label => $pass): ?>
    <li class="<?= $pass ? 'pass' : 'fail' ?>">
      <span><?= $pass ? '✅' : '❌' ?></span>
      <?= htmlspecialchars($label) ?>
      <?php if (str_contains($label, 'PHP')): ?>
        <small style="margin-left:auto;opacity:.7">(<?= PHP_VERSION ?>)</small>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php if (!$allOk): ?>
  <div class="alert danger" style="margin-top:1.2rem">
    ⚠️ Corrigez les erreurs ci-dessus avant de continuer.
  </div>
  <?php else: ?>
  <div class="alert success" style="margin-top:1.2rem">
    ✅ Votre serveur est prêt. Cliquez sur <strong>Continuer</strong>.
  </div>
  <div class="btn-group">
    <a href="install.php?step=2" class="btn btn-primary">Continuer →</a>
  </div>
  <?php endif; ?>

<?php elseif ($step === 2): ?>

  <?php if ($error): ?>
  <div class="alert danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="install.php?step=2">

    <div class="form-section">
      <h3>🏫 Informations de l'école</h3>
      <div class="form-grid">
        <div class="full">
          <label>Nom de l'établissement</label>
          <input type="text" name="app_name" required
                 value="<?= htmlspecialchars($_SESSION['install']['app_name'] ?? '') ?>"
                 placeholder="Lycée Général Leclerc">
        </div>
        <div class="full">
          <label>URL de base du site</label>
          <input type="text" name="base_url" required
                 value="<?= htmlspecialchars($_SESSION['install']['base_url'] ?? 'http://localhost/SmartSchoolHub') ?>"
                 placeholder="http://localhost/SmartSchoolHub">
          <p class="hint">Sans slash final. Ex : http://localhost/SmartSchoolHub</p>
        </div>
      </div>
    </div>

    <div class="form-section">
      <h3>🗄️ Base de données MySQL</h3>
      <div class="form-grid">
        <div>
          <label>Hôte</label>
          <input type="text" name="db_host"
                 value="<?= htmlspecialchars($_SESSION['install']['db_host'] ?? 'localhost') ?>">
        </div>
        <div>
          <label>Port</label>
          <input type="number" name="db_port"
                 value="<?= htmlspecialchars($_SESSION['install']['db_port'] ?? '3306') ?>">
        </div>
        <div>
          <label>Nom de la base</label>
          <input type="text" name="db_name" required
                 value="<?= htmlspecialchars($_SESSION['install']['db_name'] ?? 'smartschoolhub') ?>">
        </div>
        <div>
          <label>Utilisateur MySQL</label>
          <input type="text" name="db_user" required
                 value="<?= htmlspecialchars($_SESSION['install']['db_user'] ?? 'root') ?>">
        </div>
        <div class="full">
          <label>Mot de passe MySQL</label>
          <input type="password" name="db_pass"
                 value="<?= htmlspecialchars($_SESSION['install']['db_pass'] ?? '') ?>">
          <p class="hint">Laisser vide si aucun mot de passe (XAMPP/WAMP par défaut).</p>
        </div>
      </div>
    </div>

    <div class="form-section">
      <h3>👤 Compte Administrateur</h3>
      <div class="form-grid">
        <div class="full">
          <label>Email administrateur</label>
          <input type="email" name="admin_email" required
                 value="<?= htmlspecialchars($_SESSION['install']['admin_email'] ?? '') ?>"
                 placeholder="directeur@monecole.cm">
        </div>
        <div>
          <label>Mot de passe (min. 8 caractères)</label>
          <input type="password" name="admin_pass" required minlength="8">
        </div>
        <div>
          <label>Confirmer le mot de passe</label>
          <input type="password" name="admin_pass2" required minlength="8">
        </div>
      </div>
    </div>

    <div class="form-section">
      <h3>🔐 Sécurité</h3>
      <div class="form-grid">
        <div class="full">
          <label>Clé secrète</label>
          <input type="text" name="secret_key"
                 value="<?= htmlspecialchars($_SESSION['install']['secret_key'] ?? bin2hex(random_bytes(32))) ?>">
          <p class="hint">Générée automatiquement. Ne pas modifier sauf si nécessaire.</p>
        </div>
      </div>
    </div>

    <div class="btn-group">
      <a href="install.php?step=1"
         style="margin-right:auto;color:#6b7280;text-decoration:none;align-self:center">← Retour</a>
      <button type="submit" class="btn btn-primary">⚡ Lancer l'installation</button>
    </div>
  </form>

<?php elseif ($step === 3): ?>
  <div class="success-icon">🎉</div>
  <h2 style="text-align:center;color:#16a34a;font-size:1.4rem">Installation réussie !</h2>
  <p style="text-align:center;color:#6b7280;margin:.5rem 0 1.5rem;font-size:.92rem">
    SmartSchool Hub est prêt à être utilisé.
  </p>

  <table class="cred-table">
    <tr><td><strong>Application</strong></td>
        <td><?= htmlspecialchars($_SESSION['install']['app_name'] ?? '') ?></td></tr>
    <tr><td><strong>URL</strong></td>
        <td><?= htmlspecialchars($_SESSION['install']['base_url'] ?? '') ?></td></tr>
    <tr><td><strong>Base de données</strong></td>
        <td><?= htmlspecialchars($_SESSION['install']['db_name'] ?? '') ?></td></tr>
    <tr><td><strong>Email admin</strong></td>
        <td><?= htmlspecialchars($_SESSION['install']['admin_email'] ?? '') ?></td></tr>
    <tr><td><strong>Mot de passe</strong></td>
        <td><?= str_repeat('●', strlen($_SESSION['install']['admin_pass'] ?? '')) ?></td></tr>
  </table>

  <div class="info-box">
    <strong>🔒 Action obligatoire :</strong>
    Supprimez <code>install.php</code> immédiatement après avoir testé la connexion.
  </div>

  <div class="btn-group" style="justify-content:center;gap:1rem">
    <a href="login.php" class="btn btn-success">→ Se connecter maintenant</a>
  </div>
<?php endif; ?>

  </div>
</div>

<p style="color:rgba(255,255,255,.6);font-size:.78rem;margin-top:1.2rem">
  SmartSchool Hub v1.0 — Installer v<?= INSTALLER_VERSION ?>
</p>
</body>
</html>
