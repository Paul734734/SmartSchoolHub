<?php
/**
 * SmartSchool Hub - Dashboard Enseignant Perfect
 */

define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

// Démarrer la session si non démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
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

// Informations utilisateur avec gestion d'erreur
try {
    $userInfo = Database::fetchOne('SELECT u.first_name, u.last_name, u.email FROM users u WHERE u.id = ?', [$_SESSION['user_id']]);
    if (!$userInfo) {
        $userInfo = ['first_name' => 'Teacher', 'last_name' => 'Demo', 'email' => 'teacher@demo.com'];
    }
} catch (Exception $e) {
    $userInfo = ['first_name' => 'Teacher', 'last_name' => 'Demo', 'email' => 'teacher@demo.com'];
}

// Classes et matières de l'enseignant avec gestion d'erreur
$teacherClasses = [];
try {
    $teacherClasses = Database::fetchAll(
        'SELECT c.id, c.name as class_name, c.level, cs.subject_id, s.name as subject_name
         FROM class_subjects cs 
         JOIN classes c ON cs.class_id = c.id 
         JOIN subjects s ON cs.subject_id = s.id 
         WHERE cs.teacher_id = ?',
        [$_SESSION['user_id']]
    );
} catch (Exception $e) {
    $teacherClasses = [];
}

// Statistiques Enseignant avec gestion d'erreur
$stats = [];
try {
    $classIds = !empty($teacherClasses) ? array_column($teacherClasses, 'id') : [];
    $stats = [
        'total_classes' => count($teacherClasses),
        'total_students' => empty($classIds) ? 0 : Database::scalar('SELECT COUNT(*) FROM students WHERE class_id IN (' . implode(',', $classIds) . ')'),
        'total_evaluations' => Database::scalar('SELECT COUNT(*) FROM evaluations WHERE created_by = ?', [$_SESSION['user_id']]) ?? 0,
        'total_grades' => Database::scalar('SELECT COUNT(*) FROM grades WHERE entered_by = ?', [$_SESSION['user_id']]) ?? 0,
        'today_absences' => empty($classIds) ? 0 : Database::scalar('SELECT COUNT(*) FROM attendance a JOIN students st ON a.student_id = st.id WHERE st.class_id IN (' . implode(',', $classIds) . ') AND a.date = CURDATE() AND a.status = "absent"')
    ];
} catch (Exception $e) {
    $stats = [
        'total_classes' => 0,
        'total_students' => 0,
        'total_evaluations' => 0,
        'total_grades' => 0,
        'today_absences' => 0
    ];
}

// Évaluations récentes avec gestion d'erreur
$recentEvaluations = [];
try {
    $recentEvaluations = Database::fetchAll(
        'SELECT e.label, e.type, e.date, c.name as class_name, s.name as subject_name
         FROM evaluations e 
         JOIN classes c ON e.class_id = c.id 
         JOIN subjects s ON e.subject_id = s.id 
         WHERE e.created_by = ? 
         ORDER BY e.created_at DESC 
         LIMIT 5',
        [$_SESSION['user_id']]
    );
} catch (Exception $e) {
    $recentEvaluations = [];
}

// Notes à saisir avec gestion d'erreur
$pendingGrades = [];
try {
    $pendingGrades = Database::fetchAll(
        'SELECT e.id, e.label, e.date, c.name as class_name, s.name as subject_name, COUNT(st.id) as students_count
         FROM evaluations e 
         JOIN classes c ON e.class_id = c.id 
         JOIN subjects s ON e.subject_id = s.id 
         LEFT JOIN students st ON st.class_id = c.id 
         WHERE e.created_by = ? 
         AND e.id NOT IN (SELECT DISTINCT evaluation_id FROM grades WHERE entered_by = ?)
         GROUP BY e.id, e.label, e.date, c.name, s.name 
         ORDER BY e.date DESC 
         LIMIT 5',
        [$_SESSION['user_id'], $_SESSION['user_id']]
    );
} catch (Exception $e) {
    $pendingGrades = [];
}

