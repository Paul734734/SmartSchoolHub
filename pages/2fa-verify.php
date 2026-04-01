<?php
/**
 * SmartSchool Hub — Vérification 2FA lors de la connexion
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/two_factor.php';

// Vérifier que l'utilisateur est en cours de connexion 2FA
$userId = $_SESSION['2fa_user_id'] ?? 0;
$rememberMe = $_SESSION['2fa_remember_me'] ?? false;

if (!$userId) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$user = Database::fetchOne(
    'SELECT email, first_name, last_name, role FROM users WHERE id = ? AND is_active = 1',
    [$userId]
);

if (!$user) {
    unset($_SESSION['2fa_user_id'], $_SESSION['2fa_remember_me']);
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$errors = [];
$success = '';

// ── Traitement de la vérification ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        switch ($action) {
            case 'verify_code':
                $code = sanitize($_POST['verification_code'] ?? '');
                
                if (!$code) {
                    $errors[] = 'Veuillez entrer un code de vérification.';
                } elseif (!TwoFactorAuth::verifyCode($userId, $code)) {
                    $errors[] = 'Code incorrect. Veuillez réessayer.';
                } else {
                    // Code valide - compléter la connexion
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['2fa_verified'] = true;
                    
                    // Nettoyer la session 2FA
                    unset($_SESSION['2fa_user_id'], $_SESSION['2fa_remember_me']);
                    
                    // Rediriger vers le tableau de bord
                    header('Location: ' . Auth::getDashboardUrl($user['role']));
                    exit;
                }
                break;
                
            case 'use_backup':
                $backupCode = sanitize($_POST['backup_code'] ?? '');
                
                if (!$backupCode) {
                    $errors[] = 'Veuillez entrer un code de backup.';
                } elseif (!TwoFactorAuth::verifyCode($userId, $backupCode)) {
                    $errors[] = 'Code de backup incorrect.';
                } else {
                    // Code de backup valide - compléter la connexion
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['2fa_verified'] = true;
                    $_SESSION['used_backup_code'] = true;
                    
                    // Nettoyer la session 2FA
                    unset($_SESSION['2fa_user_id'], $_SESSION['2fa_remember_me']);
                    
                    setFlash('warning', 'Vous avez utilisé un code de backup. Considérez générer de nouveaux codes de backup.');
                    header('Location: ' . Auth::getDashboardUrl($user['role']));
                    exit;
                }
                break;
        }
    }
}

$pageTitle = 'Vérification 2FA';
$pageIcon = '🔐';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card" style="max-width:500px;margin:60px auto;">
    <div class="card-header" style="text-align:center;">
        <div style="font-size:48px;margin-bottom:20px;">🔐</div>
        <h2><?= $pageTitle ?></h2>
        <p style="color:var(--muted);">Bonjour <?= $user['first_name'] ?>, veuillez vérifier votre identité</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $error): ?>
                <div><?= $error ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs" style="margin:30px 0;">
        <button class="tab-btn active" onclick="switchTab('totp')">
            📱 Code Application
        </button>
        <button class="tab-btn" onclick="switchTab('backup')">
            📋 Code Backup
        </button>
    </div>

    <!-- Tab 1: Code TOTP -->
    <div id="totp-tab" class="tab-content active">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="verify_code">
            
            <div style="text-align:center;margin:30px 0;">
                <h3>📱 Entrez le code de votre application</h3>
                <p style="color:var(--muted);margin:15px 0;">
                    Ouvrez Google Authenticator (ou une autre app) et entrez le code à 6 chiffres
                </p>
                
                <div style="display:flex;justify-content:center;align-items:center;gap:15px;margin:30px 0;">
                    <input type="text" 
                           name="verification_code" 
                           placeholder="000000" 
                           maxlength="6" 
                           pattern="[0-9]{6}"
                           required
                           style="font-size:32px;text-align:center;letter-spacing:8px;width:180px;padding:15px;border:3px solid #e0e0e0;border-radius:12px;">
                </div>
                
                <button type="submit" class="btn btn-primary" style="font-size:18px;padding:12px 30px;">
                    Vérifier →
                </button>
            </div>
        </form>
    </div>

    <!-- Tab 2: Code Backup -->
    <div id="backup-tab" class="tab-content">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="use_backup">
            
            <div style="text-align:center;margin:30px 0;">
                <h3>📋 Entrez un code de backup</h3>
                <p style="color:var(--muted);margin:15px 0;">
                    Utilisez un des codes de backup qui vous ont été envoyés par email
                </p>
                
                <div style="display:flex;justify-content:center;align-items:center;gap:15px;margin:30px 0;">
                    <input type="text" 
                           name="backup_code" 
                           placeholderXXXXXXXX" 
                           maxlength="8" 
                           pattern="[A-Z0-9]{8}"
                           required
                           style="font-size:24px;text-align:center;letter-spacing:2px;width:200px;padding:12px;border:2px solid #e0e0e0;border-radius:8px;text-transform:uppercase;">
                </div>
                
                <button type="submit" class="btn btn-secondary" style="font-size:16px;padding:10px 25px;">
                    Utiliser le code backup
                </button>
            </div>
        </form>
    </div>

    <!-- Options supplémentaires -->
    <div style="border-top:1px solid #e0e0e0;padding-top:20px;margin-top:30px;text-align:center;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;">
            <div style="font-size:14px;color:var(--muted);">
                <strong>Problèmes ?</strong><br>
                <a href="mailto:support@smartschoolhub.com">Contactez le support</a>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-secondary btn-sm">
                    Annuler et retour
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.tabs {
    display: flex;
    border-bottom: 2px solid #e0e0e0;
    margin-bottom: 30px;
}

.tab-btn {
    flex: 1;
    padding: 15px;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 16px;
    color: var(--muted);
    border-bottom: 3px solid transparent;
    transition: all 0.3s;
}

.tab-btn.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 600;
}

.tab-btn:hover {
    color: var(--primary);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Animation pour les inputs */
