<?php
/**
 * SmartSchool Hub - Dashboard Parent Perfect
 */

define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

// Démarrer la session si non démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'parent') {
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
        $userInfo = ['first_name' => 'Parent', 'last_name' => 'Demo', 'email' => 'parent@demo.com'];
    }
} catch (Exception $e) {
    $userInfo = ['first_name' => 'Parent', 'last_name' => 'Demo', 'email' => 'parent@demo.com'];
}

// Récupérer les enfants du parent avec gestion d'erreur
$children = [];
try {
    $children = Database::fetchAll(
        'SELECT s.id, st.matricule, u.first_name, u.last_name, c.name as class_name, c.level
         FROM parent_student ps 
         JOIN students s ON ps.student_id = s.id 
         JOIN users u ON s.user_id = u.id 
         JOIN classes c ON s.class_id = c.id 
         WHERE ps.parent_id = ?',
        [$_SESSION['user_id']]
    );
} catch (Exception $e) {
    $children = [];
}

// Statistiques du premier enfant avec gestion d'erreur
$childStats = [];
if (!empty($children)) {
    try {
        $firstChild = $children[0];
        $childStats = [
            'total_grades' => Database::scalar('SELECT COUNT(*) FROM grades g JOIN evaluations e ON g.evaluation_id = e.id JOIN students s ON g.student_id = s.id WHERE s.id = ?', [$firstChild['id']]) ?? 0,
            'average_grade' => Database::scalar('SELECT AVG(g.score) FROM grades g JOIN evaluations e ON g.evaluation_id = e.id JOIN students s ON g.student_id = s.id WHERE s.id = ? AND g.is_absent = 0', [$firstChild['id']]) ?? 0,
            'total_absences' => Database::scalar('SELECT COUNT(*) FROM attendance WHERE student_id = ? AND status = "absent"', [$firstChild['id']]) ?? 0,
            'recent_grades' => Database::fetchAll(
                'SELECT s.label, e.label as evaluation_label, g.score, g.entered_at
                 FROM grades g 
                 JOIN evaluations e ON g.evaluation_id = e.id 
                 JOIN subjects s ON e.subject_id = s.id 
                 JOIN students st ON g.student_id = st.id 
                 WHERE st.id = ? 
                 ORDER BY g.entered_at DESC 
                 LIMIT 5',
                [$firstChild['id']]
            ) ?? [],
            'recent_absences' => Database::fetchAll(
                'SELECT date, status, reason, recorded_at
                 FROM attendance 
                 WHERE student_id = ? 
                 ORDER BY date DESC 
                 LIMIT 5',
                [$firstChild['id']]
            ) ?? [],
            'recent_fees' => Database::fetchAll(
                'SELECT amount, due_date, status, payment_date, payment_method
                 FROM fees 
                 WHERE student_id = ? 
                 ORDER BY due_date DESC 
                 LIMIT 5',
                [$firstChild['id']]
            ) ?? []
        ];
    } catch (Exception $e) {
        $childStats = [
            'total_grades' => 0,
            'average_grade' => 0,
            'total_absences' => 0,
            'recent_grades' => [],
            'recent_absences' => [],
            'recent_fees' => []
        ];
    }
}

// Annonces récentes avec gestion d'erreur
$recentAnnouncements = [];
try {
    $recentAnnouncements = Database::fetchAll(
        'SELECT title, content, target_audience, created_at
         FROM announcements 
         WHERE target_audience IN ("all", "parent") 
         ORDER BY created_at DESC 
         LIMIT 3'
    );
} catch (Exception $e) {
    $recentAnnouncements = [];
}

// Messages récents avec gestion d'erreur
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

