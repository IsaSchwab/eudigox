<?php
/**
 * GET /api/v1/patients/get?id=X
 * 
 * Retorna detalhe completo do paciente:
 *   - Dados pessoais + responsável
 *   - Histórico de triagens (resumo)
 *   - Exames moleculares
 *   - Próximas consultas
 * 
 * Acesso: doctor, nurse, admin (todos os pacientes)
 *         patient (apenas seus próprios dados)
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('GET')) {
    Response::methodNotAllowed();
}

$user = Auth::requireUser();
$id   = (int) Request::query('id', 0);
if ($id <= 0) Response::badRequest('Parâmetro id obrigatório.');

$pdo = Database::getConnection();

// 1) Paciente
$stmt = $pdo->prepare("
    SELECT p.*, u.email AS user_email, u.last_login_at,
           uc.full_name AS created_by_name
    FROM patients p
    LEFT JOIN users u  ON u.id  = p.user_id
    LEFT JOIN users uc ON uc.id = p.created_by_user_id
    WHERE p.id = :id AND p.deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$patient = $stmt->fetch();

if (!$patient) Response::notFound('Paciente não encontrado.');

// Se for paciente, só pode ver seus próprios dados
if ($user['role'] === 'patient' && (int)$patient['user_id'] !== (int)$user['id']) {
    Response::forbidden();
}

// 2) Histórico de triagens
$stmt = $pdo->prepare("
    SELECT s.id, s.score, s.threshold_applied, s.priority, s.recommendation,
           s.status, s.submitted_at, s.reviewed_at, s.created_at,
           u.full_name AS performed_by
    FROM screenings s
    LEFT JOIN users u ON u.id = s.performed_by_user_id
    WHERE s.patient_id = :pid AND s.deleted_at IS NULL
    ORDER BY s.created_at DESC
");
$stmt->execute([':pid' => $id]);
$screenings = $stmt->fetchAll();

// 3) Exames moleculares
$stmt = $pdo->prepare("
    SELECT e.id, e.exam_type, e.exam_date, e.result, e.laboratory, e.notes,
           u.full_name AS registered_by, e.created_at
    FROM molecular_exams e
    JOIN users u ON u.id = e.registered_by_user_id
    WHERE e.patient_id = :pid AND e.deleted_at IS NULL
    ORDER BY e.exam_date DESC
");
$stmt->execute([':pid' => $id]);
$exams = $stmt->fetchAll();

// 4) Próximas consultas
$stmt = $pdo->prepare("
    SELECT a.id, a.scheduled_at, a.location, a.status, a.notes, a.meeting_link,
           a.doctor_user_id,
           u.full_name AS doctor_name
    FROM appointments a
    JOIN users u ON u.id = a.doctor_user_id
    WHERE a.patient_id = :pid
    ORDER BY a.scheduled_at DESC
");
$stmt->execute([':pid' => $id]);
$appointments = $stmt->fetchAll();

// 5) Uploads (fotos + requisição) — não retorna o arquivo, só os metadados.
//    O front baixa o arquivo via /api/uploads/get.php?id=NN
$stmt = $pdo->prepare("
    SELECT id, kind, original_name, mime_type, size_bytes, created_at
    FROM patient_uploads
    WHERE patient_id = :pid AND deleted_at IS NULL
    ORDER BY kind, created_at DESC
");
$stmt->execute([':pid' => $id]);
$uploads = $stmt->fetchAll();

// 6) Triagem socioeconômica (mais recente)
$stmt = $pdo->prepare("
    SELECT *
    FROM socioeconomic_assessments
    WHERE patient_id = :pid
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([':pid' => $id]);
$socio = $stmt->fetch();

// Renda per capita — só pra exibição na ficha clínica.
// O sistema NÃO usa esse número pra decidir nada.
$socioPayload = null;
if ($socio) {
    // Mapa de faixas → valor médio em salários mínimos (referência simples
    // pra mostrar uma estimativa de renda per capita; clínica decide manual)
    $incomeMidpointSM = [
        'up_to_1' => 0.5,
        '1_to_2'  => 1.5,
        '2_to_3'  => 2.5,
        'above_3' => 3.5,
    ];
    $hh = max(1, (int)$socio['household_size']);
    $estSM = $incomeMidpointSM[$socio['income_range']] ?? null;
    $perCapitaEstSM = $estSM !== null ? round($estSM / $hh, 2) : null;

    $socioPayload = [
        'id'                   => (int)$socio['id'],
        'household_size'       => (int)$socio['household_size'],
        'income_range'         => $socio['income_range'],
        'receives_benefit'     => (bool)$socio['receives_benefit'],
        'benefit_details'      => $socio['benefit_details'],
        'provider_work_status' => $socio['provider_work_status'],
        'has_health_plan'      => (bool)$socio['has_health_plan'],
        'provider_education'   => $socio['provider_education'],
        'observations'         => $socio['observations'],
        'created_at'           => $socio['created_at'],
        // Estimativa apenas para referência clínica (sistema não decide):
        'income_midpoint_sm'   => $estSM,
        'per_capita_est_sm'    => $perCapitaEstSM,
    ];
}

// Calcular idade
$birth = new DateTime($patient['birth_date']);
$age   = (new DateTime())->diff($birth)->y;

Response::success([
    'patient' => [
        'id'                    => (int)$patient['id'],
        'full_name'             => $patient['full_name'],
        'birth_date'            => $patient['birth_date'],
        'age'                   => $age,
        'biological_sex'        => $patient['biological_sex'],
        'cpf'                   => $patient['cpf'],
        'phone'                 => $patient['phone']        ?? null,
        'zip_code'              => $patient['zip_code']     ?? null,
        'street'                => $patient['street']       ?? null,
        'number'                => $patient['number']       ?? null,
        'complement'            => $patient['complement']   ?? null,
        'neighborhood'          => $patient['neighborhood'] ?? null,
        'city'                  => $patient['city']         ?? null,
        'state'                 => $patient['state']        ?? null,
        'guardian_name'         => $patient['guardian_name'],
        'guardian_relationship' => $patient['guardian_relationship'],
        'guardian_phone'        => $patient['guardian_phone'],
        'guardian_email'        => $patient['guardian_email'],
        'family_history_notes'  => $patient['family_history_notes'] ?? null,
        'user_email'            => $patient['user_email'],
        'last_login_at'         => $patient['last_login_at'],
        'created_at'            => $patient['created_at'],
        'created_by'            => $patient['created_by_name'],
    ],
    'screenings'    => $screenings,
    'exams'         => $exams,
    'appointments'  => $appointments,
    'uploads'       => $uploads,
    'socioeconomic' => $socioPayload,
]);
