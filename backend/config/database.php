<?php
/**
 * Database — singleton PDO com fallback automático.
 * 
 * Tenta conectar usando a porta configurada (DB_PORT). Se falhar, tenta
 * outras portas comuns do MAMP/XAMPP. Útil porque o MAMP às vezes muda
 * de porta (3306 ↔ 8889) dependendo da configuração.
 * 
 * Uso: $pdo = Database::getConnection();
 */

require_once __DIR__ . '/config.php';

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        // Lista de tentativas: porta configurada primeiro, depois fallbacks comuns do MAMP
        $portsToTry = array_unique([DB_PORT, '8889', '3306']);
        $lastError  = null;

        foreach ($portsToTry as $port) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, $port, DB_NAME, DB_CHARSET
            );
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                return self::$instance;
            } catch (PDOException $e) {
                $lastError = $e;
                continue; // Tenta próxima porta
            }
        }

        // Último recurso: socket Unix do MAMP (sempre funciona quando o MAMP
        // está rodando, independente da porta)
        $sockets = [
            '/Applications/MAMP/tmp/mysql/mysql.sock',
            '/Applications/MAMP/Library/tmp/mysql.sock',
        ];
        foreach ($sockets as $sock) {
            if (file_exists($sock)) {
                $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s',
                    $sock, DB_NAME, DB_CHARSET);
                try {
                    self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                    return self::$instance;
                } catch (PDOException $e) {
                    $lastError = $e;
                    continue;
                }
            }
        }

        // Falhou tudo — devolve erro útil
        http_response_code(500);
        $message = APP_ENV === 'development'
            ? 'Erro de conexão com o banco: ' . ($lastError ? $lastError->getMessage() : 'desconhecido')
            : 'Erro interno no servidor.';
        echo json_encode(['error' => $message]);
        exit;
    }

    private function __construct() {}
    private function __clone() {}
}
