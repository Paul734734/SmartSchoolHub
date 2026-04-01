<?php
/**
 * SmartSchool Hub — Saisie des notes (Enseignant)
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('teacher');

$teacherId = Auth::id();
$yearId    = getCurrentYear()['id'] ?? 0;
$trimId    = getCurrentTrimester()['id'] ?? 0;

// ── Traitement sauvegarde AJAX ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'save_grades') {
        if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Token invalide.']);
            exit;
        }

        $evalId = (int)($_POST['eval_id'] ?? 0);
        $grades = $_POST['grades'] ?? [];

        // Vérifier que l'enseignant est bien responsable de cette éval
        $isOwner = Database::scalar(
          'SELECT COUNT(*) FROM evaluations e
           JOIN class_subjects cs ON cs.id = e.class_subject_id
           WHERE e.id = ? AND cs.teacher_id = ?',
          [$evalId, $teacherId]
        );

        if (!$isOwner) {
            echo json_encode(['success' => false, 'message' => 'Accès non autorisé.']);
            exit;
        }

        try {
            Database::beginTransaction();
            $saved = 0;
            foreach ($grades as $studentId => $data) {
                $studentId = (int)$studentId;
                $score     = $data['score'] !== '' ? (float)$data['score'] : null;
                $isAbsent  = !empty($data['absent']) ? 1 : 0;
                $comment   = sanitize($data['comment'] ?? '');

                // Valider la note
                if ($score !== null && ($score < 0 || $score > 20)) continue;

                // INSERT ON DUPLICATE UPDATE
                Database::execute(
                  'INSERT INTO grades (evaluation_id, student_id, score, is_absent, comment, entered_by)
                   VALUES (?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE
                     score = VALUES(score),
                     is_absent = VALUES(is_absent),
                     comment = VALUES(comment),
                     entered_by = VALUES(entered_by),
                     updated_at = NOW()',
                  [$evalId, $studentId, $score, $isAbsent, $comment, $teacherId]
                );
                $saved++;
            }
            Database::commit();

            // Recalculer et déclencher les alertes IA si nécessaire
            triggerAiCheck($evalId);

            Auth::logActivity($teacherId, 'save_grades', 'evaluation', $evalId, "$saved notes sauvegardées");
            echo json_encode(['success' => true, 'message' => "$saved notes enregistrées avec succès."]);
        } catch (Exception $e) {
            Database::rollBack();
            echo json_encode(['success' => false, 'message' => 'Erreur lors de la sauvegarde.']);
        }
        exit;
    }

    if ($_POST['action'] === 'create_eval') {
        if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Token invalide.']);
            exit;
        }

        $csId     = (int)($_POST['cs_id'] ?? 0);
        $type     = sanitize($_POST['type'] ?? 'devoir');
        $label    = sanitize($_POST['label'] ?? '');
        $date     = sanitize($_POST['date'] ?? date('Y-m-d'));
        $maxScore = (float)($_POST['max_score'] ?? 20);
        $coeff    = (int)($_POST['coefficient'] ?? 1);

        // Vérifier appartenance
        $owns = Database::scalar(
          'SELECT COUNT(*) FROM class_subjects WHERE id = ? AND teacher_id = ?',
          [$csId, $teacherId]
        );
        if (!$owns || empty($label)) {
            echo json_encode(['success' => false, 'message' => 'Données invalides.']);
            exit;
        }

        $evalId = Database::insert(
          'INSERT INTO evaluations (class_subject_id, trimester_id, type, label, date, max_score, coefficient, created_by)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
          [$csId, $trimId, $type, $label, $date, $maxScore, $coeff, $teacherId]
        );

        echo json_encode(['success' => true, 'eval_id' => $evalId, 'message' => 'Évaluation créée.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Action inconnue.']);
    exit;
}

// ── Déclencher analyse IA ─────────────────────────────────
function triggerAiCheck(int $evalId): void {
    $eval = Database::fetchOne(
      'SELECT e.*, cs.class_id FROM evaluations e JOIN class_subjects cs ON cs.id = e.class_subject_id WHERE e.id = ?',
      [$evalId]
    );
    if (!$eval) return;

    $students = Database::fetchAll(
      'SELECT id FROM students WHERE class_id = ? AND status = "enrolled"',
      [$eval['class_id']]
    );

    foreach ($students as $stu) {
        $riskScore = computeRiskScore($stu['id'], $eval['class_id'], $eval['trimester_id'] ?? 0);
        if ($riskScore >= 40) {
            $risk  = getRiskLevel($riskScore);
            $avg   = computeGeneralAverage($stu['id'], $eval['class_id'], $eval['trimester_id'] ?? 0);
            $absences = getStudentAbsenceCount($stu['id']);

            $msg = sprintf(
                'Score de risque : %d/100. Moyenne : %s/20. Absences : %d. Attention recommandée.',
                $riskScore,
                $avg !== null ? number_format($avg, 1) : '—',
                $absences
            );

            $existingId = Database::scalar(
              'SELECT id FROM ai_alerts
               WHERE student_id = ? AND category = "grades" AND is_resolved = 0
               ORDER BY created_at DESC
               LIMIT 1',
              [$stu['id']]
            );

            if ($existingId) {
                Database::execute(
                  'UPDATE ai_alerts
                   SET level = ?, message = ?, score = ?, created_at = NOW()
                   WHERE id = ?',
                  [$risk['level'], $msg, $riskScore, $existingId]
                );
            } else {
                Database::insert(
                  'INSERT INTO ai_alerts (student_id, level, category, message, score)
                   VALUES (?, ?, "grades", ?, ?)',
                  [$stu['id'], $risk['level'], $msg, $riskScore]
                );
            }
        }
    }
}

// ── Paramètres de filtre ──────────────────────────────────
$selectedCsId   = (int)($_GET['cs_id']   ?? 0);
$selectedEvalId = (int)($_GET['eval_id'] ?? 0);

// Classes / matières de l'enseignant
$classSubjects = Database::fetchAll(
  'SELECT cs.id, c.name AS class_name, sub.name AS subject_name,
          sub.icon, c.level, cs.coefficient
   FROM class_subjects cs
   JOIN classes c ON c.id = cs.class_id
   JOIN subjects sub ON sub.id = cs.subject_id
   WHERE cs.teacher_id = ? AND c.academic_year_id = ?
   ORDER BY c.level, c.section, sub.name',
  [$teacherId, $yearId]
);

// Si cs_id sélectionné : charger les évaluations
$evaluations = [];
$students    = [];
$evalGrades  = [];
$classAvg    = null;
$selectedEval = null;
$selectedCs   = null;

if ($selectedCsId) {
    $selectedCs = Database::fetchOne(
      'SELECT cs.*, c.name AS class_name, c.id AS class_id,
               sub.name AS subject_name, sub.icon, cs.coefficient
       FROM class_subjects cs
       JOIN classes c ON c.id = cs.class_id
       JOIN subjects sub ON sub.id = cs.subject_id
       WHERE cs.id = ? AND cs.teacher_id = ?',
      [$selectedCsId, $teacherId]
    );

    if ($selectedCs) {
        $evaluations = Database::fetchAll(
          'SELECT e.*, COUNT(g.id) AS graded_count,
                  (SELECT COUNT(*) FROM students WHERE class_id = ? AND status = "enrolled") AS total_students
           FROM evaluations e
           LEFT JOIN grades g ON g.evaluation_id = e.id
           WHERE e.class_subject_id = ? AND e.trimester_id = ?
           GROUP BY e.id
           ORDER BY e.date DESC',
          [$selectedCs['class_id'], $selectedCsId, $trimId]
        );

        // Élèves de la classe
        $students = Database::fetchAll(
          'SELECT s.id, u.first_name, u.last_name, s.matricule
           FROM students s
           JOIN users u ON u.id = s.user_id
           WHERE s.class_id = ? AND s.status = "enrolled"
           ORDER BY u.last_name, u.first_name',
          [$selectedCs['class_id']]
        );

        if ($selectedEvalId) {
            $selectedEval = Database::fetchOne(
              'SELECT * FROM evaluations WHERE id = ? AND class_subject_id = ?',
              [$selectedEvalId, $selectedCsId]
            );

            if ($selectedEval) {
                // Grades existants
                $gradeRows = Database::fetchAll(
                  'SELECT g.student_id, g.score, g.is_absent, g.comment
                   FROM grades g WHERE g.evaluation_id = ?',
                  [$selectedEvalId]
                );
                foreach ($gradeRows as $g) {
                    $evalGrades[$g['student_id']] = $g;
                }

                // Moyenne
                $scores = array_filter(array_column($gradeRows, 'score'), fn($s) => $s !== null);
                $classAvg = count($scores) > 0 ? round(array_sum($scores) / count($scores), 2) : null;
            }
        }
    }
}

$pageTitle = 'Saisie des notes';
$pageIcon  = '📝';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- ── Filtres ─────────────────────────────────────────── -->
<div class="card mb-20">
  <div style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;">

    <div class="form-group" style="margin-bottom:0;flex:1;min-width:200px;">
      <label class="form-label">Classe / Matière</label>
      <select class="form-select" id="cs-select"
        onchange="window.location='?cs_id='+this.value">
        <option value="">— Sélectionner une classe —</option>
        <?php foreach ($classSubjects as $cs): ?>
        <option value="<?= $cs['id'] ?>" <?= $cs['id'] == $selectedCsId ? 'selected' : '' ?>>
          <?= $cs['icon'] ?> <?= e($cs['subject_name']) ?> — <?= e($cs['class_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($selectedCsId && !empty($evaluations)): ?>
    <div class="form-group" style="margin-bottom:0;flex:1;min-width:200px;">
      <label class="form-label">Évaluation</label>
      <select class="form-select"
        onchange="window.location='?cs_id=<?= $selectedCsId ?>&eval_id='+this.value">
        <option value="">— Sélectionner —</option>
        <?php foreach ($evaluations as $ev): ?>
        <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $selectedEvalId ? 'selected' : '' ?>>
          <?= e($ev['label']) ?> · <?= formatDate($ev['date']) ?>
          (<?= $ev['graded_count'] ?>/<?= $ev['total_students'] ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <?php if ($selectedCs): ?>
    <div>
      <button class="btn btn-primary" onclick="openModal('create-eval-modal')">
        ➕ Nouvelle évaluation
      </button>
    </div>
    <?php endif; ?>

    <?php if ($classAvg !== null): ?>
    <div style="text-align:center;padding:12px 20px;background:var(--light);border-radius:12px;flex-shrink:0;">
      <div style="font-family:'Sora',sans-serif;font-size:28px;font-weight:800;color:var(--navy);">
        <?= $classAvg ?> <span style="font-size:16px;color:var(--muted);">/20</span>
      </div>
      <div style="font-size:12px;color:var(--muted);">Moyenne classe</div>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php if ($selectedEval && !empty($students)): ?>
<!-- ── Formulaire de saisie ──────────────────────────── -->
<div class="two-col">

  <div class="card">
    <div class="card-title">
      📝 <?= e($selectedEval['label']) ?> — <?= e($selectedCs['class_name']) ?>
      <span style="margin-left:auto;font-size:12px;color:var(--muted);font-family:'Plus Jakarta Sans',sans-serif;">
        /<?= (int)$selectedEval['max_score'] ?> · Coeff <?= $selectedEval['coefficient'] ?>
      </span>
    </div>

    <form id="grades-form">
      <input type="hidden" name="action"     value="save_grades">
      <input type="hidden" name="eval_id"    value="<?= $selectedEval['id'] ?>">
      <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

      <?php foreach ($students as $stu):
        $g        = $evalGrades[$stu['id']] ?? null;
        $initials = strtoupper(substr($stu['first_name'],0,1) . substr($stu['last_name'],0,1));
        $grads    = ['linear-gradient(135deg,#3B82F6,#06B6D4)','linear-gradient(135deg,#10B981,#06B6D4)',
                     'linear-gradient(135deg,#8B5CF6,#F43F5E)','linear-gradient(135deg,#F59E0B,#F43F5E)'];
        $grad = $grads[crc32($stu['matricule']) % count($grads)];
        $score = $g['score'] ?? '';
        $isAbsent = !empty($g['is_absent']);
      ?>
      <div class="note-row" id="row-<?= $stu['id'] ?>">
        <div class="avatar" style="background:<?= $grad ?>;width:34px;height:34px;font-size:12px;flex-shrink:0;">
          <?= e($initials) ?>
        </div>
        <div style="flex:1;">
          <div style="font-weight:600;font-size:14px;color:var(--navy);">
            <?= e($stu['last_name'] . ' ' . $stu['first_name']) ?>
          </div>
          <div style="font-size:11px;color:var(--muted);">#<?= e($stu['matricule']) ?></div>
        </div>

        <input
          class="note-input"
          type="number"
          name="grades[<?= $stu['id'] ?>][score]"
          value="<?= $isAbsent ? '' : e($score) ?>"
          min="0" max="<?= (int)$selectedEval['max_score'] ?>"
          step="0.25"
          data-max="<?= (int)$selectedEval['max_score'] ?>"
          placeholder="—"
          <?= $isAbsent ? 'disabled' : '' ?>
          id="score-<?= $stu['id'] ?>"
        >
        <span style="font-size:12px;color:var(--muted);">/<?= (int)$selectedEval['max_score'] ?></span>

        <!-- Toggle absent -->
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:var(--rose);font-weight:600;">
          <input
            type="checkbox"
            name="grades[<?= $stu['id'] ?>][absent]"
            value="1"
            <?= $isAbsent ? 'checked' : '' ?>
            onchange="toggleAbsent(this, <?= $stu['id'] ?>)"
            style="accent-color:var(--rose);"
          >
          ABS
        </label>

        <!-- Badge status -->
        <?php if ($isAbsent): ?>
          <span class="badge badge-red">ABS</span>
        <?php elseif ($score !== ''): ?>
          <?php $b = gradeBadge((float)$score, $selectedEval['max_score']); ?>
          <span class="badge <?= $b['class'] ?>" id="badge-<?= $stu['id'] ?>"><?= $b['label'] ?></span>
        <?php else: ?>
          <span class="badge badge-gray" id="badge-<?= $stu['id'] ?>">—</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <div style="margin-top:20px;display:flex;gap:12px;">
        <button type="button" class="btn btn-secondary" style="flex:1;" onclick="clearAll()">
          🔄 Effacer tout
        </button>
        <button type="button" class="btn btn-primary" style="flex:2;" onclick="saveGrades()">
          💾 Enregistrer les notes
        </button>
      </div>
    </form>
  </div>

  <!-- Statistiques live -->
  <div style="display:flex;flex-direction:column;gap:16px;">

    <div class="card">
      <div class="card-title">📊 Statistiques en direct</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;" id="live-stats">
        <div style="text-align:center;padding:16px;background:var(--light);border-radius:12px;">
          <div class="stat-num" id="stat-avg">—</div>
          <div class="stat-label">Moyenne</div>
        </div>
        <div style="text-align:center;padding:16px;background:var(--light);border-radius:12px;">
          <div class="stat-num" id="stat-best" style="color:var(--mint);">—</div>
          <div class="stat-label">Meilleure note</div>
        </div>
        <div style="text-align:center;padding:16px;background:#FEE2E2;border-radius:12px;">
          <div class="stat-num" id="stat-below" style="color:var(--rose);">0</div>
          <div class="stat-label">Sous la moyenne</div>
        </div>
        <div style="text-align:center;padding:16px;background:#FEF3C7;border-radius:12px;">
          <div class="stat-num" id="stat-absent" style="color:var(--amber);">0</div>
          <div class="stat-label">Absents</div>
        </div>
      </div>

      <!-- Graphe distribution -->
      <div style="height:140px;margin-top:16px;">
        <canvas id="distribution-chart"></canvas>
      </div>
    </div>

    <!-- Historique des évaluations -->
    <?php if (!empty($evaluations)): ?>
    <div class="card">
      <div class="card-title">📋 Évaluations de ce trimestre</div>
      <?php foreach ($evaluations as $ev):
        $prog = $ev['total_students'] > 0
          ? round(($ev['graded_count'] / $ev['total_students']) * 100) : 0;
      ?>
      <div style="padding:10px 0;border-bottom:1px solid var(--border);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
          <a href="?cs_id=<?= $selectedCsId ?>&eval_id=<?= $ev['id'] ?>"
             style="font-size:13px;font-weight:600;color:<?= $ev['id'] == $selectedEvalId ? 'var(--blue)' : 'var(--navy)' ?>;">
            <?= e($ev['label']) ?>
          </a>
          <span style="font-size:11px;color:var(--muted);"><?= formatDate($ev['date']) ?></span>
        </div>
        <div class="prog-bar">
          <div class="prog-fill" style="width:<?= $prog ?>%;background:<?= $prog === 100 ? 'var(--mint)' : ($prog > 50 ? 'var(--amber)' : 'var(--rose)') ?>;"></div>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:3px;"><?= $ev['graded_count'] ?>/<?= $ev['total_students'] ?> notés</div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php elseif ($selectedCs): ?>
<!-- Pas d'éval sélectionnée -->
<div class="card">
  <div class="empty-state">
    <div class="empty-state-icon">📝</div>
    <h3>Aucune évaluation sélectionnée</h3>
    <p>Sélectionnez une évaluation ci-dessus ou créez-en une nouvelle.</p>
    <button class="btn btn-primary mt-16" onclick="openModal('create-eval-modal')">
      ➕ Créer une évaluation
    </button>
  </div>
</div>

<?php else: ?>
<!-- Pas de cs sélectionné -->
<div class="card">
  <div class="empty-state">
    <div class="empty-state-icon">👆</div>
    <h3>Sélectionnez une classe</h3>
    <p>Choisissez une classe et une matière dans le filtre ci-dessus pour commencer la saisie.</p>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal : nouvelle évaluation ──────────────────────── -->
<div class="modal-overlay" id="create-eval-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">➕ Nouvelle évaluation</div>
      <button class="modal-close" onclick="closeModal('create-eval-modal')">✕</button>
    </div>

    <div class="form-group">
      <label class="form-label">Type d'évaluation</label>
      <select class="form-select" id="eval-type">
        <option value="devoir">📝 Devoir</option>
        <option value="interrogation">✏️ Interrogation</option>
        <option value="examen_blanc">📄 Examen blanc</option>
        <option value="tp">🔬 Travaux pratiques</option>
        <option value="oral">🎤 Oral</option>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Intitulé</label>
      <input class="form-input" type="text" id="eval-label"
        placeholder="Ex : Devoir N°3 – Géométrie" maxlength="80">
    </div>

    <div class="form-row form-row-3">
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">Date</label>
        <input class="form-input" type="date" id="eval-date" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">Note sur</label>
        <select class="form-select" id="eval-max">
          <option value="20">20</option>
          <option value="10">10</option>
          <option value="5">5</option>
          <option value="100">100</option>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">Coefficient</label>
        <select class="form-select" id="eval-coeff">
          <option value="1">1</option>
          <option value="2">2</option>
          <option value="3">3</option>
          <option value="4">4</option>
        </select>
      </div>
    </div>

    <div style="display:flex;gap:12px;margin-top:24px;">
      <button class="btn btn-secondary" style="flex:1;" onclick="closeModal('create-eval-modal')">Annuler</button>
      <button class="btn btn-primary" style="flex:2;" onclick="createEval()">✅ Créer l'évaluation</button>
    </div>
  </div>
</div>

<script>
const CS_ID   = <?= $selectedCsId ?: 'null' ?>;
const EVAL_ID = <?= $selectedEvalId ?: 'null' ?>;
const MAX     = <?= $selectedEval ? (int)$selectedEval['max_score'] : 20 ?>;

// ── Enregistrer les notes ──────────────────────────────────
async function saveGrades() {
  const form   = document.getElementById('grades-form');
  const data   = new FormData(form);
  const body   = Object.fromEntries(data);

  // Rebuild grades object properly
  const grades = {};
  for (const [key, val] of data.entries()) {
    const m = key.match(/^grades\[(\d+)\]\[(\w+)\]$/);
    if (m) {
      if (!grades[m[1]]) grades[m[1]] = {};
      grades[m[1]][m[2]] = val;
    }
  }
  body.grades = grades;

  const btn = document.querySelector('#grades-form .btn-primary');
  btn.textContent = '⏳ Enregistrement…';
  btn.disabled = true;

  try {
    const res = await fetch(window.location.pathname, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams(data),
    });
    const json = await res.json();
    showToast(json.message, json.success ? 'success' : 'error');
  } catch {
    showToast('Erreur réseau.', 'error');
  } finally {
    btn.textContent = '💾 Enregistrer les notes';
    btn.disabled = false;
  }
}

// ── Activer/désactiver champ si absent ────────────────────
function toggleAbsent(checkbox, studentId) {
  const input = document.getElementById('score-' + studentId);
  const badge = document.getElementById('badge-' + studentId);
  if (checkbox.checked) {
    input.disabled = true;
    input.value = '';
    if (badge) { badge.className = 'badge badge-red'; badge.textContent = 'ABS'; }
  } else {
    input.disabled = false;
    input.focus();
    if (badge) { badge.className = 'badge badge-gray'; badge.textContent = '—'; }
  }
  updateStats();
}

// ── Stats live ────────────────────────────────────────────
function updateStats() {
  const inputs  = document.querySelectorAll('.note-input:not([disabled])');
  const scores  = [];
  inputs.forEach(inp => {
    const v = parseFloat(inp.value);
    if (!isNaN(v) && v >= 0) scores.push(v);
  });

  const absents  = document.querySelectorAll('input[name*="[absent]"]:checked').length;
  const below    = scores.filter(s => s < MAX / 2).length;
  const avg      = scores.length ? (scores.reduce((a,b) => a+b, 0) / scores.length).toFixed(2) : '—';
  const best     = scores.length ? Math.max(...scores).toFixed(2) : '—';

  document.getElementById('stat-avg').textContent    = avg;
  document.getElementById('stat-best').textContent   = best;
  document.getElementById('stat-below').textContent  = below;
  document.getElementById('stat-absent').textContent = absents;
}

// ── Live note inputs ──────────────────────────────────────
document.querySelectorAll('.note-input').forEach(inp => {
  inp.addEventListener('input', () => {
    colorizeNote(inp);
    const sid   = inp.id.replace('score-', '');
    const badge = document.getElementById('badge-' + sid);
    const v     = parseFloat(inp.value);
    if (!isNaN(v) && badge) {
      const b = gradeBadgeJS(v, MAX);
      badge.className = 'badge ' + b.cls;
      badge.textContent = b.label;
    }
    updateStats();
  });
});

// Badge helper côté JS
function gradeBadgeJS(score, max) {
  const pct = (score / max) * 100;
  if (pct >= 80) return {cls:'badge-green',  label:'Excellent'};
  if (pct >= 70) return {cls:'badge-green',  label:'Très bien'};
  if (pct >= 60) return {cls:'badge-blue',   label:'Bien'};
  if (pct >= 50) return {cls:'badge-amber',  label:'Passable'};
  return            {cls:'badge-red',    label:'Insuffisant'};
}

function clearAll() {
  if (!confirm('Effacer toutes les notes saisies ?')) return;
  document.querySelectorAll('.note-input').forEach(inp => {
    inp.value = ''; inp.style.color = '';
  });
  document.querySelectorAll('input[name*="[absent]"]').forEach(cb => {
    cb.checked = false;
    const sid = cb.name.match(/\[(\d+)\]/)[1];
    const input = document.getElementById('score-' + sid);
    if (input) input.disabled = false;
  });
  document.querySelectorAll('[id^="badge-"]').forEach(b => {
    b.className = 'badge badge-gray'; b.textContent = '—';
  });
  updateStats();
}

// ── Créer évaluation ──────────────────────────────────────
async function createEval() {
  const label = document.getElementById('eval-label').value.trim();
  if (!label) { showToast('Veuillez saisir un intitulé.', 'warning'); return; }

  const body = new URLSearchParams({
    action: 'create_eval',
    cs_id:       CS_ID,
    type:        document.getElementById('eval-type').value,
    label:       label,
    date:        document.getElementById('eval-date').value,
    max_score:   document.getElementById('eval-max').value,
    coefficient: document.getElementById('eval-coeff').value,
    csrf_token:  document.querySelector('meta[name="csrf-token"]').content,
  });

  try {
    const res  = await fetch(window.location.pathname, {method:'POST', body});
    const json = await res.json();
    if (json.success) {
      showToast(json.message, 'success');
      setTimeout(() => {
        window.location = `?cs_id=${CS_ID}&eval_id=${json.eval_id}`;
      }, 800);
    } else {
      showToast(json.message, 'error');
    }
  } catch {
    showToast('Erreur réseau.', 'error');
  }
}

// Init stats
updateStats();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
