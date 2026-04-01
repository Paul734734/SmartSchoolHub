<?php
/**
 * SmartSchool Hub — Génération Données Complètes Collège
 * Notes, absences, paiements, discipline, bulletins, santé, etc.
 */

define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

echo "<h2>📊 Génération Données Complètes Collège</h2>";

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Récupérer les données de base
    $students = $pdo->query("SELECT s.id, s.user_id, s.class_id, c.name as class_name, c.level, u.first_name, u.last_name 
                           FROM students s JOIN classes c ON s.class_id = c.id JOIN users u ON s.user_id = u.id")->fetchAll();
    
    $teachers = $pdo->query("SELECT u.id, u.first_name, u.last_name FROM users u WHERE u.role = 'teacher'")->fetchAll();
    $subjects = $pdo->query("SELECT id, label FROM subjects")->fetchAll();
    $trimesters = $pdo->query("SELECT id, label FROM trimesters WHERE academic_year_id = (SELECT id FROM academic_years WHERE is_current = 1)")->fetchAll();
    
    echo "<h3>📝 Génération des Notes et Évaluations</h3>";
    
    foreach ($trimesters as $trimester) {
        echo "<h4>📊 Trimestre {$trimester['name']}</h4>";
        
        // Créer 3-4 évaluations par matière par trimestre
        foreach ($students as $student) {
            $class_subjects = $pdo->query("SELECT cs.id, cs.subject_id, s.label as subject_name 
                                          FROM class_subjects cs JOIN subjects s ON cs.subject_id = s.id 
                                          WHERE cs.class_id = {$student['class_id']}")->fetchAll();
            
            foreach ($class_subjects as $cs) {
                // Créer 3-4 évaluations par matière
                $eval_count = rand(3, 4);
                for ($i = 1; $i <= $eval_count; $i++) {
                    $eval_name = match($i) {
                        1 => "Devoir",
                        2 => "Composition",
                        3 => "Interrogation",
                        4 => "Projet"
                    };
                    
                    // Vérifier si l'évaluation existe déjà
                    $existing_eval = $pdo->query("SELECT id FROM evaluations 
                                               WHERE class_id = {$student['class_id']} AND subject_id = {$cs['subject_id']} 
                                               AND trimester_id = {$trimester['id']} AND label LIKE '%$eval_name%'")->fetch();
                    
                    if (!$existing_eval) {
                        $max_score = rand(15, 20);
                        $pdo->exec("INSERT INTO evaluations (class_id, subject_id, trimester_id, label, max_score, date) VALUES 
                        ({$student['class_id']}, {$cs['subject_id']}, {$trimester['id']}, '$eval_name $i', $max_score, '2024-" . str_pad(rand(9, 12), 2, '0', STR_PAD_LEFT) . "-" . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT) . "')");
                        $eval_id = $pdo->lastInsertId();
                    } else {
                        $eval_id = $existing_eval['id'];
                    }
                    
                    // Générer une note aléatoire réaliste
                    $score = rand(8, 20);
                    if ($score > 18) $score = rand(16, 20); // Notes excellentes plus rares
                    
                    // Vérifier si la note existe déjà
                    $existing_grade = $pdo->query("SELECT id FROM grades 
                                                  WHERE evaluation_id = $eval_id AND student_id = {$student['id']}")->fetch();
                    
                    if (!$existing_grade) {
                        $pdo->exec("INSERT INTO grades (evaluation_id, student_id, score, is_absent) VALUES 
                        ($eval_id, {$student['id']}, $score, 0)");
                    }
                }
            }
        }
    }
    echo "<div style='color:green;'>✅ Notes et évaluations générées pour tous les élèves</div>";
    
    echo "<h3>📅 Génération des Absences</h3>";
    foreach ($students as $student) {
        // 5-15 absences par élève par trimestre
        $absence_count = rand(5, 15);
        for ($i = 0; $i < $absence_count; $i++) {
            $date = '2024-' . str_pad(rand(9, 12), 2, '0', STR_PAD_LEFT) . '-' . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);
            $status = rand(0, 1) ? 'absent' : 'late';
            $reason = match(rand(1, 5)) {
                1 => 'Maladie',
                2 => 'Raison familiale',
                3 => 'Retard transport',
                4 => 'Consultation médicale',
                5 => 'Autre'
            };
            
            $pdo->exec("INSERT INTO attendance (student_id, date, status, reason, recorded_by) VALUES 
            ({$student['id']}, '$date', '$status', '$reason', " . $teachers[array_rand($teachers)]['id'] . ")");
        }
    }
    echo "<div style='color:green;'>✅ Absences générées pour tous les élèves</div>";
    
    echo "<h3>💰 Génération des Paiements de Scolarité</h3>";
    $fee_amounts = [
        '6ème' => 150000,
        '5ème' => 160000,
        '4ème' => 170000,
        '3ème' => 180000,
        'Spécial' => 200000
    ];
    
    foreach ($students as $student) {
        $level = $student['level'];
        $annual_fee = $fee_amounts[$level] ?? 150000;
        
        // 3 tranches par année
        $installments = [
            ['1ère Tranche', $annual_fee * 0.4, '2024-09-15'],
            ['2ème Tranche', $annual_fee * 0.3, '2024-12-15'],
            ['3ème Tranche', $annual_fee * 0.3, '2025-03-15']
        ];
        
        foreach ($installments as $installment) {
            $paid = rand(0, 1);
            $payment_method = $paid ? match(rand(1, 4)) {
                1 => 'orange_money',
                2 => 'mtn_momo',
                3 => 'cash',
                4 => 'bank'
            } : null;
            
            $pdo->exec("INSERT INTO fees (student_id, amount, due_date, status, payment_method, payment_date) VALUES 
            ({$student['id']}, {$installment[1]}, '{$installment[2]}', '" . ($paid ? 'paid' : 'pending') . "', " . 
            ($payment_method ? "'$payment_method'" : 'NULL') . ", " . ($paid ? "'2024-" . str_pad(rand(9, 12), 2, '0', STR_PAD_LEFT) . "-" . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT) . "'" : 'NULL') . ")");
        }
    }
    echo "<div style='color:green;'>✅ Paiements générés pour tous les élèves</div>";
    
    echo "<h3>🏥 Génération Données Santé</h3>";
    foreach ($students as $student) {
        $health_info = [
            'blood_type' => match(rand(1, 8)) { 1 => 'A+', 2 => 'A-', 3 => 'B+', 4 => 'B-', 5 => 'O+', 6 => 'O-', 7 => 'AB+', 8 => 'AB-' },
            'allergies' => rand(0, 1) ? match(rand(1, 5)) { 1 => 'Arachides', 2 => 'Pollen', 3 => 'Poussière', 4 => 'Médicaments', 5 => 'Aucune' } : 'Aucune',
            'medical_conditions' => rand(0, 1) ? match(rand(1, 4)) { 1 => 'Asthme', 2 => 'Diabète', 3 => 'Épilepsie', 4 => 'Aucune' } : 'Aucune',
            'emergency_contact' => '+237 6' . rand(90, 99) . ' ' . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'doctor_name' => 'Dr. ' . $teachers[array_rand($teachers)]['first_name'] . ' ' . $teachers[array_rand($teachers)]['last_name'],
            'last_checkup' => '2024-' . str_pad(rand(1, 8), 2, '0', STR_PAD_LEFT) . '-' . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT)
        ];
        
        $pdo->exec("INSERT INTO student_health (student_id, blood_type, allergies, medical_conditions, emergency_contact, doctor_name, last_checkup) VALUES 
        ({$student['id']}, '{$health_info['blood_type']}', '{$health_info['allergies']}', '{$health_info['medical_conditions']}', '{$health_info['emergency_contact']}', '{$health_info['doctor_name']}', '{$health_info['last_checkup']}')");
    }
    echo "<div style='color:green;'>✅ Données santé générées pour tous les élèves</div>";
    
    echo "<h3>⚖️ Génération Discipline et Comportement</h3>";
    foreach ($students as $student) {
        // 20% des élèves ont des incidents disciplinaires
        if (rand(1, 100) <= 20) {
            $incident_count = rand(1, 3);
            for ($i = 0; $i < $incident_count; $i++) {
                $incident_type = match(rand(1, 6)) {
                    1 => 'Retard répété',
                    2 => 'Non-respect du règlement',
                    3 => 'Bruit en classe',
                    4 => 'Absence injustifiée',
                    5 => 'Travail non fait',
                    6 => 'Trouble au comportement'
                };
                $severity = match(rand(1, 3)) {
                    1 => 'mineur',
                    2 => 'moyen',
                    3 => 'grave'
                };
                $action_taken = match($severity) {
                    'mineur' => 'Avertissement verbal',
                    'moyen' => 'Avertissement écrit',
                    'grave' => 'Convocation parents'
                };
                
                $pdo->exec("INSERT INTO discipline (student_id, date, incident_type, description, severity, action_taken, reported_by) VALUES 
                ({$student['id']}, '2024-" . str_pad(rand(9, 12), 2, '0', STR_PAD_LEFT) . "-" . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT) . "', '$incident_type', 'Description de l\'incident', '$severity', '$action_taken', " . $teachers[array_rand($teachers)]['id'] . ")");
            }
        }
        
        // 30% des élèves ont des commendations
        if (rand(1, 100) <= 30) {
            $commendation_count = rand(1, 2);
            for ($i = 0; $i < $commendation_count; $i++) {
                $commendation_type = match(rand(1, 4)) {
                    1 => 'Excellente participation',
                    2 => 'Aide aux camarades',
                    3 => 'Projet remarquable',
                    4 => 'Comportement exemplaire'
                };
                
                $pdo->exec("INSERT INTO discipline (student_id, date, incident_type, description, severity, action_taken, reported_by) VALUES 
                ({$student['id']}, '2024-" . str_pad(rand(9, 12), 2, '0', STR_PAD_LEFT) . "-" . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT) . "', '$commendation_type', 'Description de la performance positive', 'commendation', 'Félicitations', " . $teachers[array_rand($teachers)]['id'] . ")");
            }
        }
    }
    echo "<div style='color:green;'>✅ Données discipline générées</div>";
    
    echo "<h3>📢 Génération Annonces et Communications</h3>";
    
    // Annonces générales
    $announcements = [
        ['Réunion parents-professeurs', 'Une réunion parents-professeurs est prévue le 15 décembre à 14h.', 'all'],
        ['Vacances de Noël', 'Les vacances de commenceront le 20 décembre et se termineront le 5 janvier.', 'all'],
        ['Concours de mathématiques', 'Concours inter-établissements de mathématiques le 10 janvier.', 'student'],
        ['Journée portes ouvertes', 'Journée portes ouvertes le 25 janvier de 9h à 16h.', 'all'],
        ['Inscription aux cours de soutien', 'Les inscriptions pour les cours de soutien sont ouvertes.', 'parent']
    ];
    
    foreach ($announcements as $announcement) {
        $pdo->exec("INSERT INTO announcements (title, content, target_audience, author_id, created_at) VALUES 
        ('{$announcement[0]}', '{$announcement[1]}', '{$announcement[2]}', " . $teachers[array_rand($teachers)]['id'] . ", '2024-" . str_pad(rand(11, 12), 2, '0', STR_PAD_LEFT) . "-" . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT) . "')");
    }
    echo "<div style='color:green;'>✅ Annonces générées</div>";
    
    // Messages entre utilisateurs
    echo "<h3>💬 Génération Messages</h3>";
    for ($i = 0; $i < 100; $i++) {
        $sender = $teachers[array_rand($teachers)];
        $parent = $pdo->query("SELECT u.id, u.first_name, u.last_name FROM users u WHERE u.role = 'parent' ORDER BY RAND() LIMIT 1")->fetch();
        $student = $students[array_rand($students)];
        
        $message_types = [
            'Rappel: Le devoir de mathématiques est à rendre pour demain.',
            'Votre enfant a bien travaillé ce trimestre.',
            'Merci de signer le cahier de correspondance.',
            'Votre enfant était absent aujourd\'hui. Merci de nous informer.',
            'Réunion prévue la semaine prochaine pour discuter du progrès de votre enfant.'
        ];
        
        $message = $message_types[array_rand($message_types)];
        
        $pdo->exec("INSERT INTO messages (sender_id, receiver_id, subject, content, created_at) VALUES 
        ({$sender['id']}, {$parent['id']}, 'Information concernant votre enfant', '$message', '2024-" . str_pad(rand(11, 12), 2, '0', STR_PAD_LEFT) . "-" . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT) . "')");
    }
    echo "<div style='color:green;'>✅ Messages générés</div>";
    
    echo "<h3>🤖 Génération Alertes IA</h3>";
    foreach ($students as $student) {
        // 25% des élèves déclenchent des alertes IA
        if (rand(1, 100) <= 25) {
            $risk_factors = [];
            $risk_score = 0;
            
            // Calculer le score de risque basé sur absences et notes
            $absences = $pdo->query("SELECT COUNT(*) as count FROM attendance WHERE student_id = {$student['id']} AND status = 'absent'")->fetch()['count'];
            $avg_grade = $pdo->query("SELECT AVG(g.score) as avg FROM grades g JOIN evaluations e ON g.evaluation_id = e.id WHERE g.student_id = {$student['id']} AND g.is_absent = 0")->fetch()['avg'] ?? 10;
            
            if ($absences > 10) {
                $risk_score += 30;
                $risk_factors[] = 'Taux d\'absence élevé';
            }
            if ($avg_grade < 10) {
                $risk_score += 25;
                $risk_factors[] = 'Moyenne faible';
            }
            
            if ($risk_score > 0) {
                $risk_level = $risk_score > 40 ? 'high' : ($risk_score > 20 ? 'medium' : 'low');
                $recommendations = match($risk_level) {
                    'high' => 'Tutorat individuel, suivi psychologique, rencontre parents',
                    'medium' => 'Soutien scolaire, monitoring accru',
                    'low' => 'Encouragement, suivi régulier'
                };
                
                $pdo->exec("INSERT INTO ai_alerts (student_id, risk_score, risk_factors, recommendations, created_at) VALUES 
                ({$student['id']}, $risk_score, '" . implode(', ', $risk_factors) . "', '$recommendations', '2024-" . str_pad(rand(11, 12), 2, '0', STR_PAD_LEFT) . "-" . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT) . "')");
            }
        }
    }
    echo "<div style='color:green;'>✅ Alertes IA générées</div>";
    
    echo "<h3>📋 Génération Emploi du Temps</h3>";
    $time_slots = [
        ['08:00', '09:30'],
        ['09:45', '11:15'],
        ['11:30', '13:00'],
        ['14:00', '15:30'],
        ['15:45', '17:15']
    ];
    
    $rooms = ['Salle A1', 'Salle A2', 'Salle B1', 'Salle B2', 'Labo Physique', 'Labo Chimie', 'Salle Informatique', 'Salle Dessin', 'Salle Musique', 'Terrain de Sport'];
    
    foreach ($class_ids as $class_name => $class_id) {
        $class_subjects = $pdo->query("SELECT cs.subject_id, s.name as subject_name 
                                      FROM class_subjects cs JOIN subjects s ON cs.subject_id = s.id 
                                      WHERE cs.class_id = $class_id")->fetchAll();
        
        for ($day = 1; $day <= 5; $day++) { // Lundi à Vendredi
            foreach ($time_slots as $slot_index => $time_slot) {
                if (count($class_subjects) > 0) {
                    $subject = $class_subjects[array_rand($class_subjects)];
                    $teacher_id = $pdo->query("SELECT teacher_id FROM teacher_subjects WHERE subject_id = {$subject['subject_id']} LIMIT 1")->fetch()['teacher_id'] ?? $teachers[array_rand($teachers)]['id'];
                    $room = $rooms[array_rand($rooms)];
                    
                    // Vérifier si le créneau existe déjà
                    $existing = $pdo->query("SELECT id FROM timetable 
                                           WHERE class_id = $class_id AND day_of_week = $day AND start_time = '{$time_slot[0]}'")->fetch();
                    
                    if (!$existing) {
                        $pdo->exec("INSERT INTO timetable (class_id, subject_id, teacher_id, room, day_of_week, start_time, end_time) VALUES 
                        ($class_id, {$subject['subject_id']}, $teacher_id, '$room', $day, '{$time_slot[0]}', '{$time_slot[1]}')");
                    }
                }
            }
        }
    }
    echo "<div style='color:green;'>✅ Emploi du temps généré pour toutes les classes</div>";
    
    echo "<h3>🎯 Génération Statistiques Fin de Trimestre</h3>";
    foreach ($trimesters as $trimester) {
        echo "<h4>📊 Statistiques {$trimester['name']}</h4>";
        
        // Calculer les moyennes générales par classe
        foreach ($class_ids as $class_name => $class_id) {
            $class_students = $pdo->query("SELECT s.id FROM students s WHERE s.class_id = $class_id")->fetchAll();
            
            foreach ($class_students as $student) {
                $avg_grade = $pdo->query("SELECT AVG(g.score) as avg FROM grades g 
                                         JOIN evaluations e ON g.evaluation_id = e.id 
                                         WHERE g.student_id = {$student['id']} AND e.trimester_id = {$trimester['id']} AND g.is_absent = 0")->fetch()['avg'] ?? 0;
                
                if ($avg_grade > 0) {
                    $rank = $pdo->query("SELECT COUNT(*) + 1 as rank FROM students s 
                                       JOIN grades g ON s.id = g.student_id 
                                       JOIN evaluations e ON g.evaluation_id = e.id 
                                       WHERE s.class_id = $class_id AND e.trimester_id = {$trimester['id']} AND g.is_absent = 0 
                                       GROUP BY s.id HAVING AVG(g.score) > $avg_grade")->fetch()['rank'] ?? 1;
                    
                    // Insérer la moyenne trimestrielle
                    $pdo->exec("INSERT INTO trimester_averages (student_id, trimester_id, average, rank) VALUES 
                    ({$student['id']}, {$trimester['id']}, $avg_grade, $rank) 
                    ON DUPLICATE KEY UPDATE average = $avg_grade, rank = $rank");
                }
            }
        }
    }
    echo "<div style='color:green;'>✅ Statistiques trimestrielles calculées</div>";
    
    echo "<h3>🎉 Génération Données Complète Terminée !</h3>";
    echo "<div style='background:#d4edda;padding:20px;border-radius:8px;border-left:4px solid #28a745;'>";
    echo "<h4 style='color:#155724;margin-top:0;'>📊 Récapitulatif des Données Générées</h4>";
    echo "<ul style='color:#155724;'>";
    echo "<li><strong>Notes et Évaluations:</strong> " . $pdo->query("SELECT COUNT(*) as count FROM grades")->fetch()['count'] . " notes générées</li>";
    echo "<li><strong>Absences:</strong> " . $pdo->query("SELECT COUNT(*) as count FROM attendance")->fetch()['count'] . " enregistrements</li>";
    echo "<li><strong>Paiements:</strong> " . $pdo->query("SELECT COUNT(*) as count FROM fees")->fetch()['count'] . " échéances</li>";
    echo "<li><strong>Dossiers Santé:</strong> " . $pdo->query("SELECT COUNT(*) as count FROM student_health")->fetch()['count'] . " dossiers</li>";
    echo "<li><strong>Discipline:</strong> " . $pdo->query("SELECT COUNT(*) as count FROM discipline")->fetch()['count'] . " incidents</li>";
    echo "<li><strong>Annonces:</strong> " . $pdo->query("SELECT COUNT(*) as count FROM announcements")->fetch()['count'] . " annonces</li>";
    echo "<li><strong>Messages:</strong> " . $pdo->query("SELECT COUNT(*) as count FROM messages")->fetch()['count'] . " messages</li>";
    echo "<li><strong>Alertes IA:</strong> " . $pdo->query("SELECT COUNT(*) as count FROM ai_alerts")->fetch()['count'] . " alertes</li>";
    echo "<li><strong>Emploi du Temps:</strong> " . $pdo->query("SELECT COUNT(*) as count FROM timetable")->fetch()['count'] . " créneaux</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div style='background:#007bff;color:white;padding:20px;border-radius:8px;margin-top:30px;text-align:center;'>";
    echo "<h3 style='margin-top:0;'>🎓 Collège Les Étoiles du Savoir - PRÊT !</h3>";
    echo "<p><strong>500 élèves, 150 enseignants, 400 parents, 20 administrateurs</strong></p>";
    echo "<p><strong>Toutes les fonctionnalités sont activées avec des données réalistes</strong></p>";
    echo "<p><a href='http://localhost/SmartSchoolHub/login.php' style='background:white;color:#007bff;padding:10px 20px;text-decoration:none;border-radius:5px;font-weight:bold;'>🚀 ACCÉDER AU COLLÈGE</a></p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='color:red;'>❌ Erreur: " . $e->getMessage() . "</div>";
}
?>