input:focus {
    border-color: var(--primary) !important;
    outline: none;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Responsive */
@media (max-width: 768px) {
    .card {
        margin: 20px;
        max-width: none;
    }
    
    .tabs {
        flex-direction: column;
    }
    
    .tab-btn {
        border-bottom: 1px solid #e0e0e0;
        border-right: none;
    }
    
    .tab-btn.active {
        border-bottom-color: var(--primary);
    }
}
</style>

<script>
function switchTab(tabName) {
    // Masquer tous les tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Désactiver tous les boutons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Afficher le tab sélectionné
    document.getElementById(tabName + '-tab').classList.add('active');
    
    // Activer le bouton correspondant
    event.target.classList.add('active');
    
    // Focus sur le premier input du tab
    setTimeout(() => {
        const firstInput = document.querySelector('#' + tabName + '-tab input');
        if (firstInput) {
            firstInput.focus();
        }
    }, 100);
}

// Auto-focus et formatage des inputs
document.addEventListener('DOMContentLoaded', function() {
    // Input TOTP
    const totpInput = document.querySelector('input[name="verification_code"]');
    if (totpInput) {
        totpInput.focus();
        totpInput.addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/[^0-9]/g, '');
            if (e.target.value.length === 6) {
                e.target.form.submit();
            }
        });
    }
    
    // Input backup code
    const backupInput = document.querySelector('input[name="backup_code"]');
    if (backupInput) {
        backupInput.addEventListener('input', function(e) {
            e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        });
    }
});

// Timer pour afficher l'heure actuelle (pour info)
function updateTimer() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('fr-FR', { 
        hour: '2-digit', 
        minute: '2-digit',
        second: '2-digit'
    });
    
    // Optionnel: afficher un timer quelque part
    // document.getElementById('current-time').textContent = timeString;
}

setInterval(updateTimer, 1000);
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
