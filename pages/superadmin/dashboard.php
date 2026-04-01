<?php
/**
 * SmartSchool Hub — Tableau de bord Super-Administrateur
 */
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('super_admin');

// ── Statistiques globales de la plateforme ─────────────────────
$globalStats = [
  'total_schools' => Database::scalar('SELECT COUNT(*) FROM school'),
  'active_schools' => Database::scalar('SELECT COUNT(*) FROM school WHERE id IN (SELECT school_id FROM licenses WHERE status = "active")'),
  'total_users' => Database::scalar('SELECT COUNT(*) FROM users'),
  'active_users' => Database::scalar('SELECT COUNT(*) FROM users WHERE is_active = 1'),
  'total_students' => Database::scalar('SELECT COUNT(*) FROM students WHERE status = "enrolled"'),
  'total_teachers' => Database::scalar('SELECT COUNT(DISTINCT u.id) FROM users u JOIN class_subjects cs ON cs.teacher_id = u.id WHERE u.role = "teacher"'),
  'total_admins' => Database::scalar('SELECT COUNT(*) FROM users WHERE role = "admin" AND is_active = 1'),
];

// ── Revenus globaux (tous établissements confondus) ───────────────
$revenueStats = [
  'current_month' => (float) Database::scalar(
    'SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p 
     WHERE YEAR(p.payment_date) = YEAR(CURDATE()) AND MONTH(p.payment_date) = MONTH(CURDATE())'
  ),
  'current_year' => (float) Database::scalar(
    'SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p 
     WHERE YEAR(p.payment_date) = YEAR(CURDATE())'
  ),
  'last_month' => (float) Database::scalar(
    'SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p 
     WHERE YEAR(p.payment_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
     AND MONTH(p.payment_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))'
  ),
];

// ── Croissance des établissements (6 derniers mois) ───────────────────
$schoolGrowth = [];
for ($i = 5; $i >= 0; $i--) {
    $date = date('Y-m-01', strtotime("-{$i} months"));
    $label = date('M Y', strtotime($date));
    $count = (int) Database::scalar(
        'SELECT COUNT(*) FROM school WHERE DATE(created_at) >= ? AND DATE(created_at) < DATE_ADD(?, INTERVAL 1 MONTH)',
        [$date, $date]
    );
    $schoolGrowth[] = ['date' => $date, 'label' => $label, 'count' => $count];
}

// ── Répartition des licences ───────────────────────────────────────
$licenseStats = Database::fetchAll(
    'SELECT l.plan, COUNT(*) AS count, 
            SUM(l.max_students) AS total_students_allowed,
            SUM(CASE WHEN l.status = "active" THEN 1 ELSE 0 END) AS active_count
     FROM licenses l
     GROUP BY l.plan'
);

// ── Établissements récents ───────────────────────────────────────────
$recentSchools = Database::fetchAll(
    'SELECT s.*, l.plan, l.status as license_status,
            COUNT(DISTINCT u.id) as user_count
     FROM school s
     LEFT JOIN licenses l ON l.school_id = s.id
     LEFT JOIN users u ON (u.role IN ("admin","teacher","student","parent"))
     GROUP BY s.id
     ORDER BY s.created_at DESC
     LIMIT 8'
);

// ── Alertes système ───────────────────────────────────────────────────────
$systemAlerts = Database::fetchAll(
    'SELECT "Licence expirée" as type, s.name as target, l.expires_at as date
     FROM licenses l JOIN school s ON s.id = l.school_id
     WHERE l.status = "expired" OR l.expires_at < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     
     UNION ALL
     
     SELECT "Établissement sans admin" as type, s.name as target, s.created_at as date
     FROM school s
     WHERE s.id NOT IN (SELECT DISTINCT school_id FROM users WHERE role = "admin" AND is_active = 1)
     
     UNION ALL
     
     SELECT "Utilisateurs bloqués" as type, CONCAT(COUNT(*), " comptes") as target, MAX(u.last_login) as date
     FROM users u WHERE u.is_active = 0
     HAVING COUNT(*) > 0
     
     ORDER BY date DESC
     LIMIT 10'
);

