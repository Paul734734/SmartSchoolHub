<?php
/**
 * SmartSchool Hub — Tableau de bord Parent
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('parent');

$parentId  = Auth::id();
$yearId    = getCurrentYear()['id'] ?? 0;
$trimId    = getCurrentTrimester()['id'] ?? 0;

// ── Enfants du parent ─────────────────────────────────────
$children = Database::fetchAll(
  'SELECT s.id AS student_id, s.matricule,
          u.first_name, u.last_name,
          c.name AS class_name, c.id AS class_id, c.level,
          sp.relationship
   FROM student_parents sp
   JOIN students s ON s.id = sp.student_id
   JOIN users u ON u.id = s.user_id
   JOIN classes c ON c.id = s.class_id
   WHERE sp.parent_id = ? AND s.academic_year_id = ? AND s.status = "enrolled"
   ORDER BY u.last_name',
  [$parentId, $yearId]
);

// Enfant sélectionné (premier par défaut)
$selectedChildId = (int)($_GET['child_id'] ?? ($children[0]['student_id'] ?? 0));
$child = null;
foreach ($children as $c) {
    if ($c['student_id'] == $selectedChildId) { $child = $c; break; }
}
if (!$child && !empty($children)) $child = $children[0];

// ── Notes par matière ─────────────────────────────────────
$subjectGrades = [];
$generalAvg    = null;
$rank          = null;

if ($child) {
    $subjectGrades = Database::fetchAll(
      'SELECT sub.name AS subject_name, sub.icon, cs.coefficient,
              AVG(CASE WHEN g.is_absent = 0 THEN g.score ELSE NULL END) AS avg_score,
              COUNT(g.id) AS eval_count,
              MAX(g.score) AS best_score,
              MIN(CASE WHEN g.is_absent = 0 THEN g.score ELSE NULL END) AS worst_score
       FROM class_subjects cs
       JOIN subjects sub ON sub.id = cs.subject_id
       LEFT JOIN evaluations e ON e.class_subject_id = cs.id AND e.trimester_id = ?
       LEFT JOIN grades g ON g.evaluation_id = e.id AND g.student_id = ?
       WHERE cs.class_id = ?
       GROUP BY cs.id
       ORDER BY sub.name',
      [$trimId, $child['student_id'], $child['class_id']]
    );

    $generalAvg = computeGeneralAverage($child['student_id'], $child['class_id'], $trimId);
    $rank       = getClassRank($child['student_id'], $child['class_id'], $trimId);
}

// ── Absences récentes ─────────────────────────────────────
$recentAbsences = [];
if ($child) {
    $recentAbsences = Database::fetchAll(
      'SELECT a.date, a.period, a.status, a.justification, a.notified_parent,
              sub.name AS subject_name, sub.icon
       FROM attendance a
       JOIN class_subjects cs ON cs.id = a.class_subject_id
       JOIN subjects sub ON sub.id = cs.subject_id
       WHERE a.student_id = ?
       ORDER BY a.date DESC, a.period
       LIMIT 10',
      [$child['student_id']]
    );
}

$absenceCount = $child ? getStudentAbsenceCount($child['student_id']) : 0;

// ── Paiements ─────────────────────────────────────────────
$feeStatus = [];
if ($child) {
    $feeStatus = Database::fetchAll(
      'SELECT fi.id, fi.label, fi.amount, fi.due_date, fi.number,
              p.id AS payment_id, p.amount_paid, p.payment_date, p.method
       FROM fees f
       JOIN fee_installments fi ON fi.fee_id = f.id
       LEFT JOIN payments p ON p.fee_installment_id = fi.id AND p.student_id = ?
       WHERE f.academic_year_id = ? AND f.level = ?
       ORDER BY fi.number',
      [$child['student_id'], $yearId, $child['level']]
    );
}

// ── Notifications récentes ────────────────────────────────
$notifications = Database::fetchAll(
  'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8',
  [$parentId]
);

// ── Emploi du temps de demain ─────────────────────────────
$tomorrow   = date('Y-m-d', strtotime('+1 day'));
$tomorrowN  = (int)date('N', strtotime('+1 day'));
$tomorrowSchedule = [];
if ($child) {
    $tomorrowSchedule = Database::fetchAll(
      'SELECT tt.start_time, tt.end_time, tt.room,
              sub.name AS subject_name, sub.icon, sub.color
       FROM timetable tt
       JOIN class_subjects cs ON cs.id = tt.class_subject_id
       JOIN subjects sub ON sub.id = cs.subject_id
       WHERE cs.class_id = ? AND tt.day_of_week = ? AND tt.academic_year_id = ?
       ORDER BY tt.start_time',
      [$child['class_id'], $tomorrowN, $yearId]
    );
}

$pageTitle = 'Suivi de ' . ($child['first_name'] ?? 'mon enfant');
$pageIcon  = '👨‍👧';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- Sélecteur d'enfant si plusieurs -->
<?php if (count($children) > 1): ?>
<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
  <?php foreach ($children as $ch):
    $isSelected = $ch['student_id'] == $selectedChildId;
    $initials = strtoupper(substr($ch['first_name'],0,1) . substr($ch['last_name'],0,1));
  ?>
  <a href="?child_id=<?= $ch['student_id'] ?>" style="
    display:flex;align-items:center;gap:12px;padding:14px 20px;
    border-radius:14px;border:2px solid <?= $isSelected ? 'var(--blue)' : 'var(--border)' ?>;
    background:<?= $isSelected ? '#EFF6FF' : 'var(--card)' ?>;
    text-decoration:none;transition:all .2s;
  ">
    <div class="avatar" style="background:var(--grad2);"><?= e($initials) ?></div>
    <div>
      <div style="font-weight:700;color:var(--navy);"><?= e($ch['first_name']) ?></div>
      <div style="font-size:12px;color:var(--muted);"><?= e($ch['class_name']) ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($child): ?>

<!-- ── Carte profil élève ─────────────────────────────── -->
<div class="card mb-20" style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
  <div class="avatar avatar-xl" style="background:var(--grad2);">
    <?= strtoupper(substr($child['first_name'],0,1) . substr($child['last_name'],0,1)) ?>
  </div>
  <div style="flex:1;">
    <div style="font-family:'Sora',sans-serif;font-size:22px;font-weight:800;color:var(--navy);">
      <?= e(strtoupper($child['last_name']) . ' ' . $child['first_name']) ?>
    </div>
    <div style="font-size:14px;color:var(--muted);margin-top:4px;">
      <?= e($child['class_name']) ?> · Matricule #<?= e($child['matricule']) ?>
    </div>
  </div>

  <!-- KPI -->
  <?php if ($generalAvg !== null): ?>
  <div style="text-align:center;padding:16px 24px;background:var(--light);border-radius:14px;">
    <div style="font-family:'Sora',sans-serif;font-size:32px;font-weight:800;
      color:<?= gradeColor($generalAvg) ?>;">
      <?= number_format($generalAvg, 1) ?>
    </div>
    <div style="font-size:12px;color:var(--muted);">Moyenne générale</div>
  </div>
  <?php endif; ?>

  <?php if ($rank): ?>
  <div style="text-align:center;padding:16px 24px;background:var(--light);border-radius:14px;">
    <div style="font-family:'Sora',sans-serif;font-size:32px;font-weight:800;color:var(--blue);">
      <?= $rank ?><sup style="font-size:14px;">e</sup>
    </div>
    <div style="font-size:12px;color:var(--muted);">Rang de classe</div>
  </div>
  <?php endif; ?>

  <div style="text-align:center;padding:16px 24px;background:<?= $absenceCount > 5 ? '#FEF3C7' : 'var(--light)' ?>;border-radius:14px;">
    <div style="font-family:'Sora',sans-serif;font-size:32px;font-weight:800;
      color:<?= $absenceCount > 5 ? 'var(--amber)' : 'var(--mint)' ?>;">
      <?= $absenceCount ?>
    </div>
    <div style="font-size:12px;color:var(--muted);">Absences totales</div>
  </div>
</div>

<div class="two-col">

  <!-- Notes par matière -->
  <div class="card">
    <div class="card-title">
      📊 Notes par matière — <?= e(getCurrentTrimester()['label'] ?? 'Trimestre en cours') ?>
      <a href="<?= BASE_URL ?>/modules/parent/pages/grades.php?child_id=<?= (int)$child['student_id'] ?>" class="card-action">Détail →</a>
    </div>

    <?php if (empty($subjectGrades)): ?>
      <div class="empty-state" style="padding:30px;">
        <div class="empty-state-icon">📝</div>
        <p>Pas encore de notes pour ce trimestre.</p>
      </div>
    <?php else: ?>
      <div class="grade-grid">
        <?php foreach ($subjectGrades as $sg):
          $avg = $sg['avg_score'] !== null ? round((float)$sg['avg_score'], 1) : null;
          $cls = 'good';
          if ($avg === null) $cls = '';
          elseif ($avg < 10) $cls = 'low';
          elseif ($avg < 14) $cls = 'avg';
          $badge = $avg !== null ? gradeBadge($avg) : ['class' => 'badge-gray', 'label' => '—'];
        ?>
        <div class="grade-card">
          <div class="grade-icon"><?= $sg['icon'] ?></div>
          <div style="flex:1;min-width:0;">
            <div class="grade-sub"><?= e($sg['subject_name']) ?></div>
            <div class="grade-score <?= $cls ?>">
              <?= $avg !== null ? $avg . '/20' : '—' ?>
            </div>
          </div>
          <span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <button onclick="window.location='<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Bulletin%20parent'"
        class="btn btn-secondary btn-full mt-16">
        📥 Télécharger le bulletin PDF
      </button>
    <?php endif; ?>
  </div>

  <!-- Colonne droite -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Notifications / Timeline -->
    <div class="card">
      <div class="card-title">
        🔔 Dernières notifications
        <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Notifications%20parent" class="card-action">Tout voir →</a>
      </div>
      <?php if (empty($notifications)): ?>
        <div class="empty-state" style="padding:20px;">
          <p>Aucune notification récente.</p>
        </div>
      <?php else: ?>
        <div class="timeline">
          <?php foreach (array_slice($notifications, 0, 5) as $n):
            $icons = [
              'absence' => ['🚫','#FEE2E2'], 'grade' => ['📝','#D1FAE5'],
              'payment' => ['💰','#FEF3C7'], 'announcement' => ['📢','#EDE9FE'],
              'ai_alert'=> ['🤖','#DBEAFE'], 'message' => ['💬','#CFFAFE'],
            ];
            [$icon, $bg] = $icons[$n['type']] ?? ['🔔','#F1F5F9'];
          ?>
          <div class="tl-item">
            <div class="tl-dot" style="background:<?= $bg ?>;"><?= $icon ?></div>
            <div class="tl-time"><?= timeAgo($n['created_at']) ?></div>
            <div class="tl-title" style="<?= !$n['is_read'] ? 'font-weight:700;' : '' ?>">
              <?= e($n['title']) ?>
            </div>
            <div class="tl-body"><?= e(substr($n['body'], 0, 80)) ?>…</div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Absences récentes -->
    <?php if (!empty($recentAbsences)): ?>
    <div class="card">
      <div class="card-title">
        🚫 Absences récentes
        <a href="<?= BASE_URL ?>/modules/parent/pages/attendance.php?child_id=<?= (int)$child['student_id'] ?>" class="card-action">Toutes →</a>
      </div>
      <?php foreach (array_slice($recentAbsences, 0, 5) as $abs): ?>
      <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);">
        <div style="width:36px;height:36px;border-radius:10px;background:#FEE2E2;
          display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">
          🚫
        </div>
        <div style="flex:1;">
          <div style="font-size:13px;font-weight:600;color:var(--navy);">
            <?= $abs['icon'] ?> <?= e($abs['subject_name']) ?>
          </div>
          <div style="font-size:11px;color:var(--muted);">
            <?= formatDate($abs['date']) ?> · <?= e($abs['period']) ?>
          </div>
        </div>
        <?php if ($abs['justification']): ?>
          <span class="badge badge-green">✓ Justifié</span>
        <?php else: ?>
          <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Justification%20absence"
             class="btn btn-sm btn-amber">Justifier</a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- ── Paiements ──────────────────────────────────────── -->
<?php if (!empty($feeStatus)): ?>
<div class="card mt-20">
  <div class="card-title">
    💰 Scolarité <?= e(getCurrentYear()['label'] ?? '') ?>
    <a href="<?= BASE_URL ?>/modules/parent/pages/fees.php?child_id=<?= (int)$child['student_id'] ?>" class="card-action">Voir détail →</a>
  </div>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
    <?php foreach ($feeStatus as $fi): ?>
    <div style="flex:1;min-width:140px;padding:16px;border-radius:14px;text-align:center;
      background:<?= $fi['payment_id'] ? '#D1FAE5' : (strtotime($fi['due_date']) < time() ? '#FEE2E2' : '#FEF3C7') ?>;
      border:1.5px solid <?= $fi['payment_id'] ? '#A7F3D0' : (strtotime($fi['due_date']) < time() ? '#FECACA' : '#FDE68A') ?>;">
      <div style="font-size:11px;font-weight:700;color:var(--muted);margin-bottom:6px;">
        <?= e($fi['label']) ?>
      </div>
      <div style="font-family:'Sora',sans-serif;font-size:20px;font-weight:800;
        color:<?= $fi['payment_id'] ? 'var(--mint)' : (strtotime($fi['due_date']) < time() ? 'var(--rose)' : 'var(--amber)') ?>;">
        <?= $fi['payment_id'] ? '✅ Payée' : (strtotime($fi['due_date']) < time() ? '⚠️ En retard' : '⏳ À venir') ?>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-top:4px;">
        <?= formatMoney($fi['amount']) ?>
        <?php if (!$fi['payment_id']): ?>
          <br>Échéance : <?= formatDate($fi['due_date']) ?>
        <?php else: ?>
          <br>Payé le <?= formatDate($fi['payment_date']) ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php $hasDue = array_filter($feeStatus, fn($fi) => !$fi['payment_id']); ?>
  <?php if (!empty($hasDue)): ?>
  <button class="btn btn-primary btn-lg btn-full" onclick="showToast('Redirection vers le paiement mobile…', 'info')">
    📱 Payer via Orange Money / MTN MoMo
  </button>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Emploi du temps demain ─────────────────────────── -->
<?php if (!empty($tomorrowSchedule)): ?>
<div class="card mt-20">
  <div class="card-title">
    📅 Cours de demain — <?= e(dayName($tomorrowN)) ?> <?= formatDate($tomorrow) ?>
  </div>
  <?php foreach ($tomorrowSchedule as $slot): ?>
  <div class="schedule-row">
    <div class="schedule-time">
      <?= date('H\hi', strtotime($slot['start_time'])) ?> – <?= date('H\hi', strtotime($slot['end_time'])) ?>
    </div>
    <div class="schedule-block" style="background:#EFF6FF;border-left-color:var(--blue);">
      <div class="schedule-course"><?= $slot['icon'] ?> <?= e($slot['subject_name']) ?></div>
      <?php if ($slot['room']): ?>
      <div class="schedule-info">Salle <?= e($slot['room']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card">
  <div class="empty-state">
    <div class="empty-state-icon">👨‍👧</div>
    <h3>Aucun enfant inscrit</h3>
    <p>Contactez l'administration pour associer un élève à votre compte.</p>
  </div>
</div>
<?php endif; ?>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
