<?php
/**
 * SmartSchool Hub — Gestion de l'authentification et des sessions
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/two_factor.php';

class Auth
{
    // ── Connexion ──────────────────────────────────────────────────────
    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        $user = Database::fetchOne(
            'SELECT id, email, password_hash, role, first_name, last_name,
                    avatar, is_active
             FROM users
             WHERE email = ? LIMIT 1',
            [$email]
        );

        if (!$user) {
            return ['success' => false, 'message' => 'Email ou mot de passe incorrect.'];
        }

        if (!$user['is_active']) {
            return ['success' => false, 'message' => 'Ce compte est désactivé. Contactez l\'administrateur.'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            self::logActivity(null, 'login_failed', 'user', null, 'Email: ' . $email);
            return ['success' => false, 'message' => 'Email ou mot de passe incorrect.'];
        }

        // Mise à jour last_login
        Database::execute(
            'UPDATE users SET last_login = NOW() WHERE id = ?',
            [$user['id']]
        );

        // Vérifier si 2FA est requis pour cet utilisateur
        if (TwoFactorAuth::isRequiredForRole($user['role']) && TwoFactorAuth::isEnabled($user['id'])) {
            // Stocker les infos en session pour la vérification 2FA
            $_SESSION['2fa_user_id'] = $user['id'];
            $_SESSION['2fa_remember_me'] = $_POST['remember_me'] ?? false;
            
            return [
                'success' => true,
                'role' => $user['role'],
                'redirect' => BASE_URL . '/pages/2fa-verify.php',
                'require_2fa' => true
            ];
        }

        // Si 2FA est requis mais pas encore configuré
        if (TwoFactorAuth::isRequiredForRole($user['role']) && !TwoFactorAuth::isEnabled($user['id'])) {
            $_SESSION['2fa_user_id'] = $user['id'];
            $_SESSION['2fa_remember_me'] = $_POST['remember_me'] ?? false;
            
            return [
                'success' => true,
                'role' => $user['role'],
                'redirect' => BASE_URL . '/pages/2fa-setup.php',
                'require_2fa_setup' => true
            ];
        }

        // Initialiser la session
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_avatar']= $user['avatar'];
        $_SESSION['logged_in']  = true;
        $_SESSION['login_time'] = time();

        // Token CSRF
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        self::logActivity($user['id'], 'login', 'user', $user['id']);

        return [
            'success'  => true,
            'role'     => $user['role'],
            'redirect' => self::getDashboardUrl($user['role']),
        ];
    }

    // ── Déconnexion ────────────────────────────────────────────────────
    public static function logout(): void
    {
        if (isset($_SESSION['user_id'])) {
            self::logActivity($_SESSION['user_id'], 'logout', 'user', $_SESSION['user_id']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    // ── Vérification accès ─────────────────────────────────────────────
    public static function check(string ...$allowedRoles): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . BASE_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }

        // Vérifier expiration session (8h)
        if (isset($_SESSION['login_time']) &&
            (time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
            self::logout();
        }

        // Vérifier si l'utilisateur doit configurer 2FA
        if (TwoFactorAuth::needsSetup($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/pages/2fa-setup.php');
            exit;
        }

        if (!empty($allowedRoles) && !in_array($_SESSION['user_role'], $allowedRoles, true)) {
            http_response_code(403);
            include BASE_PATH . '/includes/403.php';
            exit;
        }
    }

    // ── Connecté ? ─────────────────────────────────────────────────────
    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
    }

    // ── Getters ────────────────────────────────────────────────────────
    public static function id(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string
    {
        return $_SESSION['user_role'] ?? null;
    }

    public static function name(): string
    {
        return $_SESSION['user_name'] ?? 'Utilisateur';
    }

    public static function initials(): string
    {
        $parts = explode(' ', trim(self::name()));
        $init  = '';
        foreach ($parts as $p) {
            if ($p !== '') $init .= strtoupper($p[0]);
            if (strlen($init) >= 2) break;
        }
        return $init ?: 'U';
    }

    public static function email(): ?string
    {
        return $_SESSION['user_email'] ?? null;
    }

    // ── CSRF ───────────────────────────────────────────────────────────
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(string $token): bool
    {
        return isset($_SESSION['csrf_token']) &&
               hash_equals($_SESSION['csrf_token'], $token);
    }

    // ── Mot de passe ───────────────────────────────────────────────────
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function isValidPassword(string $password): bool
    {
        // Min 8 chars, 1 majuscule, 1 chiffre
        return strlen($password) >= 8 &&
               preg_match('/[A-Z]/', $password) &&
               preg_match('/[0-9]/', $password);
    }

    // ── URL tableau de bord par rôle ───────────────────────────────────
    public static function getDashboardUrl(string $role): string
    {
        return match($role) {
            'admin'   => BASE_URL . '/modules/admin/pages/dashboard.php',
            'teacher' => BASE_URL . '/modules/teacher/pages/dashboard.php',
            'parent'  => BASE_URL . '/modules/parent/pages/dashboard.php',
            'student' => BASE_URL . '/modules/student/pages/dashboard.php',
            default   => BASE_URL . '/login.php',
        };
    }

    // ── Récupérer les infos complètes de l'utilisateur courant ─────────
    public static function currentUser(): ?array
    {
        if (!self::isLoggedIn()) return null;
        return Database::fetchOne(
            'SELECT u.*, 
                    CONCAT(u.first_name, " ", u.last_name) AS full_name
             FROM users u WHERE u.id = ?',
            [self::id()]
        );
    }

    // ── Journal d'activité ─────────────────────────────────────────────
    public static function logActivity(
        ?int $userId,
        string $action,
        string $target = '',
        ?int $targetId = null,
        string $details = ''
    ): void {
        try {
            Database::insert(
                'INSERT INTO activity_log (user_id, action, target, target_id, details, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $action,
                    $target,
                    $targetId,
                    $details,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]
            );
        } catch (Exception $e) {
            // Silencieux : ne pas bloquer l'app si le log échoue
        }
    }
}
