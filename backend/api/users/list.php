<?php
/**
 * GET /api/v1/users/list
 * 
 * Lista profissionais (doctor, nurse, admin). Não retorna pacientes.
 * Acesso: APENAS admin.
 * 
 * Query: ?role=doctor|nurse|admin (opcional)
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('GET')) Response::methodNotAllowed();

Auth::requireRole('admin', 'receptionist');

$role = Request::query('role');
$pdo  = Database::getConnection();

$where  = ["u.role IN ('doctor', 'nurse', 'admin')", "u.deleted_at IS NULL"];
$params = [];
if (in_array($role, ['doctor', 'nurse', 'admin'], true)) {
    $where[] = 'u.role = :r';
    $params[':r'] = $role;
}

$sql = "
    SELECT u.id, u.email, u.full_name, u.role, u.professional_id,
           u.phone, u.is_active, u.last_login_at, u.created_at
    FROM users u
    WHERE " . implode(' AND ', $where) . "
    ORDER BY u.full_name
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

Response::success($stmt->fetchAll());
