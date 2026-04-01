<?php
/**
 * SmartSchool Hub — JWT Token Management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class JWTManager
{
    private static string $secretKey = JWT_SECRET_KEY;
    private static int $accessTokenLifetime = 900; // 15 minutes
    private static int $refreshTokenLifetime = 604800; // 7 days
    
    /**
     * Générer un token JWT
     */
    public static function generateToken(array $payload, string $type = 'access'): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256'
        ];
        
        $now = time();
        $exp = $type === 'access' 
            ? $now + self::$accessTokenLifetime 
            : $now + self::$refreshTokenLifetime;
        
        $tokenPayload = array_merge($payload, [
            'iat' => $now,
            'exp' => $exp,
            'type' => $type,
            'jti' => self::generateTokenId()
        ]);
        
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($tokenPayload));
        
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, self::$secretKey, true);
        $signatureEncoded = self::base64UrlEncode($signature);
        
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
    
    /**
     * Valider un token JWT
     */
    public static function validateToken(string $token): ?array
    {
        if (empty($token)) {
            return null;
        }
        
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
        
        // Vérifier la signature
        $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, self::$secretKey, true);
        $expectedSignatureEncoded = self::base64UrlEncode($expectedSignature);
        
        if (!hash_equals($signatureEncoded, $expectedSignatureEncoded)) {
            return null;
        }
        
        // Décoder le payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        if (!$payload) {
            return null;
        }
        
        // Vérifier l'expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }
        
        // Vérifier que le token n'est pas révoqué
        if (self::isTokenRevoked($payload['jti'] ?? '')) {
            return null;
        }
        
        return $payload;
    }
    
    /**
     * Stocker un token en base de données
     */
    public static function storeToken(int $userId, string $token, string $type = 'access'): bool
    {
        try {
            $payload = self::validateToken($token);
            if (!$payload) {
                return false;
            }
            
            // Nettoyer les anciens tokens expirés
            self::cleanupExpiredTokens();
            
            Database::execute(
                'INSERT INTO jwt_tokens (user_id, token_id, token_type, expires_at, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $payload['jti'],
                    $type,
                    date('Y-m-d H:i:s', $payload['exp']),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]
            );
            
            return true;
        } catch (Exception $e) {
            error_log("Erreur stockage token JWT: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Révoquer un token
     */
    public static function revokeToken(string $tokenId): bool
    {
        try {
            Database::execute(
                'UPDATE jwt_tokens SET revoked = 1, revoked_at = NOW() WHERE token_id = ?',
                [$tokenId]
            );
            return true;
        } catch (Exception $e) {
            error_log("Erreur révocation token JWT: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Révoquer tous les tokens d'un utilisateur
     */
    public static function revokeAllUserTokens(int $userId): bool
    {
        try {
            Database::execute(
                'UPDATE jwt_tokens SET revoked = 1, revoked_at = NOW() WHERE user_id = ?',
                [$userId]
            );
            return true;
        } catch (Exception $e) {
            error_log("Erreur révocation tokens utilisateur: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Vérifier si un token est révoqué
     */
    public static function isTokenRevoked(string $tokenId): bool
    {
        $revoked = Database::scalar(
            'SELECT revoked FROM jwt_tokens WHERE token_id = ?',
            [$tokenId]
        );
        return (bool)$revoked;
    }
    
    /**
     * Nettoyer les tokens expirés
     */
    public static function cleanupExpiredTokens(): void
    {
        Database::execute(
            'DELETE FROM jwt_tokens WHERE expires_at < NOW() OR revoked = 1'
        );
    }
    
    /**
     * Rafraîchir un token access
     */
    public static function refreshToken(string $refreshToken): ?array
    {
        $payload = self::validateToken($refreshToken);
        if (!$payload || $payload['type'] !== 'refresh') {
            return null;
        }
        
        $userId = $payload['user_id'] ?? null;
        if (!$userId) {
            return null;
        }
        
        // Vérifier que le refresh token est valide en base
        $tokenExists = Database::scalar(
            'SELECT COUNT(*) FROM jwt_tokens WHERE token_id = ? AND token_type = "refresh" AND revoked = 0',
            [$payload['jti']]
        );
        
        if (!$tokenExists) {
            return null;
        }
        
        // Générer nouveaux tokens
        $newPayload = [
            'user_id' => $userId,
            'role' => $payload['role'] ?? '',
            'email' => $payload['email'] ?? ''
        ];
        
        $newAccessToken = self::generateToken($newPayload, 'access');
        $newRefreshToken = self::generateToken($newPayload, 'refresh');
        
        // Stocker les nouveaux tokens
        self::storeToken($userId, $newAccessToken, 'access');
        self::storeToken($userId, $newRefreshToken, 'refresh');
        
        // Révoquer l'ancien refresh token
        self::revokeToken($payload['jti']);
        
        return [
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in' => self::$accessTokenLifetime
        ];
    }
    
    /**
     * Extraire le token du header Authorization
     */
    public static function extractTokenFromHeader(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (empty($header)) {
            return null;
        }
        
        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Middleware pour protéger les routes API
     */
    public static function authenticate(): ?array
    {
        $token = self::extractTokenFromHeader();
        if (!$token) {
            http_response_code(401);
            echo json_encode(['error' => 'Token manquant']);
            exit;
        }
        
        $payload = self::validateToken($token);
        if (!$payload) {
            http_response_code(401);
            echo json_encode(['error' => 'Token invalide ou expiré']);
            exit;
        }
        
        if ($payload['type'] !== 'access') {
            http_response_code(401);
            echo json_encode(['error' => 'Type de token invalide']);
            exit;
        }
        
        return $payload;
    }
    
    /**
     * Obtenir les tokens actifs d'un utilisateur
     */
    public static function getUserActiveTokens(int $userId): array
    {
        return Database::fetchAll(
            'SELECT token_id, token_type, expires_at, ip_address, user_agent, created_at
             FROM jwt_tokens 
             WHERE user_id = ? AND revoked = 0 AND expires_at > NOW()
             ORDER BY created_at DESC',
            [$userId]
        );
    }
    
    /**
     * Générer un ID de token unique
     */
    private static function generateTokenId(): string
    {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Encodage Base64 URL-safe
     */
    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
    
    /**
     * Décodage Base64 URL-safe
     */
    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data) . str_repeat('=', 4 - strlen($data) % 4));
    }
    
    /**
     * Obtenir la date d'expiration d'un token
     */
    public static function getTokenExpiration(string $token): ?string
    {
        $payload = self::validateToken($token);
        return $payload ? date('Y-m-d H:i:s', $payload['exp']) : null;
    }
}

/**
 * Constantes pour la configuration JWT
 */
if (!defined('JWT_SECRET_KEY')) {
    define('JWT_SECRET_KEY', 'SmartSchoolHub_Secret_Key_2026_Change_In_Production');
}
?>
