<?php
/**
 * Bootstrap — incluído no início de TODO endpoint da API.
 * 
 * Responsabilidades:
 *   - CORS (com credentials para cookies de sessão funcionarem)
 *   - Inicia a sessão PHP
 *   - JSON error handler global
 *   - Carrega classes core
 *   - Loga erros em arquivo (logs/error.log) pra facilitar debug
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Audit.php';

// ----- CORS com credentials (necessário para cookies funcionarem) -----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, CORS_ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
} else {
    // No mesmo origin (página servida pelo mesmo Apache que serve a API)
    // não há header Origin na requisição. Não precisa devolver Allow-Origin.
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Pré-flight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ----- Garantir diretório de logs -----
$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/error.log');

// ----- Iniciar sessão PHP -----
Auth::startSession();

// ----- Error handlers globais → sempre devolvem JSON -----
set_exception_handler(function (Throwable $e) {
    error_log('[SGX] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $msg = APP_ENV === 'development'
        ? $e->getMessage()
        : 'Erro interno do servidor.';
    Response::serverError($msg);
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