// ── Activité récente globale ───────────────────────────────────────────
$globalActivity = Database::fetchAll(
    'SELECT al.action, al.target, al.details, al.created_at,
            u.first_name, u.last_name, u.role, s.name as school_name
     FROM activity_log al
     LEFT JOIN users u ON u.id = al.user_id
     LEFT JOIN students st ON st.id = al.target_id AND al.target = "student"
     LEFT JOIN classes c ON c.id = st.class_id
     LEFT JOIN school s ON s.id = (SELECT school_id FROM licenses WHERE school_id = 1 LIMIT 1)
     ORDER BY al.created_at DESC
     LIMIT 10'
);

// ── Performance système ────────────────────────────────────────────────
$systemPerformance = [
    'db_size' => Database::scalar('SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE()'),
    'total_tables' => Database::scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'),
    'uptime' => shell_exec('uptime 2>/dev/null') ?: 'N/A',
    'disk_usage' => function() {
        $free = disk_free_space('/');
        $total = disk_total_space('/');
        return [
            'free_gb' => round($free / 1024 / 1024 / 1024, 2),
            'total_gb' => round($total / 1024 / 1024 / 1024, 2),
            'used_percent' => round((($total - $free) / $total) * 100, 1)
        ];
    }
];

$pageTitle = 'Tableau de Bord Global';
$pageIcon = '🌐';
require_once BASE_PATH . '/includes/header.php';
?>

<!-- ── Stats principales Super-Admin ──────────────────────────────── -->
<div class="stat-grid">

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#EFF6FF;">🏫</div>
      <span class="badge badge-green">Plateforme</span>
    </div>
    <div class="stat-num"><?= number_format((int)$globalStats['total_schools']) ?></div>
    <div class="stat-label">Établissements totaux</div>
    <div class="stat-trend up">↑ <?= (int)$globalStats['active_schools'] ?> actifs</div>
  </div>

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#FEF3C7;">👥</div>
      <span class="badge badge-blue">Utilisateurs</span>
    </div>
    <div class="stat-num"><?= number_format((int)$globalStats['total_users']) ?></div>
    <div class="stat-label">Total utilisateurs</div>
    <div class="stat-trend up">↑ <?= (int)$globalStats['active_users'] ?> actifs</div>
  </div>

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#D1FAE5;">💰</div>
      <span class="badge badge-green">Revenus</span>
    </div>
    <div class="stat-num" style="font-size:24px;">
      <?= number_format($revenueStats['current_month'] / 1000, 0, ',', ' ') ?>K
    </div>
    <div class="stat-label">FCFA ce mois</div>
    <?php
      $growth = $revenueStats['last_month'] > 0 
        ? round((($revenueStats['current_month'] - $revenueStats['last_month']) / $revenueStats['last_month']) * 100)
        : 0;
    ?>
    <div class="stat-trend <?= $growth >= 0 ? 'up' : 'down' ?>">
      <?= $growth >= 0 ? '↑' : '↓' ?> <?= abs($growth) ?>% vs mois dernier
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-top">
      <div class="stat-icon" style="background:#FEE2E2;">🎓</div>
      <span class="badge badge-purple">Éducation</span>
    </div>
    <div class="stat-num"><?= number_format((int)$globalStats['total_students']) ?></div>
    <div class="stat-label">Élèves inscrits</div>
    <div class="stat-trend up">↑ <?= (int)$globalStats['total_teachers'] ?> enseignants</div>
  </div>

</div><!-- /stat-grid -->

