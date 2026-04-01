<?php
/**
 * SmartSchool Hub - Dashboard Élève Perfect
 */

define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

// Démarrer la session si non démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: login_correct.php');
    exit;
}

// Fonction helper pour convertir hex en RGB
function hex2rgb($hex) {
    $hex = str_replace("#", "", $hex);
    if (strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    return "$r,$g,$b";
}

// Informations de l'élève avec gestion d'erreur
try {
    $studentInfo = Database::fetchOne(
        'SELECT s.id, s.matricule, s.class_id, c.name as class_name, c.level, u.first_name, u.last_name 
         FROM students s 
         JOIN classes c ON s.class_id = c.id 
         JOIN users u ON s.user_id = u.id 
         WHERE u.id = ?',
        [$_SESSION['user_id']]
    );
    if (!$studentInfo) {
        $studentInfo = ['id' => 0, 'matricule' => 'DEMO001', 'class_id' => 1, 'class_name' => '6ème A', 'level' => '6ème', 'first_name' => 'Student', 'last_name' => 'Demo'];
    }
} catch (Exception $e) {
    $studentInfo = ['id' => 0, 'matricule' => 'DEMO001', 'class_id' => 1, 'class_name' => '6ème A', 'level' => '6ème', 'first_name' => 'Student', 'last_name' => 'Demo'];
}

// Statistiques de l'élève avec gestion d'erreur
$studentStats = [];
try {
    $studentStats = [
        'total_grades' => Database::scalar('SELECT COUNT(*) FROM grades g JOIN evaluations e ON g.evaluation_id = e.id WHERE g.student_id = ?', [$studentInfo['id']]) ?? 0,
        'average_grade' => Database::scalar('SELECT AVG(g.score) FROM grades g JOIN evaluations e ON g.evaluation_id = e.id WHERE g.student_id = ? AND g.is_absent = 0', [$studentInfo['id']]) ?? 0,
        'total_absences' => Database::scalar('SELECT COUNT(*) FROM attendance WHERE student_id = ? AND status = "absent"', [$studentInfo['id']]) ?? 0,
        'recent_grades' => Database::fetchAll(
            'SELECT s.label, e.label as evaluation_label, g.score, g.entered_at
             FROM grades g 
             JOIN evaluations e ON g.evaluation_id = e.id 
             JOIN subjects s ON e.subject_id = s.id 
             WHERE g.student_id = ? 
             ORDER BY g.entered_at DESC 
             LIMIT 5',
            [$studentInfo['id']]
        ) ?? [],
        'recent_absences' => Database::fetchAll(
            'SELECT date, status, reason, recorded_at
             FROM attendance 
             WHERE student_id = ? 
             ORDER BY date DESC 
             LIMIT 5',
            [$studentInfo['id']]
        ) ?? [],
        'recent_homework' => Database::fetchAll(
            'SELECT h.title, h.description, h.due_date, s.name as subject_name
             FROM homework h 
             JOIN class_subjects cs ON h.class_subject_id = cs.id 
             JOIN subjects s ON cs.subject_id = s.id 
             WHERE cs.class_id = ? 
             ORDER BY h.due_date ASC 
             LIMIT 5',
            [$studentInfo['class_id']]
        ) ?? [],
        'recent_timetable' => Database::fetchAll(
            'SELECT t.day_of_week, t.start_time, t.end_time, t.room, s.name as subject_name
             FROM timetable t 
             JOIN class_subjects cs ON t.class_subject_id = cs.id 
             JOIN subjects s ON cs.subject_id = s.id 
             WHERE cs.class_id = ? 
             ORDER BY t.day_of_week, t.start_time 
             LIMIT 10',
            [$studentInfo['class_id']]
        ) ?? []
    ];
} catch (Exception $e) {
    $studentStats = [
        'total_grades' => 0,
        'average_grade' => 0,
        'total_absences' => 0,
        'recent_grades' => [],
        'recent_absences' => [],
        'recent_homework' => [],
        'recent_timetable' => []
    ];
}

// Messages reçus avec gestion d'erreur
$recentMessages = [];
try {
    $recentMessages = Database::fetchAll(
        'SELECT m.subject, m.content, m.created_at, u.first_name, u.last_name as sender_name
         FROM messages m 
         JOIN users u ON m.sender_id = u.id 
         WHERE m.receiver_id = ? 
         ORDER BY m.created_at DESC 
         LIMIT 5',
        [$_SESSION['user_id']]
    );
} catch (Exception $e) {
    $recentMessages = [];
}

// Annonces récentes avec gestion d'erreur
$recentAnnouncements = [];
try {
    $recentAnnouncements = Database::fetchAll(
        'SELECT title, content, target_audience, created_at
         FROM announcements 
         WHERE target_audience IN ("all", "student") 
         ORDER BY created_at DESC 
         LIMIT 3'
    );
} catch (Exception $e) {
    $recentAnnouncements = [];
}

// Couleurs pour Élève
$primaryColor = '#007bff';
$secondaryColor = '#0056b3';
$gradientBg = 'linear-gradient(135deg, #007bff 0%, #6f42c1 100%)';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Élève - SmartSchool Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primaryColor; ?>;
            --secondary-color: <?php echo $secondaryColor; ?>;
            --gradient-bg: <?php echo $gradientBg; ?>;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: #212529;
        }
        
        .sidebar {
            background: white;
            min-height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar-header {
            background: var(--gradient-bg);
            color: white;
            padding: 2rem 1.5rem;
            text-align: center;
        }
        
        .sidebar-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        .user-info {
            padding: 1.5rem;
            border-bottom: 1px solid #e9ecef;
        }
        
        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--gradient-bg);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
            margin: 0 auto 1rem;
        }
        
        .user-name {
            font-weight: 600;
            color: #212529;
            text-align: center;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }
        
        .user-role {
            text-align: center;
            font-size: 0.875rem;
            color: #6c757d;
            margin-bottom: 1rem;
        }
        
        .student-info {
            background: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e9ecef;
        }
        
        .student-info h6 {
            margin: 0 0 0.5rem 0;
            color: var(--primary-color);
            font-size: 0.9rem;
        }
        
        .today-schedule {
            background: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e9ecef;
        }
        
        .today-schedule h6 {
            margin: 0 0 0.5rem 0;
            color: var(--primary-color);
            font-size: 0.9rem;
        }
        
        .nav-menu {
            padding: 1rem 0;
        }
        
        .nav-item {
            display: block;
            padding: 0.875rem 1.5rem;
            color: #495057;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            font-size: 0.95rem;
        }
        
        .nav-item:hover {
            background-color: #f8f9fa;
            color: var(--primary-color);
            border-left-color: var(--primary-color);
        }
        
        .nav-item.active {
            background-color: rgba(<?php echo hex2rgb(str_replace('#', '', $primaryColor)); ?>, 0.1);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
            font-weight: 500;
        }
        
        .nav-item i {
            width: 20px;
            margin-right: 0.75rem;
            text-align: center;
        }
        
        .nav-item.text-danger {
            color: #dc3545;
        }
        
        .nav-item.text-danger:hover {
            background-color: #f8d7da;
            border-left-color: #dc3545;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 0;
            min-height: 100vh;
        }
        
        .top-header {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-title {
            color: #212529;
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .content-area {
            padding: 2rem;
        }
        
        .dashboard-header {
            background: var(--gradient-bg);
            color: white;
            padding: 2rem;
            border-radius: 1rem;
            margin-bottom: 2rem;
        }
        
        .dashboard-header h1 {
            margin: 0 0 0.5rem;
            font-weight: 600;
            font-size: 2rem;
        }
        
        .dashboard-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 1.1rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 0.75rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #212529;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .card {
            background: white;
            border-radius: 0.75rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            border: none;
        }
        
        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e9ecef;
            font-weight: 600;
            background: transparent;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .badge-primary {
            background: var(--primary-color);
        }
        
        .sidebar-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.5rem;
            border-radius: 0.375rem;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .sidebar-toggle {
                display: block;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
            
            .dashboard-header h1 {
                font-size: 1.5rem;
            }
            
            .dashboard-header p {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h5>🎓 SmartSchool Hub</h5>
        </div>
        
        <div class="user-info">
            <div class="user-avatar">
                <?php echo strtoupper(substr($studentInfo['first_name'], 0, 1) . substr($studentInfo['last_name'], 0, 1)); ?>
            </div>
            <div class="user-name"><?php echo htmlspecialchars($studentInfo['first_name'] . ' ' . $studentInfo['last_name']); ?></div>
            <div class="user-role">Élève</div>
        </div>
        
        <!-- Informations de l'élève -->
        <div class="student-info">
            <div class="text-center">
                <h6>📋 Mes informations</h6>
                <p class="mb-1">
                    <span class="badge bg-primary"><?php echo htmlspecialchars($studentInfo['class_name']); ?></span>
                    <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($studentInfo['matricule']); ?></span>
                </p>
            </div>
        </div>
        
        <nav class="nav-menu">
            <a href="dashboard_student_perfect.php" class="nav-item active">
                <i class="fas fa-tachometer-alt"></i>
                Dashboard
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-chart-line"></i>
                Mes notes
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-calendar-check"></i>
                Absences
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-clock"></i>
                Emploi du temps
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-book"></i>
                Devoirs
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-question-circle"></i>
                Quiz
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-gavel"></i>
                Discipline
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-envelope"></i>
                Messages
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-user"></i>
                Profil
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-cog"></i>
                Paramètres
            </a>
            <a href="logout.php" class="nav-item text-danger">
                <i class="fas fa-sign-out-alt"></i>
                Déconnexion
            </a>
        </nav>
    </aside>
    
    <!-- Main Content -->
    <main class="main-content">
        <button class="sidebar-toggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        
        <header class="top-header">
            <div class="d-flex justify-content-between align-items-center w-100">
                <h1 class="page-title">Dashboard Élève</h1>
                <div class="header-actions">
                    <span class="badge bg-success">En ligne</span>
                    <button class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-bell"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <div class="content-area">
            <!-- Emploi du temps du jour -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="today-schedule">
                        <h6>📅 Emploi du temps d'aujourd'hui</h6>
                        <?php
                        $today_day = date('N'); // 1 = Lundi, 7 = Dimanche
                        $today_schedule = array_filter($studentStats['recent_timetable'], function($item) use ($today_day) {
                            return $item['day_of_week'] == $today_day;
                        });
                        
                        if (!empty($today_schedule)):
                        ?>
                            <div class="row">
                                <?php foreach ($today_schedule as $slot): ?>
                                    <div class="col-md-4 mb-2">
                                        <div class="card border-primary">
                                            <div class="card-body p-2">
                                                <h6 class="card-title mb-1"><?php echo htmlspecialchars($slot['subject_name']); ?></h6>
                                                <p class="card-text small mb-0">
                                                    <i class="fas fa-clock"></i> <?php echo $slot['start_time']; ?> - <?php echo $slot['end_time']; ?><br>
                                                    <i class="fas fa-door-open"></i> <?php echo htmlspecialchars($slot['room']); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">Aucun cours aujourd'hui</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Statistiques principales -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-number"><?php echo $studentStats['total_grades']; ?></div>
                    <div class="stat-label">Notes totales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($studentStats['average_grade'], 2, ',', ' '); ?>/20</div>
                    <div class="stat-label">Moyenne générale</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <div class="stat-number"><?php echo $studentStats['total_absences']; ?></div>
                    <div class="stat-label">Total absences</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-number">
                        <?php 
                        $pending_homework = count(array_filter($studentStats['recent_homework'], function($hw) {
                            return strtotime($hw['due_date']) >= strtotime(date('Y-m-d'));
                        }));
                        echo $pending_homework;
                        ?>
                    </div>
                    <div class="stat-label">Devoirs à faire</div>
                </div>
            </div>
            
            <!-- Informations détaillées -->
            <div class="row">
                <!-- Notes récentes -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">📊 Mes notes récentes</h5>
                            <a href="#" class="btn btn-sm btn-primary">Voir toutes les notes</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($studentStats['recent_grades'])): ?>
                                <?php foreach ($studentStats['recent_grades'] as $grade): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <strong><?php echo htmlspecialchars($grade['evaluation_label']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($grade['label']); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge <?php echo $grade['score'] >= 10 ? 'bg-success' : 'bg-warning'; ?>">
                                                <?php echo $grade['score']; ?>/20
                                            </span><br>
                                            <small class="text-muted"><?php echo date('d/m/Y', strtotime($grade['entered_at'])); ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Aucune note récente</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Devoirs à faire -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">📚 Devoirs à faire</h5>
                            <a href="#" class="btn btn-sm btn-warning">Voir tous les devoirs</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($studentStats['recent_homework'])): ?>
                                <?php foreach ($studentStats['recent_homework'] as $homework): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <strong><?php echo htmlspecialchars($homework['title']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($homework['subject_name']); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <?php
                                            $days_left = (strtotime($homework['due_date']) - strtotime(date('Y-m-d'))) / (60 * 60 * 24);
                                            $badge_class = $days_left <= 2 ? 'bg-danger' : ($days_left <= 7 ? 'bg-warning' : 'bg-info');
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo date('d/m', strtotime($homework['due_date'])); ?>
                                            </span><br>
                                            <small class="text-muted"><?php echo $days_left > 0 ? ceil($days_left) . ' jours' : 'En retard'; ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Aucun devoir à faire</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Absences et messages -->
            <div class="row">
                <!-- Absences récentes -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">📅 Mes absences</h5>
                            <a href="#" class="btn btn-sm btn-info">Voir toutes les absences</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($studentStats['recent_absences'])): ?>
                                <?php foreach ($studentStats['recent_absences'] as $absence): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <span class="badge <?php echo $absence['status'] == 'absent' ? 'bg-danger' : 'bg-warning'; ?>">
                                                <?php echo ucfirst($absence['status']); ?>
                                            </span><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($absence['reason']); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo date('d/m/Y', strtotime($absence['date'])); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Aucune absence récente</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Messages reçus -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">💬 Messages reçus</h5>
                            <a href="#" class="btn btn-sm btn-primary">Voir tous les messages</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recentMessages)): ?>
                                <?php foreach ($recentMessages as $message): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <strong><?php echo htmlspecialchars($message['subject']); ?></strong><br>
                                            <small class="text-muted">De: <?php echo htmlspecialchars($message['sender_name']); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($message['created_at'])); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Aucun message récent</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        }
        
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.sidebar-toggle');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !toggle.contains(event.target) &&
                sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
            }
        });
    </script>
</body>
</html>
