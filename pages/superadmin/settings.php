<?php
/**
 * SmartSchool Hub — Paramètres Système (Super-Admin)
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('super_admin');

$errors = [];
$success = '';

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_settings':
                $settings = $_POST['settings'] ?? [];
                foreach ($settings as $key => $value) {
                    Database::execute(
                        'UPDATE system_settings SET value = ?, updated_at = NOW(), updated_by = ? WHERE key = ?',
                        [$value, Auth::id(), $key]
                    );
                }
                setFlash('success', 'Paramètres mis à jour avec succès.');
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
                break;
        }
    }
}

// Récupérer tous les paramètres système
$systemSettings = Database::fetchAll(
    'SELECT * FROM system_settings ORDER BY `key`'
);

$pageTitle = 'Paramètres Système';
$pageIcon = '⚙️';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
    <div class="card-title">
        <?= $pageTitle ?>
        <span class="badge badge-blue">Configuration Globale</span>
    </div>
    
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
        <input type="hidden" name="action" value="update_settings">
        
        <div class="settings-grid">
            <?php foreach ($systemSettings as $setting): ?>
            <div class="setting-item">
                <label><?= e($setting['description']) ?></label>
                <?php if ($setting['type'] === 'boolean'): ?>
                <select name="settings[<?= $setting['key'] ?>]">
                    <option value="1" <?= $setting['value'] == '1' ? 'selected' : '' ?>>Activé</option>
                    <option value="0" <?= $setting['value'] == '0' ? 'selected' : '' ?>>Désactivé</option>
                </select>
                <?php elseif ($setting['type'] === 'number'): ?>
                <input type="number" name="settings[<?= $setting['key'] ?>]" value="<?= e($setting['value']) ?>">
                <?php else: ?>
                <input type="text" name="settings[<?= $setting['key'] ?>]" value="<?= e($setting['value']) ?>">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">💾 Sauvegarder</button>
        </div>
    </form>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
