<?php
/**
 * SmartSchool Hub — Vue Détail Établissement (Super-Admin)
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('super_admin');

$schoolId = (int)($_GET['id'] ?? 0);
if (!$schoolId) {
    header('Location: ' . BASE_URL . '/modules/superadmin/pages/schools.php');
    exit;
}

$school = Database::fetchOne(
    'SELECT s.*, l.plan, l.status as license_status, l.expires_at, l.max_students, l.max_teachers
     FROM school s
     LEFT JOIN licenses l ON l.school_id = s.id
     WHERE s.id = ?',
    [$schoolId]
);

if (!$school) {
    setFlash('error', 'Établissement introuvable.');
    header('Location: ' . BASE_URL . '/modules/superadmin/pages/schools.php');
    exit;
}

$pageTitle = 'Détail Établissement';
$pageIcon = '🏫';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
    <div class="card-title">
        <?= $pageTitle ?> - <?= e($school['name']) ?>
        <a href="<?= BASE_URL ?>/modules/superadmin/pages/schools.php" class="card-action">← Retour</a>
    </div>
    
    <div class="school-details">
        <div class="detail-grid">
            <div class="detail-item">
                <label>Nom</label>
                <span><?= e($school['name']) ?></span>
            </div>
            <div class="detail-item">
                <label>Nom court</label>
                <span><?= e($school['short_name']) ?></span>
            </div>
            <div class="detail-item">
                <label>Adresse</label>
                <span><?= e($school['address']) ?></span>
            </div>
            <div class="detail-item">
                <label>Ville</label>
                <span><?= e($school['city']) ?></span>
            </div>
            <div class="detail-item">
                <label>Pays</label>
                <span><?= e($school['country']) ?></span>
            </div>
            <div class="detail-item">
                <label>Téléphone</label>
                <span><?= e($school['phone']) ?></span>
            </div>
            <div class="detail-item">
                <label>Email</label>
                <span><?= e($school['email']) ?></span>
            </div>
            <div class="detail-item">
                <label>Site web</label>
                <span><?= e($school['website']) ?></span>
            </div>
        </div>
        
        <div class="license-info">
            <h3>📋 Informations Licence</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <label>Plan</label>
                    <span class="badge badge-<?= $school['plan'] === 'free' ? 'gray' : ($school['plan'] === 'pro' ? 'blue' : 'purple') ?>">
                        <?= ucfirst($school['plan']) ?>
                    </span>
                </div>
                <div class="detail-item">
                    <label>Statut</label>
                    <span class="badge badge-<?= $school['license_status'] === 'active' ? 'green' : ($school['license_status'] === 'expired' ? 'red' : 'amber') ?>">
                        <?= $school['license_status'] === 'active' ? '✓ Actif' : ($school['license_status'] === 'expired' ? '⚠ Expiré' : '⏳ En attente') ?>
                    </span>
                </div>
                <div class="detail-item">
                    <label>Expiration</label>
                    <span><?= date('d/m/Y', strtotime($school['expires_at'])) ?></span>
                </div>
                <div class="detail-item">
                    <label>Max Élèves</label>
                    <span><?= number_format($school['max_students']) ?></span>
                </div>
                <div class="detail-item">
                    <label>Max Enseignants</label>
                    <span><?= number_format($school['max_teachers']) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
