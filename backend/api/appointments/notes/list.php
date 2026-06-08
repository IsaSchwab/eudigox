<?php
/**
 * GET /api/appointments/notes/list.php?appointment_id=123
 *
 * Lista as anotações clínicas de uma consulta, em ordem cronológica
 * (da mais antiga para a mais nova).
 *
 * Acesso: doctor, nurse, admin e receptionist — todos que abrem o
 *         prontuário do paciente. (Decisão da equipe: recepção também vê.)
 */

require_once __DIR__ . '/../../../core/bootstrap.php';

if (!Request::isMethod('GET')) {
    Response::methodNotAllowed();
}

Auth::requireRole('doctor', 'nurse', 'admin', 'receptionist');

$appointmentId = (int)($_GET['appointment_id'] ?? 0);
if ($appointmentId <= 0) {
    Response::badRequest('Parâmetro appointment_id obrigatório.');
}

$pdo = Database::getConnection();

// Confirma que a consulta existe
$stmt = $pdo->prepare("SELECT id FROM appointments WHERE id = :id");
$stmt->execute([':id' => $appointmentId]);
if (!$stmt->fetch()) {
    Response::notFound('Consulta não encontrada.');
}

$stmt = $pdo->prepare("
    SELECT n.id,
           n.note,
           n.created_at,
           n.author_user_id,
           u.full_name AS author_name
    FROM appointment_notes n
    LEFT JOIN users u ON u.id = n.author_user_id
    WHERE n.appointment_id = :aid
    ORDER BY n.created_at ASC, n.id ASC
");
$stmt->execute([':aid' => $appointmentId]);
$notes = $stmt->fetchAll();

Response::success([
    'appointment_id' => $appointmentId,
    'notes'          => $notes,
]);
