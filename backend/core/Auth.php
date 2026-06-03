<?php
/**
 * Auth — autenticação por sessão PHP nativa.
 * 
 * Mais simples que tokens Bearer e funciona perfeitamente no MAMP/XAMPP.
 * Não precisa da tabela auth_tokens. Não precisa de localStorage.
 * 
 * Fluxo:
 *   1. Login (POST /auth/login.php) — verifica senha e cria $_SESSION['user_id']
 *   2. Endpoints protegidos chamam Auth::user() ou Auth::requireRole(...)
 *   3. Logout (POST /auth/logout.php) — destrói sessão
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/Response.php';

class Auth
{
    private static ?array $cachedUser = null;
    private static bool $sessionStarted = false;

    /**
     * Inicia a sessão PHP se ainda não começou.
     * Configura cookie cross-site para o front conseguir mandar credenciais.
     */
    public static function startSession(): void
    {
        if (self::$sessionStarted || session_status() === PHP_SESSION_ACTIVE) {
            self::$sessionStarted = true;
            return;
        }

        // Configurar cookie da sessão
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 8, // 8 horas
            'path'     => '/',
            'domain'   => '',
            'secure'   => false, // false em dev (http). true em produção (https)
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('SGXSESSID');
        session_start();
        self::$sessionStarted = true;
    }

    /**
     * Login: valida e-mail/senha e cria sessão.
     */
    public static function login(int $userId): void
    {
        self::startSession();
        // Regenera ID da sessão pra prevenir session fixation
        session_regenerate_id(true);
        $_SESSION['user_id']    = $userId;
        $_SESSION['logged_at']  = time();
    }

    /**
     * Logout: destrói a sessão.
     */
    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$cachedUser = null;
    }

    /**
     * Retorna o usuário autenticado ou NULL se não logado.
     */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }

        self::startSession();
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT id, email, full_name, role, is_active
            FROM users
            WHERE id = :id
              AND is_active = 1
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            // Usuário foi deletado ou desativado: força logout
            self::logout();
            return null;
        }

        return self::$cachedUser = $user;
    }

    /**
     * Garante que existe usuário autenticado. Caso contrário, encerra com 401.
     */
    public static function requireUser(): array
    {
        $user = self::user();
        if (!$user) {
            Response::unauthorized('Você precisa fazer login.');
        }
        return $user;
    }

    /**
     * Garante que o usuário tem um dos papéis informados. Caso contrário, 403.
     */
    public static function requireRole(string ...$roles): array
    {
        $user = self::requireUser();
        if (!in_array($user['role'], $roles, true)) {
            Response::forbidden('Seu perfil não tem permissão para esta ação.');
        }
        return $user;
    }
}
