<?php
/**
 * SmartSchool Hub — Tableau de bord Administrateur
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$currentYear = getCurrentYear();
$yearId      = $currentYear['id'] ?? 0;
$trimester   = getCurrentTrimester();
$trimId      = $trimester['id'] ?? 0;

// ── Statistiques principales ───────────────────────────────
$stats = [
  'students' => Database::scalar(
    'SELECT COUNT(*) FROM students WHERE academic_year_id = ? AND status = "enrolled"',
    [$yearId]
  ),
  'teachers' => Database::scalar(
    'SELECT COUNT(DISTINCT u.id) FROM users u
     JOIN class_subjects cs ON cs.teacher_id = u.id
     JOIN classes c ON c.id = cs.class_id
     WHERE u.role = "teacher" AND c.academic_year_id = ?',
    [$yearId]
  ),
  'classes' => Database::scalar(
    'SELECT COUNT(*) FROM classes WHERE academic_year_id = ?', [$yearId]
  ),
  'absences_today' => Database::scalar(
    'SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status = "absent"'
  ),
  'absences_unjustified' => Database::scalar(
    'SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status = "absent" AND justification IS NULL'
  ),
];

// Recettes du mois
$stats['revenue_month'] = (float) Database::scalar(
  'SELECT COALESCE(SUM(p.amount_paid), 0)
   FROM payments p
   WHERE YEAR(p.payment_date) = YEAR(CURDATE())
     AND MONTH(p.payment_date) = MONTH(CURDATE())'
);

// Total prévu
$stats['revenue_target'] = (float) Database::scalar(
  'SELECT COALESCE(SUM(fi.amount), 0)
   FROM fee_installments fi
   JOIN fees f ON f.id = fi.fee_id
   WHERE f.academic_year_id = ?
     AND MONTH(fi.due_date) = MONTH(CURDATE())',
  [$yearId]
);

// ── Élèves récemment inscrits ─────────────────────────────
$recentStudents = Database::fetchAll(
  'SELECT u.first_name, u.last_name, u.email,
          s.matricule, s.enrollment_date, s.status,
          c.name AS class_name,
          (SELECT AVG(g.score)
           FROM grades g
           JOIN evaluations e ON e.id = g.evaluation_id
           JOIN class_subjects cs ON cs.id = e.class_subject_id
           WHERE g.student_id = s.id AND cs.class_id = s.class_id
           ) AS avg_score
   FROM students s
   JOIN users u ON u.id = s.user_id
   JOIN classes c ON c.id = s.class_id
   WHERE s.academic_year_id = ?
   ORDER BY s.enrollment_date DESC
   LIMIT 8',
  [$yearId]
);

// ── Absences des 7 derniers jours ─────────────────────────
$absenceChart = [];
for ($i = 6; $i >= 0; $i--) {
  $date  = date('Y-m-d', strtotime("-{$i} days"));
  $label = date('d/m', strtotime($date));
  $count = (int) Database::scalar(
    'SELECT COUNT(*) FROM attendance WHERE date = ? AND status = "absent"', [$date]
  );
  $absenceChart[] = ['date' => $date, 'label' => $label, 'count' => $count];
}
$counts = array_column($absenceChart, 'count');
$maxAbs = !empty($counts) ? max(max($counts), 1) : 1;

// ── Alertes IA (élèves à risque) ─────────────────────────
$aiAlerts = Database::fetchAll(
  'SELECT aa.*, u.first_name, u.last_name, c.name AS class_name, aa.score,
          aa.level, aa.message
   FROM ai_alerts aa
   JOIN students s ON s.id = aa.student_id
   JOIN users u ON u.id = s.user_id
   JOIN classes c ON c.id = s.class_id
   WHERE aa.is_resolved = 0
   ORDER BY aa.score DESC, aa.created_at DESC
   LIMIT 5'
);

// ── Annonces récentes ─────────────────────────────────────
$announcements = Database::fetchAll(
  'SELECT a.*, u.first_name, u.last_name
   FROM announcements a
   JOIN users u ON u.id = a.created_by
   WHERE (a.expires_at IS NULL OR a.expires_at > NOW())
   ORDER BY a.is_pinned DESC, a.created_at DESC
   LIMIT 4'
);

// ── Paiements impayés par niveau ──────────────────────────
$unpaidByLevel = Database::fetchAll(
  'SELECT f.level,
          COUNT(DISTINCT CASE WHEN p.id IS NULL THEN s.id END) AS unpaid_count,
          COUNT(DISTINCT s.id) AS total
   FROM students s
   JOIN classes c ON c.id = s.class_id AND c.academic_year_id = :yr
   JOIN fees f ON f.level = c.level AND f.academic_year_id = :yr
   JOIN fee_installments fi ON fi.fee_id = f.id
   LEFT JOIN payments p ON p.student_id = s.id AND p.fee_installment_id = fi.id
   WHERE fi.due_date <= CURDATE()
   GROUP BY f.level
   ORDER BY unpaid_count DESC',
  [':yr' => $yearId]
);

$pageTitle = 'Tableau de bord';
$pageIcon  = '📊';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- ── Stats principales ──────────────────────────────── -->
<div class="stat-grid">

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#EFF6FF;">🎓</div>
      <span class="badge badge-green">Inscrits</span>
    </div>
    <div class="stat-num"><?= number_format((int)$stats['students']) ?></div>
    <div class="stat-label">Élèves inscrits</div>
    <div class="stat-trend up">↑ Année <?= e($currentYear['label'] ?? '—') ?></div>
  </div>

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#FEF3C7;">👩‍🏫</div>
      <span class="badge badge-blue">Actifs</span>
    </div>
    <div class="stat-num"><?= (int)$stats['teachers'] ?></div>
    <div class="stat-label">Enseignants</div>
    <div class="stat-trend up"><?= (int)$stats['classes'] ?> classes au total</div>
  </div>

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#FEE2E2;">🚫</div>
      <span class="badge badge-red">Aujourd'hui</span>
    </div>
    <div class="stat-num"><?= (int)$stats['absences_today'] ?></div>
    <div class="stat-label">Absences</div>
    <div class="stat-trend down">↓ <?= (int)$stats['absences_unjustified'] ?> non justifiées</div>
  </div>

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#D1FAE5;">💰</div>
      <span class="badge badge-green">Ce mois</span>
    </div>
    <div class="stat-num" style="font-size:24px;">
      <?= number_format($stats['revenue_month'] / 1000, 0, ',', ' ') ?>K
    </div>
    <div class="stat-label">FCFA collectés</div>
    <?php
      $pct = $stats['revenue_target'] > 0
        ? round(($stats['revenue_month'] / $stats['revenue_target']) * 100)
        : 0;
    ?>
    <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;background:var(--mint);"></div></div>
    <div class="stat-trend up">↑ <?= $pct ?>% du prévisionnel</div>
  </div>

</div><!-- /stat-grid -->

<!-- ── Ligne principale : table élèves + panneau droit ── -->
<div class="three-col">

  <!-- Table élèves récents -->
  <div class="card">
    <div class="card-title">
      👨‍🎓 Élèves récemment inscrits
      <a href="<?= BASE_URL ?>/modules/admin/pages/students.php" class="card-action">Voir tout →</a>
    </div>
    <div class="table-wrap">
      <table id="recent-students-table">
        <thead>
          <tr>
            <th>Élève</th>
            <th>Classe</th>
            <th>Statut</th>
            <th>Moy.</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentStudents as $s):
            $avg = $s['avg_score'] !== null ? round($s['avg_score'], 1) : null;
            $initials = strtoupper(substr($s['first_name'],0,1) . substr($s['last_name'],0,1));
            $gradients = [
              'linear-gradient(135deg,#3B82F6,#06B6D4)',
              'linear-gradient(135deg,#10B981,#06B6D4)',
              'linear-gradient(135deg,#8B5CF6,#F43F5E)',
              'linear-gradient(135deg,#F59E0B,#F43F5E)',
              'linear-gradient(135deg,#06B6D4,#8B5CF6)',
            ];
            $grad = $gradients[crc32($s['matricule']) % count($gradients)];
          ?>
          <tr>
            <td>
              <div class="user-cell">
                <div class="avatar" style="background:<?= $grad ?>;"><?= e($initials) ?></div>
                <div>
                  <div class="user-cell-name"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></div>
                  <div class="user-cell-sub">#<?= e($s['matricule']) ?></div>
                </div>
              </div>
            </td>
            <td><span class="badge badge-blue"><?= e($s['class_name']) ?></span></td>
            <td>
              <?php if ($s['status'] === 'enrolled'): ?>
                <span class="badge badge-green">✓ Inscrit</span>
              <?php elseif ($s['status'] === 'pending'): ?>
                <span class="badge badge-amber">⏳ En attente</span>
              <?php else: ?>
                <span class="badge badge-gray"><?= e($s['status']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($avg !== null): ?>
                <strong style="color:<?= gradeColor($avg) ?>;"><?= $avg ?></strong>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Fiche%20eleve"
                 class="btn btn-sm btn-secondary">Voir</a>
            </td>
          </tr>
          <?php endforeach; ?>

          <?php if (empty($recentStudents)): ?>
          <tr>
            <td colspan="5">
              <div class="empty-state" style="padding:30px;">
                <div class="empty-state-icon">🎓</div>
                <h3>Aucun élève inscrit</h3>
                <p>Commencez par <a href="<?= BASE_URL ?>/modules/admin/pages/students.php">ajouter des élèves</a>.</p>
              </div>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Colonne droite -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Graphe absences 7 jours -->
    <div class="card">
      <div class="card-title">📊 Absences — 7 derniers jours</div>
      <div class="chart-bars">
        <?php foreach ($absenceChart as $day):
          $heightPct = $maxAbs > 0 ? round(($day['count'] / $maxAbs) * 100) : 0;
          $isWeekend = in_array(date('N', strtotime($day['date'])), [6, 7]);
        ?>
        <div class="chart-bar-wrap">
          <div class="chart-bar <?= $isWeekend ? 'secondary' : '' ?>"
               style="height:<?= max(6, $heightPct) ?>%;"
               title="<?= $day['label'] ?> : <?= $day['count'] ?> absences">
          </div>
          <div class="chart-label"><?= $day['label'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Actions rapides -->
    <div class="card">
      <div class="card-title">⚡ Actions rapides</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <a href="<?= BASE_URL ?>/modules/admin/pages/students.php"
           class="btn btn-secondary" style="justify-content:center;">➕ Nouvel élève</a>
        <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Bulletins"
           class="btn btn-success" style="justify-content:center;">📋 Bulletins</a>
        <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Annonces"
           class="btn btn-amber" style="justify-content:center;">📢 Annonce</a>
        <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Emploi%20du%20temps"
           class="btn btn-purple" style="justify-content:center;">📅 Emploi du temps</a>
      </div>
    </div>

    <!-- Impayés par niveau -->
    <?php if (!empty($unpaidByLevel)): ?>
    <div class="card">
      <div class="card-title">💰 Impayés par niveau</div>
      <?php foreach ($unpaidByLevel as $lvl):
        $pctPaid = $lvl['total'] > 0 ? round((($lvl['total'] - $lvl['unpaid_count']) / $lvl['total']) * 100) : 0;
      ?>
      <div style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
          <span style="font-size:13px;font-weight:600;color:var(--navy);"><?= e($lvl['level']) ?></span>
          <span style="font-size:12px;color:var(--muted);">
            <strong style="color:var(--rose);"><?= $lvl['unpaid_count'] ?></strong> / <?= $lvl['total'] ?>
          </span>
        </div>
        <div class="prog-bar">
          <div class="prog-fill" style="width:<?= $pctPaid ?>%;background:<?= $pctPaid >= 80 ? 'var(--mint)' : ($pctPaid >= 50 ? 'var(--amber)' : 'var(--rose)') ?>;"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>

</div><!-- /three-col -->

<!-- ── Alertes IA ─────────────────────────────────────── -->
<?php if (!empty($aiAlerts)): ?>
<div class="card gap-20">
  <div class="card-title">
    🤖 Alertes IA — Élèves à risque
    <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Module%20IA" class="card-action">Voir tout →</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Élève</th>
          <th>Classe</th>
          <th>Score risque</th>
          <th>Message IA</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($aiAlerts as $alert):
          $risk = getRiskLevel($alert['score'] ?? 0);
          $initials = strtoupper(substr($alert['first_name'],0,1) . substr($alert['last_name'],0,1));
        ?>
        <tr>
          <td>
            <div class="user-cell">
              <div class="avatar" style="background:var(--grad2);font-size:12px;"><?= e($initials) ?></div>
              <div>
                <div class="user-cell-name"><?= e($alert['first_name'] . ' ' . $alert['last_name']) ?></div>
                <div class="user-cell-sub" style="color:var(--muted);">Créée <?= timeAgo($alert['created_at']) ?></div>
              </div>
            </div>
          </td>
          <td><span class="badge badge-blue"><?= e($alert['class_name']) ?></span></td>
          <td><span class="badge <?= $risk['class'] ?>"><?= $risk['label'] ?></span></td>
          <td style="max-width:280px;">
            <span style="font-size:13px;color:var(--muted);"><?= e(substr($alert['message'], 0, 80)) ?>…</span>
          </td>
          <td>
            <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Plan%20de%20remediation"
               class="btn btn-sm btn-purple">Plan remédiation →</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Annonces + Notifications ───────────────────────── -->
<div class="two-col mt-20">

  <!-- Annonces récentes -->
  <div class="card">
    <div class="card-title">
      📢 Annonces récentes
      <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Annonces" class="card-action">Gérer →</a>
    </div>
    <?php if (empty($announcements)): ?>
      <div class="empty-state" style="padding:20px;">
        <div class="empty-state-icon">📢</div>
        <p>Aucune annonce active.</p>
      </div>
    <?php else: ?>
      <?php foreach ($announcements as $ann): ?>
      <div class="notif-item">
        <div class="notif-dot-icon" style="background:#EDE9FE;">
          <?= $ann['is_pinned'] ? '📌' : '📢' ?>
        </div>
        <div>
          <div class="notif-title"><?= e($ann['title']) ?></div>
          <div class="notif-body"><?= e(substr(strip_tags($ann['body']), 0, 90)) ?>…</div>
          <div class="notif-time">
            Par <?= e($ann['first_name'] . ' ' . $ann['last_name']) ?>
            · <?= timeAgo($ann['created_at']) ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Activité récente -->
  <div class="card">
    <div class="card-title">
      📋 Activité récente
      <a href="<?= BASE_URL ?>/modules/common/pages/coming-soon.php?module=Journal" class="card-action">Journal →</a>
    </div>
    <?php
    $logs = Database::fetchAll(
      'SELECT al.action, al.target, al.details, al.created_at,
              u.first_name, u.last_name, u.role
       FROM activity_log al
       LEFT JOIN users u ON u.id = al.user_id
       ORDER BY al.created_at DESC
       LIMIT 6'
    );
    $actionIcons = [
      'login' => '🔐', 'logout' => '🚪', 'create' => '➕',
      'update' => '✏️', 'delete' => '🗑️', 'login_failed' => '⚠️',
    ];
    ?>
    <?php if (empty($logs)): ?>
      <div class="empty-state" style="padding:20px;">
        <p>Aucune activité enregistrée.</p>
      </div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach ($logs as $log):
          $icon = $actionIcons[$log['action']] ?? '📝';
        ?>
        <div class="tl-item">
          <div class="tl-dot" style="background:#EFF6FF;"><?= $icon ?></div>
          <div class="tl-time"><?= timeAgo($log['created_at']) ?></div>
          <div class="tl-title">
            <?= e($log['first_name'] ? $log['first_name'] . ' ' . $log['last_name'] : 'Système') ?>
            — <?= e($log['action']) ?>
          </div>
          <?php if ($log['details']): ?>
          <div class="tl-body"><?= e(substr($log['details'], 0, 60)) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div><!-- /two-col -->

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