// Couleurs pour Parent
$primaryColor = '#17a2b8';
$secondaryColor = '#138496';
$gradientBg = 'linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%)';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Parent - SmartSchool Hub</title>
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
        
        .child-selector {
            background: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e9ecef;
        }
        
        .child-selector h6 {
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
                <?php echo strtoupper(substr($userInfo['first_name'], 0, 1) . substr($userInfo['last_name'], 0, 1)); ?>
            </div>
            <div class="user-name"><?php echo htmlspecialchars($userInfo['first_name'] . ' ' . $userInfo['last_name']); ?></div>
            <div class="user-role">Parent</div>
        </div>
        
        <!-- Sélecteur d'enfant -->
        <?php if (count($children) > 1): ?>
        <div class="child-selector">
            <h6>👦 Mes enfants:</h6>
            <select class="form-select form-select-sm" onchange="switchChild(this.value)">
                <?php foreach ($children as $index => $child): ?>
                    <option value="<?php echo $index; ?>" <?php echo $index == 0 ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?> (<?php echo htmlspecialchars($child['class_name']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        
        <nav class="nav-menu">
            <a href="dashboard_parent_perfect.php" class="nav-item active">
                <i class="fas fa-tachometer-alt"></i>
                Dashboard
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-child"></i>
                Mes enfants
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-chart-line"></i>
                Bulletins
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
                <i class="fas fa-clock"></i>
                Emploi du temps
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-book"></i>
                Devoirs
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
                <h1 class="page-title">Dashboard Parent</h1>
                <div class="header-actions">
                    <span class="badge bg-success">En ligne</span>
                    <button class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-bell"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <div class="content-area">
            <?php if (!empty($children)): ?>
            <!-- Informations sur l'enfant sélectionné -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card bg-light">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h5 class="mb-0">👦 <?php echo htmlspecialchars($children[0]['first_name'] . ' ' . $children[0]['last_name']); ?></h5>
                                    <p class="mb-0">
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($children[0]['class_name']); ?></span>
                                        <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($children[0]['matricule']); ?></span>
                                        <span class="badge bg-info ms-2"><?php echo htmlspecialchars($children[0]['level']); ?></span>
                                    </p>
                                </div>
                                <div class="col-md-4 text-end">
                                    <a href="#" class="btn btn-primary">Voir le profil</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistiques principales -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($childStats['total_grades']); ?></div>
                    <div class="stat-label">Notes totales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($childStats['average_grade'], 2, ',', ' '); ?>/20</div>
                    <div class="stat-label">Moyenne générale</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($childStats['total_absences']); ?></div>
                    <div class="stat-label">Total absences</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-number">
                        <?php 
                        $paid_fees = 0;
                        foreach ($childStats['recent_fees'] as $fee) {
                            if ($fee['status'] == 'paid') $paid_fees++;
                        }
                        echo $paid_fees;
                        ?>
                    </div>
                    <div class="stat-label">Paiements effectués</div>
                </div>
            </div>
            
            <!-- Informations détaillées -->
            <div class="row">
                <!-- Notes récentes -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">📊 Notes récentes</h5>
                            <a href="#" class="btn btn-sm btn-primary">Voir toutes les notes</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($childStats['recent_grades'])): ?>
                                <?php foreach ($childStats['recent_grades'] as $grade): ?>
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
                
                <!-- Absences récentes -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">📅 Absences récentes</h5>
                            <a href="#" class="btn btn-sm btn-warning">Voir toutes les absences</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($childStats['recent_absences'])): ?>
                                <?php foreach ($childStats['recent_absences'] as $absence): ?>
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
            </div>
            
            <!-- Paiements et messages -->
            <div class="row">
                <!-- Paiements -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">💰 Paiements</h5>
                            <a href="#" class="btn btn-sm btn-success">Gérer les paiements</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($childStats['recent_fees'])): ?>
                                <?php foreach ($childStats['recent_fees'] as $fee): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <strong><?php echo number_format($fee['amount'], 0, ',', ' '); ?> FCFA</strong><br>
                                            <small class="text-muted">Échéance: <?php echo date('d/m/Y', strtotime($fee['due_date'])); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <?php
                                            $status_class = $fee['status'] == 'paid' ? 'success' : ($fee['status'] == 'pending' ? 'warning' : 'danger');
                                            echo '<span class="badge bg-' . $status_class . '">' . ucfirst($fee['status']) . '</span><br>';
                                            ?>
                                            <?php if ($fee['payment_method']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($fee['payment_method']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Aucun paiement récent</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Messages -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">💬 Messages récents</h5>
                            <a href="#" class="btn btn-sm btn-info">Voir tous les messages</a>
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
            
            <?php else: ?>
            <!-- Aucun enfant -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center">
                            <h4>👦 Aucun enfant associé à votre compte</h4>
                            <p class="text-muted">Veuillez contacter l'administration pour associer vos enfants à votre compte.</p>
                            <a href="mailto:admin@demo.com" class="btn btn-primary">Contacter l'administration</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        }
        
        function switchChild(childIndex) {
            // Rediriger vers le dashboard avec l'index de l'enfant
            window.location.href = 'dashboard_parent_perfect.php?child=' + childIndex;
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
