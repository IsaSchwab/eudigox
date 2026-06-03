<?php
/**
 * POST /api/screenings/edit-answers.php
 *
 * Permite que uma profissional (doctor) corrija as respostas dos
 * indicadores de uma triagem. Recalcula score e prioridade
 * automaticamente.
 *
 * Acesso: doctor, admin.
 * Restrição: só a triagem MAIS RECENTE do paciente pode ser editada.
 *
 * Body: {
 *   screening_id: X,
 *   answers: [ { indicator_id, answer, observation? }, ... ]
 * }
 *
 * Resposta:
 *   {
 *     screening_id,
 *     old_score, new_score,
 *     old_priority, new_priority,
 *     message
 *   }
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/ScoreCalculator.php';

if (!Request::isMethod('POST') && !Request::isMethod('PATCH')) {
    Response::methodNotAllowed();
}

$user = Auth::requireRole('doctor', 'admin');
$body = Request::body();

$screeningId = (int)($body['screening_id'] ?? 0);
$answers     = $body['answers'] ?? null;

if ($screeningId <= 0) Response::badRequest('Campo screening_id obrigatório.');
if (!is_array($answers) || count($answers) === 0) {
    Response::badRequest('Lista de answers obrigatória.');
}

$pdo = Database::getConnection();

// Pega a triagem + paciente
$stmt = $pdo->prepare("
    SELECT s.id, s.patient_id, s.score, s.threshold_applied, s.priority, s.status,
           p.biological_sex, p.full_name
    FROM screenings s
    JOIN patients p ON p.id = s.patient_id
    WHERE s.id = :id AND s.deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([':id' => $screeningId]);
$screening = $stmt->fetch();
if (!$screening) Response::notFound('Triagem não encontrada.');

// Restrição: só pode editar a MAIS RECENTE do paciente
$stmt = $pdo->prepare("
    SELECT id FROM screenings
    WHERE patient_id = :pid AND deleted_at IS NULL
    ORDER BY submitted_at DESC, created_at DESC
    LIMIT 1
");
$stmt->execute([':pid' => (int)$screening['patient_id']]);
$latest = $stmt->fetch();
if (!$latest || (int)$latest['id'] !== $screeningId) {
    Response::forbidden('Só é possível editar a triagem mais recente do paciente.');
}

// Carrega respostas atuais pra comparar (auditoria) e validar indicadores
$stmt = $pdo->prepare("
    SELECT id, indicator_id, answer, observation
    FROM screening_answers
    WHERE screening_id = :sid
");
$stmt->execute([':sid' => $screeningId]);
$currentRows = $stmt->fetchAll();
$currentByInd = [];
foreach ($currentRows as $r) {
    $currentByInd[(int)$r['indicator_id']] = $r;
}

// Valida que todos os indicator_id enviados existem na triagem
$changes = [];
$normalizedAnswers = [];
foreach ($answers as $a) {
    $iid    = (int)($a['indicator_id'] ?? 0);
    $ans    = $a['answer']      ?? null;
    $obs    = array_key_exists('observation', $a) ? $a['observation'] : null;

    if ($iid <= 0) continue;
    if (!isset($currentByInd[$iid])) {
        Response::unprocessable("Indicador {$iid} não pertence a esta triagem.");
    }
    if (!in_array($ans, ['yes', 'no', 'unknown'], true)) {
        Response::unprocessable("Resposta inválida para indicador {$iid}.");
    }

    $normalizedAnswers[] = [
        'indicator_id' => $iid,
        'answer'       => $ans,
        'observation'  => $obs,
    ];

    // Detecta mudança (resposta ou observação)
    $cur = $currentByInd[$iid];
    if ($cur['answer'] !== $ans
        || ($cur['observation'] ?? '') !== ($obs ?? '')) {
        $changes[] = [
            'indicator_id' => $iid,
            'from' => ['answer' => $cur['answer'], 'observation' => $cur['observation']],
            'to'   => ['answer' => $ans,           'observation' => $obs],
        ];
    }
}

if (empty($changes)) {
    Response::success([
        'screening_id' => $screeningId,
        'changed'      => false,
        'message'      => 'Nenhuma alteração detectada.',
        'old_score'    => (float)$screening['score'],
        'new_score'    => (float)$screening['score'],
        'old_priority' => $screening['priority'],
        'new_priority' => $screening['priority'],
    ]);
}

// Recalcula score com as respostas NOVAS (substituindo as antigas
// dos indicators editados; mantém as demais).
$mergedAnswers = $currentByInd;
foreach ($normalizedAnswers as $a) {
    $mergedAnswers[$a['indicator_id']] = [
        'indicator_id' => $a['indicator_id'],
        'answer'       => $a['answer'],
        'observation'  => $a['observation'],
    ];
}
$forCalc = [];
foreach ($mergedAnswers as $r) {
    $forCalc[] = ['indicator_id' => $r['indicator_id'], 'answer' => $r['answer']];
}

$calc = ScoreCalculator::calculate($screening['biological_sex'], $forCalc);

// Aplica em transação
try {
    $pdo->beginTransaction();

    // Atualiza/insere respostas (UPDATE pelos indicators que mudaram)
    $upd = $pdo->prepare("
        UPDATE screening_answers
        SET answer = :a, observation = :o
        WHERE screening_id = :sid AND indicator_id = :iid
    ");
    foreach ($normalizedAnswers as $a) {
        $upd->execute([
            ':a'   => $a['answer'],
            ':o'   => $a['observation'],
            ':sid' => $screeningId,
            ':iid' => $a['indicator_id'],
        ]);
    }

    // Atualiza a triagem com novo score
    $stmt = $pdo->prepare("
        UPDATE screenings
        SET score = :sc, threshold_applied = :th, priority = :pr, recommendation = :rc,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':sc' => $calc['score'],
        ':th' => $calc['threshold'],
        ':pr' => $calc['priority'],
        ':rc' => $calc['recommendation'],
        ':id' => $screeningId,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

// Audit log com todas as mudanças
Audit::log('SCREENING_ANSWERS_EDITED', 'screening', $screeningId, [
    'edited_by_user_id' => (int)$user['id'],
    'edited_by_role'    => $user['role'],
    'patient_id'        => (int)$screening['patient_id'],
    'patient_name'      => $screening['full_name'],
    'changes'           => $changes,
    'old_score'         => (float)$screening['score'],
    'new_score'         => $calc['score'],
    'old_priority'      => $screening['priority'],
    'new_priority'      => $calc['priority'],
]);

Response::success([
    'screening_id' => $screeningId,
    'changed'      => true,
    'changes_count'=> count($changes),
    'old_score'    => (float)$screening['score'],
    'new_score'    => $calc['score'],
    'old_priority' => $screening['priority'],
    'new_priority' => $calc['priority'],
    'message'      => 'Respostas atualizadas e score recalculado.',
]);
