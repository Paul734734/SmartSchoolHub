<?php
/**
 * SmartSchool Hub — Configuration 2FA
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/two_factor.php';

// Vérifier que l'utilisateur est connecté
if (!Auth::isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$userId = Auth::id();
$user = Database::fetchOne('SELECT role, email, first_name, last_name FROM users WHERE id = ?', [$userId]);

// Vérifier si 2FA est requis pour ce rôle
if (!TwoFactorAuth::isRequiredForRole($user['role'])) {
    header('Location: ' . Auth::getDashboardUrl($user['role']));
    exit;
}

$errors = [];
$success = '';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$secret = '';
$qrCodeUrl = '';

// ── Étape 1: Génération du secret ───────────────────────────────
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $secret = TwoFactorAuth::generateSecret();
        
        // Sauvegarder temporairement en session
        $_SESSION['2fa_secret'] = $secret;
        $_SESSION['2fa_setup_user'] = $userId;
        
        // Générer l'URL QR Code
        $qrCodeUrl = TwoFactorAuth::generateQRCodeURL($user['email'], $secret);
        
        $step = 2;
    }
}

// ── Étape 2: Vérification du code ───────────────────────────────
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $code = sanitize($_POST['verification_code'] ?? '');
        $secret = $_SESSION['2fa_secret'] ?? '';
        
        if (!$code || !$secret) {
            $errors[] = 'Code de vérification manquant.';
        } else {
            // Vérifier temporairement avec le secret en session
            if (TwoFactorAuth::verifyTOTPCode($secret, $code)) {
                // Activer 2FA pour l'utilisateur
                if (TwoFactorAuth::enable2FA($userId, $secret)) {
                    // Envoyer les codes de backup par email
                    TwoFactorAuth::sendBackupCodesEmail($userId);
                    
                    // Nettoyer la session
                    unset($_SESSION['2fa_secret'], $_SESSION['2fa_setup_user']);
                    
                    $success = 'Authentification à deux facteurs activée avec succès !';
                    $step = 3;
                } else {
                    $errors[] = 'Erreur lors de l\'activation de 2FA.';
                }
            } else {
                $errors[] = 'Code de vérification incorrect. Veuillez réessayer.';
            }
        }
    }
}

// ── Si 2FA est déjà configuré ───────────────────────────────────
if (TwoFactorAuth::isEnabled($userId)) {
    header('Location: ' . Auth::getDashboardUrl($user['role']));
    exit;
}

$pageTitle = 'Configuration Authentification 2FA';
$pageIcon = '🔐';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card" style="max-width:600px;margin:40px auto;">
    <div class="card-header">
        <h2><?= $pageTitle ?></h2>
        <p>Sécurisez votre compte avec l'authentification à deux facteurs</p>
    </div>

    <!-- Progress steps -->
    <div class="steps">
        <div class="step <?= $step >= 1 ? 'active' : '' ?>">
            <div class="step-number">1</div>
            <div class="step-label">Génération</div>
        </div>
        <div class="step <?= $step >= 2 ? 'active' : '' ?>">
            <div class="step-number">2</div>
            <div class="step-label">Vérification</div>
        </div>
        <div class="step <?= $step >= 3 ? 'active' : '' ?>">
            <div class="step-number">3</div>
            <div class="step-label">Terminé</div>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $error): ?>
                <div><?= $error ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= $success ?>
        </div>
    <?php endif; ?>

    <!-- Étape 1: Introduction -->
    <?php if ($step === 1): ?>
    <div class="step-content">
        <div style="text-align:center;margin:30px 0;">
            <div style="font-size:48px;margin-bottom:20px;">🔐</div>
            <h3>Pourquoi activer 2FA ?</h3>
            <p style="color:var(--muted);margin:20px 0;">
                L'authentification à deux facteurs ajoute une couche de sécurité supplémentaire à votre compte. 
                Même si quelqu'un connaît votre mot de passe, il ne pourra pas accéder à votre compte sans votre téléphone.
            </p>
        </div>

        <div style="background:#f8f9fa;padding:20px;border-radius:8px;margin:20px 0;">
            <h4>📋 Ce dont vous aurez besoin :</h4>
            <ul style="margin:10px 0;">
                <li>✅ Une application d'authentification (Google Authenticator, Authy, Microsoft Authenticator)</li>
                <li>✅ Votre smartphone ou tablette</li>
                <li>✅ Un accès à votre email pour recevoir les codes de backup</li>
            </ul>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <div style="text-align:center;margin:30px 0;">
                <button type="submit" class="btn btn-primary" style="font-size:18px;padding:12px 30px;">
                    Commencer la configuration →
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Étape 2: Configuration -->
    <?php if ($step === 2): ?>
    <div class="step-content">
        <h3>📱 Configurez votre application d'authentification</h3>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;margin:30px 0;">
            <!-- QR Code -->
            <div style="text-align:center;">
                <h4>1. Scannez ce QR Code</h4>
                <div style="background:white;padding:20px;border-radius:8px;display:inline-block;margin:20px 0;">
                    <?php
                    // Générer le QR Code (simple placeholder - dans la vraie implémentation, utiliser une librairie)
                    $qrData = urlencode($qrCodeUrl);
                    echo "<img src='https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={$qrData}' alt='QR Code' style='max-width:200px;'>";
                    ?>
                </div>
                <p style="font-size:12px;color:var(--muted);">
                    Utilisez votre application d'authentification pour scanner ce code
                </p>
            </div>

            <!-- Manuel -->
            <div>
                <h4>2. Ou entrez manuellement</h4>
                <div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0;">
                    <p style="font-size:12px;color:var(--muted);margin-bottom:10px;">Secret (copiez ce code) :</p>
                    <div style="background:white;padding:10px;border-radius:4px;font-family:monospace;font-size:16px;text-align:center;border:2px solid #e0e0e0;">
                        <?= $secret ?>
                    </div>
                    <button onclick="copySecret()" class="btn btn-sm btn-secondary" style="margin-top:10px;">
                        📋 Copier
                    </button>
                </div>
                <p style="font-size:12px;color:var(--muted);">
                    <strong>Nom du compte :</strong> <?= $user['email'] ?><br>
                    <strong>Émetteur :</strong> SmartSchoolHub
                </p>
            </div>
        </div>

        <!-- Vérification -->
        <div style="background:#fff3cd;padding:20px;border-radius:8px;margin:30px 0;">
            <h4>3. Entrez le code de vérification</h4>
            <p style="font-size:14px;margin:10px 0;">
                Ouvrez votre application d'authentification et entrez le code à 6 chiffres qui s'affiche :
            </p>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
                <div style="display:flex;gap:10px;align-items:center;justify-content:center;margin:20px 0;">
                    <input type="text" 
                           name="verification_code" 
                           placeholder="000000" 
                           maxlength="6" 
                           pattern="[0-9]{6}"
                           required
                           style="font-size:24px;text-align:center;letter-spacing:4px;width:150px;padding:10px;border:2px solid #e0e0e0;border-radius:8px;">
                    <button type="submit" class="btn btn-primary">
                        Vérifier →
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Étape 3: Terminé -->
    <?php if ($step === 3): ?>
    <div class="step-content" style="text-align:center;">
        <div style="font-size:64px;margin:30px 0;">✅</div>
        <h3>Authentification 2FA activée !</h3>
        <p style="color:var(--muted);margin:20px 0;">
            Votre compte est maintenant sécurisé avec l'authentification à deux facteurs.
        </p>
        
        <div style="background:#d4edda;padding:20px;border-radius:8px;margin:20px 0;text-align:left;">
            <h4>📧 Codes de backup envoyés</h4>
            <p style="margin:10px 0;">
                Des codes de backup ont été envoyés à votre email <strong><?= $user['email'] ?></strong>. 
                Conservez-les dans un endroit sécurisé.
            </p>
        </div>

        <div style="background:#f8f9fa;padding:20px;border-radius:8px;margin:20px 0;text-align:left;">
            <h4>💡 Prochaines connexions</h4>
            <ul style="margin:10px 0;">
                <li>Vous devrez entrer un code de votre application d'authentification</li>
                <li>En cas de perte de votre téléphone, utilisez un code de backup</li>
                <li>Vous pouvez désactiver 2FA depuis vos paramètres de sécurité</li>
            </ul>
        </div>

        <div style="margin:40px 0;">
            <a href="<?= Auth::getDashboardUrl($user['role']) ?>" class="btn btn-primary" style="font-size:18px;padding:12px 30px;">
                Accéder au tableau de bord →
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.steps {
    display: flex;
    justify-content: center;
    margin: 30px 0;
}

.step {
    display: flex;
    align-items: center;
    opacity: 0.5;
    transition: opacity 0.3s;
}

.step.active {
    opacity: 1;
}

.step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e0e0e0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-right: 10px;
}

.step.active .step-number {
    background: var(--primary);
    color: white;
}

.step-label {
    font-size: 14px;
    color: var(--muted);
}

.step.active .step-label {
    color: var(--primary);
    font-weight: 600;
}

.step:not(:last-child)::after {
    content: '';
    width: 50px;
    height: 2px;
    background: #e0e0e0;
    margin: 0 20px;
}

.step.active:not(:last-child)::after {
    background: var(--primary);
}
</style>

<script>
function copySecret() {
    const secret = '<?= $secret ?>';
    navigator.clipboard.writeText(secret).then(() => {
        alert('Secret copié dans le presse-papiers !');
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = secret;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('Secret copié dans le presse-papiers !');
    });
}

// Auto-focus verification code input
const codeInput = document.querySelector('input[name="verification_code"]');
if (codeInput) {
    codeInput.focus();
    
    // Only allow numbers
    codeInput.addEventListener('input', function(e) {
        e.target.value = e.target.value.replace(/[^0-9]/g, '');
    });
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
