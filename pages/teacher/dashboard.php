<?php
/**
 * SmartSchool Hub — Tableau de bord Enseignant
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('teacher');

$teacherId   = Auth::id();
$currentYear = getCurrentYear();
$yearId      = $currentYear['id'] ?? 0;
$trimester   = getCurrentTrimester();
$trimId      = $trimester['id'] ?? 0;

// ── Classes de l'enseignant ───────────────────────────────
$myClasses = Database::fetchAll(
  'SELECT DISTINCT c.id, c.name, c.level, c.section,
          COUNT(DISTINCT s.id) AS student_count,
          sub.name AS subject_name, sub.icon AS subject_icon,
          cs.id AS cs_id
   FROM class_subjects cs
   JOIN classes c ON c.id = cs.class_id
   JOIN subjects sub ON sub.id = cs.subject_id
   LEFT JOIN students s ON s.class_id = c.id AND s.status = "enrolled"
   WHERE cs.teacher_id = ? AND c.academic_year_id = ?
   GROUP BY cs.id
   ORDER BY c.level, c.section',
  [$teacherId, $yearId]
);

// ── Emploi du temps d'aujourd'hui ─────────────────────────
$todayNum = (int)date('N'); // 1=Lun ... 7=Dim
$todaySchedule = Database::fetchAll(
  'SELECT tt.start_time, tt.end_time, tt.room,
          c.name AS class_name, c.id AS class_id,
          sub.name AS subject_name, sub.icon, sub.color,
          cs.id AS cs_id
   FROM timetable tt
   JOIN class_subjects cs ON cs.id = tt.class_subject_id
   JOIN classes c ON c.id = cs.class_id
   JOIN subjects sub ON sub.id = cs.subject_id
   WHERE cs.teacher_id = ? AND tt.day_of_week = ? AND tt.academic_year_id = ?
   ORDER BY tt.start_time',
  [$teacherId, $todayNum, $yearId]
);

// ── Devoirs à noter récents ───────────────────────────────
$pendingGrades = Database::fetchAll(
  'SELECT e.id, e.label, e.date, e.type, e.max_score,
          c.name AS class_name, sub.name AS subject_name, sub.icon,
          COUNT(DISTINCT s.id) AS total_students,
          COUNT(DISTINCT g.id) AS graded_count
   FROM evaluations e
   JOIN class_subjects cs ON cs.id = e.class_subject_id
   JOIN classes c ON c.id = cs.class_id
   JOIN subjects sub ON sub.id = cs.subject_id
   LEFT JOIN students s ON s.class_id = c.id AND s.status = "enrolled"
   LEFT JOIN grades g ON g.evaluation_id = e.id AND g.student_id = s.id
   WHERE cs.teacher_id = ?
     AND e.trimester_id = ?
     AND e.date <= CURDATE()
   GROUP BY e.id
   HAVING graded_count < total_students
   ORDER BY e.date DESC
   LIMIT 5',
  [$teacherId, $trimId]
);

// ── Statistiques générales ────────────────────────────────
$totalStudents = (int) Database::scalar(
  'SELECT COUNT(DISTINCT s.id)
   FROM students s
   JOIN classes c ON c.id = s.class_id
   JOIN class_subjects cs ON cs.class_id = c.id
   WHERE cs.teacher_id = ? AND c.academic_year_id = ? AND s.status = "enrolled"',
  [$teacherId, $yearId]
);

$absencesToday = (int) Database::scalar(
  'SELECT COUNT(*)
   FROM attendance a
   JOIN class_subjects cs ON cs.id = a.class_subject_id
   WHERE cs.teacher_id = ? AND a.date = CURDATE() AND a.status = "absent"',
  [$teacherId]
);

$evalsDone = (int) Database::scalar(
  'SELECT COUNT(*)
   FROM evaluations e
   JOIN class_subjects cs ON cs.id = e.class_subject_id
   WHERE cs.teacher_id = ? AND e.trimester_id = ?',
  [$teacherId, $trimId]
);

// ── Alertes IA sur mes classes ────────────────────────────
$myAlerts = Database::fetchAll(
  'SELECT aa.*, u.first_name, u.last_name, c.name AS class_name
   FROM ai_alerts aa
   JOIN students s ON s.id = aa.student_id
   JOIN users u ON u.id = s.user_id
   JOIN classes c ON c.id = s.class_id
   JOIN class_subjects cs ON cs.class_id = c.id
   WHERE cs.teacher_id = ? AND aa.is_resolved = 0
   GROUP BY aa.id
   ORDER BY aa.score DESC
   LIMIT 4',
  [$teacherId]
);

$pageTitle = 'Mon tableau de bord';
$pageIcon  = '📊';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- ── Stats ──────────────────────────────────────────── -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#EFF6FF;">👨‍🎓</div>
      <span class="badge badge-blue"><?= count($myClasses) ?> classes</span>
    </div>
    <div class="stat-num"><?= $totalStudents ?></div>
    <div class="stat-label">Mes élèves</div>
    <div class="stat-trend up">Année <?= e($currentYear['label'] ?? '—') ?></div>
  </div>

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#FEE2E2;">🚫</div>
      <span class="badge badge-red">Aujourd'hui</span>
    </div>
    <div class="stat-num"><?= $absencesToday ?></div>
    <div class="stat-label">Absences</div>
    <div class="stat-trend <?= $absencesToday > 5 ? 'down' : 'up' ?>">
      <?= $absencesToday > 0 ? 'À signaler' : 'Bonne présence ✓' ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#D1FAE5;">📝</div>
      <span class="badge badge-green">Ce trimestre</span>
    </div>
    <div class="stat-num"><?= $evalsDone ?></div>
    <div class="stat-label">Évaluations</div>
    <div class="stat-trend up"><?= count($pendingGrades) ?> à compléter</div>
  </div>

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#EDE9FE;">🤖</div>
      <span class="badge badge-purple">IA</span>
    </div>
    <div class="stat-num"><?= count($myAlerts) ?></div>
    <div class="stat-label">Alertes élèves</div>
    <div class="stat-trend <?= count($myAlerts) > 0 ? 'down' : 'up' ?>">
      <?= count($myAlerts) > 0 ? 'Attention requise' : 'Tout va bien ✓' ?>
    </div>
  </div>

</div>

<div class="two-col">

  <!-- Emploi du temps du jour -->
  <div class="card">
    <div class="card-title">
      📅 Mon emploi du temps — <?= e(dayName($todayNum)) ?> <?= e(date('d/m/Y')) ?>
      <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Emploi%20du%20temps" class="card-action">Semaine →</a>
    </div>
    <?php if (empty($todaySchedule)): ?>
      <div class="empty-state" style="padding:30px;">
        <div class="empty-state-icon">🎉</div>
        <h3>Pas de cours aujourd'hui</h3>
        <p>Profitez de votre journée libre !</p>
      </div>
    <?php else: ?>
      <?php
      $scheduleColors = [
        'var(--blue)'   => ['bg' => '#EFF6FF', 'border' => 'var(--blue)'],
        'var(--mint)'   => ['bg' => '#D1FAE5', 'border' => 'var(--mint)'],
        'var(--purple)' => ['bg' => '#EDE9FE', 'border' => 'var(--purple)'],
        'var(--amber)'  => ['bg' => '#FEF3C7', 'border' => 'var(--amber)'],
        'var(--rose)'   => ['bg' => '#FEE2E2', 'border' => 'var(--rose)'],
        'var(--cyan)'   => ['bg' => '#CFFAFE', 'border' => 'var(--cyan)'],
      ];
      $colorKeys = array_keys($scheduleColors);
      $ci = 0;
      ?>
      <?php foreach ($todaySchedule as $slot):
        $colorKey = $colorKeys[$ci % count($colorKeys)];
        $c = $scheduleColors[$colorKey];
        $ci++;
        $start = date('H\hi', strtotime($slot['start_time']));
        $end   = date('H\hi', strtotime($slot['end_time']));

        // Cours en cours ?
        $now = date('H:i:s');
        $isCurrent = $now >= $slot['start_time'] && $now <= $slot['end_time'];
      ?>
      <div class="schedule-row" <?= $isCurrent ? 'style="background:#F0FFF4;border-radius:10px;"' : '' ?>>
        <div class="schedule-time">
          <?= $start ?> – <?= $end ?>
          <?php if ($isCurrent): ?>
            <div class="badge badge-green" style="margin-top:4px;font-size:9px;">🔴 En cours</div>
          <?php endif; ?>
        </div>
        <div class="schedule-block" style="background:<?= $c['bg'] ?>;border-left-color:<?= $c['border'] ?>;">
          <div class="schedule-course">
            <?= $slot['subject_icon'] ?> <?= e($slot['subject_name']) ?>
          </div>
          <div class="schedule-info">
            <?= e($slot['class_name']) ?>
            <?= $slot['room'] ? ' · Salle ' . e($slot['room']) : '' ?>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
          <a href="<?= BASE_URL ?>/modules/teacher/pages/attendance.php?cs_id=<?= (int)$slot['cs_id'] ?>&date=<?= date('Y-m-d') ?>"
             class="btn btn-sm btn-secondary" style="font-size:11px;">🚫 Appel</a>
          <a href="<?= BASE_URL ?>/modules/teacher/pages/grades.php?cs_id=<?= (int)$slot['cs_id'] ?>"
             class="btn btn-sm btn-secondary" style="font-size:11px;">📝 Notes</a>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Colonne droite -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Devoirs à compléter -->
    <div class="card">
      <div class="card-title">
        ⏳ Notes à compléter
        <a href="<?= BASE_URL ?>/modules/teacher/pages/grades.php" class="card-action">Tout voir →</a>
      </div>
      <?php if (empty($pendingGrades)): ?>
        <div class="empty-state" style="padding:20px;">
          <div class="empty-state-icon">✅</div>
          <p>Toutes les notes sont saisies !</p>
        </div>
      <?php else: ?>
        <?php foreach ($pendingGrades as $ev):
          $pct = $ev['total_students'] > 0
            ? round(($ev['graded_count'] / $ev['total_students']) * 100) : 0;
        ?>
        <div style="padding:12px 0;border-bottom:1px solid var(--border);">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
            <div>
              <div style="font-size:13px;font-weight:600;color:var(--navy);">
                <?= $ev['subject_icon'] ?> <?= e($ev['label']) ?>
                <span class="badge badge-blue" style="margin-left:6px;"><?= e($ev['class_name']) ?></span>
              </div>
              <div style="font-size:11px;color:var(--muted);">
                <?= formatDate($ev['date']) ?> — <?= $ev['graded_count'] ?>/<?= $ev['total_students'] ?> élèves notés
              </div>
            </div>
            <a href="<?= BASE_URL ?>/modules/teacher/pages/grades.php?eval_id=<?= (int)$ev['id'] ?>"
               class="btn btn-sm btn-primary">Saisir →</a>
          </div>
          <div class="prog-bar">
            <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $pct < 50 ? 'var(--rose)' : ($pct < 100 ? 'var(--amber)' : 'var(--mint)') ?>;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Alertes IA -->
    <?php if (!empty($myAlerts)): ?>
    <div class="ai-card">
      <div class="ai-title">🤖 Alertes IA — Mes élèves</div>
      <?php foreach (array_slice($myAlerts, 0, 3) as $alert):
        $risk = getRiskLevel($alert['score'] ?? 0);
        $initials = strtoupper(substr($alert['first_name'],0,1) . substr($alert['last_name'],0,1));
      ?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;position:relative;z-index:1;">
        <div class="avatar" style="background:rgba(255,255,255,.2);font-size:12px;width:30px;height:30px;">
          <?= e($initials) ?>
        </div>
        <div style="flex:1;">
          <div style="font-size:13px;font-weight:600;">
            <?= e($alert['first_name'] . ' ' . $alert['last_name']) ?>
            <span class="badge <?= $risk['class'] ?>" style="margin-left:6px;font-size:9px;">
              <?= $risk['label'] ?>
            </span>
          </div>
          <div style="font-size:11px;color:rgba(255,255,255,.6);"><?= e($alert['class_name']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="ai-tags" style="margin-top:10px;">
        <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Module%20IA%20enseignant" class="ai-tag" style="text-decoration:none;">
          Voir les recommandations →
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Mes classes -->
    <div class="card">
      <div class="card-title">
        📋 Mes classes
        <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Mes%20classes" class="card-action">Voir tout →</a>
      </div>
      <?php foreach (array_slice($myClasses, 0, 5) as $cl): ?>
      <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);">
        <div style="width:36px;height:36px;border-radius:10px;background:var(--grad2);
          display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">
          <?= $cl['subject_icon'] ?>
        </div>
        <div style="flex:1;">
          <div style="font-size:13px;font-weight:600;color:var(--navy);">
            <?= e($cl['subject_name']) ?> — <?= e($cl['name']) ?>
          </div>
          <div style="font-size:11px;color:var(--muted);"><?= $cl['student_count'] ?> élèves</div>
        </div>
        <div style="display:flex;gap:6px;">
          <a href="<?= BASE_URL ?>/modules/teacher/pages/attendance.php?cs_id=<?= (int)$cl['cs_id'] ?>"
             class="btn btn-sm btn-secondary" style="font-size:11px;">Appel</a>
          <a href="<?= BASE_URL ?>/modules/teacher/pages/grades.php?cs_id=<?= (int)$cl['cs_id'] ?>"
             class="btn btn-sm btn-secondary" style="font-size:11px;">Notes</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
