<?php
/**
 * POST /api/screenings/create-self.php
 *
 * Paciente JÁ CADASTRADO faz uma NOVA triagem, reaproveitando seu cadastro.
 * Não recria user/patient nem pede dados pessoais.
 *
 * Coleta: questionário (indicadores) + anexos (fotos + requisição) + socioeconômica.
 * Tudo é vinculado à nova triagem (screening_id).
 *
 * Cria, em uma transação:
 *   - screenings (status='submitted', score calculado)
 *   - screening_answers
 *   - patient_uploads (com screening_id)
 *   - socioeconomic_assessments (com screening_id)
 *
 * Acesso: APENAS o paciente logado.
 *
 * Body: {
 *   "answers": [ { indicator_id, answer, observation? }, ... ],
 *   "upload_tokens": { photo_front, photo_side, medical_request },
 *   "socioeconomic": { ... }
 * }
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/ScoreCalculator.php';

if (!Request::isMethod('POST')) {
    Response::methodNotAllowed();
}

$user = Auth::requireRole('patient');
$body = Request::body();

$answers = $body['answers']       ?? [];
$uploads = $body['upload_tokens'] ?? [];
$socio   = $body['socioeconomic'] ?? [];

// ----- Validação dos indicadores -----
if (!is_array($answers) || count($answers) === 0) {
    Response::unprocessable('É preciso responder ao menos um indicador.');
}

// ----- Validação dos anexos (mesma do cadastro) -----
$pendingFromSession = $_SESSION['pending_uploads'] ?? [];
$requiredKinds = ['photo_front', 'photo_side', 'medical_request'];
$uploadsByKind = [];
$uploadErrors  = [];
foreach ($requiredKinds as $kind) {
    $token = $uploads[$kind] ?? null;
    if (!$token || !isset($pendingFromSession[$token])) {
        $uploadErrors[$kind] = match ($kind) {
            'photo_front'     => 'Falta enviar a foto de frente do paciente.',
            'photo_side'      => 'Falta enviar a foto de perfil do paciente.',
            'medical_request' => 'Falta enviar a requisição médica.',
        };
        continue;
    }
    $info = $pendingFromSession[$token];
    if (($info['kind'] ?? null) !== $kind) {
        $uploadErrors[$kind] = 'Tipo do arquivo enviado não corresponde ao esperado.';
        continue;
    }
    $uploadsByKind[$kind] = ['token' => $token] + $info;
}

// ----- Validação da socioeconômica (mesma do cadastro) -----
$socio = is_array($socio) ? $socio : [];
$validIncomeRanges = ['up_to_1', '1_to_2', '2_to_3', 'above_3'];
$validWorkStatuses = ['formal', 'informal', 'unemployed', 'retired'];
$validEducation    = [
    'fundamental_incomplete', 'fundamental_complete',
    'high_school_incomplete', 'high_school_complete',
    'higher_incomplete',      'higher_complete',
    'postgrad_incomplete',    'postgrad_complete',
];

$socioErrors = [];
$householdSize = (int)($socio['household_size'] ?? 0);
if ($householdSize < 1 || $householdSize > 30) {
    $socioErrors['household_size'] = 'Informe quantas pessoas moram na casa (entre 1 e 30).';
}
if (!in_array($socio['income_range'] ?? '', $validIncomeRanges, true)) {
    $socioErrors['income_range'] = 'Selecione a faixa de renda mensal da família.';
}
$receivesBenefit = !empty($socio['receives_benefit']) ? 1 : 0;
if (!in_array($socio['provider_work_status'] ?? '', $validWorkStatuses, true)) {
    $socioErrors['provider_work_status'] = 'Selecione a situação de trabalho do provedor.';
}
$hasHealthPlan = !empty($socio['has_health_plan']) ? 1 : 0;
if (!in_array($socio['provider_education'] ?? '', $validEducation, true)) {
    $socioErrors['provider_education'] = 'Selecione a escolaridade do responsável/provedor.';
}

// ----- Junta erros (anexos + socio) e responde de uma vez -----
$allErrors = array_merge($uploadErrors, $socioErrors);
if ($allErrors) {
    Response::unprocessable('Não é possível enviar: faltam informações.', $allErrors);
}

$pdo = Database::getConnection();

// Pega o paciente do usuário logado (e o sexo, pra calcular o score)
$stmt = $pdo->prepare("
    SELECT id, biological_sex
    FROM patients
    WHERE user_id = :uid AND deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([':uid' => (int)$user['id']]);
$patient = $stmt->fetch();
if (!$patient) {
    Response::notFound('Paciente não encontrado para este usuário. Faça a triagem inicial primeiro.');
}
$patientId = (int)$patient['id'];
$sex       = $patient['biological_sex'];

// Regra de produto: só permite NOVA triagem se a consulta da triagem anterior
// já foi realizada — ou seja, existe um agendamento 'completed' com data igual
// ou posterior à última triagem do paciente.
$stmt = $pdo->prepare("
    SELECT COALESCE(submitted_at, created_at) AS last_dt
    FROM screenings
    WHERE patient_id = :pid AND deleted_at IS NULL
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([':pid' => $patientId]);
$lastScreening = $stmt->fetch();
if ($lastScreening) {
    $stmt = $pdo->prepare("
        SELECT id FROM appointments
        WHERE patient_id = :pid AND status = 'completed'
          AND scheduled_at >= :dt
        LIMIT 1
    ");
    $stmt->execute([':pid' => $patientId, ':dt' => $lastScreening['last_dt']]);
    if (!$stmt->fetch()) {
        Response::conflict('Para fazer uma nova triagem, primeiro agende e realize a consulta da triagem anterior.');
    }
}

$uploadsDir = realpath(__DIR__ . '/../../uploads');
$pendingDir = $uploadsDir . '/_pending';
$movedFiles = []; // [dst => src] — pra desfazer se a transação falhar

try {
    $pdo->beginTransaction();

    // Score
    $result = ScoreCalculator::calculate($sex, $answers);

    // Screening
    $stmt = $pdo->prepare("
        INSERT INTO screenings
            (patient_id, score, threshold_applied, priority, recommendation,
             status, submitted_at)
        VALUES
            (:pid, :sc, :th, :pr, :rc, 'submitted', NOW())
    ");
    $stmt->execute([
        ':pid' => $patientId,
        ':sc'  => $result['score'],
        ':th'  => $result['threshold'],
        ':pr'  => $result['priority'],
        ':rc'  => $result['recommendation'],
    ]);
    $screeningId = (int)$pdo->lastInsertId();

    // Respostas
    $stmtA = $pdo->prepare("
        INSERT INTO screening_answers (screening_id, indicator_id, answer, observation)
        VALUES (:sid, :iid, :ans, :obs)
    ");
    foreach ($answers as $a) {
        $stmtA->execute([
            ':sid' => $screeningId,
            ':iid' => (int)($a['indicator_id'] ?? 0),
            ':ans' => $a['answer'] ?? 'unknown',
            ':obs' => $a['observation'] ?? null,
        ]);
    }

    // Anexos: move de _pending pra pasta do paciente e registra (com screening_id)
    $patientFolderRel = 'patients/' . $patientId;
    $patientFolderAbs = $uploadsDir . '/' . $patientFolderRel;
    if (!is_dir($patientFolderAbs)) {
        @mkdir($patientFolderAbs, 0775, true);
    }
    $stmtUp = $pdo->prepare("
        INSERT INTO patient_uploads
            (patient_id, screening_id, kind, original_name, stored_path, mime_type, size_bytes,
             uploaded_by_user_id)
        VALUES
            (:pid, :sid, :kind, :orig, :path, :mime, :size, :uid)
    ");
    foreach ($uploadsByKind as $kind => $up) {
        $src = $pendingDir . '/' . $up['stored_name'];
        if (!is_file($src)) {
            throw new RuntimeException("Arquivo temporário não encontrado: {$kind}");
        }
        $dstName = $up['stored_name'];
        $dstAbs  = $patientFolderAbs . '/' . $dstName;
        $dstRel  = $patientFolderRel  . '/' . $dstName;
        if (!@rename($src, $dstAbs)) {
            throw new RuntimeException("Falha ao mover upload {$kind}.");
        }
        $movedFiles[$dstAbs] = $src;
        $stmtUp->execute([
            ':pid'  => $patientId,
            ':sid'  => $screeningId,
            ':kind' => $kind,
            ':orig' => $up['original_name'],
            ':path' => $dstRel,
            ':mime' => $up['mime_type'],
            ':size' => $up['size_bytes'],
            ':uid'  => (int)$user['id'],
        ]);
    }

    // Socioeconômica (ligada a ESTA triagem)
    $benefitDetails = $receivesBenefit && !empty($socio['benefit_details'])
        ? trim((string)$socio['benefit_details']) : null;
    $observations = !empty($socio['observations'])
        ? trim((string)$socio['observations']) : null;
    $stmtS = $pdo->prepare("
        INSERT INTO socioeconomic_assessments
            (patient_id, screening_id, household_size, income_range,
             receives_benefit, benefit_details,
             provider_work_status, has_health_plan,
             provider_education, observations)
        VALUES
            (:pid, :sid, :hh, :ir, :rb, :bd, :ws, :hp, :ed, :ob)
    ");
    $stmtS->execute([
        ':pid' => $patientId,
        ':sid' => $screeningId,
        ':hh'  => $householdSize,
        ':ir'  => $socio['income_range'],
        ':rb'  => $receivesBenefit,
        ':bd'  => $benefitDetails,
        ':ws'  => $socio['provider_work_status'],
        ':hp'  => $hasHealthPlan,
        ':ed'  => $socio['provider_education'],
        ':ob'  => $observations,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Devolve arquivos movidos pra pasta temporária (não deixa lixo)
    foreach ($movedFiles as $dst => $src) {
        if (is_file($dst)) @rename($dst, $src);
    }
    throw $e;
}

// Limpa tokens da sessão (já processados)
foreach ($uploadsByKind as $kind => $up) {
    unset($_SESSION['pending_uploads'][$up['token']]);
}

Audit::log('PATIENT_RETRIAGE', 'screening', $screeningId, [
    'patient_id' => $patientId,
    'priority'   => $result['priority'],
]);

Response::created([
    'screening_id'   => $screeningId,
    'score'          => $result['score'],
    'priority'       => $result['priority'],
    'recommendation' => $result['recommendation'],
    'message'        => 'Nova triagem enviada com sucesso. A equipe clínica vai analisar.',
]);
