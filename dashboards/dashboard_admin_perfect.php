<?php
/**
 * SmartSchool Hub - Dashboard Admin Perfect
 */

define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

// Démarrer la session si non démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
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
        $userInfo = ['first_name' => 'Admin', 'last_name' => 'Demo', 'email' => 'admin@demo.com'];
    }
} catch (Exception $e) {
    $userInfo = ['first_name' => 'Admin', 'last_name' => 'Demo', 'email' => 'admin@demo.com'];
}

// Statistiques Admin avec gestion d'erreur
$stats = [];
try {
    $stats = [
        'total_students' => Database::scalar('SELECT COUNT(*) FROM students') ?? 0,
        'total_teachers' => Database::scalar('SELECT COUNT(*) FROM users WHERE role = "teacher"') ?? 0,
        'total_parents' => Database::scalar('SELECT COUNT(*) FROM users WHERE role = "parent"') ?? 0,
        'total_classes' => Database::scalar('SELECT COUNT(*) FROM classes') ?? 0,
        'total_subjects' => Database::scalar('SELECT COUNT(*) FROM subjects') ?? 0,
        'today_absences' => Database::scalar('SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status = "absent"') ?? 0
    ];
} catch (Exception $e) {
    $stats = [
        'total_students' => 0,
        'total_teachers' => 0,
        'total_parents' => 0,
        'total_classes' => 0,
        'total_subjects' => 0,
        'today_absences' => 0
    ];
}

// Étudiants récents avec gestion d'erreur
$recentStudents = [];
try {
    $recentStudents = Database::fetchAll(
        'SELECT u.first_name, u.last_name, c.name as class_name, st.matricule, st.enrollment_date
         FROM students st 
         JOIN users u ON st.user_id = u.id 
         JOIN classes c ON st.class_id = c.id 
         ORDER BY st.enrollment_date DESC 
         LIMIT 5'
    );
} catch (Exception $e) {
    $recentStudents = [];
}

// Paiements récents avec gestion d'erreur
$recentPayments = [];
try {
    $recentPayments = Database::fetchAll(
        'SELECT u.first_name, u.last_name, f.amount, f.status, f.due_date
         FROM fees f 
         JOIN students st ON f.student_id = st.id 
         JOIN users u ON st.user_id = u.id 
         ORDER BY f.created_at DESC 
         LIMIT 5'
    );
} catch (Exception $e) {
    $recentPayments = [];
}

// Couleurs pour Admin
$primaryColor = '#28a745';
$secondaryColor = '#1e7e34';
$gradientBg = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - SmartSchool Hub</title>
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
        
        .progress {
            height: 10px;
            background-color: #e9ecef;
            border-radius: 5px;
        }
        
        .progress-bar {
            background: var(--primary-color);
            border-radius: 5px;
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
            <div class="user-role">Administrateur</div>
        </div>
        
        <nav class="nav-menu">
            <a href="dashboard_admin_perfect.php" class="nav-item active">
                <i class="fas fa-tachometer-alt"></i>
                Dashboard
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-user-graduate"></i>
                Élèves
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-chalkboard-teacher"></i>
                Enseignants
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-users"></i>
                Parents
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-school"></i>
                Classes
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-book"></i>
                Matières
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-calendar-check"></i>
                Absences
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-money-bill-wave"></i>
                Paiements
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
                <h1 class="page-title">Dashboard Admin</h1>
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
                <p>Gestion quotidienne de l'établissement</p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['total_students']); ?></div>
                    <div class="stat-label">Élèves</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['total_teachers']); ?></div>
                    <div class="stat-label">Enseignants</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['total_parents']); ?></div>
                    <div class="stat-label">Parents</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-school"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['total_classes']); ?></div>
                    <div class="stat-label">Classes</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['total_subjects']); ?></div>
                    <div class="stat-label">Matières</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['today_absences']); ?></div>
                    <div class="stat-label">Absences aujourd'hui</div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">👥 Étudiants récents</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recentStudents)): ?>
                                <?php foreach ($recentStudents as $student): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($student['class_name']); ?> • <?php echo htmlspecialchars($student['matricule']); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo date('d/m/Y', strtotime($student['enrollment_date'])); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Aucun étudiant récent</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">💰 Paiements récents</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recentPayments)): ?>
                                <?php foreach ($recentPayments as $payment): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <strong><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo number_format($payment['amount'], 0, ',', ' '); ?> FCFA</small>
                                        </div>
                                        <div class="text-end">
                                            <?php
                                            $status_class = $payment['status'] == 'paid' ? 'bg-success' : ($payment['status'] == 'pending' ? 'bg-warning' : 'bg-danger');
                                            echo '<span class="badge ' . $status_class . '">' . ucfirst($payment['status']) . '</span><br>';
                                            ?>
                                            <small class="text-muted"><?php echo date('d/m/Y', strtotime($payment['due_date'])); ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Aucun paiement récent</p>
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
