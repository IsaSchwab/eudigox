<?php
/**
 * POST /api/v1/users/create
 * 
 * Cria novo profissional (doctor, nurse ou admin).
 * Acesso: APENAS admin.
 * 
 * Body: { email, password, full_name, role, professional_id?, phone? }
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('POST')) Response::methodNotAllowed();

$admin = Auth::requireRole('admin');
$body  = Request::body();

$v = new Validator($body);
$v->required('email')->email('email');
$v->required('password')->minLength('password', PASSWORD_MIN_LENGTH);
$v->required('full_name')->maxLength('full_name', 180);
$v->required('role')->in('role', ['doctor', 'nurse', 'admin', 'receptionist']);

if ($v->fails()) Response::unprocessable('Dados inválidos.', $v->errors());

// Para doctor/nurse, exigir professional_id (CRM/COREN)
if (in_array($body['role'], ['doctor', 'nurse'], true) && empty($body['professional_id'])) {
    Response::unprocessable('Dados inválidos.', [
        'professional_id' => ['Obrigatório para médicos e enfermeiros (CRM ou COREN).']
    ]);
}

$pdo = Database::getConnection();

// E-mail único
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
$stmt->execute([':e' => $body['email']]);
if ($stmt->fetch()) Response::conflict('Já existe usuário com este e-mail.');

$stmt = $pdo->prepare("
    INSERT INTO users (email, password_hash, full_name, role, professional_id, phone)
    VALUES (:e, :p, :n, :r, :pid, :ph)
");
$stmt->execute([
    ':e'  => $body['email'],
    ':p'  => password_hash($body['password'], PASSWORD_BCRYPT),
    ':n'  => $body['full_name'],
    ':r'  => $body['role'],
    ':pid'=> $body['professional_id'] ?? null,
    ':ph' => $body['phone'] ?? null,
]);
$userId = (int)$pdo->lastInsertId();

Audit::log('USER_CREATED_BY_ADMIN', 'user', $userId, [
    'role'       => $body['role'],
    'created_by' => (int)$admin['id'],
]);

Response::created([
    'user_id' => $userId,
    'message' => 'Profissional criado com sucesso.',
]);
