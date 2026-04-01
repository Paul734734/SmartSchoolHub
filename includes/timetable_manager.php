<?php
/**
 * SmartSchool Hub — Gestion avancée de l'emploi du temps
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class TimetableManager
{
    /**
     * Détecter les conflits dans l'emploi du temps
     */
    public static function detectConflicts(int $academicYearId): array
    {
        $conflicts = [];
        
        // Conflits enseignant (un enseignant ne peut être dans 2 classes en même temps)
        $teacherConflicts = Database::fetchAll(
            'SELECT t1.day_of_week, t1.start_time, t1.end_time,
                    cs1.class_id, c1.name as class1_name,
                    cs2.class_id, c2.name as class2_name,
                    u.first_name, u.last_name,
                    sub1.name as subject1_name, sub2.name as subject2_name
             FROM timetable t1
             JOIN timetable t2 ON (
                 t1.day_of_week = t2.day_of_week AND
                 t1.start_time < t2.end_time AND
                 t1.end_time > t2.start_time AND
                 t1.id < t2.id
             )
             JOIN class_subjects cs1 ON cs1.id = t1.class_subject_id
             JOIN class_subjects cs2 ON cs2.id = t2.class_subject_id
             JOIN classes c1 ON c1.id = cs1.class_id
             JOIN classes c2 ON c2.id = cs2.class_id
             JOIN subjects sub1 ON sub1.id = cs1.subject_id
             JOIN subjects sub2 ON sub2.id = cs2.subject_id
             JOIN users u ON u.id = cs1.teacher_id
             WHERE t1.academic_year_id = ? AND cs1.teacher_id = cs2.teacher_id
             ORDER BY t1.day_of_week, t1.start_time',
            [$academicYearId]
        );
        
        foreach ($teacherConflicts as $conflict) {
            $conflicts[] = [
                'type' => 'teacher_conflict',
                'severity' => 'high',
                'message' => "Conflit enseignant: {$conflict['first_name']} {$conflict['last_name']} est prévu dans {$conflict['class1_name']} et {$conflict['class2_name']} en même temps",
                'details' => $conflict
            ];
        }
        
        // Conflits salle (une salle ne peut être utilisée par 2 classes en même temps)
        $roomConflicts = Database::fetchAll(
            'SELECT t1.day_of_week, t1.start_time, t1.end_time,
                    c1.name as class1_name, c2.name as class2_name,
                    t1.room, sub1.name as subject1_name, sub2.name as subject2_name
             FROM timetable t1
             JOIN timetable t2 ON (
                 t1.day_of_week = t2.day_of_week AND
                 t1.start_time < t2.end_time AND
                 t1.end_time > t2.start_time AND
                 t1.id < t2.id AND
                 t1.room IS NOT NULL AND t1.room = t2.room
             )
             JOIN class_subjects cs1 ON cs1.id = t1.class_subject_id
             JOIN class_subjects cs2 ON cs2.id = t2.class_subject_id
             JOIN classes c1 ON c1.id = cs1.class_id
             JOIN classes c2 ON c2.id = cs2.class_id
             JOIN subjects sub1 ON sub1.id = cs1.subject_id
             JOIN subjects sub2 ON sub2.id = cs2.subject_id
             WHERE t1.academic_year_id = ?
             ORDER BY t1.day_of_week, t1.start_time',
            [$academicYearId]
        );
        
        foreach ($roomConflicts as $conflict) {
            $conflicts[] = [
                'type' => 'room_conflict',
                'severity' => 'medium',
                'message' => "Conflit salle: {$conflict['room']} utilisée par {$conflict['class1_name']} et {$conflict['class2_name']} en même temps",
                'details' => $conflict
            ];
        }
        
        // Conflits classe (une classe ne peut avoir 2 cours en même temps)
        $classConflicts = Database::fetchAll(
            'SELECT t1.day_of_week, t1.start_time, t1.end_time,
                    c.name as class_name,
                    sub1.name as subject1_name, sub2.name as subject2_name,
                    u1.first_name as teacher1_first, u1.last_name as teacher1_last,
                    u2.first_name as teacher2_first, u2.last_name as teacher2_last
             FROM timetable t1
             JOIN timetable t2 ON (
                 t1.day_of_week = t2.day_of_week AND
                 t1.start_time < t2.end_time AND
                 t1.end_time > t2.start_time AND
                 t1.id < t2.id
             )
             JOIN class_subjects cs1 ON cs1.id = t1.class_subject_id
             JOIN class_subjects cs2 ON cs2.id = t2.class_subject_id
             JOIN classes c ON c.id = cs1.class_id
             JOIN subjects sub1 ON sub1.id = cs1.subject_id
             JOIN subjects sub2 ON sub2.id = cs2.subject_id
             JOIN users u1 ON u1.id = cs1.teacher_id
             JOIN users u2 ON u2.id = cs2.teacher_id
             WHERE t1.academic_year_id = ? AND cs1.class_id = cs2.class_id
             ORDER BY t1.day_of_week, t1.start_time',
            [$academicYearId]
        );
        
        foreach ($classConflicts as $conflict) {
            $conflicts[] = [
                'type' => 'class_conflict',
                'severity' => 'high',
                'message' => "Conflit classe: {$conflict['class_name']} a {$conflict['subject1_name']} et {$conflict['subject2_name']} en même temps",
                'details' => $conflict
            ];
        }
        
        return $conflicts;
    }
    
    /**
     * Générer automatiquement un emploi du temps
     */
    public static function generateTimetable(int $classId, array $subjects): array
    {
        $generatedSlots = [];
        $errors = [];
        
        // Récupérer les créneaux disponibles
        $timeSlots = self::getAvailableTimeSlots();
        
        // Récupérer l'emploi du temps existant pour éviter les conflits
        $existingSlots = self::getExistingSlots($classId);
        
        foreach ($subjects as $subject) {
            $subjectId = $subject['subject_id'];
            $teacherId = $subject['teacher_id'];
            $weeklyHours = (int)($subject['weekly_hours'] ?? 2);
            
            // Calculer combien de créneaux sont nécessaires
            $slotsNeeded = ceil($weeklyHours / 1); // 1h par créneau
            
            for ($i = 0; $i < $slotsNeeded; $i++) {
                $slot = self::findAvailableSlot($timeSlots, $existingSlots, $teacherId);
                
                if (!$slot) {
                    $errors[] = "Impossible de trouver un créneau pour le sujet ID: {$subjectId}";
                    continue;
                }
                
                // Insérer le créneau
                try {
                    $timetableId = Database::insert(
                        'INSERT INTO timetable (academic_year_id, class_subject_id, day_of_week, start_time, end_time, room)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [
                            getCurrentYear()['id'],
                            $subject['class_subject_id'],
                            $slot['day_of_week'],
                            $slot['start_time'],
                            $slot['end_time'],
                            $slot['room']
                        ]
                    );
                    
                    $generatedSlots[] = [
                        'id' => $timetableId,
                        'day_of_week' => $slot['day_of_week'],
                        'start_time' => $slot['start_time'],
                        'end_time' => $slot['end_time'],
                        'room' => $slot['room'],
                        'subject_name' => $subject['subject_name'],
                        'teacher_name' => $subject['teacher_name']
                    ];
                    
                    // Ajouter à l'existant pour éviter les conflits dans les prochains créneaux
                    $existingSlots[] = [
                        'day_of_week' => $slot['day_of_week'],
                        'start_time' => $slot['start_time'],
                        'end_time' => $slot['end_time'],
                        'teacher_id' => $teacherId
                    ];
                    
                } catch (Exception $e) {
                    $errors[] = "Erreur lors de l'insertion du créneau: " . $e->getMessage();
                }
            }
        }
        
        return [
            'success' => empty($errors),
            'generated_slots' => $generatedSlots,
            'errors' => $errors
        ];
    }
    
    /**
     * Obtenir les créneaux horaires disponibles
     */
    private static function getAvailableTimeSlots(): array
    {
        return [
            ['day_of_week' => 1, 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'room' => 'Salle A1'],
            ['day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'room' => 'Salle A1'],
            ['day_of_week' => 1, 'start_time' => '10:15:00', 'end_time' => '11:15:00', 'room' => 'Salle A1'],
            ['day_of_week' => 1, 'start_time' => '11:15:00', 'end_time' => '12:15:00', 'room' => 'Salle A1'],
            ['day_of_week' => 1, 'start_time' => '13:30:00', 'end_time' => '14:30:00', 'room' => 'Salle A1'],
            ['day_of_week' => 1, 'start_time' => '14:30:00', 'end_time' => '15:30:00', 'room' => 'Salle A1'],
            ['day_of_week' => 1, 'start_time' => '15:45:00', 'end_time' => '16:45:00', 'room' => 'Salle A1'],
            
            // Répéter pour chaque jour de la semaine
            ['day_of_week' => 2, 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'room' => 'Salle A1'],
            ['day_of_week' => 2, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'room' => 'Salle A1'],
            ['day_of_week' => 2, 'start_time' => '10:15:00', 'end_time' => '11:15:00', 'room' => 'Salle A1'],
            ['day_of_week' => 2, 'start_time' => '11:15:00', 'end_time' => '12:15:00', 'room' => 'Salle A1'],
            ['day_of_week' => 2, 'start_time' => '13:30:00', 'end_time' => '14:30:00', 'room' => 'Salle A1'],
            ['day_of_week' => 2, 'start_time' => '14:30:00', 'end_time' => '15:30:00', 'room' => 'Salle A1'],
            ['day_of_week' => 2, 'start_time' => '15:45:00', 'end_time' => '16:45:00', 'room' => 'Salle A1'],
            
            ['day_of_week' => 3, 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'room' => 'Salle A1'],
            ['day_of_week' => 3, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'room' => 'Salle A1'],
            ['day_of_week' => 3, 'start_time' => '10:15:00', 'end_time' => '11:15:00', 'room' => 'Salle A1'],
            ['day_of_week' => 3, 'start_time' => '11:15:00', 'end_time' => '12:15:00', 'room' => 'Salle A1'],
            ['day_of_week' => 3, 'start_time' => '13:30:00', 'end_time' => '14:30:00', 'room' => 'Salle A1'],
            ['day_of_week' => 3, 'start_time' => '14:30:00', 'end_time' => '15:30:00', 'room' => 'Salle A1'],
            ['day_of_week' => 3, 'start_time' => '15:45:00', 'end_time' => '16:45:00', 'room' => 'Salle A1'],
            
            ['day_of_week' => 4, 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'room' => 'Salle A1'],
            ['day_of_week' => 4, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'room' => 'Salle A1'],
            ['day_of_week' => 4, 'start_time' => '10:15:00', 'end_time' => '11:15:00', 'room' => 'Salle A1'],
            ['day_of_week' => 4, 'start_time' => '11:15:00', 'end_time' => '12:15:00', 'room' => 'Salle A1'],
            ['day_of_week' => 4, 'start_time' => '13:30:00', 'end_time' => '14:30:00', 'room' => 'Salle A1'],
            ['day_of_week' => 4, 'start_time' => '14:30:00', 'end_time' => '15:30:00', 'room' => 'Salle A1'],
            ['day_of_week' => 4, 'start_time' => '15:45:00', 'end_time' => '16:45:00', 'room' => 'Salle A1'],
            
            ['day_of_week' => 5, 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'room' => 'Salle A1'],
            ['day_of_week' => 5, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'room' => 'Salle A1'],
            ['day_of_week' => 5, 'start_time' => '10:15:00', 'end_time' => '11:15:00', 'room' => 'Salle A1'],
            ['day_of_week' => 5, 'start_time' => '11:15:00', 'end_time' => '12:15:00', 'room' => 'Salle A1'],
            ['day_of_week' => 5, 'start_time' => '13:30:00', 'end_time' => '14:30:00', 'room' => 'Salle A1'],
            ['day_of_week' => 5, 'start_time' => '14:30:00', 'end_time' => '15:30:00', 'room' => 'Salle A1'],
            ['day_of_week' => 5, 'start_time' => '15:45:00', 'end_time' => '16:45:00', 'room' => 'Salle A1'],
        ];
    }
    
    /**
     * Obtenir les créneaux existants pour une classe
     */
    private static function getExistingSlots(int $classId): array
    {
        return Database::fetchAll(
            'SELECT t.day_of_week, t.start_time, t.end_time, cs.teacher_id
             FROM timetable t
             JOIN class_subjects cs ON cs.id = t.class_subject_id
             WHERE cs.class_id = ? AND t.academic_year_id = ?',
            [$classId, getCurrentYear()['id']]
        );
    }
    
    /**
     * Trouver un créneau disponible
     */
    private static function findAvailableSlot(array $availableSlots, array $existingSlots, int $teacherId): ?array
    {
        foreach ($availableSlots as $slot) {
            $isAvailable = true;
            
            // Vérifier les conflits avec les créneaux existants
            foreach ($existingSlots as $existing) {
                if ($existing['day_of_week'] === $slot['day_of_week'] &&
                    $slot['start_time'] < $existing['end_time'] &&
                    $slot['end_time'] > $existing['start_time']) {
                    
                    // Conflit détecté
                    if ($existing['teacher_id'] === $teacherId) {
                        $isAvailable = false;
                        break;
                    }
                }
            }
            
            if ($isAvailable) {
                return $slot;
            }
        }
        
        return null;
    }
    
    /**
     * Optimiser l'emploi du temps existant
     */
    public static function optimizeTimetable(int $academicYearId): array
    {
        $optimizations = [];
        
        // Analyser la répartition des cours
        $distribution = Database::fetchAll(
            'SELECT cs.class_id, c.name as class_name,
                    COUNT(*) as total_hours,
                    COUNT(CASE WHEN t.day_of_week IN (1,2,3,4,5) THEN 1 END) as weekday_hours,
                    COUNT(CASE WHEN t.day_of_week IN (6,7) THEN 1 END) as weekend_hours
             FROM timetable t
             JOIN class_subjects cs ON cs.id = t.class_subject_id
             JOIN classes c ON c.id = cs.class_id
             WHERE t.academic_year_id = ?
             GROUP BY cs.class_id
             ORDER BY total_hours DESC',
            [$academicYearId]
        );
        
        foreach ($distribution as $dist) {
            if ($dist['weekend_hours'] > 0) {
                $optimizations[] = [
                    'type' => 'weekend_usage',
                    'severity' => 'low',
                    'message' => "La classe {$dist['class_name']} a {$dist['weekend_hours']} heures le week-end",
                    'suggestion' => 'Considérer déplacer ces cours vers la semaine si possible'
                ];
            }
            
            if ($dist['total_hours'] > 35) { // Plus de 35h/semaine
                $optimizations[] = [
                    'type' => 'overload',
                    'severity' => 'medium',
                    'message' => "La classe {$dist['class_name']} a {$dist['total_hours']} heures/semaine (charge élevée)",
                    'suggestion' => 'Vérifier si cette charge est appropriée'
                ];
            }
        }
        
        return $optimizations;
    }
    
    /**
     * Exporter l'emploi du temps en format iCal
     */
    public static function exportToICal(int $classId, int $academicYearId): string
    {
        $schedule = Database::fetchAll(
            'SELECT t.day_of_week, t.start_time, t.end_time, t.room,
                    c.name as class_name, sub.name as subject_name,
                    u.first_name, u.last_name
             FROM timetable t
             JOIN class_subjects cs ON cs.id = t.class_subject_id
             JOIN classes c ON c.id = cs.class_id
             JOIN subjects sub ON sub.id = cs.subject_id
             JOIN users u ON u.id = cs.teacher_id
             WHERE cs.class_id = ? AND t.academic_year_id = ?
             ORDER BY t.day_of_week, t.start_time',
            [$classId, $academicYearId]
        );
        
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//SmartSchool Hub//Timetable//EN\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        
        $startDate = new DateTime('2024-09-01'); // Début de l'année scolaire
        $endDate = new DateTime('2025-07-31');   // Fin de l'année scolaire
        
        foreach ($schedule as $slot) {
            // Générer les occurrences pour chaque semaine
            $current = clone $startDate;
            while ($current <= $endDate) {
                if ($current->format('N') == $slot['day_of_week']) {
                    $startTime = clone $current;
                    $startTime->setTime(
                        (int)substr($slot['start_time'], 0, 2),
                        (int)substr($slot['start_time'], 3, 2)
                    );
                    
                    $endTime = clone $current;
                    $endTime->setTime(
                        (int)substr($slot['end_time'], 0, 2),
                        (int)substr($slot['end_time'], 3, 2)
                    );
                    
                    $uid = md5($slot['class_name'] . $slot['subject_name'] . $startTime->format('Y-m-d H:i'));
                    
                    $ical .= "BEGIN:VEVENT\r\n";
                    $ical .= "UID:{$uid}@smartschoolhub.com\r\n";
                    $ical .= "DTSTART:{$startTime->format('Ymd\THis\Z')}\r\n";
                    $ical .= "DTEND:{$endTime->format('Ymd\THis\Z')}\r\n";
                    $ical .= "SUMMARY:{$slot['subject_name']} - {$slot['class_name']}\r\n";
                    $ical .= "DESCRIPTION:Cours de {$slot['subject_name']} avec {$slot['first_name']} {$slot['last_name']}\r\n";
                    $ical .= "LOCATION:{$slot['room']}\r\n";
                    $ical .= "END:VEVENT\r\n";
                }
                $current->add(new DateInterval('P1D'));
            }
        }
        
        $ical .= "END:VCALENDAR\r\n";
        
        return $ical;
    }
    
    /**
     * Obtenir les statistiques de l'emploi du temps
     */
    public static function getStatistics(int $academicYearId): array
    {
        return [
            'total_slots' => Database::scalar(
                'SELECT COUNT(*) FROM timetable WHERE academic_year_id = ?',
                [$academicYearId]
            ),
            'total_classes' => Database::scalar(
                'SELECT COUNT(DISTINCT cs.class_id) FROM timetable t
                 JOIN class_subjects cs ON cs.id = t.class_subject_id
                 WHERE t.academic_year_id = ?',
                [$academicYearId]
            ),
            'total_teachers' => Database::scalar(
                'SELECT COUNT(DISTINCT cs.teacher_id) FROM timetable t
                 JOIN class_subjects cs ON cs.id = t.class_subject_id
                 WHERE t.academic_year_id = ?',
                [$academicYearId]
            ),
            'rooms_used' => Database::fetchAll(
                'SELECT room, COUNT(*) as usage_count FROM timetable 
                 WHERE academic_year_id = ? AND room IS NOT NULL
                 GROUP BY room ORDER BY usage_count DESC',
                [$academicYearId]
            ),
            'daily_distribution' => Database::fetchAll(
                'SELECT day_of_week, COUNT(*) as slot_count FROM timetable
                 WHERE academic_year_id = ?
                 GROUP BY day_of_week ORDER BY day_of_week',
                [$academicYearId]
            )
        ];
    }
}
?>
