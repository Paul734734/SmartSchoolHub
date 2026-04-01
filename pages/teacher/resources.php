<?php
/**
 * SmartSchool Hub — Gestion des Ressources Pédagogiques (Enseignant)
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('teacher');

$teacherId = Auth::id();
$yearId = getCurrentYear()['id'] ?? 0;
$errors = [];
$success = '';

// ── Traitement des actions ───────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        switch ($action) {
            case 'upload_resource':
                $title = sanitize($_POST['title'] ?? '');
                $description = sanitize($_POST['description'] ?? '');
                $type = sanitize($_POST['type'] ?? 'document');
                $subjectId = (int)($_POST['subject_id'] ?? 0);
                $classId = (int)($_POST['class_id'] ?? 0);
                $isPublic = isset($_POST['is_public']) ? 1 : 0;
                $tags = $_POST['tags'] ?? [];
                
                if (!$title || !$type || !$subjectId) {
                    $errors[] = 'Titre, type et matière sont obligatoires.';
                } else {
                    // Gestion de l'upload de fichier
                    $filePath = null;
                    $fileName = null;
                    $fileSize = null;
                    $mimeType = null;
                    
                    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                        $uploadResult = handleFileUpload($_FILES['file'], 'resources');
                        if ($uploadResult['success']) {
                            $filePath = $uploadResult['file_path'];
                            $fileName = $uploadResult['file_name'];
                            $fileSize = $uploadResult['file_size'];
                            $mimeType = $uploadResult['mime_type'];
                        } else {
                            $errors[] = $uploadResult['error'];
                        }
                    }
                    
                    if (empty($errors)) {
                        try {
                            $resourceId = Database::insert(
                                'INSERT INTO resources (title, description, type, file_path, file_name, file_size, file_mime_type, subject_id, class_id, teacher_id, is_public, tags)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                [$title, $description, $type, $filePath, $fileName, $fileSize, $mimeType, $subjectId, $classId ?: null, $teacherId, $isPublic, json_encode($tags)]
                            );
                            
                            // Notifier les élèves de la classe
                            if ($classId) {
                                NotificationManager::sendToClass($classId, 'resource', '📚 Nouvelle ressource', $title, [
                                    'link' => BASE_URL . '/pages/student/resources.php',
                                    'icon' => '📚'
                                ]);
                            }
                            
                            setFlash('success', 'Ressource ajoutée avec succès.');
                            header('Location: ' . BASE_URL . '/pages/teacher/resources.php');
                            exit;
                        } catch (Exception $e) {
                            $errors[] = 'Erreur lors de l\'ajout: ' . $e->getMessage();
                        }
                    }
                }
                break;
                
            case 'delete_resource':
                $resourceId = (int)($_POST['resource_id'] ?? 0);
                if ($resourceId) {
                    $resource = Database::fetchOne(
                        'SELECT file_path FROM resources WHERE id = ? AND teacher_id = ?',
                        [$resourceId, $teacherId]
                    );
                    
                    if ($resource) {
                        // Supprimer le fichier physique
                        if ($resource['file_path'] && file_exists(BASE_PATH . '/' . $resource['file_path'])) {
                            unlink(BASE_PATH . '/' . $resource['file_path']);
                        }
                        
                        // Supprimer de la base
                        Database::execute('DELETE FROM resources WHERE id = ?', [$resourceId]);
                        setFlash('success', 'Ressource supprimée avec succès.');
                    }
                }
                break;
                
            case 'share_resource':
                $resourceId = (int)($_POST['resource_id'] ?? 0);
                $shareType = sanitize($_POST['share_type'] ?? 'class');
                $shareWith = (int)($_POST['share_with'] ?? 0);
                $permissions = $_POST['permissions'] ?? ['view'];
                
                if ($resourceId && $shareType) {
                    Database::execute(
                        'INSERT INTO resource_shares (resource_id, shared_by, share_type, shared_with, permissions)
                         VALUES (?, ?, ?, ?, ?)',
                        [$resourceId, $teacherId, $shareType, $shareWith ?: null, implode(',', $permissions)]
                    );
                    setFlash('success', 'Ressource partagée avec succès.');
                }
                break;
        }
    }
}

// ── Récupération des données ─────────────────────────────────────
$mySubjects = Database::fetchAll(
    'SELECT DISTINCT s.id, s.name, s.icon, s.color
     FROM class_subjects cs
     JOIN subjects s ON s.id = cs.subject_id
     WHERE cs.teacher_id = ? AND cs.class_id IN (
         SELECT id FROM classes WHERE academic_year_id = ?
     ) ORDER BY s.name',
    [$teacherId, $yearId]
);

$myClasses = Database::fetchAll(
    'SELECT DISTINCT c.id, c.name, c.level, c.section
     FROM class_subjects cs
     JOIN classes c ON c.id = cs.class_id
     WHERE cs.teacher_id = ? AND c.academic_year_id = ?
     ORDER BY c.level, c.section',
    [$teacherId, $yearId]
);

// Filtres
$subjectFilter = (int)($_GET['subject'] ?? 0);
$classFilter = (int)($_GET['class'] ?? 0);
$typeFilter = sanitize($_GET['type'] ?? '');

$whereConditions = ['r.teacher_id = ?'];
$params = [$teacherId];

if ($subjectFilter) {
    $whereConditions[] = 'r.subject_id = ?';
    $params[] = $subjectFilter;
}

if ($classFilter) {
    $whereConditions[] = 'r.class_id = ?';
    $params[] = $classFilter;
}

if ($typeFilter) {
    $whereConditions[] = 'r.type = ?';
    $params[] = $typeFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Récupérer les ressources
$resources = Database::fetchAll(
    "SELECT r.*, s.name as subject_name, s.icon as subject_icon, s.color,
            c.name as class_name,
            CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
            COUNT(DISTINCT ra.id) as access_count,
            COUNT(DISTINCT rb.id) as bookmark_count
     FROM resources r
     LEFT JOIN subjects s ON s.id = r.subject_id
     LEFT JOIN classes c ON c.id = r.class_id
     LEFT JOIN users u ON u.id = r.teacher_id
     LEFT JOIN resource_access ra ON ra.resource_id = r.id
     LEFT JOIN resource_bookmarks rb ON rb.resource_id = r.id
     {$whereClause}
     GROUP BY r.id
     ORDER BY r.created_at DESC",
    $params
);

// Statistiques
$stats = [
    'total_resources' => count($resources),
    'total_views' => array_sum(array_column($resources, 'view_count')),
    'total_downloads' => array_sum(array_column($resources, 'download_count')),
    'by_type' => []
];

foreach ($resources as $resource) {
    $type = $resource['type'];
    $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
}

$pageTitle = 'Ressources Pédagogiques';
$pageIcon = '📚';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- ── Header avec actions ───────────────────────────────────── -->
<div class="page-header">
    <div>
        <h1><?= $pageTitle ?></h1>
        <p>Gérez et partagez vos ressources pédagogiques</p>
    </div>
    <div>
        <button onclick="openModal('uploadModal')" class="btn btn-primary">
            ⬆️ Ajouter une ressource
        </button>
    </div>
</div>

<!-- ── Filtres et statistiques ───────────────────────────────── -->
<div class="card mb-20">
    <form method="GET" class="filter-form">
        <div class="filter-grid">
            <div class="form-group">
                <label>Matière</label>
                <select name="subject" onchange="this.form.submit()">
                    <option value="">Toutes les matières</option>
                    <?php foreach ($mySubjects as $subject): ?>
                    <option value="<?= $subject['id'] ?>" <?= $subjectFilter == $subject['id'] ? 'selected' : '' ?>>
                        <?= $subject['icon'] ?> <?= $subject['name'] ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Classe</label>
                <select name="class" onchange="this.form.submit()">
                    <option value="">Toutes les classes</option>
                    <?php foreach ($myClasses as $class): ?>
                    <option value="<?= $class['id'] ?>" <?= $classFilter == $class['id'] ? 'selected' : '' ?>>
                        <?= $class['name'] ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Type</label>
                <select name="type" onchange="this.form.submit()">
                    <option value="">Tous les types</option>
                    <option value="document" <?= $typeFilter === 'document' ? 'selected' : '' ?>>📄 Document</option>
                    <option value="video" <?= $typeFilter === 'video' ? 'selected' : '' ?>>🎥 Vidéo</option>
                    <option value="audio" <?= $typeFilter === 'audio' ? 'selected' : '' ?>>🎵 Audio</option>
                    <option value="image" <?= $typeFilter === 'image' ? 'selected' : '' ?>>🖼️ Image</option>
                    <option value="link" <?= $typeFilter === 'link' ? 'selected' : '' ?>>🔗 Lien</option>
                    <option value="assignment" <?= $typeFilter === 'assignment' ? 'selected' : '' ?>>📝 Devoir</option>
                    <option value="quiz" <?= $typeFilter === 'quiz' ? 'selected' : '' ?>>🧪 Quiz</option>
                </select>
            </div>
        </div>
    </form>
    
    <!-- Statistiques -->
    <div class="stats-row">
        <div class="stat-item">
            <span class="stat-label">Total ressources</span>
            <span class="stat-value"><?= $stats['total_resources'] ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Vues totales</span>
            <span class="stat-value"><?= number_format($stats['total_views']) ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Téléchargements</span>
            <span class="stat-value"><?= number_format($stats['total_downloads']) ?></span>
        </div>
    </div>
</div>

<!-- ── Grille des ressources ───────────────────────────────────── -->
<div class="resources-grid">
    <?php foreach ($resources as $resource):
        $typeIcon = match($resource['type']) {
            'document' => '📄',
            'video' => '🎥',
            'audio' => '🎵',
            'image' => '🖼️',
            'link' => '🔗',
            'assignment' => '📝',
            'quiz' => '🧪',
            default => '📁'
        };
        
        $fileSizeFormatted = $resource['file_size'] ? formatBytes($resource['file_size']) : '—';
    ?>
    <div class="resource-card">
        <div class="resource-header">
            <div class="resource-type"><?= $typeIcon ?></div>
            <div class="resource-actions">
                <button onclick="shareResource(<?= $resource['id'] ?>)" class="btn btn-xs btn-secondary" title="Partager">
                    📤
                </button>
                <button onclick="editResource(<?= $resource['id'] ?>)" class="btn btn-xs btn-amber" title="Modifier">
                    ✏️
                </button>
                <button onclick="deleteResource(<?= $resource['id'] ?>)" class="btn btn-xs btn-red" title="Supprimer">
                    🗑️
                </button>
            </div>
        </div>
        
        <div class="resource-content">
            <h3 class="resource-title"><?= e($resource['title']) ?></h3>
            <p class="resource-description"><?= e(substr(strip_tags($resource['description'] ?? ''), 0, 100)) ?></p>
            
            <div class="resource-meta">
                <div class="meta-item">
                    <?= $resource['subject_icon'] ?> <?= $resource['subject_name'] ?>
                </div>
                <?php if ($resource['class_name']): ?>
                <div class="meta-item">
                    🎒 <?= $resource['class_name'] ?>
                </div>
                <?php endif; ?>
                <div class="meta-item">
                    📊 <?= $resource['view_count'] ?> vues
                </div>
                <div class="meta-item">
                    ⬇️ <?= $resource['download_count'] ?> téléchargements
                </div>
                <?php if ($resource['file_size']): ?>
                <div class="meta-item">
                    💾 <?= $fileSizeFormatted ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($resource['tags']): ?>
            <div class="resource-tags">
                <?php $tags = json_decode($resource['tags'], true) ?? []; ?>
                <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                <span class="tag"><?= e($tag) ?></span>
                <?php endforeach; ?>
                <?php if (count($tags) > 3): ?>
                <span class="tag">+<?= count($tags) - 3 ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="resource-footer">
            <button onclick="viewResource(<?= $resource['id'] ?>)" class="btn btn-sm btn-primary">
                👁️ Voir
            </button>
            <?php if ($resource['file_path']): ?>
            <button onclick="downloadResource(<?= $resource['id'] ?>)" class="btn btn-sm btn-secondary">
                ⬇️ Télécharger
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($resources)): ?>
    <div class="empty-state" style="grid-column: 1 / -1;">
        <div class="empty-state-icon">📚</div>
        <h3>Aucune ressource</h3>
        <p>Commencez par ajouter votre première ressource pédagogique.</p>
        <button onclick="openModal('uploadModal')" class="btn btn-primary">
            ⬆️ Ajouter une ressource
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- ── Modal Upload Ressource ───────────────────────────────────── -->
<div id="uploadModal" class="modal">
    <div class="modal-content" style="max-width:700px;">
        <div class="modal-header">
            <h3>⬆️ Ajouter une ressource</h3>
            <button class="modal-close" onclick="closeModal('uploadModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="upload_resource">
            
            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Titre *</label>
                    <input type="text" name="title" required>
                </div>
                <div class="form-group full-width">
                    <label>Description</label>
                    <textarea name="description" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>Type *</label>
                    <select name="type" required onchange="toggleFileInput()">
                        <option value="document">📄 Document</option>
                        <option value="video">🎥 Vidéo</option>
                        <option value="audio">🎵 Audio</option>
                        <option value="image">🖼️ Image</option>
                        <option value="link">🔗 Lien web</option>
                        <option value="assignment">📝 Devoir</option>
                        <option value="quiz">🧪 Quiz</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Matière *</label>
                    <select name="subject_id" required>
                        <option value="">-- Choisir --</option>
                        <?php foreach ($mySubjects as $subject): ?>
                        <option value="<?= $subject['id'] ?>"><?= $subject['icon'] ?> <?= $subject['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Classe</label>
                    <select name="class_id">
                        <option value="">-- Toutes mes classes --</option>
                        <?php foreach ($myClasses as $class): ?>
                        <option value="<?= $class['id'] ?>"><?= $class['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="fileInputGroup">
                    <label>Fichier</label>
                    <input type="file" name="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.mp4,.mp3">
                </div>
                <div class="form-group" id="urlGroup" style="display:none;">
                    <label>URL</label>
                    <input type="url" name="url" placeholder="https://...">
                </div>
                <div class="form-group full-width">
                    <label>Tags (séparés par des virgules)</label>
                    <input type="text" name="tags" placeholder="ex: mathématiques, exercices, révision">
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_public">
                        Rendre publique
                    </label>
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
                <button type="button" class="btn btn-secondary" onclick="closeModal('uploadModal')">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    Ajouter la ressource
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.resources-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
}

.resource-card {
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}

.resource-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.resource-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
}

.resource-type {
    font-size: 24px;
}

.resource-actions {
    display: flex;
    gap: 5px;
    opacity: 0;
    transition: opacity 0.2s;
}

.resource-card:hover .resource-actions {
    opacity: 1;
}

.resource-content {
    padding: 20px;
}

.resource-title {
    margin: 0 0 10px 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--navy);
}

.resource-description {
    color: var(--muted);
    margin: 0 0 15px 0;
    line-height: 1.5;
}

.resource-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 15px;
}

.meta-item {
    font-size: 12px;
    color: var(--muted);
    background: #f0f0f0;
    padding: 4px 8px;
    border-radius: 4px;
}

.resource-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-bottom: 15px;
}

.tag {
    font-size: 11px;
    background: var(--primary);
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
}

.resource-footer {
    display: flex;
    gap: 10px;
    padding: 15px 20px;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stats-row {
    display: flex;
    gap: 30px;
    padding-top: 20px;
    border-top: 1px solid #e0e0e0;
}

.stat-item {
    text-align: center;
}

.stat-label {
    display: block;
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 5px;
}

.stat-value {
    font-size: 24px;
    font-weight: 600;
    color: var(--primary);
}
</style>

<script>
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function toggleFileInput() {
    const type = document.querySelector('select[name="type"]').value;
    const fileGroup = document.getElementById('fileInputGroup');
    const urlGroup = document.getElementById('urlGroup');
    
    if (type === 'link') {
        fileGroup.style.display = 'none';
        urlGroup.style.display = 'block';
    } else {
        fileGroup.style.display = 'block';
        urlGroup.style.display = 'none';
    }
}

function viewResource(resourceId) {
    window.open('<?= BASE_URL ?>/pages/common/view_resource.php?id=' + resourceId, '_blank');
}

function downloadResource(resourceId) {
    window.location.href = '<?= BASE_URL ?>/api/download_resource.php?id=' + resourceId;
}

function editResource(resourceId) {
    // Implémenter la modal d'édition
    alert('Fonction d\'édition à implémenter');
}

function deleteResource(resourceId) {
    if (confirm('Êtes-vous sûr de vouloir supprimer cette ressource ?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="delete_resource">
            <input type="hidden" name="resource_id" value="${resourceId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function shareResource(resourceId) {
    // Implémenter la modal de partage
    alert('Fonction de partage à implémenter');
}

// Fermer les modals en cliquant à l'extérieur
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
