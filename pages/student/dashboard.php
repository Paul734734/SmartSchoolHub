<?php
/**
 * SmartSchool Hub — Tableau de bord Élève
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('student');

$userId  = Auth::id();
$yearId  = getCurrentYear()['id'] ?? 0;
$trimId  = getCurrentTrimester()['id'] ?? 0;

// Profil élève
$student = Database::fetchOne(
  'SELECT s.*, u.first_name, u.last_name, u.email, u.phone,
          c.name AS class_name, c.id AS class_id, c.level
   FROM students s
   JOIN users u ON u.id = s.user_id
   JOIN classes c ON c.id = s.class_id
   WHERE s.user_id = ? AND s.academic_year_id = ?',
  [$userId, $yearId]
);

if (!$student) {
    setFlash('error', 'Votre profil élève est introuvable. Contactez l\'administration.');
    header('Location: ' . BASE_URL . '/login.php'); exit;
}

$studentId = $student['id'];
$classId   = $student['class_id'];

// Notes par matière
$subjectGrades = Database::fetchAll(
  'SELECT sub.name, sub.icon, sub.color, cs.coefficient,
          AVG(CASE WHEN g.is_absent=0 THEN g.score ELSE NULL END) AS avg_score,
          COUNT(g.id) AS eval_count
   FROM class_subjects cs
   JOIN subjects sub ON sub.id = cs.subject_id
   LEFT JOIN evaluations e ON e.class_subject_id = cs.id AND e.trimester_id = ?
   LEFT JOIN grades g ON g.evaluation_id = e.id AND g.student_id = ?
   WHERE cs.class_id = ?
   GROUP BY cs.id
   ORDER BY (avg_score IS NULL) ASC, avg_score DESC',
  [$trimId, $studentId, $classId]
);

$generalAvg = computeGeneralAverage($studentId, $classId, $trimId);
$rank       = getClassRank($studentId, $classId, $trimId);
$absCount   = getStudentAbsenceCount($studentId);

// Emploi du temps aujourd'hui
$todayN = (int)date('N');
$todaySchedule = Database::fetchAll(
  'SELECT tt.start_time, tt.end_time, tt.room,
          sub.name AS subject_name, sub.icon, sub.color,
          u.first_name AS teacher_fn, u.last_name AS teacher_ln
   FROM timetable tt
   JOIN class_subjects cs ON cs.id = tt.class_subject_id
   JOIN subjects sub ON sub.id = cs.subject_id
   JOIN users u ON u.id = cs.teacher_id
   WHERE cs.class_id = ? AND tt.day_of_week = ? AND tt.academic_year_id = ?
   ORDER BY tt.start_time',
  [$classId, $todayN, $yearId]
);

// Dernières notes
$recentGrades = Database::fetchAll(
  'SELECT g.score, g.is_absent, e.label, e.date, e.max_score,
          sub.name AS subject_name, sub.icon
   FROM grades g
   JOIN evaluations e ON e.id = g.evaluation_id
   JOIN class_subjects cs ON cs.id = e.class_subject_id
   JOIN subjects sub ON sub.id = cs.subject_id
   WHERE g.student_id = ?
   ORDER BY e.date DESC
   LIMIT 6',
  [$studentId]
);

// Annonces
$announcements = Database::fetchAll(
  'SELECT a.title, a.body, a.created_at, u.first_name, u.last_name
   FROM announcements a
   JOIN users u ON u.id = a.created_by
   WHERE (a.target_role LIKE "%student%" OR a.target_role = "all")
     AND (a.class_id IS NULL OR a.class_id = ?)
     AND (a.expires_at IS NULL OR a.expires_at > NOW())
   ORDER BY a.is_pinned DESC, a.created_at DESC
   LIMIT 4',
  [$classId]
);

$pageTitle = 'Mon espace';
$pageIcon  = '🎒';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- ── Bannière de bienvenue ──────────────────────────── -->
<div style="
  background: var(--grad1); border-radius: var(--radius);
  padding: 28px 32px; margin-bottom: 24px;
  display: flex; align-items: center; gap: 24px;
  position: relative; overflow: hidden;
">
  <div style="position:absolute;right:-20px;top:-20px;width:200px;height:200px;
    border-radius:50%;background:rgba(59,130,246,.15);"></div>
  <div style="position:absolute;right:100px;bottom:-40px;width:120px;height:120px;
    border-radius:50%;background:rgba(6,182,212,.1);"></div>

  <div class="avatar avatar-xl" style="background:rgba(255,255,255,.15);backdrop-filter:blur(10px);position:relative;z-index:1;">
    <?= strtoupper(substr($student['first_name'],0,1) . substr($student['last_name'],0,1)) ?>
  </div>

  <div style="flex:1;position:relative;z-index:1;">
    <div style="font-family:'Sora',sans-serif;font-size:24px;font-weight:800;color:#fff;margin-bottom:4px;">
      Bonjour, <?= e($student['first_name']) ?> 👋
    </div>
    <div style="font-size:14px;color:rgba(255,255,255,.7);">
      <?= e($student['class_name']) ?> · #<?= e($student['matricule']) ?>
      · <?= e(date('l d F Y')) ?>
    </div>
    <?php if ($generalAvg): ?>
    <div style="display:flex;align-items:center;gap:8px;margin-top:12px;flex-wrap:wrap;">
      <span style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);
        border-radius:20px;padding:6px 14px;font-size:13px;font-weight:700;color:#fff;">
        📊 Moyenne : <?= number_format($generalAvg, 1) ?>/20
      </span>
      <?php if ($rank): ?>
      <span style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);
        border-radius:20px;padding:6px 14px;font-size:13px;font-weight:700;color:#fff;">
        🏆 Rang : <?= $rank ?>e de la classe
      </span>
      <?php endif; ?>
      <span style="background:<?= $absCount > 5 ? 'rgba(244,63,94,.3)' : 'rgba(16,185,129,.2)' ?>;border:1px solid rgba(255,255,255,.2);
        border-radius:20px;padding:6px 14px;font-size:13px;font-weight:700;color:#fff;">
        🚫 <?= $absCount ?> absence<?= $absCount > 1 ? 's' : '' ?>
      </span>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Cours du jour ──────────────────────────────────── -->
<div class="two-col">
  <div class="card">
    <div class="card-title">
      📅 Mes cours — <?= e(dayName($todayN)) ?> <?= e(date('d/m')) ?>
      <a href="<?= BASE_URL ?>/modules/student/pages/timetable.php" class="card-action">Semaine →</a>
    </div>
    <?php if (empty($todaySchedule)): ?>
      <div class="empty-state" style="padding:30px;">
        <div class="empty-state-icon">🎉</div>
        <h3>Pas de cours aujourd'hui !</h3>
        <p>Profite pour réviser ou te reposer.</p>
      </div>
    <?php else: ?>
      <?php
      $colorPalette = [
        ['#EFF6FF','var(--blue)'], ['#D1FAE5','var(--mint)'],
        ['#EDE9FE','var(--purple)'], ['#FEF3C7','var(--amber)'],
        ['#CFFAFE','var(--cyan)'], ['#FEE2E2','var(--rose)'],
      ];
      $ci = 0;
      $now = date('H:i:s');
      ?>
      <?php foreach ($todaySchedule as $slot):
        [$bg, $border] = $colorPalette[$ci % count($colorPalette)]; $ci++;
        $start = date('H\hi', strtotime($slot['start_time']));
        $end   = date('H\hi', strtotime($slot['end_time']));
        $isCurrent = $now >= $slot['start_time'] && $now <= $slot['end_time'];
        $isPast    = $now > $slot['end_time'];
      ?>
      <div class="schedule-row" style="<?= $isCurrent ? 'background:#F0FFF4;border-radius:10px;padding:0 8px;' : ($isPast ? 'opacity:.6;' : '') ?>">
        <div class="schedule-time">
          <?= $start ?> – <?= $end ?>
          <?php if ($isCurrent): ?>
          <div><span class="badge badge-green" style="font-size:9px;margin-top:3px;">🔴 En cours</span></div>
          <?php endif; ?>
        </div>
        <div class="schedule-block" style="background:<?= $bg ?>;border-left-color:<?= $border ?>;">
          <div class="schedule-course"><?= $slot['icon'] ?> <?= e($slot['subject_name']) ?></div>
          <div class="schedule-info">
            Prof. <?= e($slot['teacher_fn'] . ' ' . $slot['teacher_ln']) ?>
            <?= $slot['room'] ? ' · Salle ' . e($slot['room']) : '' ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Dernières notes -->
  <div class="card">
    <div class="card-title">
      📝 Mes dernières notes
      <a href="<?= BASE_URL ?>/modules/student/pages/grades.php" class="card-action">Voir tout →</a>
    </div>
    <?php if (empty($recentGrades)): ?>
      <div class="empty-state" style="padding:20px;">
        <p>Aucune note enregistrée pour le moment.</p>
      </div>
    <?php else: ?>
      <?php foreach ($recentGrades as $g):
        $badge = $g['is_absent'] ? ['class'=>'badge-red','label'=>'ABS'] : gradeBadge($g['score'], $g['max_score']);
        $scoreDisplay = $g['is_absent'] ? 'ABS' : formatGrade($g['score'], $g['max_score']);
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--border);">
        <div style="width:36px;height:36px;border-radius:10px;background:var(--light);
          display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">
          <?= $g['icon'] ?>
        </div>
        <div style="flex:1;">
          <div style="font-size:13px;font-weight:600;color:var(--navy);"><?= e($g['label']) ?></div>
          <div style="font-size:11px;color:var(--muted);">
            <?= e($g['subject_name']) ?> · <?= formatDate($g['date']) ?>
          </div>
        </div>
        <div style="text-align:right;">
          <div style="font-family:'Sora',sans-serif;font-size:20px;font-weight:800;
            color:<?= $g['is_absent'] ? 'var(--rose)' : gradeColor($g['score'], $g['max_score']) ?>;">
            <?= $scoreDisplay ?>
          </div>
          <span class="badge <?= $badge['class'] ?>" style="font-size:9px;"><?= $badge['label'] ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ── Matières + Annonces ────────────────────────────── -->
<div class="two-col mt-20">

  <!-- Aperçu matières -->
  <div class="card">
    <div class="card-title">
      📊 Mes moyennes par matière
      <a href="<?= BASE_URL ?>/modules/student/pages/grades.php" class="card-action">Détail →</a>
    </div>
    <?php if (empty($subjectGrades)): ?>
      <div class="empty-state" style="padding:20px;"><p>Aucune note saisie.</p></div>
    <?php else: ?>
      <?php foreach ($subjectGrades as $sg):
        $avg = $sg['avg_score'] !== null ? round((float)$sg['avg_score'], 1) : null;
        $pct = $avg !== null ? min(100, round(($avg / 20) * 100)) : 0;
        $color = $avg === null ? 'var(--muted)' : ($avg >= 14 ? 'var(--mint)' : ($avg >= 10 ? 'var(--amber)' : 'var(--rose)'));
      ?>
      <div style="padding:10px 0;border-bottom:1px solid var(--border);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:18px;"><?= $sg['icon'] ?></span>
            <span style="font-size:13px;font-weight:600;color:var(--navy);"><?= e($sg['name']) ?></span>
          </div>
          <span style="font-family:'Sora',sans-serif;font-size:16px;font-weight:800;color:<?= $color ?>;">
            <?= $avg !== null ? $avg . '/20' : '—' ?>
          </span>
        </div>
        <?php if ($avg !== null): ?>
        <div class="prog-bar">
          <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Annonces -->
  <div class="card">
    <div class="card-title">
      📢 Annonces de l'établissement
      <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Annonces%20eleve" class="card-action">Voir tout →</a>
    </div>
    <?php if (empty($announcements)): ?>
      <div class="empty-state" style="padding:20px;"><p>Aucune annonce récente.</p></div>
    <?php else: ?>
      <?php foreach ($announcements as $ann): ?>
      <div class="notif-item">
        <div class="notif-dot-icon" style="background:#EDE9FE;">📢</div>
        <div>
          <div class="notif-title"><?= e($ann['title']) ?></div>
          <div class="notif-body"><?= e(substr(strip_tags($ann['body']), 0, 100)) ?>…</div>
          <div class="notif-time">
            <?= e($ann['first_name'] . ' ' . $ann['last_name']) ?> · <?= timeAgo($ann['created_at']) ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Quiz IA -->
    <div class="ai-card mt-16">
      <div class="ai-title">🧠 Quiz adaptatif IA</div>
      <div class="ai-body">
        L'IA a analysé tes lacunes et a préparé des exercices personnalisés pour t'aider à progresser.
      </div>
      <div class="ai-tags">
        <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Quiz%20IA%20eleve" class="ai-tag" style="text-decoration:none;">
          ▶ Commencer le quiz →
        </a>
      </div>
    </div>
  </div>

</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
