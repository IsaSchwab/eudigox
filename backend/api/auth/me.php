<?php
/**
 * GET /api/v1/auth/me
 * Útil para o front conferir se o token ainda é válido e recarregar dados do usuário.
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('GET')) {
    Response::methodNotAllowed();
}

$user = Auth::requireUser();

Response::success([
    'user' => [
        'id'        => (int)$user['id'],
        'email'     => $user['email'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
    ],
]);
