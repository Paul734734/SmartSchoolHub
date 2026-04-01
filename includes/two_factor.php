<?php
/**
 * SmartSchool Hub — Authentification à Deux Facteurs (2FA) - VERSION CORRIGÉE
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class TwoFactorAuth
{
    private static string $issuer = 'SmartSchoolHub';
    
    /**
     * Générer un secret 2FA pour un utilisateur
     */
    public static function generateSecret(): string
    {
        return strtoupper(substr(str_replace(['=', '+', '/'], '', base64_encode(random_bytes(16))), 0, 16));
    }
    
    /**
     * Générer un code de backup
     */
    public static function generateBackupCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(md5(random_bytes(16)), 0, 8));
        }
        return $codes;
    }
    
    /**
     * Activer 2FA pour un utilisateur
     */
    public static function enable2FA(int $userId, string $secret): bool
    {
        try {
            // Supprimer ancienne configuration si existante
            Database::execute('DELETE FROM user_2fa WHERE user_id = ?', [$userId]);
            
            // Générer codes de backup
            $backupCodes = self::generateBackupCodes();
            
            // Insérer nouvelle configuration
            Database::execute(
                'INSERT INTO user_2fa (user_id, secret, backup_codes, enabled, verified_at) 
                 VALUES (?, ?, ?, 1, NOW())',
                [$userId, $secret, json_encode($backupCodes)]
            );
            
            // Mettre à jour l'utilisateur
            Database::execute(
                'UPDATE users SET `2fa_required` = 1 WHERE id = ?',
                [$userId]
            );
            
            return true;
        } catch (Exception $e) {
            error_log("Erreur activation 2FA: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Désactiver 2FA pour un utilisateur
     */
    public static function disable2FA(int $userId): bool
    {
        try {
            Database::execute('DELETE FROM user_2fa WHERE user_id = ?', [$userId]);
            Database::execute('UPDATE users SET `2fa_required` = 0 WHERE id = ?', [$userId]);
            return true;
        } catch (Exception $e) {
            error_log("Erreur désactivation 2FA: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Vérifier si 2FA est activé pour un utilisateur
     */
    public static function isEnabled(int $userId): bool
    {
        $enabled = Database::scalar(
            'SELECT enabled FROM user_2fa WHERE user_id = ?',
            [$userId]
        );
        return (bool)$enabled;
    }
    
    /**
     * Vérifier si 2FA est requis pour le rôle de l'utilisateur
     */
    public static function isRequiredForRole(string $role): bool
    {
        // TEMPORAIREMENT DÉSACTIVÉ - Retourne false pour tous les rôles
        return false;
        
        $setting = Database::scalar(
            'SELECT value FROM system_settings WHERE `key` = \'2fa_required_admin\''
        );
        
        return $setting === '1' && in_array($role, ['super_admin', 'admin']);
    }
    
    /**
     * Vérifier un code TOTP
     */
    public static function verifyCode(int $userId, string $code): bool
    {
        $user2FA = Database::fetchOne(
            'SELECT secret, backup_codes FROM user_2fa WHERE user_id = ? AND enabled = 1',
            [$userId]
        );
        
        if (!$user2FA) {
            return false;
        }
        
        // Vérifier les codes de backup d'abord
        $backupCodes = json_decode($user2FA['backup_codes'] ?? '[]', true);
        if (in_array(strtoupper($code), $backupCodes)) {
            // Supprimer le code utilisé
            $remainingCodes = array_diff($backupCodes, [strtoupper($code)]);
            Database::execute(
                'UPDATE user_2fa SET backup_codes = ?, last_used = NOW() WHERE user_id = ?',
                [json_encode(array_values($remainingCodes)), $userId]
            );
            return true;
        }
        
        // Vérifier le code TOTP
        return self::verifyTOTP($user2FA['secret'], $code);
    }
    
    /**
     * Vérifier un code TOTP (méthode publique)
     */
    public static function verifyTOTPCode(string $secret, string $code): bool
    {
        return self::verifyTOTP($secret, $code);
    }
    
    /**
     * Vérification TOTP (Time-based One-Time Password)
     */
    private static function verifyTOTP(string $secret, string $code): bool
    {
        // Intervalle de temps (30 secondes)
        $interval = 30;
        $window = 1; // Fenêtre de temps: 1 intervalle avant et après
        
        $timestamp = time();
        
        for ($i = -$window; $i <= $window; $i++) {
            $counter = floor(($timestamp + ($i * $interval)) / $interval);
            $expectedCode = self::generateTOTP($secret, $counter);
            
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Générer un code TOTP
     */
    private static function generateTOTP(string $secret, int $counter): string
    {
        // Convertir le secret en bytes
        $secretBytes = self::base32_decode($secret);
        
        // Convertir le counter en bytes (big-endian)
        $counterBytes = pack('N*', $counter);
        
        // Calculer HMAC-SHA1
        $hash = hash_hmac('sha1', $counterBytes, $secretBytes, true);
        
        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );
        
        return str_pad($binary % 1000000, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Générer l'URL QR Code pour Google Authenticator (avec secret fourni)
     */
    public static function generateQRCodeURL(string $email, string $secret): string
    {
        $issuer = self::$issuer;
        
        $params = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30
        ];
        
        return 'otpauth://totp/' . urlencode($issuer) . ':' . urlencode($email) . '?' . http_build_query($params);
    }
    
    /**
     * Générer l'URL QR Code pour Google Authenticator
     */
    public static function getQRCodeURL(int $userId): string
    {
        $user = Database::fetchOne(
            'SELECT email, first_name, last_name FROM users WHERE id = ?',
            [$userId]
        );
        
        if (!$user) {
            return '';
        }
        
        $user2FA = Database::fetchOne(
            'SELECT secret FROM user_2fa WHERE user_id = ?',
            [$userId]
        );
        
        if (!$user2FA) {
            return '';
        }
        
        $name = $user['email'];
        $secret = $user2FA['secret'];
        $issuer = self::$issuer;
        
        $params = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30
        ];
        
        return 'otpauth://totp/' . urlencode($issuer) . ':' . urlencode($name) . '?' . http_build_query($params);
    }
    
    /**
     * Vérifier si l'utilisateur doit configurer 2FA
     */
    public static function needsSetup(int $userId): bool
    {
        // TEMPORAIREMENT DÉSACTIVÉ - Personne ne doit configurer 2FA
        return false;
        
        $user = Database::fetchOne(
            'SELECT role, `2fa_required` FROM users WHERE id = ?',
            [$userId]
        );
        
        if (!$user) {
            return false;
        }
        
        // Si 2FA est requis pour ce rôle mais pas encore configuré
        return self::isRequiredForRole($user['role']) && $user['2fa_required'] == 0;
    }
    
    /**
     * Envoyer les codes de backup par email
     */
    public static function sendBackupCodesEmail(int $userId): bool
    {
        try {
            $user = Database::fetchOne(
                'SELECT email, first_name, last_name FROM users WHERE id = ?',
                [$userId]
            );
            
            if (!$user) {
                return false;
            }
            
            $user2FA = Database::fetchOne(
                'SELECT backup_codes FROM user_2fa WHERE user_id = ?',
                [$userId]
            );
            
            if (!$user2FA) {
                return false;
            }
            
            $backupCodes = json_decode($user2FA['backup_codes'] ?? '[]', true);
            
            $title = '🔐 Vos codes de backup SmartSchoolHub';
            $message = "
            <h2>Codes de backup pour l'authentification à deux facteurs</h2>
            <p>Bonjour {$user['first_name']},</p>
            <p>Voici vos codes de backup pour SmartSchoolHub. Conservez-les dans un endroit sécurisé :</p>
            <div style='background:#f8f9fa;padding:15px;border-radius:8px;font-family:monospace;'>
            " . implode('<br>', $backupCodes) . "
            </div>
            <p><strong>Important :</strong></p>
            <ul>
                <li>Chaque code ne peut être utilisé qu'une seule fois</li>
                <li>Gardez ces codes dans un endroit sûr</li>
                <li>Utilisez-les si vous perdez l'accès à votre application d'authentification</li>
            </ul>
            <p>Cordialement,<br>L'équipe SmartSchoolHub</p>
            ";
            
            // Utiliser mail() simple pour l'instant
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'From: SmartSchoolHub <noreply@smartschoolhub.com>',
                'Reply-To: noreply@smartschoolhub.com'
            ];
            
            return mail($user['email'], $title, $message, implode("\r\n", $headers));
            
        } catch (Exception $e) {
            error_log("Erreur envoi codes backup: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Décodage Base32 (simple implementation)
     */
    private static function base32_decode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $buffer = 0;
        $bufferLength = 0;
        
        // Nettoyer l'input
        $input = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input));
        
        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            $value = strpos($alphabet, $char);
            
            if ($value === false) {
                continue;
            }
            
            $buffer = ($buffer << 5) | $value;
            $bufferLength += 5;
            
            if ($bufferLength >= 8) {
                $bufferLength -= 8;
                $output .= chr(($buffer >> $bufferLength) & 0xFF);
            }
        }
        
        return $output;
    }
}
?>
