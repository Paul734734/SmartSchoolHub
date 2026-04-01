<?php
/**
 * SmartSchool Hub — Sidebar de navigation (rôle-adaptive)
 * Inclus automatiquement via header.php
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit; }

$role        = Auth::role();
$currentUri  = $_SERVER['REQUEST_URI'];
$school      = getSchool();
$schoolName  = $school['short_name'] ?? ($school['name'] ?? APP_NAME);
$unreadNotif = getUnreadNotifCount(Auth::id());
$unreadMsg   = getUnreadMsgCount(Auth::id());

// Détermine si un lien est actif
function isActive(string $path): bool {
    return strpos($_SERVER['REQUEST_URI'], $path) !== false;
}

// Construit un élément de menu
function menuItem(
    string $icon,
    string $label,
    string $href,
    int $badge = 0,
    string $badgeColor = 'var(--rose)'
): void {
    $active = isActive(parse_url($href, PHP_URL_PATH)) ? 'active' : '';
    echo '<a href="' . htmlspecialchars($href) . '" class="sidebar-item ' . $active . '" style="text-decoration:none;">';
    echo '<span class="sidebar-item-icon">' . $icon . '</span>';
    echo '<span>' . htmlspecialchars($label) . '</span>';
    if ($badge > 0) {
        echo '<span class="sidebar-item-badge" style="background:' . $badgeColor . ';">'
           . ($badge > 99 ? '99+' : $badge) . '</span>';
    }
    echo '</a>';
}
?>

<div class="sidebar" id="sidebar">

  <!-- Logo établissement -->
  <div class="sidebar-logo">
    <div class="sidebar-logo-icon">🎓</div>
    <div>
      <div class="sidebar-logo-text">SmartSchool</div>
      <div class="sidebar-logo-sub"><?= e($schoolName) ?></div>
    </div>
  </div>

  <nav class="sidebar-nav">

  <?php if ($role === 'admin'): ?>
  <!-- ══════════════════ ADMIN ══════════════════ -->

    <div class="sidebar-section">Principal</div>
    <?php menuItem('📊', 'Tableau de bord', BASE_URL . '/modules/admin/pages/dashboard.php'); ?>
    <?php menuItem('👨‍🎓', 'Élèves',         BASE_URL . '/modules/admin/pages/students.php'); ?>
    <?php menuItem('👩‍🏫', 'Enseignants',    BASE_URL . '/modules/admin/pages/teachers.php'); ?>
    <?php menuItem('🏛️', 'Classes',         BASE_URL . '/modules/admin/pages/classes.php'); ?>
    <?php menuItem('👨‍👧', 'Parents',        BASE_URL . '/modules/admin/pages/parents.php'); ?>

    <div class="sidebar-section">Pédagogie</div>
    <?php menuItem('📅', 'Emplois du temps', BASE_URL . '/modules/admin/pages/timetable.php'); ?>
    <?php menuItem('📝', 'Notes & Bulletins', BASE_URL . '/modules/admin/pages/bulletins.php'); ?>
    <?php
      $absToday = (int)Database::scalar(
        'SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status = "absent"'
      );
      menuItem('🚫', 'Absences', BASE_URL . '/modules/admin/pages/absences.php', $absToday);
    ?>
    <?php menuItem('⚖️', 'Discipline',       BASE_URL . '/modules/admin/pages/discipline.php'); ?>
    <?php menuItem('📚', 'Matières',         BASE_URL . '/modules/admin/pages/subjects.php'); ?>

    <div class="sidebar-section">Gestion</div>
    <?php
      $unpaidCount = (int)Database::scalar(
        'SELECT COUNT(DISTINCT sp.student_id)
         FROM student_parents sp
         JOIN students s ON s.id = sp.student_id
         JOIN fees f ON f.level = (SELECT level FROM classes WHERE id = s.class_id)
         JOIN fee_installments fi ON fi.fee_id = f.id
         LEFT JOIN payments p ON p.student_id = sp.student_id AND p.fee_installment_id = fi.id
         WHERE fi.due_date <= CURDATE() AND p.id IS NULL'
      );
      menuItem('💰', 'Scolarités', BASE_URL . '/modules/admin/pages/payments.php', $unpaidCount, 'var(--amber)');
    ?>
    <?php menuItem('📢', 'Annonces',    BASE_URL . '/modules/admin/pages/announcements.php'); ?>
    <?php menuItem('💬', 'Messagerie',  BASE_URL . '/modules/common/pages/messages.php', $unreadMsg); ?>
    <?php menuItem('🤖', 'Module IA',   BASE_URL . '/modules/admin/pages/ai.php'); ?>
    <?php menuItem('📊', 'Rapports',    BASE_URL . '/modules/admin/pages/reports.php'); ?>

    <div class="sidebar-section">Système</div>
    <?php menuItem('🗓️', 'Année scolaire', BASE_URL . '/modules/admin/pages/years.php'); ?>
    <?php menuItem('⚙️', 'Paramètres',    BASE_URL . '/modules/common/pages/settings.php'); ?>
    <?php menuItem('📋', 'Journal',       BASE_URL . '/modules/admin/pages/journal.php'); ?>
    <?php menuItem('🩺', 'Health check',  BASE_URL . '/modules/admin/pages/health.php'); ?>

  <?php elseif ($role === 'teacher'): ?>
  <!-- ══════════════════ ENSEIGNANT ══════════════════ -->

    <div class="sidebar-section">Mon espace</div>
    <?php menuItem('📊', 'Tableau de bord',   BASE_URL . '/modules/teacher/pages/dashboard.php'); ?>
    <?php menuItem('📝', 'Saisie des notes',  BASE_URL . '/modules/teacher/pages/grades.php'); ?>
    <?php menuItem('🚫', 'Appel / Absences',  BASE_URL . '/modules/teacher/pages/attendance.php'); ?>
    <?php menuItem('📋', 'Mes classes',       BASE_URL . '/modules/teacher/pages/classes.php'); ?>
    <?php menuItem('📅', 'Emploi du temps',   BASE_URL . '/modules/teacher/pages/timetable.php'); ?>
    <?php menuItem('📚', 'Cahier de texte',   BASE_URL . '/modules/teacher/pages/gradebook.php'); ?>
    <?php menuItem('🧾', 'Devoirs (rendus)',  BASE_URL . '/modules/teacher/pages/homework.php'); ?>
    <?php menuItem('⚖️', 'Discipline',        BASE_URL . '/modules/teacher/pages/discipline.php'); ?>

    <div class="sidebar-section">Communication</div>
    <?php menuItem('💬', 'Messagerie',   BASE_URL . '/modules/common/pages/messages.php',     $unreadMsg); ?>
    <?php menuItem('🔔', 'Notifications',BASE_URL . '/modules/common/pages/notifications.php',$unreadNotif); ?>

    <div class="sidebar-section">IA</div>
    <?php menuItem('🧠', 'Quiz IA',          BASE_URL . '/modules/teacher/pages/quiz.php'); ?>

  <?php elseif ($role === 'parent'): ?>
  <!-- ══════════════════ PARENT ══════════════════ -->

    <?php
    // Récupérer les enfants du parent connecté
    $children = Database::fetchAll(
      'SELECT s.id, u.first_name, u.last_name, c.name AS class_name
       FROM student_parents sp
       JOIN students s ON s.id = sp.student_id
       JOIN users u ON u.id = s.user_id
       JOIN classes c ON c.id = s.class_id
       WHERE sp.parent_id = ?',
      [Auth::id()]
    );

    // Absences non justifiées des enfants
    $absUnread = 0;
    foreach ($children as $child) {
      $absUnread += (int)Database::scalar(
        'SELECT COUNT(*) FROM attendance
         WHERE student_id = ? AND status = "absent" AND justification IS NULL
           AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)',
        [$child['id']]
      );
    }
    ?>

    <div class="sidebar-section">Mon enfant</div>
    <?php menuItem('📊', 'Vue d\'ensemble',     BASE_URL . '/modules/parent/pages/dashboard.php'); ?>
    <?php menuItem('📝', 'Notes & Bulletins',   BASE_URL . '/modules/parent/pages/grades.php'); ?>
    <?php menuItem('📄', 'Bulletin (PDF)',      BASE_URL . '/modules/common/pages/bulletin.php'); ?>
    <?php menuItem('🚫', 'Absences',            BASE_URL . '/modules/parent/pages/attendance.php', $absUnread); ?>
    <?php menuItem('📅', 'Emploi du temps',     BASE_URL . '/modules/parent/pages/timetable.php'); ?>
    <?php menuItem('🧾', 'Devoirs',             BASE_URL . '/modules/parent/pages/homework.php'); ?>
    <?php menuItem('💰', 'Scolarité & Paiements',BASE_URL . '/modules/parent/pages/fees.php'); ?>
    <?php menuItem('⚖️', 'Discipline',          BASE_URL . '/modules/parent/pages/discipline.php'); ?>

    <div class="sidebar-section">Communication</div>
    <?php menuItem('💬', 'Messagerie profs',    BASE_URL . '/modules/common/pages/messages.php',     $unreadMsg); ?>
    <?php menuItem('🔔', 'Notifications',       BASE_URL . '/modules/common/pages/notifications.php',$unreadNotif); ?>
    <?php menuItem('📢', 'Annonces',            BASE_URL . '/modules/common/pages/announcements.php'); ?>

    <?php if (!empty($children)): ?>
    <div class="sidebar-section">Enfants</div>
    <?php foreach ($children as $child): ?>
      <a href="<?= BASE_URL ?>/modules/parent/pages/child.php?child_id=<?= (int)$child['id'] ?>"
         class="sidebar-item <?= isActive('/modules/parent/pages/child.php') ? 'active' : '' ?>"
         style="text-decoration:none;">
        <span class="sidebar-item-icon">👦</span>
        <span>
          <?= e($child['first_name']) ?><br>
          <small style="color:rgba(255,255,255,.4);font-size:10px;"><?= e($child['class_name']) ?></small>
        </span>
      </a>
    <?php endforeach; ?>
    <?php endif; ?>

  <?php elseif ($role === 'student'): ?>
  <!-- ══════════════════ ÉLÈVE ══════════════════ -->

    <div class="sidebar-section">Mon espace</div>
    <?php menuItem('📊', 'Tableau de bord',  BASE_URL . '/modules/student/pages/dashboard.php'); ?>
    <?php menuItem('📝', 'Mes notes',        BASE_URL . '/modules/student/pages/grades.php'); ?>
    <?php menuItem('📄', 'Mon bulletin',     BASE_URL . '/modules/common/pages/bulletin.php'); ?>
    <?php menuItem('🚫', 'Mes absences',     BASE_URL . '/modules/student/pages/attendance.php'); ?>
    <?php menuItem('📅', 'Mon EDT',          BASE_URL . '/modules/student/pages/timetable.php'); ?>
    <?php menuItem('🧾', 'Mes devoirs',      BASE_URL . '/modules/student/pages/homework.php'); ?>
    <?php menuItem('📚', 'Cahier de texte',  BASE_URL . '/modules/student/pages/homework.php'); ?>
    <?php menuItem('⚖️', 'Discipline',       BASE_URL . '/modules/student/pages/discipline.php'); ?>
    <?php menuItem('🧠', 'Quiz IA',          BASE_URL . '/modules/student/pages/quiz.php'); ?>

    <div class="sidebar-section">Communication</div>
    <?php menuItem('💬', 'Messagerie',    BASE_URL . '/modules/common/pages/messages.php',     $unreadMsg); ?>
    <?php menuItem('🔔', 'Notifications', BASE_URL . '/modules/common/pages/notifications.php',$unreadNotif); ?>
    <?php menuItem('📢', 'Annonces',      BASE_URL . '/modules/common/pages/announcements.php'); ?>

  <?php endif; ?>

  </nav><!-- /sidebar-nav -->

  <!-- Profil utilisateur en bas -->
  <div class="sidebar-bottom">
    <div class="sidebar-user" onclick="togglePanel('user-panel')">
      <div class="sidebar-avatar"><?= e(Auth::initials()) ?></div>
      <div>
        <div class="sidebar-user-name"><?= e(Auth::name()) ?></div>
        <div class="sidebar-user-role"><?= ucfirst(e($role)) ?></div>
      </div>
      <span style="margin-left:auto;color:rgba(255,255,255,.3);font-size:14px;">›</span>
    </div>
  </div>

</div><!-- /sidebar -->
