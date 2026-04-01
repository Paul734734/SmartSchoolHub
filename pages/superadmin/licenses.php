<?php
/**
 * SmartSchool Hub — Gestion des Licences (Super-Admin)
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('super_admin');

$errors = [];
$success = '';

// ── Traitement des actions ───────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        switch ($action) {
            case 'update_license':
                $licenseId = (int)($_POST['license_id'] ?? 0);
                $plan = sanitize($_POST['plan'] ?? 'free');
                $maxStudents = (int)($_POST['max_students'] ?? 100);
                $maxTeachers = (int)($_POST['max_teachers'] ?? 20);
                $monthlyPrice = (float)($_POST['monthly_price'] ?? 0);
                $expiresAt = sanitize($_POST['expires_at'] ?? '');

                if (!$licenseId) {
                    $errors[] = 'ID licence invalide.';
                } else {
                    Database::execute(
                        'UPDATE licenses SET plan=?, max_students=?, max_teachers=?, monthly_price=?, expires_at=?, updated_at=NOW()
                         WHERE id=?',
                        [$plan, $maxStudents, $maxTeachers, $monthlyPrice, $expiresAt, $licenseId]
                    );
                    setFlash('success', 'Licence mise à jour avec succès.');
                    header('Location: ' . BASE_URL . '/modules/superadmin/pages/licenses.php');
                    exit;
                }
                break;

            case 'renew_license':
                $licenseId = (int)($_POST['license_id'] ?? 0);
                $months = (int)($_POST['months'] ?? 12);
                
                if ($licenseId && $months > 0) {
                    Database::execute(
                        'UPDATE licenses SET expires_at=DATE_ADD(expires_at, INTERVAL ? MONTH), status="active", updated_at=NOW()
                         WHERE id=?',
                        [$months, $licenseId]
                    );
                    setFlash('success', 'Licence renouvelée avec succès.');
                    header('Location: ' . BASE_URL . '/modules/superadmin/pages/licenses.php');
                    exit;
                }
                break;
        }
    }
}

// ── Récupération des données ─────────────────────────────────────
$licenses = Database::fetchAll(
    'SELECT l.*, s.name as school_name, s.city, s.country,
            COUNT(DISTINCT u.id) as current_users,
            COUNT(DISTINCT CASE WHEN u.role = "student" THEN u.id END) as current_students,
            COUNT(DISTINCT CASE WHEN u.role = "teacher" THEN u.id END) as current_teachers,
            DATEDIFF(l.expires_at, CURDATE()) as days_remaining
     FROM licenses l
     JOIN school s ON s.id = l.school_id
     LEFT JOIN users u ON u.role IN ("admin","teacher","student","parent")
     GROUP BY l.id
     ORDER BY l.expires_at ASC'
);

// Statistiques globales des licences
$licenseStats = [
    'total' => count($licenses),
    'active' => count(array_filter($licenses, fn($l) => $l['status'] === 'active')),
    'expired' => count(array_filter($licenses, fn($l) => $l['status'] === 'expired')),
    'expiring_soon' => count(array_filter($licenses, fn($l) => $l['days_remaining'] <= 30 && $l['days_remaining'] >= 0)),
    'revenue_monthly' => array_sum(array_column($licenses, 'monthly_price')),
];

$pageTitle = 'Gestion des Licences';
$pageIcon = '💳';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- ── Header ───────────────────────────────────────────────────── -->
<div class="page-header">
    <div>
        <h1><?= $pageTitle ?></h1>
        <p>Gérez les licences et abonnements des établissements</p>
    </div>
</div>

<!-- ── Statistiques des licences ───────────────────────────────── -->
<div class="card mb-20">
    <div class="stat-grid">
        <div class="stat-item">
            <div class="stat-label">Total Licences</div>
            <div class="stat-value"><?= $licenseStats['total'] ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Licences Actives</div>
            <div class="stat-value" style="color:var(--green);"><?= $licenseStats['active'] ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Expirées</div>
            <div class="stat-value" style="color:var(--rose);"><?= $licenseStats['expired'] ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Expirant bientôt</div>
            <div class="stat-value" style="color:var(--amber);"><?= $licenseStats['expiring_soon'] ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Revenu Mensuel</div>
            <div class="stat-value"><?= number_format($licenseStats['revenue_monthly'], 0, ',', ' ') ?> FCFA</div>
        </div>
    </div>
</div>

<!-- ── Tableau des licences ─────────────────────────────────────── -->
<div class="card">
    <div class="table-wrap">
        <table id="licenses-table">
            <thead>
                <tr>
                    <th>Établissement</th>
                    <th>Plan</th>
                    <th>Utilisateurs</th>
                    <th>Limites</th>
                    <th>Prix/Mois</th>
                    <th>Expiration</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($licenses as $license):
                    $usagePercent = $license['max_students'] > 0 
                        ? round(($license['current_students'] / $license['max_students']) * 100)
                        : 0;
                    $isExpiringSoon = $license['days_remaining'] <= 30 && $license['days_remaining'] >= 0;
                    $isExpired = $license['days_remaining'] < 0;
                ?>
                <tr class="<?= $isExpired ? 'expired-row' : ($isExpiringSoon ? 'warning-row' : '') ?>">
                    <td>
                        <div class="user-cell">
                            <div class="avatar" style="background:linear-gradient(135deg,#3B82F6,#06B6D4);">
                                <?= strtoupper(substr($license['school_name'], 0, 2)) ?>
                            </div>
                            <div>
                                <div class="user-cell-name"><?= e($license['school_name']) ?></div>
                                <div class="user-cell-sub"><?= e($license['city']) ?>, <?= e($license['country']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge badge-<?= match($license['plan']) {
                            'free' => 'gray',
                            'pro' => 'blue', 
                            'enterprise' => 'purple',
                            default => 'gray'
                        } ?>">
                            <?= ucfirst($license['plan']) ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-size:12px;">
                            <div>🎓 <?= $license['current_students'] ?>/<?= $license['max_students'] ?></div>
                            <div>👨‍🏫 <?= $license['current_teachers'] ?>/<?= $license['max_teachers'] ?></div>
                            <div class="prog-bar mt-2">
                                <div class="prog-fill" style="width:<?= $usagePercent ?>%;background:<?= $usagePercent > 80 ? 'var(--rose)' : ($usagePercent > 60 ? 'var(--amber)' : 'var(--mint)') ?>;"></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:12px;">
                            <div>Max: <?= $license['max_students'] ?> élèves</div>
                            <div>Max: <?= $license['max_teachers'] ?> enseignants</div>
                        </div>
                    </td>
                    <td>
                        <span style="font-weight:600;"><?= number_format($license['monthly_price'], 0, ',', ' ') ?> FCFA</span>
                    </td>
                    <td>
                        <div>
                            <div style="font-weight:600;"><?= date('d/m/Y', strtotime($license['expires_at'])) ?></div>
                            <div style="font-size:11px;color:<?= $isExpired ? 'var(--rose)' : ($isExpiringSoon ? 'var(--amber)' : 'var(--muted)') ?>;">
                                <?php if ($isExpired): ?>
                                    Expiré depuis <?= abs($license['days_remaining']) ?> jours
                                <?php elseif ($isExpiringSoon): ?>
                                    Expire dans <?= $license['days_remaining'] ?> jours
                                <?php else: ?>
                                    <?= $license['days_remaining'] ?> jours restants
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge badge-<?= match($license['status']) {
                            'active' => 'green',
                            'expired' => 'red',
                            'suspended' => 'amber',
                            default => 'gray'
                        } ?>">
                            <?= match($license['status']) {
                                'active' => '✓ Actif',
                                'expired' => '⚠ Expiré',
                                'suspended' => '⏸ Suspendu',
                                default => 'Inconnu'
                            } ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:5px;">
                            <button onclick="editLicense(<?= $license['id'] ?>)" 
                                    class="btn btn-sm btn-amber" title="Modifier">✏️</button>
                            <?php if ($isExpired || $isExpiringSoon): ?>
                            <button onclick="renewLicense(<?= $license['id'] ?>)" 
                                    class="btn btn-sm btn-green" title="Renouveler">🔄</button>
                            <?php endif; ?>
                            <?php if ($license['status'] === 'active'): ?>
                            <button onclick="suspendLicense(<?= $license['id'] ?>)" 
                                    class="btn btn-sm btn-red" title="Suspendre">⏸️</button>
                            <?php elseif ($license['status'] === 'suspended'): ?>
                            <button onclick="activateLicense(<?= $license['id'] ?>)" 
                                    class="btn btn-sm btn-green" title="Activer">▶️</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($licenses)): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state" style="padding:40px;">
                            <div class="empty-state-icon">💳</div>
                            <h3>Aucune licence</h3>
                            <p>Les licences seront créées automatiquement lors de l'ajout des établissements.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Modal Modification Licence ───────────────────────────────── -->
<div id="editLicenseModal" class="modal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h3>✏️ Modifier la Licence</h3>
            <button class="modal-close" onclick="closeModal('editLicenseModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="update_license">
            <input type="hidden" name="license_id" id="edit_license_id">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Plan</label>
                    <select name="plan" id="edit_plan" onchange="updateLicenseLimits()">
                        <option value="free">Free</option>
                        <option value="pro">Professionnel</option>
                        <option value="enterprise">Enterprise</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nombre maximum d'élèves</label>
                    <input type="number" name="max_students" id="edit_max_students" min="1">
                </div>
                <div class="form-group">
                    <label>Nombre maximum d'enseignants</label>
                    <input type="number" name="max_teachers" id="edit_max_teachers" min="1">
                </div>
                <div class="form-group">
                    <label>Prix mensuel (FCFA)</label>
                    <input type="number" name="monthly_price" id="edit_monthly_price" min="0" step="1000">
                </div>
                <div class="form-group full-width">
                    <label>Date d'expiration</label>
                    <input type="date" name="expires_at" id="edit_expires_at">
                </div>
            </div>

            <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?= $error ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editLicenseModal')">
                    Annuler
                </button>
                <button type="submit" class="btn btn-amber">
                    Mettre à jour
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Modal Renouvellement Licence ─────────────────────────────── -->
<div id="renewLicenseModal" class="modal">
    <div class="modal-content" style="max-width:400px;">
        <div class="modal-header">
            <h3>🔄 Renouveler la Licence</h3>
            <button class="modal-close" onclick="closeModal('renewLicenseModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="renew_license">
            <input type="hidden" name="license_id" id="renew_license_id">
            
            <div class="form-group">
                <label>Période de renouvellement</label>
                <select name="months">
                    <option value="1">1 mois</option>
                    <option value="3">3 mois</option>
                    <option value="6">6 mois</option>
                    <option value="12" selected>12 mois</option>
                    <option value="24">24 mois</option>
                </select>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('renewLicenseModal')">
                    Annuler
                </button>
                <button type="submit" class="btn btn-green">
                    Renouveler
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Scripts ───────────────────────────────────────────────────── -->
<script>
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function editLicense(licenseId) {
    // Charger les données de la licence
    const licenses = <?= json_encode($licenses) ?>;
    const license = licenses.find(l => l.id == licenseId);
    
    if (license) {
        document.getElementById('edit_license_id').value = license.id;
        document.getElementById('edit_plan').value = license.plan;
        document.getElementById('edit_max_students').value = license.max_students;
        document.getElementById('edit_max_teachers').value = license.max_teachers;
        document.getElementById('edit_monthly_price').value = license.monthly_price;
        document.getElementById('edit_expires_at').value = license.expires_at;
        
        openModal('editLicenseModal');
    }
}

function renewLicense(licenseId) {
    document.getElementById('renew_license_id').value = licenseId;
    openModal('renewLicenseModal');
}

function updateLicenseLimits() {
    const plan = document.getElementById('edit_plan').value;
    const limits = {
        'free': { students: 100, teachers: 20, price: 0 },
        'pro': { students: 500, teachers: 50, price: 25000 },
        'enterprise': { students: 2000, teachers: 200, price: 100000 }
    };
    
    document.getElementById('edit_max_students').value = limits[plan].students;
    document.getElementById('edit_max_teachers').value = limits[plan].teachers;
    document.getElementById('edit_monthly_price').value = limits[plan].price;
}

function suspendLicense(licenseId) {
    if (confirm('Êtes-vous sûr de vouloir suspendre cette licence ?')) {
        updateLicenseStatus(licenseId, 'suspended');
    }
}

function activateLicense(licenseId) {
    if (confirm('Êtes-vous sûr de vouloir activer cette licence ?')) {
        updateLicenseStatus(licenseId, 'active');
    }
}

function updateLicenseStatus(licenseId, status) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="license_id" value="${licenseId}">
        <input type="hidden" name="status" value="${status}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Fermer les modals en cliquant à l'extérieur
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Styles pour les lignes spéciales
const style = document.createElement('style');
style.textContent = `
    .expired-row { background-color: rgba(244, 63, 94, 0.05); }
    .warning-row { background-color: rgba(245, 158, 11, 0.05); }
`;
document.head.appendChild(style);
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
