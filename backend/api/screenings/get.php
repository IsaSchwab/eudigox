<?php
/**
 * GET /api/v1/screenings/get?id=X
 * 
 * Detalhe completo de uma triagem incluindo todas as respostas
 * com nome do indicador (já joinado).
 * 
 * Acesso: doctor, nurse, admin (qualquer triagem)
 *         patient (apenas triagens do próprio paciente)
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('GET')) {
    Response::methodNotAllowed();
}

$user = Auth::requireUser();
$id   = (int) Request::query('id', 0);
if ($id <= 0) Response::badRequest('Parâmetro id obrigatório.');

$pdo = Database::getConnection();

// Triagem + paciente
$stmt = $pdo->prepare("
    SELECT s.*, p.full_name AS patient_name, p.birth_date, p.biological_sex,
           p.user_id AS patient_user_id,
           u.full_name AS performed_by, ur.full_name AS reviewed_by
    FROM screenings s
    JOIN patients p ON p.id = s.patient_id
    LEFT JOIN users u  ON u.id  = s.performed_by_user_id
    LEFT JOIN users ur ON ur.id = s.reviewed_by_user_id
    WHERE s.id = :id AND s.deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$screening = $stmt->fetch();

if (!$screening) Response::notFound('Triagem não encontrada.');

// Paciente só vê suas próprias triagens
if ($user['role'] === 'patient' && (int)$screening['patient_user_id'] !== (int)$user['id']) {
    Response::forbidden();
}

// Respostas com info do indicador
$stmt = $pdo->prepare("
    SELECT a.id, a.answer, a.observation,
           i.id AS indicator_id, i.code, i.display_name, i.lay_label,
           i.category, i.weight_male, i.weight_female, i.applies_to,
           i.display_order
    FROM screening_answers a
    JOIN indicators i ON i.id = a.indicator_id
    WHERE a.screening_id = :sid
    ORDER BY i.category, i.display_order
");
$stmt->execute([':sid' => $id]);
$answers = $stmt->fetchAll();

// Agrupa respostas por categoria + calcula contribuição de cada indicador "yes"
$sex = $screening['biological_sex'];
$weightField = ($sex === 'M') ? 'weight_male' : 'weight_female';
$grouped = ['development' => [], 'behavioral' => [], 'physical' => []];

foreach ($answers as $a) {
    $contribution = 0;
    if ($a['answer'] === 'yes' &&
        ($a['applies_to'] === 'both' || $a['applies_to'] === $sex)) {
        $contribution = (float)$a[$weightField];
    }
    $grouped[$a['category']][] = [
        'indicator_id' => (int)$a['indicator_id'],
        'code'         => $a['code'],
        'display_name' => $a['display_name'],
        'lay_label'    => $a['lay_label'],
        'answer'       => $a['answer'],
        'observation'  => $a['observation'],
        'contribution' => round($contribution, 4),
    ];
}

// Esconde score do paciente (só vê resultado liberado)
$showScore = $user['role'] !== 'patient' || $screening['status'] === 'closed';

Response::success([
    'screening' => [
        'id'                    => (int)$screening['id'],
        'patient_id'            => (int)$screening['patient_id'],
        'patient_name'          => $screening['patient_name'],
        'patient_age'           => (new DateTime())->diff(new DateTime($screening['birth_date']))->y,
        'patient_sex'           => $sex,
        'score'                 => $showScore ? (float)$screening['score'] : null,
        'threshold_applied'     => $showScore ? (float)$screening['threshold_applied'] : null,
        'priority'              => $showScore ? $screening['priority'] : null,
        'recommendation'        => $showScore ? $screening['recommendation'] : null,
        'clinical_notes'        => $screening['clinical_notes'],
        'include_notes_in_report' => (int)$screening['include_notes_in_report'],
        'status'                => $screening['status'],
        'submitted_at'          => $screening['submitted_at'],
        'reviewed_at'           => $screening['reviewed_at'],
        'performed_by'          => $screening['performed_by'],
        'reviewed_by'           => $screening['reviewed_by'],
        'created_at'            => $screening['created_at'],
    ],
    'answers_by_category' => $grouped,
]);
