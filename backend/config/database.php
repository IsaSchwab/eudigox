<?php
/**
 * Database — singleton PDO.
 *
 * Dois modos:
 *  - AZURE / produção (DB_SSL ligado): conecta direto no host/porta
 *    configurados, com SSL (Azure exige). Uma tentativa.
 *  - LOCAL / MAMP (DB_SSL desligado): mantém o comportamento antigo, com
 *    fallback automático de portas (8889 ↔ 3306) e socket Unix do MAMP.
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

        // =============================================================
        // MODO AZURE / PRODUÇÃO — conexão única com SSL
        // =============================================================
        if (DB_SSL) {
            // Liga SSL. Se DB_SSL_CA apontar pra um certificado existente,
            // faz verificação completa do servidor; senão, criptografa em
            // trânsito sem verificar o certificado (suficiente p/ o TCC).
            if (DB_SSL_CA !== '' && file_exists(DB_SSL_CA)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            } else {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                return self::$instance;
            } catch (PDOException $e) {
                self::fail($e);
            }
        }

        // =============================================================
        // MODO LOCAL / MAMP — fallback de portas + socket
        // =============================================================
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

        // Último recurso: socket Unix do MAMP
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

        self::fail($lastError);
    }

    /** Erro de conexão padronizado (não vaza detalhes em produção). */
    private static function fail(?PDOException $e): void
    {
        http_response_code(500);
        $message = APP_ENV === 'development'
            ? 'Erro de conexão com o banco: ' . ($e ? $e->getMessage() : 'desconhecido')
            : 'Erro interno no servidor.';
        echo json_encode(['error' => $message]);
        exit;
    }

    private function __construct() {}
    private function __clone() {}
}
