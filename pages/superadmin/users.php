<?php
/**
 * SmartSchool Hub — Gestion Utilisateurs (Super-Admin)
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('super_admin');

$pageTitle = 'Gestion Utilisateurs';
$pageIcon = '👥';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
    <div class="card-title">
        <?= $pageTitle ?>
        <span class="badge badge-blue">Vue Globale</span>
    </div>
    
    <div class="empty-state">
        <div class="empty-state-icon">👥</div>
        <h3>Gestion des utilisateurs</h3>
        <p>Fonctionnalité en développement - Utilisez les tableaux de bord des établissements pour gérer les utilisateurs.</p>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
