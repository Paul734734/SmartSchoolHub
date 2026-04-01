<?php
/**
 * SmartSchool Hub — Appel / Absences (Enseignant)
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('teacher');

$teacherId = Auth::id();
$yearId    = getCurrentYear()['id'] ?? 0;

// ── Traitement AJAX (sauvegarde appel) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Token CSRF invalide.']);
        exit;
    }

    $csId       = (int)($_POST['cs_id'] ?? 0);
    $date       = sanitize($_POST['date'] ?? date('Y-m-d'));
    $period     = sanitize($_POST['period'] ?? '');
    $attendance = $_POST['attendance'] ?? [];

    // Vérifier appartenance
    $owns = Database::scalar(
      'SELECT COUNT(*) FROM class_subjects WHERE id = ? AND teacher_id = ?',
      [$csId, $teacherId]
    );

    if (!$owns) {
        echo json_encode(['success' => false, 'message' => 'Accès non autorisé.']);
        exit;
    }

    try {
        Database::beginTransaction();
        $absentParents = [];

        foreach ($attendance as $studentId => $status) {
            $studentId = (int)$studentId;
            $status    = in_array($status, ['present','absent','late','excused']) ? $status : 'present';

            Database::execute(
              'INSERT INTO attendance (class_subject_id, student_id, date, period, status, recorded_by)
               VALUES (?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE
                 status = VALUES(status),
                 recorded_by = VALUES(recorded_by)',
              [$csId, $studentId, $date, $period, $status, $teacherId]
            );

            // Collecter les parents à notifier pour les absents
            if (in_array($status, ['absent', 'late'])) {
                $parents = Database::fetchAll(
                  'SELECT u.phone, u.id AS parent_id,
                          stu_u.first_name, stu_u.last_name,
                          sub.name AS subject_name
                   FROM student_parents sp
                   JOIN users u ON u.id = sp.parent_id
                   JOIN students s ON s.id = sp.student_id
                   JOIN users stu_u ON stu_u.id = s.user_id
                   JOIN class_subjects cs ON cs.class_id = s.class_id
                   JOIN subjects sub ON sub.id = cs.subject_id
                   WHERE sp.student_id = ? AND cs.id = ? AND sp.is_primary = 1',
                  [$studentId, $csId]
                );
                foreach ($parents as $p) {
                    $absentParents[] = $p;

                    // Notification in-app au parent
                    createNotification(
                        $p['parent_id'],
                        'absence',
                        'Absence signalée — ' . $p['subject_name'],
                        $p['first_name'] . ' ' . $p['last_name'] . ' a été marqué(e) ' .
                        ($status === 'late' ? 'en retard' : 'absent(e)') .
                        ' le ' . formatDate($date) . ' en ' . $p['subject_name'] . '.',
                        BASE_URL . '/modules/common/pages/coming-soon.php?module=Suivi%20absence%20parent'
                    );

                    // Marquer notifié
                    Database::execute(
                      'UPDATE attendance SET notified_parent = 1, notified_at = NOW()
                       WHERE class_subject_id = ? AND student_id = ? AND date = ? AND period = ?',
                      [$csId, $studentId, $date, $period]
                    );
                }
            }
        }

        Database::commit();

        // SMS (si activé)
        $smsSent = 0;
        if (SMS_ENABLED && !empty($absentParents)) {
            foreach ($absentParents as $p) {
                if ($p['phone']) {
                    $smsSent += sendSms(
                        $p['phone'],
                        "SmartSchool: " . $p['first_name'] . " " . $p['last_name'] .
                        " a été absent(e) le " . $date . " en " . $p['subject_name'] . "."
                    );
                }
            }
        }

        Auth::logActivity($teacherId, 'save_attendance', 'class_subject', $csId,
            count($attendance) . ' élèves traités, ' . count($absentParents) . ' absents');

        echo json_encode([
            'success'    => true,
            'message'    => 'Appel enregistré. ' . count($absentParents) . ' parent(s) notifié(s).' .
                            ($smsSent > 0 ? " $smsSent SMS envoyé(s)." : ''),
            'absent_count' => count(array_filter($attendance, fn($s) => $s === 'absent')),
            'present_count'=> count(array_filter($attendance, fn($s) => $s === 'present')),
        ]);

    } catch (Exception $e) {
        Database::rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la sauvegarde.']);
    }
    exit;
}

// ── Envoi SMS (stub) ──────────────────────────────────────
function sendSms(string $phone, string $message): int {
    // Intégrer votre fournisseur SMS ici
    // Exemple : Orange SMS API, Informatique-Pro, etc.
    if (!SMS_ENABLED || !SMS_API_KEY) return 0;
    try {
        $ch = curl_init(SMS_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['to' => $phone, 'message' => $message, 'sender' => SMS_SENDER]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . SMS_API_KEY],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = curl_exec($ch); curl_close($ch);
        $data = json_decode($res, true);
        return ($data['status'] ?? '') === 'sent' ? 1 : 0;
    } catch (Exception $e) { return 0; }
}

// ── Paramètres ────────────────────────────────────────────
$selectedCsId = (int)($_GET['cs_id'] ?? 0);
$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Classes de l'enseignant
$classSubjects = Database::fetchAll(
  'SELECT cs.id, c.name AS class_name, sub.name AS subject_name,
          sub.icon, c.id AS class_id
   FROM class_subjects cs
   JOIN classes c ON c.id = cs.class_id
   JOIN subjects sub ON sub.id = cs.subject_id
   WHERE cs.teacher_id = ? AND c.academic_year_id = ?
   ORDER BY c.level, c.section',
  [$teacherId, $yearId]
);

// Période (créneau horaire du jour)
$periods = Database::fetchAll(
  'SELECT DISTINCT CONCAT(TIME_FORMAT(start_time, "%Hh%i"), " – ", TIME_FORMAT(end_time, "%Hh%i")) AS period_label,
          CONCAT(start_time, "|", end_time) AS period_value
   FROM timetable
   WHERE class_subject_id = ? AND day_of_week = ?
   ORDER BY start_time',
  [$selectedCsId, (int)date('N', strtotime($selectedDate))]
);
if (empty($periods)) {
    $periods = [['period_label' => '08h00 – 10h00', 'period_value' => '08:00:00|10:00:00']];
}
$selectedPeriod = $_GET['period'] ?? ($periods[0]['period_label'] ?? '08h00 – 10h00');

// Élèves + statuts existants
$students       = [];
$existingStatus = [];
$selectedCs     = null;
$monthlyStats   = [];

if ($selectedCsId) {
    $selectedCs = Database::fetchOne(
      'SELECT cs.*, c.name AS class_name, c.id AS class_id,
               sub.name AS subject_name, sub.icon
       FROM class_subjects cs
       JOIN classes c ON c.id = cs.class_id
       JOIN subjects sub ON sub.id = cs.subject_id
       WHERE cs.id = ? AND cs.teacher_id = ?',
      [$selectedCsId, $teacherId]
    );

    if ($selectedCs) {
        $students = Database::fetchAll(
          'SELECT s.id, s.matricule, u.first_name, u.last_name
           FROM students s
           JOIN users u ON u.id = s.user_id
           WHERE s.class_id = ? AND s.status = "enrolled"
           ORDER BY u.last_name, u.first_name',
          [$selectedCs['class_id']]
        );

        // Statuts existants pour cette date/période
        $existingRows = Database::fetchAll(
          'SELECT student_id, status FROM attendance
           WHERE class_subject_id = ? AND date = ? AND period = ?',
          [$selectedCsId, $selectedDate, $selectedPeriod]
        );
        foreach ($existingRows as $r) {
            $existingStatus[$r['student_id']] = $r['status'];
        }

        // Stats absences du mois par élève
        $monthlyStats = Database::fetchAll(
          'SELECT student_id, COUNT(*) AS count
           FROM attendance
           WHERE class_subject_id = ?
             AND YEAR(date) = YEAR(CURDATE()) AND MONTH(date) = MONTH(CURDATE())
             AND status IN ("absent","late")
           GROUP BY student_id',
          [$selectedCsId]
        );
        $monthlyStats = array_column($monthlyStats, 'count', 'student_id');
    }
}

$presentCount = count(array_filter($existingStatus, fn($s) => $s === 'present'));
$absentCount  = count(array_filter($existingStatus, fn($s) => $s === 'absent'));

$pageTitle = 'Faire l\'appel';
$pageIcon  = '🚫';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- ── Filtres ─────────────────────────────────────────── -->
<div class="card mb-20">
  <div style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;">
    <div class="form-group" style="margin-bottom:0;flex:1;min-width:180px;">
      <label class="form-label">Classe / Matière</label>
      <select class="form-select" onchange="updateFilter('cs_id', this.value)">
        <option value="">— Sélectionner —</option>
        <?php foreach ($classSubjects as $cs): ?>
        <option value="<?= $cs['id'] ?>" <?= $cs['id'] == $selectedCsId ? 'selected' : '' ?>>
          <?= $cs['icon'] ?> <?= e($cs['subject_name']) ?> — <?= e($cs['class_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group" style="margin-bottom:0;">
      <label class="form-label">Date</label>
      <input class="form-input" type="date" value="<?= e($selectedDate) ?>"
        max="<?= date('Y-m-d') ?>" onchange="updateFilter('date', this.value)">
    </div>

    <?php if ($selectedCsId && !empty($periods)): ?>
    <div class="form-group" style="margin-bottom:0;">
      <label class="form-label">Créneau horaire</label>
      <select class="form-select" onchange="updateFilter('period', this.value)">
        <?php foreach ($periods as $p): ?>
        <option value="<?= e($p['period_label']) ?>" <?= $p['period_label'] === $selectedPeriod ? 'selected' : '' ?>>
          <?= e($p['period_label']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <?php if ($selectedCsId && !empty($existingStatus)): ?>
    <div style="display:flex;gap:10px;flex-shrink:0;">
      <span class="badge badge-green" style="font-size:13px;padding:8px 14px;">
        ✅ <?= $presentCount ?> présents
      </span>
      <span class="badge badge-red" style="font-size:13px;padding:8px 14px;">
        🚫 <?= $absentCount ?> absents
      </span>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($selectedCs && !empty($students)): ?>
<div class="two-col">

  <!-- Liste d'appel -->
  <div class="card">
    <div class="card-title">
      📋 Appel — <?= e($selectedCs['class_name']) ?>
      · <?= e($selectedCs['subject_name']) ?>
      · <?= formatDate($selectedDate) ?>
    </div>

    <!-- Boutons rapides -->
    <div style="display:flex;gap:8px;margin-bottom:16px;">
      <button class="btn btn-success btn-sm" onclick="setAllStatus('present')">
        ✅ Tous présents
      </button>
      <button class="btn btn-secondary btn-sm" onclick="resetAll()">
        🔄 Réinitialiser
      </button>
    </div>

    <div id="attendance-list">
      <?php foreach ($students as $stu):
        $status   = $existingStatus[$stu['id']] ?? 'present';
        $initials = strtoupper(substr($stu['first_name'],0,1) . substr($stu['last_name'],0,1));
        $grads    = ['linear-gradient(135deg,#3B82F6,#06B6D4)','linear-gradient(135deg,#10B981,#06B6D4)',
                     'linear-gradient(135deg,#8B5CF6,#F43F5E)','linear-gradient(135deg,#F59E0B,#F43F5E)'];
        $grad = $grads[crc32($stu['matricule']) % count($grads)];
        $monthlyAbs = $monthlyStats[$stu['id']] ?? 0;
      ?>
      <div class="att-row" data-student-id="<?= $stu['id'] ?>">
        <div class="avatar" style="background:<?= $grad ?>;width:34px;height:34px;font-size:12px;flex-shrink:0;">
          <?= e($initials) ?>
        </div>
        <div class="att-name">
          <div style="font-weight:600;color:var(--navy);">
            <?= e($stu['last_name'] . ' ' . $stu['first_name']) ?>
          </div>
          <?php if ($monthlyAbs > 0): ?>
          <div style="font-size:11px;color:<?= $monthlyAbs >= 5 ? 'var(--rose)' : 'var(--amber)' ?>;">
            <?= $monthlyAbs ?> abs. ce mois
          </div>
          <?php endif; ?>
        </div>

        <button
          class="att-btn present <?= $status === 'present' ? 'active' : '' ?>"
          onclick="setStatus(this, 'present', <?= $stu['id'] ?>)"
        >✅ Présent</button>

        <button
          class="att-btn late <?= $status === 'late' ? 'active' : '' ?>"
          onclick="setStatus(this, 'late', <?= $stu['id'] ?>)"
          style="border-color:var(--amber);color:var(--amber);"
        >⏰ Retard</button>

        <button
          class="att-btn absent <?= $status === 'absent' ? 'active' : '' ?>"
          onclick="setStatus(this, 'absent', <?= $stu['id'] ?>)"
        >🚫 Absent</button>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Info SMS -->
    <div style="margin-top:20px;padding:14px;background:#EFF6FF;border-radius:12px;font-size:13px;color:var(--blue);font-weight:600;">
      ⚡ Les parents des élèves absents seront
      <?= SMS_ENABLED ? 'notifiés par SMS et' : '' ?>
      notifiés par l'application dès la validation.
    </div>

    <button class="btn btn-primary btn-full btn-lg mt-16" onclick="submitAttendance()">
      ✅ Valider l'appel
    </button>
  </div>

  <!-- Colonne droite -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Résumé du mois -->
    <div class="card">
      <div class="card-title">📊 Absences du mois — <?= e($selectedCs['class_name']) ?></div>
      <?php
      $topAbsent = Database::fetchAll(
        'SELECT a.student_id, COUNT(*) AS count,
                u.first_name, u.last_name
         FROM attendance a
         JOIN students s ON s.id = a.student_id
         JOIN users u ON u.id = s.user_id
         WHERE a.class_subject_id = ?
           AND YEAR(a.date) = YEAR(CURDATE()) AND MONTH(a.date) = MONTH(CURDATE())
           AND a.status IN ("absent","late")
         GROUP BY a.student_id
         ORDER BY count DESC
         LIMIT 5',
        [$selectedCsId]
      );
      ?>
      <?php if (empty($topAbsent)): ?>
        <div class="empty-state" style="padding:20px;">
          <div class="empty-state-icon">🎉</div>
          <p>Aucune absence ce mois !</p>
        </div>
      <?php else: ?>
        <?php foreach ($topAbsent as $ta):
          $pct = min(100, $ta['count'] * 10);
          $color = $ta['count'] >= 8 ? 'var(--rose)' : ($ta['count'] >= 5 ? 'var(--amber)' : 'var(--blue)');
          $initials = strtoupper(substr($ta['first_name'],0,1) . substr($ta['last_name'],0,1));
        ?>
        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);">
          <div class="avatar" style="background:<?= $color ?>;width:32px;height:32px;font-size:11px;opacity:.8;">
            <?= e($initials) ?>
          </div>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:600;color:var(--navy);">
              <?= e($ta['first_name'] . ' ' . $ta['last_name']) ?>
            </div>
            <div class="prog-bar mt-4">
              <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
            </div>
          </div>
          <span class="badge" style="background:<?= $ta['count'] >= 8 ? '#FEE2E2' : '#FEF3C7' ?>;
            color:<?= $color ?>;"><?= $ta['count'] ?> abs.</span>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Alerte IA -->
    <?php
    $riskStudents = [];
    foreach ($students as $stu) {
        $abs = $monthlyStats[$stu['id']] ?? 0;
        if ($abs >= 5) {
            $riskStudents[] = array_merge($stu, ['monthly_abs' => $abs]);
        }
    }
    ?>
    <?php if (!empty($riskStudents)): ?>
    <div class="ai-card">
      <div class="ai-title">🤖 Alerte IA — Décrochage détecté</div>
      <div class="ai-body">
        <?= count($riskStudents) ?> élève(s) présentent un nombre élevé d'absences ce mois.
        L'IA recommande un contact avec les familles.
      </div>
      <div class="ai-tags">
        <?php foreach (array_slice($riskStudents, 0, 3) as $rs): ?>
        <span class="ai-tag">
          <?= e($rs['first_name']) ?> — <?= $rs['monthly_abs'] ?> abs.
        </span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php elseif ($selectedCsId): ?>
<div class="card">
  <div class="empty-state">
    <div class="empty-state-icon">👥</div>
    <h3>Aucun élève dans cette classe</h3>
    <p>Contactez l'administration pour ajouter des élèves.</p>
  </div>
</div>

<?php else: ?>
<div class="card">
  <div class="empty-state">
    <div class="empty-state-icon">👆</div>
    <h3>Sélectionnez une classe</h3>
    <p>Choisissez une classe et une matière dans le filtre ci-dessus pour commencer l'appel.</p>
  </div>
</div>
<?php endif; ?>

<script>
const CS_ID    = <?= $selectedCsId ?: 'null' ?>;
const ATT_DATE = '<?= e($selectedDate) ?>';
const ATT_PERIOD = '<?= e($selectedPeriod) ?>';
const studentStatuses = {};

// ── Initialiser depuis PHP ─────────────────────────────────
<?php foreach ($existingStatus as $sid => $st): ?>
studentStatuses[<?= $sid ?>] = '<?= $st ?>';
<?php endforeach; ?>

function setStatus(btn, status, studentId) {
  const row = btn.closest('.att-row');
  row.querySelectorAll('.att-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  studentStatuses[studentId] = status;
  updateCounter();
}

function setAllStatus(status) {
  document.querySelectorAll('.att-row').forEach(row => {
    const sid = parseInt(row.dataset.studentId);
    row.querySelectorAll('.att-btn').forEach(b => b.classList.remove('active'));
    const btn = row.querySelector(`.att-btn.${status}`);
    if (btn) btn.classList.add('active');
    studentStatuses[sid] = status;
  });
  updateCounter();
}

function resetAll() {
  setAllStatus('present');
}

function updateCounter() {
  const absent  = Object.values(studentStatuses).filter(s => s === 'absent').length;
  const present = Object.values(studentStatuses).filter(s => s === 'present').length;
  // Mise à jour badges topbar
}

function updateFilter(key, value) {
  const url = new URL(window.location.href);
  url.searchParams.set('cs_id', CS_ID || '');
  url.searchParams.set('date', ATT_DATE);
  url.searchParams.set('period', ATT_PERIOD);
  url.searchParams.set(key, value);
  window.location.href = url.toString();
}

async function submitAttendance() {
  if (!CS_ID) { showToast('Sélectionnez une classe.', 'warning'); return; }

  const body = new URLSearchParams({
    csrf_token: document.querySelector('meta[name="csrf-token"]').content,
    cs_id:   CS_ID,
    date:    ATT_DATE,
    period:  ATT_PERIOD,
  });

  for (const [sid, status] of Object.entries(studentStatuses)) {
    body.append(`attendance[${sid}]`, status);
  }

  const btn = document.querySelector('.btn-primary[onclick="submitAttendance()"]');
  btn.textContent = '⏳ Enregistrement…';
  btn.disabled = true;

  try {
    const res  = await fetch(window.location.pathname, { method: 'POST', body });
    const json = await res.json();
    showToast(json.message, json.success ? 'success' : 'error');
    if (json.success) {
      setTimeout(() => window.location.reload(), 1500);
    }
  } catch {
    showToast('Erreur réseau.', 'error');
  } finally {
    btn.textContent = '✅ Valider l\'appel';
    btn.disabled = false;
  }
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