<!-- ── Ligne principale : établissements + performance ── -->
<div class="three-col">

  <!-- Table établissements récents -->
  <div class="card">
    <div class="card-title">
      🏫 Établissements récents
      <a href="<?= BASE_URL ?>/modules/superadmin/pages/schools.php" class="card-action">Gérer →</a>
    </div>
    <div class="table-wrap">
      <table id="recent-schools-table">
        <thead>
          <tr>
            <th>Établissement</th>
            <th>Licence</th>
            <th>Utilisateurs</th>
            <th>Statut</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentSchools as $school):
            $licenseBadge = $school['license_status'] === 'active' ? 'success' : 
                          ($school['license_status'] === 'expired' ? 'red' : 'amber');
          ?>
          <tr>
            <td>
              <div class="user-cell">
                <div class="avatar" style="background:linear-gradient(135deg,#3B82F6,#06B6D4);">
                  <?= strtoupper(substr($school['name'], 0, 2)) ?>
                </div>
                <div>
                  <div class="user-cell-name"><?= e($school['name']) ?></div>
                  <div class="user-cell-sub"><?= e($school['city'] ?? '—') ?></div>
                </div>
              </div>
            </td>
            <td>
              <span class="badge badge-<?= $licenseBadge ?>">
                <?= e(ucfirst($school['plan'] ?? 'free')) ?>
              </span>
            </td>
            <td>
              <span style="font-size:13px;"><?= (int)$school['user_count'] ?></span>
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
              <a href="<?= BASE_URL ?>/modules/superadmin/pages/school-view.php?id=<?= $school['id'] ?>"
                 class="btn btn-sm btn-secondary">Voir</a>
            </td>
          </tr>
          <?php endforeach; ?>

          <?php if (empty($recentSchools)): ?>
          <tr>
            <td colspan="5">
              <div class="empty-state" style="padding:30px;">
                <div class="empty-state-icon">🏫</div>
                <h3>Aucun établissement</h3>
                <p>Commencez par <a href="<?= BASE_URL ?>/modules/superadmin/pages/schools.php">ajouter un établissement</a>.</p>
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

    <!-- Croissance établissements -->
    <div class="card">
      <div class="card-title">📈 Croissance Établissements</div>
      <div class="chart-bars">
        <?php foreach ($schoolGrowth as $month):
          $maxCount = max(array_column($schoolGrowth, 'count'));
          $heightPct = $maxCount > 0 ? round(($month['count'] / $maxCount) * 100) : 0;
        ?>
        <div class="chart-bar-wrap">
          <div class="chart-bar" style="height:<?= max(6, $heightPct) ?>%;"
               title="<?= $month['label'] ?> : <?= $month['count'] ?> établissements">
          </div>
          <div class="chart-label"><?= substr($month['label'], 0, 3) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Actions rapides Super-Admin -->
    <div class="card">
      <div class="card-title">⚡ Actions Super-Admin</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <a href="<?= BASE_URL ?>/modules/superadmin/pages/schools.php"
           class="btn btn-secondary" style="justify-content:center;">➕ Établissement</a>
        <a href="<?= BASE_URL ?>/modules/superadmin/pages/licenses.php"
           class="btn btn-success" style="justify-content:center;">💳 Licences</a>
        <a href="<?= BASE_URL ?>/modules/superadmin/pages/settings.php"
           class="btn btn-amber" style="justify-content:center;">⚙️ Paramètres</a>
        <a href="<?= BASE_URL ?>/modules/superadmin/pages/backup.php"
           class="btn btn-purple" style="justify-content:center;">💾 Sauvegarde</a>
      </div>
    </div>

    <!-- Répartition licences -->
    <?php if (!empty($licenseStats)): ?>
    <div class="card">
      <div class="card-title">💳 Répartition Licences</div>
      <?php foreach ($licenseStats as $license): ?>
      <div style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
          <span style="font-size:13px;font-weight:600;color:var(--navy);">
            <?= ucfirst($license['plan']) ?>
          </span>
          <span style="font-size:12px;color:var(--muted);">
            <?= $license['count'] ?> écoles
          </span>
        </div>
        <div class="prog-bar">
          <div class="prog-fill" style="width:<?= round(($license['count'] / $globalStats['total_schools']) * 100) ?>%;background:var(--<?= $license['plan'] === 'free' ? 'mint' : ($license['plan'] === 'pro' ? 'blue' : 'purple') ?>);"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>

