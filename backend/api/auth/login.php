<?php
/**
 * POST /api/auth/login.php
 * 
 * Body: { "email": "...", "password": "..." }
 * Resp: { "data": { "user": {...} } }
 * 
 * Cria a sessão PHP — o navegador automaticamente guarda o cookie de sessão
 * e envia em todas as requisições seguintes.
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('POST')) {
    Response::methodNotAllowed();
}

$body = Request::body();
$v = new Validator($body);
$v->required('email')->email('email');
$v->required('password');
if ($v->fails()) {
    Response::unprocessable('Dados inválidos.', $v->errors());
}

$email    = trim((string)$body['email']);
$password = (string)$body['password'];

$pdo = Database::getConnection();
$stmt = $pdo->prepare("
    SELECT id, email, password_hash, full_name, role, is_active
    FROM users
    WHERE email = :e AND deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([':e' => $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    Response::unauthorized('E-mail ou senha incorretos.');
}

if (!$user['is_active']) {
    Response::forbidden('Conta desativada. Contate o administrador.');
}

// Atualiza last_login_at
$pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")
    ->execute([':id' => $user['id']]);

// Cria sessão PHP
Auth::login((int)$user['id']);

Audit::log('USER_LOGIN', 'user', (int)$user['id']);

Response::success([
    'user' => [
        'id'        => (int)$user['id'],
        'email'     => $user['email'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
    ],
]);
