<?php
/**
 * SmartSchool Hub — Gestion des Établissements (Super-Admin)
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
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$editId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        switch ($action) {
            case 'add_school':
                $name = sanitize($_POST['name'] ?? '');
                $shortName = sanitize($_POST['short_name'] ?? '');
                $address = sanitize($_POST['address'] ?? '');
                $city = sanitize($_POST['city'] ?? '');
                $country = sanitize($_POST['country'] ?? 'Cameroun');
                $phone = sanitize($_POST['phone'] ?? '');
                $email = sanitize($_POST['email'] ?? '');
                $website = sanitize($_POST['website'] ?? '');
                $currency = sanitize($_POST['currency'] ?? 'FCFA');
                $timezone = sanitize($_POST['timezone'] ?? 'Africa/Douala');

                if (!$name || !$shortName || !$city) {
                    $errors[] = 'Nom, nom court et ville sont obligatoires.';
                }

                if (empty($errors)) {
                    try {
                        Database::beginTransaction();
                        
                        $schoolId = Database::insert(
                            'INSERT INTO school (name, short_name, address, city, country, phone, email, website, currency, timezone)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                            [$name, $shortName, $address, $city, $country, $phone, $email, $website, $currency, $timezone]
                        );

                        // Créer licence automatique (plan free par défaut)
                        Database::insert(
                            'INSERT INTO licenses (school_id, plan, status, max_students, max_teachers, created_by)
                             VALUES (?, "free", "active", 100, 20, ?)',
                            [$schoolId, Auth::id()]
                        );

                        Database::commit();
                        setFlash('success', 'Établissement créé avec succès.');
                        header('Location: ' . BASE_URL . '/modules/superadmin/pages/schools.php');
                        exit;
                    } catch (Exception $e) {
                        Database::rollback();
                        $errors[] = 'Erreur lors de la création: ' . $e->getMessage();
                    }
                }
                break;

            case 'edit_school':
                $schoolId = (int)($_POST['school_id'] ?? 0);
                if (!$schoolId) {
                    $errors[] = 'ID établissement invalide.';
                } else {
                    $name = sanitize($_POST['name'] ?? '');
                    $shortName = sanitize($_POST['short_name'] ?? '');
                    $address = sanitize($_POST['address'] ?? '');
                    $city = sanitize($_POST['city'] ?? '');
                    $country = sanitize($_POST['country'] ?? 'Cameroun');
                    $phone = sanitize($_POST['phone'] ?? '');
                    $email = sanitize($_POST['email'] ?? '');
                    $website = sanitize($_POST['website'] ?? '');
                    $currency = sanitize($_POST['currency'] ?? 'FCFA');
                    $timezone = sanitize($_POST['timezone'] ?? 'Africa/Douala');

                    if (!$name || !$shortName || !$city) {
                        $errors[] = 'Nom, nom court et ville sont obligatoires.';
                    }

                    if (empty($errors)) {
                        Database::execute(
                            'UPDATE school SET name=?, short_name=?, address=?, city=?, country=?, phone=?, email=?, website=?, currency=?, timezone=?
                             WHERE id=?',
                            [$name, $shortName, $address, $city, $country, $phone, $email, $website, $currency, $timezone, $schoolId]
                        );
                        setFlash('success', 'Établissement mis à jour.');
                        header('Location: ' . BASE_URL . '/modules/superadmin/pages/schools.php');
                        exit;
                    }
                }
                break;

            case 'delete_school':
                $schoolId = (int)($_POST['school_id'] ?? 0);
                if (!$schoolId) {
                    $errors[] = 'ID établissement invalide.';
                } else {
                    // Vérifier qu'il n'y a pas d'utilisateurs actifs
                    $userCount = Database::scalar(
                        'SELECT COUNT(*) FROM users WHERE role != "super_admin"'
                    );
                    
                    if ($userCount > 0) {
                        $errors[] = 'Impossible de supprimer un établissement avec des utilisateurs actifs.';
                    } else {
                        Database::execute('DELETE FROM school WHERE id=?', [$schoolId]);
                        setFlash('success', 'Établissement supprimé.');
                        header('Location: ' . BASE_URL . '/modules/superadmin/pages/schools.php');
                        exit;
                    }
                }
                break;

            case 'toggle_status':
                $schoolId = (int)($_POST['school_id'] ?? 0);
                $status = sanitize($_POST['status'] ?? '');
                
                if ($schoolId && in_array($status, ['active', 'suspended'])) {
                    Database::execute(
                        'UPDATE licenses SET status=? WHERE school_id=?',
                        [$status, $schoolId]
                    );
                    setFlash('success', 'Statut de la licence mis à jour.');
                    header('Location: ' . BASE_URL . '/modules/superadmin/pages/schools.php');
                    exit;
                }
                break;
        }
    }
}

// ── Récupération des données ─────────────────────────────────────
$schools = Database::fetchAll(
    'SELECT s.*, 
            l.plan, l.status as license_status, l.expires_at,
            COUNT(DISTINCT u.id) as user_count,
            COUNT(DISTINCT CASE WHEN u.role = "student" THEN u.id END) as student_count,
            COUNT(DISTINCT CASE WHEN u.role = "teacher" THEN u.id END) as teacher_count,
            COUNT(DISTINCT CASE WHEN u.role = "admin" THEN u.id END) as admin_count
     FROM school s
     LEFT JOIN licenses l ON l.school_id = s.id
     LEFT JOIN users u ON u.role IN ("admin","teacher","student","parent")
     GROUP BY s.id
     ORDER BY s.created_at DESC'
);

// Données pour édition
$editSchool = null;
if ($editId) {
    $editSchool = Database::fetchOne(
        'SELECT s.*, l.plan, l.status as license_status, l.expires_at, l.max_students, l.max_teachers
         FROM school s
         LEFT JOIN licenses l ON l.school_id = s.id
         WHERE s.id = ?',
        [$editId]
    );
}

$pageTitle = 'Gestion des Établissements';
$pageIcon = '🏫';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- ── Header avec actions ───────────────────────────────────── -->
<div class="page-header">
    <div>
        <h1><?= $pageTitle ?></h1>
        <p>Gérez tous les établissements de la plateforme</p>
    </div>
    <div>
        <button onclick="openModal('addSchoolModal')" class="btn btn-primary">
            ➕ Nouvel Établissement
        </button>
    </div>
</div>

<!-- ── Filtres et statistiques ───────────────────────────────── -->
<div class="card mb-20">
    <div class="stat-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <div class="stat-item">
            <div class="stat-label">Total Établissements</div>
            <div class="stat-value"><?= count($schools) ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Licences Actives</div>
            <div class="stat-value"><?= count(array_filter($schools, fn($s) => $s['license_status'] === 'active')) ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Total Utilisateurs</div>
            <div class="stat-value"><?= number_format(array_sum(array_column($schools, 'user_count'))) ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Total Élèves</div>
            <div class="stat-value"><?= number_format(array_sum(array_column($schools, 'student_count'))) ?></div>
        </div>
    </div>
</div>

<!-- ── Tableau des établissements ───────────────────────────────── -->
<div class="card">
    <div class="table-wrap">
        <table id="schools-table">
            <thead>
                <tr>
                    <th>Établissement</th>
                    <th>Licence</th>
                    <th>Utilisateurs</th>
                    <th>Élèves</th>
                    <th>Enseignants</th>
                    <th>Admins</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schools as $school): ?>
                <tr>
                    <td>
                        <div class="user-cell">
                            <div class="avatar" style="background:linear-gradient(135deg,#3B82F6,#06B6D4);">
                                <?= strtoupper(substr($school['name'], 0, 2)) ?>
                            </div>
                            <div>
                                <div class="user-cell-name"><?= e($school['name']) ?></div>
                                <div class="user-cell-sub"><?= e($school['short_name']) ?> • <?= e($school['city']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div>
                            <span class="badge badge-<?= $school['plan'] === 'free' ? 'gray' : ($school['plan'] === 'pro' ? 'blue' : 'purple') ?>">
                                <?= ucfirst($school['plan']) ?>
                            </span>
                            <?php if ($school['expires_at']): ?>
                            <div style="font-size:11px;color:var(--muted);">
                                Expire: <?= date('d/m/Y', strtotime($school['expires_at'])) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span style="font-weight:600;"><?= (int)$school['user_count'] ?></span>
                    </td>
                    <td>
                        <span style="color:var(--blue);"><?= (int)$school['student_count'] ?></span>
                    </td>
                    <td>
                        <span style="color:var(--green);"><?= (int)$school['teacher_count'] ?></span>
                    </td>
                    <td>
                        <span style="color:var(--purple);"><?= (int)$school['admin_count'] ?></span>
                    </td>
                    <td>
                        <?php if ($school['license_status'] === 'active'): ?>
                            <span class="badge badge-green">✓ Actif</span>
                        <?php elseif ($school['license_status'] === 'expired'): ?>
                            <span class="badge badge-red">⚠ Expiré</span>
                        <?php else: ?>
                            <span class="badge badge-amber">⏳ En attente</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:5px;">
                            <a href="<?= BASE_URL ?>/modules/superadmin/pages/school-view.php?id=<?= $school['id'] ?>"
                               class="btn btn-sm btn-secondary" title="Voir">👁️</a>
                            <button onclick="editSchool(<?= $school['id'] ?>)" 
                                    class="btn btn-sm btn-amber" title="Modifier">✏️</button>
                            <?php if ($school['license_status'] === 'active'): ?>
                            <button onclick="toggleLicenseStatus(<?= $school['id'] ?>, 'suspended')" 
                                    class="btn btn-sm btn-red" title="Suspendre">⏸️</button>
                            <?php else: ?>
                            <button onclick="toggleLicenseStatus(<?= $school['id'] ?>, 'active')" 
                                    class="btn btn-sm btn-green" title="Activer">▶️</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($schools)): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state" style="padding:40px;">
                            <div class="empty-state-icon">🏫</div>
                            <h3>Aucun établissement</h3>
                            <p>Commencez par ajouter le premier établissement à la plateforme.</p>
                            <button onclick="openModal('addSchoolModal')" class="btn btn-primary">
                                ➕ Ajouter un établissement
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Modal Ajout Établissement ───────────────────────────────── -->
<div id="addSchoolModal" class="modal">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h3>➕ Ajouter un Établissement</h3>
            <button class="modal-close" onclick="closeModal('addSchoolModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="add_school">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Nom de l'établissement *</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>Nom court *</label>
                    <input type="text" name="short_name" required>
                </div>
                <div class="form-group full-width">
                    <label>Adresse</label>
                    <textarea name="address" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label>Ville *</label>
                    <input type="text" name="city" required>
                </div>
                <div class="form-group">
                    <label>Pays</label>
                    <input type="text" name="country" value="Cameroun">
                </div>
                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="tel" name="phone">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                <div class="form-group">
                    <label>Site web</label>
                    <input type="url" name="website">
                </div>
                <div class="form-group">
                    <label>Devise</label>
                    <select name="currency">
                        <option value="FCFA">FCFA</option>
                        <option value="EUR">EUR</option>
                        <option value="USD">USD</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Fuseau horaire</label>
                    <select name="timezone">
                        <option value="Africa/Douala">Africa/Douala</option>
                        <option value="Africa/Paris">Africa/Paris</option>
                        <option value="Europe/Paris">Europe/Paris</option>
                        <option value="America/New_York">America/New_York</option>
                    </select>
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
                <button type="button" class="btn btn-secondary" onclick="closeModal('addSchoolModal')">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    Créer l'établissement
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Modal Modification Établissement ─────────────────────────── -->
<div id="editSchoolModal" class="modal">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h3>✏️ Modifier l'Établissement</h3>
            <button class="modal-close" onclick="closeModal('editSchoolModal')">&times;</button>
        </div>
        <form method="POST" id="editSchoolForm">
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="edit_school">
            <input type="hidden" name="school_id" id="edit_school_id">
            
            <div class="form-grid">
                <!-- Les champs seront remplis par JavaScript -->
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editSchoolModal')">
                    Annuler
                </button>
                <button type="submit" class="btn btn-amber">
                    Mettre à jour
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

function editSchool(schoolId) {
    // Charger les données de l'établissement
    fetch('<?= BASE_URL ?>/api/schools.php?id=' + schoolId)
        .then(response => response.json())
        .then(data => {
            document.getElementById('edit_school_id').value = data.id;
            // Remplir le formulaire avec les données
            const form = document.getElementById('editSchoolForm');
            // TODO: Remplir les champs
            openModal('editSchoolModal');
        });
}

function toggleLicenseStatus(schoolId, status) {
    if (confirm('Êtes-vous sûr de vouloir ' + (status === 'active' ? 'activer' : 'suspendre') + ' cette licence ?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="school_id" value="${schoolId}">
            <input type="hidden" name="status" value="${status}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Fermer les modals en cliquant à l'extérieur
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