</div><!-- /three-col -->

<!-- ── Alertes Système ─────────────────────────────────────── -->
<?php if (!empty($systemAlerts)): ?>
<div class="card gap-20">
  <div class="card-title">
    🚨 Alertes Système
    <a href="<?= BASE_URL ?>/modules/superadmin/pages/alerts.php" class="card-action">Voir tout →</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Type d'alerte</th>
          <th>Cible</th>
          <th>Date</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($systemAlerts as $alert): ?>
        <tr>
          <td>
            <span class="badge <?= strpos($alert['type'], 'expirée') !== false ? 'badge-red' : 'badge-amber' ?>">
              <?= e($alert['type']) ?>
            </span>
          </td>
          <td><?= e($alert['target']) ?></td>
          <td>
            <span style="font-size:13px;color:var(--muted);">
              <?= timeAgo($alert['date']) ?>
            </span>
          </td>
          <td>
            <a href="<?= BASE_URL ?>/modules/superadmin/pages/alerts.php?action=resolve"
               class="btn btn-sm btn-purple">Résoudre →</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Activité Globale + Performance Système ───────────────────────── -->
<div class="two-col mt-20">

  <!-- Activité globale -->
  <div class="card">
    <div class="card-title">
      📊 Activité Globale
      <a href="<?= BASE_URL ?>/modules/superadmin/pages/activity.php" class="card-action">Journal →</a>
    </div>
    <?php if (empty($globalActivity)): ?>
      <div class="empty-state" style="padding:20px;">
        <p>Aucune activité récente.</p>
      </div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach ($globalActivity as $activity):
          $icon = match($activity['action']) {
            'login' => '🔐', 'logout' => '🚪', 'create' => '➕',
            'update' => '✏️', 'delete' => '🗑️', 'login_failed' => '⚠️',
            default => '📝'
          };
        ?>
        <div class="tl-item">
          <div class="tl-dot" style="background:#EFF6FF;"><?= $icon ?></div>
          <div class="tl-time"><?= timeAgo($activity['created_at']) ?></div>
          <div class="tl-title">
            <?= e($activity['first_name'] ? $activity['first_name'] . ' ' . $activity['last_name'] : 'Système') ?>
            — <?= e($activity['action']) ?>
          </div>
          <?php if ($activity['details']): ?>
          <div class="tl-body"><?= e(substr($activity['details'], 0, 60)) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Performance système -->
  <div class="card">
    <div class="card-title">
      💻 Performance Système
      <a href="<?= BASE_URL ?>/modules/superadmin/pages/system.php" class="card-action">Détails →</a>
    </div>
    <div style="display:grid;gap:15px;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:13px;">📁 Base de données</span>
        <span style="font-size:13px;font-weight:600;"><?= $systemPerformance['db_size'] ?> MB</span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:13px;">🗂️ Tables totales</span>
        <span style="font-size:13px;font-weight:600;"><?= $systemPerformance['total_tables'] ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:13px;">💾 Espace disque</span>
        <span style="font-size:13px;font-weight:600;"><?= $systemPerformance['disk_usage']['used_percent'] ?>% utilisé</span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:13px;">⏱️ Uptime</span>
        <span style="font-size:13px;font-weight:600;"><?= substr($systemPerformance['uptime'], 0, 20) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <span style="font-size:13px;">📊 Espace libre</span>
        <span style="font-size:13px;font-weight:600;"><?= $systemPerformance['disk_usage']['free_gb'] ?> GB</span>
      </div>
    </div>
  </div>

</div><!-- /two-col -->

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
