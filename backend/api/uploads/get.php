<?php
/**
 * GET /api/uploads/get.php?id=N
 *
 * Serve o arquivo de patient_uploads (foto ou requisição) com checagem
 * de permissão. Acesso permitido para:
 *   - profissionais (admin, doctor, nurse)
 *   - o próprio paciente dono do arquivo
 *
 * Como o arquivo está em backend/uploads/ (fora do DocumentRoot público
 * graças ao .htaccess), este endpoint é a ÚNICA porta de entrada.
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('GET')) {
    Response::methodNotAllowed();
}

$user = Auth::requireUser();
$id   = (int) Request::query('id', 0);
if ($id <= 0) Response::badRequest('Parâmetro id obrigatório.');

$pdo = Database::getConnection();
$stmt = $pdo->prepare("
    SELECT pu.*, p.user_id AS patient_user_id
    FROM patient_uploads pu
    JOIN patients p ON p.id = pu.patient_id
    WHERE pu.id = :id AND pu.deleted_at IS NULL AND p.deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) Response::notFound('Arquivo não encontrado.');

// Permissão
$isProfessional = in_array($user['role'], ['admin', 'doctor', 'nurse'], true);
$isOwnerPatient = $user['role'] === 'patient'
               && (int)$row['patient_user_id'] === (int)$user['id'];
if (!$isProfessional && !$isOwnerPatient) {
    Response::forbidden();
}

$filePath = UPLOAD_DIR . '/' . $row['stored_path'];
if (!is_file($filePath)) {
    Response::notFound('Arquivo físico não encontrado no servidor.');
}

// Audit
Audit::log('PATIENT_UPLOAD_VIEWED', 'patient_upload', $id, [
    'kind' => $row['kind'],
]);

// Stream
header('Content-Type: '   . $row['mime_type']);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . basename($row['original_name']) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($filePath);
exit;
