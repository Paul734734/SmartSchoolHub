<?php
/**
 * SmartSchool Hub — Gestion des Notifications (Push, SMS, Email)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class NotificationManager
{
    /**
     * Types de notifications
     */
    const TYPES = [
        'absence' => 'Absence signalée',
        'grade' => 'Nouvelle note publiée',
        'bulletin' => 'Bulletin disponible',
        'payment' => 'Paiement requis',
        'message' => 'Nouveau message',
        'announcement' => 'Nouvelle annonce',
        'reminder' => 'Rappel',
        'alert' => 'Alerte système',
        'deadline' => 'Échéance approche'
    ];
    
    /**
     * Envoyer une notification multi-canal
     */
    public static function sendNotification(int $userId, string $type, string $title, string $message, array $options = []): bool
    {
        try {
            // 1. Enregistrer en base de données
            $notificationId = self::saveNotification($userId, $type, $title, $message, $options);
            
            // 2. Envoyer selon les préférences utilisateur
            $user = Database::fetchOne(
                'SELECT email, phone, notification_preferences FROM users WHERE id = ?',
                [$userId]
            );
            
            if (!$user) {
                return false;
            }
            
            $preferences = json_decode($user['notification_preferences'] ?? '{}', true);
            $success = true;
            
            // Notification Push (Web Push)
            if (($preferences['push'] ?? true) && self::isPushEnabled()) {
                $success = self::sendPushNotification($userId, $title, $message, $options) && $success;
            }
            
            // SMS
            if (($preferences['sms'] ?? false) && $user['phone'] && self::isSMSEnabled()) {
                $success = self::sendSMS($user['phone'], $title, $message, $options) && $success;
            }
            
            // Email
            if (($preferences['email'] ?? true) && $user['email']) {
                $success = self::sendEmail($user['email'], $title, $message, $options) && $success;
            }
            
            return $success;
            
        } catch (Exception $e) {
            error_log("Erreur notification: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Envoyer une notification à plusieurs utilisateurs
     */
    public static function sendBulkNotification(array $userIds, string $type, string $title, string $message, array $options = []): array
    {
        $results = ['success' => 0, 'failed' => 0, 'details' => []];
        
        foreach ($userIds as $userId) {
            $success = self::sendNotification($userId, $type, $title, $message, $options);
            if ($success) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['details'][] = "Failed for user ID: {$userId}";
            }
        }
        
        return $results;
    }
    
    /**
     * Envoyer une notification par rôle
     */
    public static function sendToRole(string $role, string $type, string $title, string $message, array $options = []): array
    {
        $users = Database::fetchAll(
            'SELECT id FROM users WHERE role = ? AND is_active = 1',
            [$role]
        );
        
        $userIds = array_column($users, 'id');
        return self::sendBulkNotification($userIds, $type, $title, $message, $options);
    }
    
    /**
     * Envoyer une notification à une classe
     */
    public static function sendToClass(int $classId, string $type, string $title, string $message, array $options = []): array
    {
        $users = Database::fetchAll(
            'SELECT u.id FROM users u
             JOIN students s ON s.user_id = u.id
             WHERE s.class_id = ? AND s.status = "enrolled"',
            [$classId]
        );
        
        $userIds = array_column($users, 'id');
        return self::sendBulkNotification($userIds, $type, $title, $message, $options);
    }
    
    /**
     * Sauvegarder une notification en base de données
     */
    private static function saveNotification(int $userId, string $type, string $title, string $message, array $options): int
    {
        return Database::insert(
            'INSERT INTO notifications (user_id, type, title, body, link, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [
                $userId,
                $type,
                $title,
                $message,
                $options['link'] ?? null
            ]
        );
    }
    
    /**
     * Envoyer une notification Push (Web Push API)
     */
    private static function sendPushNotification(int $userId, string $title, string $message, array $options): bool
    {
        try {
            // Récupérer les abonnements Push de l'utilisateur
            $subscriptions = Database::fetchAll(
                'SELECT endpoint, p256dh_key, auth_key FROM push_subscriptions WHERE user_id = ? AND active = 1',
                [$userId]
            );
            
            if (empty($subscriptions)) {
                return false; // Pas d'abonnement Push
            }
            
            $payload = [
                'title' => $title,
                'body' => $message,
                'icon' => $options['icon'] ?? BASE_URL . '/assets/images/icon-192x192.png',
                'badge' => $options['badge'] ?? BASE_URL . '/assets/images/badge-72x72.png',
                'tag' => $options['tag'] ?? 'general',
                'requireInteraction' => $options['require_interaction'] ?? false,
                'actions' => $options['actions'] ?? [],
                'data' => [
                    'url' => $options['link'] ?? BASE_URL,
                    'notification_id' => $options['notification_id'] ?? null
                ]
            ];
            
            $success = true;
            foreach ($subscriptions as $subscription) {
                $result = self::sendWebPush($subscription, json_encode($payload));
                if (!$result) {
                    $success = false;
                    // Marquer l'abonnement comme inactif si trop d'échecs
                    Database::execute(
                        'UPDATE push_subscriptions SET active = 0, last_error = NOW() WHERE endpoint = ?',
                        [$subscription['endpoint']]
                    );
                }
            }
            
            return $success;
            
        } catch (Exception $e) {
            error_log("Erreur Push notification: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Envoyer un SMS
     */
    private static function sendSMS(string $phoneNumber, string $title, string $message, array $options): bool
    {
        try {
            // Nettoyer le numéro de téléphone
            $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
            
            // Configuration SMS (Orange Money API, MTN Mobile Money, etc.)
            $smsConfig = self::getSMSConfig();
            
            if (!$smsConfig['enabled']) {
                return false;
            }
            
            $fullMessage = $title . "\n" . $message;
            
            // Limiter à 160 caractères pour SMS standard
            if (strlen($fullMessage) > 160) {
                $fullMessage = substr($fullMessage, 0, 157) . '...';
            }
            
            // Simulation d'envoi SMS (remplacer par vraie API)
            $result = self::simulateSMSSend($phoneNumber, $fullMessage, $smsConfig);
            
            // Logger l'envoi SMS
            Database::execute(
                'INSERT INTO sms_logs (phone_number, message, status, sent_at, provider)
                 VALUES (?, ?, ?, NOW(), ?)',
                [$phoneNumber, $fullMessage, $result ? 'sent' : 'failed', $smsConfig['provider']]
            );
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Erreur SMS: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Envoyer un email
     */
    private static function sendEmail(string $email, string $title, string $message, array $options): bool
    {
        try {
            $subject = $title;
            $htmlMessage = self::formatEmailHTML($title, $message, $options);
            
            // Utiliser la fonction d'envoi d'email existante
            return sendEmail($email, $subject, $htmlMessage);
            
        } catch (Exception $e) {
            error_log("Erreur Email: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Envoyer Web Push via service worker
     */
    private static function sendWebPush(array $subscription, string $payload): bool
    {
        // Implémentation simplifiée - nécessite une librairie comme web-push-php
        try {
            $headers = [
                'Content-Type: application/octet-stream',
                'TTL: 3600'
            ];
            
            // Simulation - en production, utiliser une vraie librairie Web Push
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $subscription['endpoint']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode >= 200 && $httpCode < 300;
            
        } catch (Exception $e) {
            error_log("Erreur Web Push: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Simuler l'envoi SMS (remplacer par vraie API)
     */
    private static function simulateSMSSend(string $phoneNumber, string $message, array $config): bool
    {
        // Simulation - remplacer par appel API réel
        if ($config['provider'] === 'orange') {
            // Orange Money SMS API
            return true; // Simulé
        } elseif ($config['provider'] === 'mtn') {
            // MTN Mobile Money SMS API
            return true; // Simulé
        }
        
        return false;
    }
    
    /**
     * Obtenir la configuration SMS
     */
    private static function getSMSConfig(): array
    {
        return [
            'enabled' => Database::scalar('SELECT value FROM system_settings WHERE `key` = \'sms_notifications\'') === '1',
            'provider' => Database::scalar('SELECT value FROM system_settings WHERE `key` = \'sms_provider\'') ?: 'orange',
            'api_key' => Database::scalar('SELECT value FROM system_settings WHERE `key` = \'sms_api_key\'') ?: '',
            'sender_id' => Database::scalar('SELECT value FROM system_settings WHERE `key` = \'sms_sender_id\'') ?: 'SmartSchool'
        ];
    }
    
    /**
     * Formater le message en HTML pour email
     */
    private static function formatEmailHTML(string $title, string $message, array $options): string
    {
        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>%s</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #1A56DB; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .footer { padding: 20px; text-align: center; color: #666; font-size: 12px; }
                .btn { display: inline-block; padding: 12px 24px; background: #1A56DB; color: white; text-decoration: none; border-radius: 4px; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>🎓 SmartSchool Hub</h1>
            </div>
            <div class="content">
                <h2>%s</h2>
                <p>%s</p>
                %s
            </div>
            <div class="footer">
                <p>&copy; 2026 SmartSchool Hub. Tous droits réservés.</p>
                <p>Cet email a été envoyé automatiquement. Merci de ne pas répondre.</p>
            </div>
        </body>
        </html>';
        
        $button = '';
        if (isset($options['link'])) {
            $button = sprintf('<p style="text-align: center; margin: 30px 0;"><a href="%s" class="btn">Voir les détails</a></p>', $options['link']);
        }
        
        return sprintf($template, htmlspecialchars($title), htmlspecialchars($title), nl2br(htmlspecialchars($message)), $button);
    }
    
    /**
     * Vérifier si les notifications Push sont activées
     */
    private static function isPushEnabled(): bool
    {
        return Database::scalar('SELECT value FROM system_settings WHERE `key` = \'push_notifications\'') === '1';
    }
    
    /**
     * Vérifier si les SMS sont activés
     */
    private static function isSMSEnabled(): bool
    {
        return Database::scalar('SELECT value FROM system_settings WHERE `key` = \'sms_notifications\'') === '1';
    }
    
    /**
     * Marquer une notification comme lue
     */
    public static function markAsRead(int $notificationId, int $userId): bool
    {
        return Database::execute(
            'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?',
            [$notificationId, $userId]
        );
    }
    
    /**
     * Marquer toutes les notifications comme lues
     */
    public static function markAllAsRead(int $userId): bool
    {
        return Database::execute(
            'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }
    
    /**
     * Obtenir les notifications non lues
     */
    public static function getUnreadNotifications(int $userId, int $limit = 10): array
    {
        return Database::fetchAll(
            'SELECT * FROM notifications 
             WHERE user_id = ? AND is_read = 0 
             ORDER BY created_at DESC 
             LIMIT ?',
            [$userId, $limit]
        );
    }
    
    /**
     * Obtenir le nombre de notifications non lues
     */
    public static function getUnreadCount(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }
    
    /**
     * Nettoyer les anciennes notifications
     */
    public static function cleanupOldNotifications(int $daysToKeep = 30): bool
    {
        return Database::execute(
            'DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND is_read = 1',
            [$daysToKeep]
        );
    }
    
    /**
     * Envoyer des notifications automatiques basées sur les événements
     */
    public static function triggerEventNotification(string $event, array $data): void
    {
        switch ($event) {
            case 'absence_reported':
                self::handleAbsenceNotification($data);
                break;
            case 'grade_published':
                self::handleGradeNotification($data);
                break;
            case 'payment_due':
                self::handlePaymentNotification($data);
                break;
            case 'announcement_published':
                self::handleAnnouncementNotification($data);
                break;
        }
    }
    
    /**
     * Gérer les notifications d'absence
     */
    private static function handleAbsenceNotification(array $data): void
    {
        $studentId = $data['student_id'];
        $date = $data['date'];
        $reason = $data['reason'] ?? '';
        
        // Notifier les parents
        $parents = Database::fetchAll(
            'SELECT u.id FROM users u
             JOIN student_parents sp ON sp.parent_id = u.id
             WHERE sp.student_id = ?',
            [$studentId]
        );
        
        foreach ($parents as $parent) {
            self::sendNotification(
                $parent['id'],
                'absence',
                '🚨 Absence signalée',
                "Votre enfant a été signalé absent le {$date}. Motif: {$reason}",
                [
                    'link' => BASE_URL . '/pages/parent/attendance.php',
                    'priority' => 'high'
                ]
            );
        }
    }
    
    /**
     * Gérer les notifications de note
     */
    private static function handleGradeNotification(array $data): void
    {
        $studentId = $data['student_id'];
        $subject = $data['subject'];
        $grade = $data['grade'];
        
        // Notifier l'élève
        $student = Database::fetchOne('SELECT user_id FROM students WHERE id = ?', [$studentId]);
        if ($student) {
            self::sendNotification(
                $student['user_id'],
                'grade',
                '📊 Nouvelle note publiée',
                "Vous avez obtenu {$grade}/20 en {$subject}",
                [
                    'link' => BASE_URL . '/pages/student/grades.php',
                    'priority' => 'normal'
                ]
            );
        }
        
        // Notifier les parents
        $parents = Database::fetchAll(
            'SELECT u.id FROM users u
             JOIN student_parents sp ON sp.parent_id = u.id
             WHERE sp.student_id = ?',
            [$studentId]
        );
        
        foreach ($parents as $parent) {
            self::sendNotification(
                $parent['id'],
                'grade',
                '📊 Nouvelle note publiée',
                "Votre enfant a obtenu {$grade}/20 en {$subject}",
                [
                    'link' => BASE_URL . '/pages/parent/grades.php',
                    'priority' => 'normal'
                ]
            );
        }
    }
    
    /**
     * Gérer les notifications de paiement
     */
    private static function handlePaymentNotification(array $data): void
    {
        $studentId = $data['student_id'];
        $amount = $data['amount'];
        $dueDate = $data['due_date'];
        
        // Notifier les parents
        $parents = Database::fetchAll(
            'SELECT u.id FROM users u
             JOIN student_parents sp ON sp.parent_id = u.id
             WHERE sp.student_id = ?',
            [$studentId]
        );
        
        foreach ($parents as $parent) {
            self::sendNotification(
                $parent['id'],
                'payment',
                '💰 Paiement requis',
                "Un paiement de {$amount} FCFA est dû avant le {$dueDate}",
                [
                    'link' => BASE_URL . '/pages/parent/payments.php',
                    'priority' => 'high'
                ]
            );
        }
    }
    
    /**
     * Gérer les notifications d'annonce
     */
    private static function handleAnnouncementNotification(array $data): void
    {
        $targetRole = $data['target_role'];
        $title = $data['title'];
        $content = $data['content'];
        
        if ($targetRole === 'all') {
            $users = Database::fetchAll('SELECT id FROM users WHERE is_active = 1');
            $userIds = array_column($users, 'id');
            self::sendBulkNotification($userIds, 'announcement', '📢 ' . $title, $content);
        } else {
            self::sendToRole($targetRole, 'announcement', '📢 ' . $title, $content);
        }
    }
}
?>
