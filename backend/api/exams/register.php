<?php
/**
 * POST /api/v1/exams/register
 * 
 * Registra um exame molecular (PCR ou Southern Blotting).
 * Acesso: APENAS doctor (RBAC).
 * 
 * Body: {
 *   "patient_id": 123,
 *   "screening_id": 45,        // opcional - triagem que motivou
 *   "exam_type": "pcr" | "southern_blotting",
 *   "exam_date": "YYYY-MM-DD",
 *   "result": "positive" | "negative" | "inconclusive",
 *   "laboratory": "...",       // opcional
 *   "notes": "..."             // opcional
 * }
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('POST')) {
    Response::methodNotAllowed();
}

$user = Auth::requireRole('doctor');
$body = Request::body();

$v = new Validator($body);
$v->required('patient_id');
$v->required('exam_type')->in('exam_type', ['pcr', 'southern_blotting']);
$v->required('exam_date')->date('exam_date');
$v->required('result')->in('result', ['positive', 'negative', 'inconclusive']);

if ($v->fails()) Response::unprocessable('Dados inválidos.', $v->errors());

$pdo = Database::getConnection();

// Confirma que o paciente existe
$stmt = $pdo->prepare("SELECT id FROM patients WHERE id = :id AND deleted_at IS NULL");
$stmt->execute([':id' => (int)$body['patient_id']]);
if (!$stmt->fetch()) Response::notFound('Paciente não encontrado.');

$stmt = $pdo->prepare("
    INSERT INTO molecular_exams
        (patient_id, screening_id, exam_type, exam_date, result,
         laboratory, notes, registered_by_user_id)
    VALUES
        (:pid, :sid, :type, :date, :result, :lab, :notes, :uid)
");
$stmt->execute([
    ':pid'    => (int)$body['patient_id'],
    ':sid'    => isset($body['screening_id']) ? (int)$body['screening_id'] : null,
    ':type'   => $body['exam_type'],
    ':date'   => $body['exam_date'],
    ':result' => $body['result'],
    ':lab'    => $body['laboratory'] ?? null,
    ':notes'  => $body['notes'] ?? null,
    ':uid'    => (int)$user['id'],
]);
$examId = (int)$pdo->lastInsertId();

// Se o exame foi positivo, fechar triagem associada
if (!empty($body['screening_id']) && $body['result'] === 'positive') {
    $pdo->prepare("
        UPDATE screenings
        SET status = 'closed', reviewed_by_user_id = :uid, reviewed_at = NOW()
        WHERE id = :sid
    ")->execute([
        ':uid' => (int)$user['id'],
        ':sid' => (int)$body['screening_id'],
    ]);
}

Audit::log('EXAM_REGISTERED', 'exam', $examId, [
    'patient_id' => (int)$body['patient_id'],
    'exam_type'  => $body['exam_type'],
    'result'     => $body['result'],
]);

Response::created([
    'exam_id' => $examId,
    'message' => 'Exame registrado com sucesso.',
]);
