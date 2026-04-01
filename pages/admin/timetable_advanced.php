<?php
/**
 * SmartSchool Hub — Emploi du Temps Avancé (Admin)
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/timetable_manager.php';

Auth::check('admin');

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
            case 'generate_timetable':
                $classId = (int)($_POST['class_id'] ?? 0);
                
                if (!$classId) {
                    $errors[] = 'Veuillez sélectionner une classe.';
                } else {
                    // Récupérer les matières de la classe
                    $subjects = Database::fetchAll(
                        'SELECT cs.id as class_subject_id, cs.weekly_hours, cs.coefficient,
                                s.id as subject_id, s.name as subject_name,
                                u.id as teacher_id, u.first_name, u.last_name
                         FROM class_subjects cs
                         JOIN subjects s ON s.id = cs.subject_id
                         JOIN users u ON u.id = cs.teacher_id
                         WHERE cs.class_id = ?',
                        [$classId]
                    );
                    
                    if (empty($subjects)) {
                        $errors[] = 'Aucune matière trouvée pour cette classe.';
                    } else {
                        $result = TimetableManager::generateTimetable($classId, $subjects);
                        
                        if ($result['success']) {
                            $success = "Emploi du temps généré avec succès ! {$result['generated_slots']} créneaux créés.";
                        } else {
                            $errors = array_merge($errors, $result['errors']);
                        }
                    }
                }
                break;
                
            case 'delete_slot':
                $slotId = (int)($_POST['slot_id'] ?? 0);
                if ($slotId) {
                    Database::execute('DELETE FROM timetable WHERE id = ?', [$slotId]);
                    $success = 'Créneau supprimé avec succès.';
                }
                break;
                
            case 'add_slot':
                $classSubjectId = (int)($_POST['class_subject_id'] ?? 0);
                $dayOfWeek = (int)($_POST['day_of_week'] ?? 0);
                $startTime = sanitize($_POST['start_time'] ?? '');
                $endTime = sanitize($_POST['end_time'] ?? '');
                $room = sanitize($_POST['room'] ?? '');
                
                if (!$classSubjectId || !$dayOfWeek || !$startTime || !$endTime) {
                    $errors[] = 'Tous les champs sont obligatoires.';
                } else {
                    try {
                        Database::execute(
                            'INSERT INTO timetable (academic_year_id, class_subject_id, day_of_week, start_time, end_time, room)
                             VALUES (?, ?, ?, ?, ?, ?)',
                            [$yearId, $classSubjectId, $dayOfWeek, $startTime, $endTime, $room]
                        );
                        $success = 'Créneau ajouté avec succès.';
                    } catch (Exception $e) {
                        $errors[] = 'Erreur lors de l\'ajout du créneau.';
                    }
                }
                break;
        }
    }
}

// ── Récupération des données ─────────────────────────────────────
$classes = Database::fetchAll(
    'SELECT id, name, level, section FROM classes WHERE academic_year_id = ? ORDER BY level, section',
    [$yearId]
);

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$timetable = [];
$classSubjects = [];

if ($selectedClassId) {
    $timetable = Database::fetchAll(
        'SELECT t.id, t.day_of_week, t.start_time, t.end_time, t.room,
                cs.id as class_subject_id, sub.name as subject_name, sub.icon, sub.color,
                u.first_name, u.last_name
         FROM timetable t
         JOIN class_subjects cs ON cs.id = t.class_subject_id
         JOIN subjects sub ON sub.id = cs.subject_id
         JOIN users u ON u.id = cs.teacher_id
         WHERE cs.class_id = ? AND t.academic_year_id = ?
         ORDER BY t.day_of_week, t.start_time',
        [$selectedClassId, $yearId]
    );
    
    $classSubjects = Database::fetchAll(
        'SELECT cs.id, cs.weekly_hours, sub.name as subject_name, sub.icon,
                u.first_name, u.last_name
         FROM class_subjects cs
         JOIN subjects sub ON sub.id = cs.subject_id
         JOIN users u ON u.id = cs.teacher_id
         WHERE cs.class_id = ?
         ORDER BY sub.name',
        [$selectedClassId]
    );
}

// ── Détection des conflits ───────────────────────────────────────
$conflicts = TimetableManager::detectConflicts($yearId);
$optimizations = TimetableManager::optimizeTimetable($yearId);

$pageTitle = 'Emploi du Temps Avancé';
$pageIcon = '📅';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- ── Header avec actions ───────────────────────────────────── -->
<div class="page-header">
    <div>
        <h1><?= $pageTitle ?></h1>
        <p>Gestion avancée de l'emploi du temps avec détection de conflits</p>
    </div>
    <div style="display:flex;gap:10px;">
        <button onclick="openModal('generateModal')" class="btn btn-primary">
            🎲 Générer Auto
        </button>
        <button onclick="openModal('addSlotModal')" class="btn btn-secondary">
            ➕ Ajouter Créneau
        </button>
        <a href="<?= BASE_URL ?>/pages/admin/timetable_advanced.php?export=<?= $selectedClassId ?>" class="btn btn-purple">
            📥 Exporter iCal
        </a>
    </div>
</div>

<!-- ── Alertes de conflits ─────────────────────────────────────── -->
<?php if (!empty($conflicts)): ?>
<div class="card mb-20" style="border-left:4px solid var(--rose);">
    <div class="card-title">
        🚨 Conflits Détectés (<?= count($conflicts) ?>)
        <button onclick="toggleConflicts()" class="btn btn-sm btn-secondary">Voir tout</button>
    </div>
    <div id="conflictsList" style="display:none;">
        <?php foreach ($conflicts as $conflict): ?>
        <div class="alert alert-<?= $conflict['severity'] === 'high' ? 'danger' : 'warning' ?>" style="margin:10px 0;">
            <strong><?= $conflict['type'] === 'teacher_conflict' ? '👨‍🏫' : ($conflict['type'] === 'room_conflict' ? '🏠' : '🎒') ?></strong>
            <?= $conflict['message'] ?>
            <?php if ($conflict['severity'] === 'high'): ?>
            <div style="margin-top:10px;">
                <a href="#" class="btn btn-sm btn-red">Résoudre</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Sélection de classe ───────────────────────────────────── -->
<div class="card mb-20">
    <div class="form-group">
        <label>Sélectionner une classe</label>
        <select onchange="window.location.href='?class_id='+this.value" class="form-control">
            <option value="">-- Choisir une classe --</option>
            <?php foreach ($classes as $class): ?>
            <option value="<?= $class['id'] ?>" <?= $selectedClassId == $class['id'] ? 'selected' : '' ?>>
                <?= $class['name'] ?> (<?= $class['level'] ?><?= $class['section'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if ($selectedClassId): ?>
<!-- ── Vue hebdomadaire de l'emploi du temps ───────────────────── -->
<div class="card">
    <div class="card-title">
        📅 Emploi du Temps - <?= $classes[array_search($selectedClassId, array_column($classes, 'id'))]['name'] ?>
        <span class="badge badge-blue"><?= count($timetable) ?> créneaux</span>
    </div>
    
    <div class="timetable-grid">
        <div class="timetable-header">
            <div class="time-header">Heure</div>
            <div class="day-header">Lundi</div>
            <div class="day-header">Mardi</div>
            <div class="day-header">Mercredi</div>
            <div class="day-header">Jeudi</div>
            <div class="day-header">Vendredi</div>
        </div>
        
        <?php
        $timeSlots = [
            ['08:00', '09:00'],
            ['09:00', '10:00'],
            ['10:15', '11:15'],
            ['11:15', '12:15'],
            ['13:30', '14:30'],
            ['14:30', '15:30'],
            ['15:45', '16:45']
        ];
        
        foreach ($timeSlots as $slot): ?>
        <div class="timetable-row">
            <div class="time-cell">
                <?= $slot[0] ?> - <?= $slot[1] ?>
            </div>
            <?php for ($day = 1; $day <= 5; $day++): ?>
            <div class="day-cell" data-day="<?= $day ?>" data-start="<?= $slot[0] ?>" data-end="<?= $slot[1] ?>">
                <?php
                $currentSlot = null;
                foreach ($timetable as $entry) {
                    if ($entry['day_of_week'] == $day && 
                        $entry['start_time'] == $slot[0] . ':00' && 
                        $entry['end_time'] == $slot[1] . ':00') {
                        $currentSlot = $entry;
                        break;
                    }
                }
                
                if ($currentSlot):
                ?>
                <div class="slot-item" style="background:<?= $currentSlot['color'] ?>20;border-color:<?= $currentSlot['color'] ?>;">
                    <div class="slot-subject">
                        <?= $currentSlot['icon'] ?> <?= $currentSlot['subject_name'] ?>
                    </div>
                    <div class="slot-teacher">
                        <?= $currentSlot['first_name'] ?> <?= $currentSlot['last_name'][0] ?>.
                    </div>
                    <div class="slot-room">
                        📍 <?= $currentSlot['room'] ?? '—' ?>
                    </div>
                    <div class="slot-actions">
                        <button onclick="deleteSlot(<?= $currentSlot['id'] ?>)" class="btn btn-xs btn-red">🗑️</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── Statistiques de la classe ───────────────────────────────── -->
<div class="two-col mt-20">
    <div class="card">
        <div class="card-title">📊 Statistiques de la classe</div>
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-label">Total créneaux</div>
                <div class="stat-value"><?= count($timetable) ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Heures/semaine</div>
                <div class="stat-value"><?= count($timetable) ?>h</div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Matières</div>
                <div class="stat-value"><?= count($classSubjects) ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Enseignants</div>
                <div class="stat-value"><?= count(array_unique(array_column($timetable, 'first_name'))) ?></div>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-title">📚 Répartition des matières</div>
        <?php
        $subjectDistribution = [];
        foreach ($timetable as $slot) {
            $subject = $slot['subject_name'];
            $subjectDistribution[$subject] = ($subjectDistribution[$subject] ?? 0) + 1;
        }
        arsort($subjectDistribution);
        ?>
        <div style="display:grid;gap:10px;">
            <?php foreach ($subjectDistribution as $subject => $hours): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <span><?= $subject ?></span>
                <span class="badge badge-blue"><?= $hours ?>h</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ── Modal Génération Auto ───────────────────────────────────── -->
<div id="generateModal" class="modal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h3>🎲 Génération Automatique</h3>
            <button class="modal-close" onclick="closeModal('generateModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="generate_timetable">
            
            <div class="form-group">
                <label>Classe</label>
                <select name="class_id" required>
                    <option value="">-- Choisir une classe --</option>
                    <?php foreach ($classes as $class): ?>
                    <option value="<?= $class['id'] ?>"><?= $class['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="alert alert-info">
                🤖 L'algorithme va automatiquement créer un emploi du temps optimisé en évitant les conflits d'enseignants et de salles.
            </div>

            <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?= $error ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('generateModal')">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    Générer l'emploi du temps
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Modal Ajout Créneau ─────────────────────────────────────── -->
<div id="addSlotModal" class="modal">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h3>➕ Ajouter un Créneau</h3>
            <button class="modal-close" onclick="closeModal('addSlotModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="add_slot">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Matière</label>
                    <select name="class_subject_id" required>
                        <option value="">-- Choisir --</option>
                        <?php foreach ($classSubjects as $subject): ?>
                        <option value="<?= $subject['id'] ?>">
                            <?= $subject['icon'] ?> <?= $subject['subject_name'] ?> (<?= $subject['first_name'] ?> <?= $subject['last_name'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Jour</label>
                    <select name="day_of_week" required>
                        <option value="1">Lundi</option>
                        <option value="2">Mardi</option>
                        <option value="3">Mercredi</option>
                        <option value="4">Jeudi</option>
                        <option value="5">Vendredi</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Heure de début</label>
                    <select name="start_time" required>
                        <option value="08:00:00">08:00</option>
                        <option value="09:00:00">09:00</option>
                        <option value="10:15:00">10:15</option>
                        <option value="11:15:00">11:15</option>
                        <option value="13:30:00">13:30</option>
                        <option value="14:30:00">14:30</option>
                        <option value="15:45:00">15:45</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Heure de fin</label>
                    <select name="end_time" required>
                        <option value="09:00:00">09:00</option>
                        <option value="10:00:00">10:00</option>
                        <option value="11:15:00">11:15</option>
                        <option value="12:15:00">12:15</option>
                        <option value="14:30:00">14:30</option>
                        <option value="15:30:00">15:30</option>
                        <option value="16:45:00">16:45</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>Salle</label>
                    <input type="text" name="room" placeholder="ex: Salle A1">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addSlotModal')">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    Ajouter le créneau
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.timetable-grid {
    display: grid;
    grid-template-columns: 120px repeat(5, 1fr);
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    overflow: hidden;
}

.timetable-header {
    display: contents;
}

.timetable-header > div {
    background: #f8f9fa;
    padding: 15px 10px;
    font-weight: 600;
    text-align: center;
    border-right: 1px solid #e0e0e0;
}

.timetable-header > div:last-child {
    border-right: none;
}

.timetable-row {
    display: contents;
}

.timetable-row:hover .day-cell {
    background: #f8f9fa;
}

.time-cell {
    background: #f8f9fa;
    padding: 15px 10px;
    text-align: center;
    font-weight: 500;
    border-right: 1px solid #e0e0e0;
    border-top: 1px solid #e0e0e0;
}

.day-cell {
    padding: 10px;
    border-right: 1px solid #e0e0e0;
    border-top: 1px solid #e0e0e0;
    min-height: 60px;
    position: relative;
}

.day-cell:last-child {
    border-right: none;
}

.slot-item {
    background: white;
    border: 2px solid;
    border-radius: 6px;
    padding: 8px;
    font-size: 12px;
    height: 100%;
    cursor: pointer;
    transition: transform 0.2s;
}

.slot-item:hover {
    transform: scale(1.02);
}

.slot-subject {
    font-weight: 600;
    margin-bottom: 2px;
}

.slot-teacher {
    color: #666;
    margin-bottom: 2px;
}

.slot-room {
    color: #888;
    font-size: 11px;
}

.slot-actions {
    position: absolute;
    top: 5px;
    right: 5px;
    opacity: 0;
    transition: opacity 0.2s;
}

.slot-item:hover .slot-actions {
    opacity: 1;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.stat-item {
    text-align: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.stat-label {
    font-size: 12px;
    color: #666;
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

function toggleConflicts() {
    const list = document.getElementById('conflictsList');
    list.style.display = list.style.display === 'none' ? 'block' : 'none';
}

function deleteSlot(slotId) {
    if (confirm('Êtes-vous sûr de vouloir supprimer ce créneau ?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="delete_slot">
            <input type="hidden" name="slot_id" value="${slotId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Export iCal
<?php if ($selectedClassId && isset($_GET['export'])): ?>
<?php
$icalContent = TimetableManager::exportToICal($selectedClassId, $yearId);
header('Content-Type: text/calendar');
header('Content-Disposition: attachment; filename="timetable_' . $selectedClassId . '.ics"');
echo $icalContent;
exit;
?>
<?php endif; ?>

// Fermer les modals en cliquant à l'extérieur
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
