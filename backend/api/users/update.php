<?php
/**
 * PATCH /api/v1/users/update
 * 
 * Atualiza dados de profissional ou ativa/desativa.
 * Acesso: APENAS admin.
 * 
 * Body: {
 *   "id": 5,
 *   "full_name": "...",       // opcional
 *   "phone": "...",           // opcional
 *   "professional_id": "...", // opcional
 *   "is_active": true|false,  // opcional
 *   "password": "..."         // opcional - reset de senha
 * }
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('PATCH') && !Request::isMethod('POST')) {
    Response::methodNotAllowed();
}

$admin = Auth::requireRole('admin');
$body  = Request::body();

$id = (int)($body['id'] ?? 0);
if ($id <= 0) Response::badRequest('Campo id obrigatório.');

// Admin não pode desativar a si mesmo (evita lockout)
if ($id === (int)$admin['id'] && isset($body['is_active']) && !$body['is_active']) {
    Response::badRequest('Você não pode desativar sua própria conta.');
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = :id AND deleted_at IS NULL");
$stmt->execute([':id' => $id]);
$target = $stmt->fetch();
if (!$target) Response::notFound('Usuário não encontrado.');

if ($target['role'] === 'patient') {
    Response::forbidden('Esta tela gerencia apenas profissionais.');
}

$updates = [];
$params  = [':id' => $id];

if (array_key_exists('full_name', $body) && trim($body['full_name']) !== '') {
    $updates[] = 'full_name = :n'; $params[':n'] = $body['full_name'];
}
if (array_key_exists('phone', $body)) {
    $updates[] = 'phone = :ph'; $params[':ph'] = $body['phone'];
}
if (array_key_exists('professional_id', $body)) {
    $updates[] = 'professional_id = :pid'; $params[':pid'] = $body['professional_id'];
}
if (array_key_exists('is_active', $body)) {
    $updates[] = 'is_active = :a'; $params[':a'] = $body['is_active'] ? 1 : 0;
}
if (!empty($body['password'])) {
    if (strlen($body['password']) < PASSWORD_MIN_LENGTH) {
        Response::unprocessable('Senha muito curta.');
    }
    $updates[] = 'password_hash = :p';
    $params[':p'] = password_hash($body['password'], PASSWORD_BCRYPT);
}

if (empty($updates)) Response::badRequest('Nenhuma alteração informada.');

$pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id")
    ->execute($params);

Audit::log('USER_UPDATED_BY_ADMIN', 'user', $id, [
    'updated_by' => (int)$admin['id'],
    'fields'     => array_keys(array_intersect_key($body, array_flip(
        ['full_name', 'phone', 'professional_id', 'is_active', 'password']
    ))),
]);

Response::success(['message' => 'Usuário atualizado.']);
