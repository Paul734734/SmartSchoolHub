<?php
/**
 * SmartSchool Hub — API Authentication Endpoints
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../includes/functions.php';

// Endpoint routing
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'login':
            if ($method === 'POST') {
                handleLogin();
            } else {
                sendJsonError('Méthode non autorisée', 405);
            }
            break;
            
        case 'refresh':
            if ($method === 'POST') {
                handleRefresh();
            } else {
                sendJsonError('Méthode non autorisée', 405);
            }
            break;
            
        case 'logout':
            if ($method === 'POST') {
                handleLogout();
            } else {
                sendJsonError('Méthode non autorisée', 405);
            }
            break;
            
        case 'verify':
            if ($method === 'GET') {
                handleVerify();
            } else {
                sendJsonError('Méthode non autorisée', 405);
            }
            break;
            
        case 'me':
            if ($method === 'GET') {
                handleMe();
            } else {
                sendJsonError('Méthode non autorisée', 405);
            }
            break;
            
        default:
            sendJsonError('Endpoint non trouvé', 404);
    }
} catch (Exception $e) {
    error_log("API Auth Error: " . $e->getMessage());
    sendJsonError('Erreur serveur', 500);
}

/**
 * Handle login endpoint
 */
function handleLogin(): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    $email = sanitize($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    if (!$email || !$password) {
        sendJsonError('Email et mot de passe requis', 400);
    }
    
    // Utiliser la méthode d'authentification existante
    $result = Auth::login($email, $password);
    
    if (!$result['success']) {
        sendJsonError($result['message'], 401);
    }
    
    $user = Database::fetchOne(
        'SELECT id, email, role, first_name, last_name, avatar, is_active FROM users WHERE email = ?',
        [$email]
    );
    
    if (!$user) {
        sendJsonError('Utilisateur non trouvé', 404);
    }
    
    // Si 2FA est requis, retourner une réponse spéciale
    if (isset($result['require_2fa']) && $result['require_2fa']) {
        sendJson([
            'success' => true,
            'require_2fa' => true,
            'message' => 'Vérification 2FA requise',
            'redirect' => $result['redirect']
        ]);
    }
    
    if (isset($result['require_2fa_setup']) && $result['require_2fa_setup']) {
        sendJson([
            'success' => true,
            'require_2fa_setup' => true,
            'message' => 'Configuration 2FA requise',
            'redirect' => $result['redirect']
        ]);
    }
    
    // Générer les tokens JWT
    $payload = [
        'user_id' => $user['id'],
        'role' => $user['role'],
        'email' => $user['email']
    ];
    
    $accessToken = JWTManager::generateToken($payload, 'access');
    $refreshToken = JWTManager::generateToken($payload, 'refresh');
    
    // Stocker les tokens
    JWTManager::storeToken($user['id'], $accessToken, 'access');
    JWTManager::storeToken($user['id'], $refreshToken, 'refresh');
    
    sendJson([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'avatar' => $user['avatar']
        ],
        'tokens' => [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 900 // 15 minutes
        ],
        'redirect' => $result['redirect']
    ]);
}

/**
 * Handle token refresh endpoint
 */
function handleRefresh(): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    $refreshToken = $input['refresh_token'] ?? '';
    
    if (!$refreshToken) {
        sendJsonError('Refresh token requis', 400);
    }
    
    $result = JWTManager::refreshToken($refreshToken);
    
    if (!$result) {
        sendJsonError('Refresh token invalide', 401);
    }
    
    sendJson([
        'success' => true,
        'tokens' => [
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $result['expires_in']
        ]
    ]);
}

/**
 * Handle logout endpoint
 */
function handleLogout(): void
{
    // Authentifier avec JWT
    $payload = JWTManager::authenticate();
    
    // Révoquer le token actuel
    $token = JWTManager::extractTokenFromHeader();
    if ($token && isset($payload['jti'])) {
        JWTManager::revokeToken($payload['jti']);
    }
    
    sendJson([
        'success' => true,
        'message' => 'Déconnexion réussie'
    ]);
}

/**
 * Handle token verification endpoint
 */
function handleVerify(): void
{
    $token = JWTManager::extractTokenFromHeader();
    
    if (!$token) {
        sendJsonError('Token manquant', 401);
    }
    
    $payload = JWTManager::validateToken($token);
    
    if (!$payload) {
        sendJsonError('Token invalide', 401);
    }
    
    sendJson([
        'valid' => true,
        'payload' => [
            'user_id' => $payload['user_id'],
            'role' => $payload['role'],
            'email' => $payload['email'],
            'type' => $payload['type'],
            'exp' => $payload['exp']
        ]
    ]);
}

/**
 * Handle current user info endpoint
 */
function handleMe(): void
{
    $payload = JWTManager::authenticate();
    
    $user = Database::fetchOne(
        'SELECT id, email, role, first_name, last_name, avatar, phone, is_active, last_login 
         FROM users WHERE id = ?',
        [$payload['user_id']]
    );
    
    if (!$user) {
        sendJsonError('Utilisateur non trouvé', 404);
    }
    
    // Ajouter les permissions selon le rôle
    $permissions = getRolePermissions($user['role']);
    
    sendJson([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'avatar' => $user['avatar'],
            'phone' => $user['phone'],
            'is_active' => $user['is_active'],
            'last_login' => $user['last_login'],
            'permissions' => $permissions
        ]
    ]);
}

/**
 * Obtenir les permissions selon le rôle
 */
function getRolePermissions(string $role): array
{
    $permissions = [
        'super_admin' => [
            'manage_schools' => true,
            'manage_licenses' => true,
            'manage_users' => true,
            'view_reports' => true,
            'system_settings' => true
        ],
        'admin' => [
            'manage_students' => true,
            'manage_teachers' => true,
            'manage_classes' => true,
            'view_reports' => true,
            'manage_payments' => true
        ],
        'teacher' => [
            'manage_grades' => true,
            'manage_attendance' => true,
            'view_classes' => true,
            'manage_resources' => true
        ],
        'student' => [
            'view_grades' => true,
            'view_schedule' => true,
            'view_resources' => true,
            'submit_assignments' => true
        ],
        'parent' => [
            'view_child_grades' => true,
            'view_child_schedule' => true,
            'view_child_attendance' => true,
            'make_payments' => true
        ]
    ];
    
    return $permissions[$role] ?? [];
}

/**
 * Envoyer une réponse JSON
 */
function sendJson(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

/**
 * Envoyer une erreur JSON
 */
function sendJsonError(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error' => $message
    ]);
    exit;
}
?>
