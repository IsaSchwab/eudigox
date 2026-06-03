<?php
/**
 * POST /api/appointments/cancel-self.php
 *
 * Paciente cancela a própria consulta.
 *
 * Acesso: APENAS o paciente logado (e a consulta tem que ser dele).
 *
 * Body: { appointment_id }
 *
 * Efeito: muda status para 'cancelled' (a vaga libera no calendário).
 * Não apaga o registro — fica no histórico do paciente.
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('POST')) Response::methodNotAllowed();

$user = Auth::requireRole('patient');
$body = Request::body();

$appointmentId = (int)($body['appointment_id'] ?? 0);
if ($appointmentId <= 0) Response::badRequest('Campo appointment_id obrigatório.');

$pdo = Database::getConnection();

// Pega o paciente desse user
$stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1");
$stmt->execute([':uid' => (int)$user['id']]);
$row = $stmt->fetch();
if (!$row) Response::notFound('Paciente não encontrado.');
$patientId = (int)$row['id'];

// Verifica que a consulta existe E é desse paciente E ainda não passou
$stmt = $pdo->prepare("
    SELECT id, scheduled_at, status
    FROM appointments
    WHERE id = :id AND patient_id = :pid
    LIMIT 1
");
$stmt->execute([':id' => $appointmentId, ':pid' => $patientId]);
$appt = $stmt->fetch();
if (!$appt) Response::notFound('Consulta não encontrada.');

if ($appt['status'] === 'cancelled') {
    Response::badRequest('Esta consulta já está cancelada.');
}
if ($appt['status'] === 'completed') {
    Response::badRequest('Não é possível cancelar uma consulta já realizada.');
}

// (Permite cancelar até o horário da consulta — depois disso a clínica
//  é quem marca como 'completed' ou 'no_show'.)
if (strtotime($appt['scheduled_at']) < time()) {
    Response::badRequest('Esta consulta já passou. Entre em contato com a clínica.');
}

$stmt = $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = :id");
$stmt->execute([':id' => $appointmentId]);

Audit::log('APPOINTMENT_SELF_CANCELLED', 'appointment', $appointmentId, [
    'patient_id' => $patientId,
]);

Response::success(['message' => 'Consulta cancelada.']);