// Couleurs pour Enseignant
$primaryColor = '#ffc107';
$secondaryColor = '#e0a800';
$gradientBg = 'linear-gradient(135deg, #ffc107 0%, #fd7e14 100%)';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Enseignant - SmartSchool Hub</title>
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
        
        .class-info {
            background: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e9ecef;
        }
        
        .class-info h6 {
            margin: 0 0 0.5rem 0;
            color: var(--primary-color);
            font-size: 0.9rem;
        }
        
        .class-item {
            margin-bottom: 0.5rem;
            padding: 0.5rem;
            background: white;
            border-radius: 0.25rem;
            border-left: 3px solid var(--primary-color);
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
            color: #212529;
        }
        
        .btn-primary:hover {
            background: var(--secondary-color);
            border-color: var(--secondary-color);
            color: #212529;
        }
        
        .badge-primary {
            background: var(--primary-color);
            color: #212529;
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
                <?php echo strtoupper(substr($userInfo['first_name'], 0, 1) . substr($userInfo['last_name'], 0, 1)); ?>
            </div>
            <div class="user-name"><?php echo htmlspecialchars($userInfo['first_name'] . ' ' . $userInfo['last_name']); ?></div>
            <div class="user-role">Enseignant</div>
        </div>
        
        <!-- Classes et matières -->
        <?php if (!empty($teacherClasses)): ?>
        <div class="class-info">
            <h6>📚 Mes classes et matières:</h6>
            <?php foreach ($teacherClasses as $class): ?>
                <div class="class-item">
                    <strong><?php echo htmlspecialchars($class['class_name'] ?? 'Classe'); ?></strong><br>
                    <small class="text-muted"><?php echo htmlspecialchars($class['subject_name'] ?? 'Matière'); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <nav class="nav-menu">
            <a href="dashboard_teacher_perfect.php" class="nav-item active">
                <i class="fas fa-tachometer-alt"></i>
                Dashboard
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-calendar-check"></i>
                Absences
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-chart-line"></i>
                Notes
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-clipboard-list"></i>
                Évaluations
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
                <h1 class="page-title">Dashboard Enseignant</h1>
                <div class="header-actions">
                    <span class="badge bg-success">En ligne</span>
                    <button class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-bell"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <div class="content-area">
            <div class="dashboard-header">
                <h1>Bienvenue, <?php echo htmlspecialchars($userInfo['first_name']); ?> !</h1>
                <p>Gestion pédagogique et suivi des élèves</p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-school"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['total_classes']); ?></div>
                    <div class="stat-label">Classes</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['total_students']); ?></div>
                    <div class="stat-label">Élèves</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['total_evaluations']); ?></div>
                    <div class="stat-label">Évaluations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['total_grades']); ?></div>
                    <div class="stat-label">Notes saisies</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['today_absences']); ?></div>
                    <div class="stat-label">Absences aujourd'hui</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format(count($pendingGrades)); ?></div>
                    <div class="stat-label">Notes à saisir</div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">📝 Évaluations récentes</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recentEvaluations)): ?>
                                <?php foreach ($recentEvaluations as $evaluation): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <strong><?php echo htmlspecialchars($evaluation['label']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($evaluation['class_name']); ?> • <?php echo htmlspecialchars($evaluation['subject_name']); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-secondary"><?php echo ucfirst($evaluation['type']); ?></span><br>
                                            <small class="text-muted"><?php echo date('d/m/Y', strtotime($evaluation['date'])); ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Aucune évaluation récente</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">📊 Notes à saisir</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($pendingGrades)): ?>
                                <?php foreach ($pendingGrades as $grade): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <strong><?php echo htmlspecialchars($grade['label']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($grade['class_name']); ?> • <?php echo htmlspecialchars($grade['subject_name']); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-warning"><?php echo $grade['students_count']; ?> élèves</span><br>
                                            <small class="text-muted"><?php echo date('d/m/Y', strtotime($grade['date'])); ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Aucune note à saisir</p>
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
