<?php
/**
 * POST /api/appointments/create.php
 * 
 * Acesso: APENAS admin.
 * 
 * Body: {
 *   "patient_id": X,
 *   "doctor_user_id": X,
 *   "scheduled_at": "YYYY-MM-DD HH:MM:SS",
 *   "location": "...",  (opcional)
 *   "notes": "..."      (opcional)
 * }
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('POST')) Response::methodNotAllowed();

$admin = Auth::requireRole('admin', 'receptionist');
$body  = Request::body();

$v = new Validator($body);
$v->required('patient_id');
$v->required('doctor_user_id');
$v->required('scheduled_at');

if ($v->fails()) Response::unprocessable('Dados inválidos.', $v->errors());

// Valida data
$dt = DateTime::createFromFormat('Y-m-d H:i:s', $body['scheduled_at'])
   ?: DateTime::createFromFormat('Y-m-d\TH:i', $body['scheduled_at'])
   ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $body['scheduled_at']);
if (!$dt) {
    Response::unprocessable('Data/hora inválida.', ['scheduled_at' => ['Formato esperado: YYYY-MM-DD HH:MM']]);
}
$scheduledAt = $dt->format('Y-m-d H:i:s');

$pdo = Database::getConnection();

// Valida paciente
$stmt = $pdo->prepare("SELECT id FROM patients WHERE id = :id AND deleted_at IS NULL");
$stmt->execute([':id' => (int)$body['patient_id']]);
if (!$stmt->fetch()) Response::notFound('Paciente não encontrado.');

// Valida médico
$stmt = $pdo->prepare("
    SELECT id FROM users
    WHERE id = :id AND role IN ('doctor','nurse')
      AND is_active = 1 AND deleted_at IS NULL
");
$stmt->execute([':id' => (int)$body['doctor_user_id']]);
if (!$stmt->fetch()) Response::notFound('Profissional não encontrado ou inativo.');

$stmt = $pdo->prepare("
    INSERT INTO appointments (patient_id, doctor_user_id, scheduled_at, location, notes)
    VALUES (:pid, :did, :sa, :loc, :notes)
");
$stmt->execute([
    ':pid'   => (int)$body['patient_id'],
    ':did'   => (int)$body['doctor_user_id'],
    ':sa'    => $scheduledAt,
    ':loc'   => $body['location'] ?? null,
    ':notes' => $body['notes']    ?? null,
]);
$id = (int)$pdo->lastInsertId();

Audit::log('APPOINTMENT_CREATED', 'appointment', $id, [
    'patient_id' => (int)$body['patient_id'],
    'scheduled_by_admin' => (int)$admin['id'],
]);

Response::created([
    'appointment_id' => $id,
    'message' => 'Consulta agendada.',
]);
