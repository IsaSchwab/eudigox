<?php
/**
 * PATCH /api/v1/screenings/review
 * 
 * Body: {
 *   "id": 123,
 *   "status": "reviewed" | "closed",
 *   "clinical_notes": "...",
 *   "include_notes_in_report": true|false
 * }
 * 
 * Acesso: doctor, nurse
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('PATCH') && !Request::isMethod('POST')) {
    Response::methodNotAllowed();
}

$user = Auth::requireRole('doctor', 'nurse');
$body = Request::body();

$id = (int)($body['id'] ?? 0);
if ($id <= 0) Response::badRequest('Campo id obrigatório.');

$status = $body['status'] ?? null;
if ($status && !in_array($status, ['reviewed', 'closed'], true)) {
    Response::badRequest('Status inválido.');
}

// Apenas médico pode "fechar" (closed)
if ($status === 'closed' && $user['role'] !== 'doctor') {
    Response::forbidden('Apenas médicos podem fechar uma triagem.');
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT id FROM screenings WHERE id = :id AND deleted_at IS NULL");
$stmt->execute([':id' => $id]);
if (!$stmt->fetch()) Response::notFound('Triagem não encontrada.');

$updates = [];
$params  = [':id' => $id];

if (array_key_exists('clinical_notes', $body)) {
    $updates[] = 'clinical_notes = :notes';
    $params[':notes'] = $body['clinical_notes'];
}
if (array_key_exists('include_notes_in_report', $body)) {
    $updates[] = 'include_notes_in_report = :inr';
    $params[':inr'] = $body['include_notes_in_report'] ? 1 : 0;
}
if ($status) {
    $updates[] = 'status = :st';
    $params[':st'] = $status;
    if ($status === 'reviewed') {
        $updates[] = 'reviewed_at = NOW()';
        $updates[] = 'reviewed_by_user_id = :rev';
        $params[':rev'] = (int)$user['id'];
    }
}

if (empty($updates)) Response::badRequest('Nenhuma alteração informada.');

$sql = "UPDATE screenings SET " . implode(', ', $updates) . " WHERE id = :id";
$pdo->prepare($sql)->execute($params);

Audit::log('SCREENING_REVIEWED', 'screening', $id, ['status' => $status]);

Response::success(['message' => 'Triagem atualizada.']);
