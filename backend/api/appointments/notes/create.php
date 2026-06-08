<?php
/**
 * POST /api/appointments/notes/create.php
 *
 * Adiciona uma anotação clínica a uma consulta (APPEND-ONLY).
 * Cada chamada cria uma LINHA NOVA — nada é sobrescrito nem apagado.
 *
 * Acesso: doctor (apenas nas SUAS próprias consultas — mesma regra do
 *         appointments/update.php).
 *
 * Body: {
 *   "appointment_id": 123,
 *   "note": "texto da anotação"
 * }
 */

require_once __DIR__ . '/../../../core/bootstrap.php';

if (!Request::isMethod('POST')) {
    Response::methodNotAllowed();
}

$user = Auth::requireRole('doctor');
$body = Request::body();

$v = new Validator($body);
$v->required('appointment_id');
$v->required('note');
if ($v->fails()) Response::unprocessable('Dados inválidos.', $v->errors());

$appointmentId = (int)($body['appointment_id'] ?? 0);
$note          = trim((string)($body['note'] ?? ''));

if ($appointmentId <= 0) {
    Response::badRequest('Consulta inválida.');
}
if ($note === '') {
    Response::unprocessable('A anotação não pode ficar vazia.', ['note' => 'Escreva algo.']);
}

$pdo = Database::getConnection();

// Confirma que a consulta existe e descobre o médico responsável
$stmt = $pdo->prepare("SELECT id, doctor_user_id FROM appointments WHERE id = :id");
$stmt->execute([':id' => $appointmentId]);
$appt = $stmt->fetch();
if (!$appt) {
    Response::notFound('Consulta não encontrada.');
}

// Médica só anota nas próprias consultas
if ((int)$appt['doctor_user_id'] !== (int)$user['id']) {
    Response::forbidden('Você só pode anotar nas suas próprias consultas.');
}

$stmt = $pdo->prepare("
    INSERT INTO appointment_notes (appointment_id, author_user_id, note)
    VALUES (:aid, :uid, :note)
");
$stmt->execute([
    ':aid'  => $appointmentId,
    ':uid'  => (int)$user['id'],
    ':note' => $note,
]);
$noteId = (int)$pdo->lastInsertId();

Audit::log('APPOINTMENT_NOTE_ADDED', 'appointment', $appointmentId, [
    'note_id'        => $noteId,
    'author_user_id' => (int)$user['id'],
]);

Response::created([
    'id'             => $noteId,
    'appointment_id' => $appointmentId,
    'note'           => $note,
    'author_name'    => $user['full_name'] ?? null,
    'created_at'     => date('Y-m-d H:i:s'),
    'message'        => 'Anotação adicionada.',
]);
