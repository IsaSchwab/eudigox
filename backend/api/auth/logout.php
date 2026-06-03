<?php
/**
 * POST /api/auth/logout.php
 * Destrói a sessão PHP.
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('POST')) {
    Response::methodNotAllowed();
}

$user = Auth::user();
if ($user) {
    Audit::log('USER_LOGOUT', 'user', (int)$user['id']);
}

Auth::logout();
Response::success(['message' => 'Sessão encerrada.']);
