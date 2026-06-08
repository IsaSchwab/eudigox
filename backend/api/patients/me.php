<?php
/**
 * GET /api/v1/patients/me
 * 
 * Retorna dados completos do paciente logado:
 *   - Dados pessoais + responsável
 *   - Histórico de triagens
 *   - Exames moleculares
 *   - Próximas consultas
 */
 
require_once __DIR__ . '/../../core/bootstrap.php';
 
if (!Request::isMethod('GET')) {
    Response::methodNotAllowed();
}
 
$user = Auth::requireRole('patient');
$pdo  = Database::getConnection();
 
// 1) Buscar paciente vinculado a este user
$stmt = $pdo->prepare("
    SELECT p.*, u.email AS user_email, u.last_login_at
    FROM patients p
    LEFT JOIN users u ON u.id = p.user_id
    WHERE p.user_id = :uid AND p.deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([':uid' => (int)$user['id']]);
$patient = $stmt->fetch();
 
if (!$patient) {
    Response::notFound('Você ainda não tem cadastro de paciente.');
}
 
$patientId = (int)$patient['id'];
 
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
$stmt->execute([':pid' => $patientId]);
$screenings = $stmt->fetchAll();

// 2b) Respostas de cada triagem — pra mostrar ao paciente o que ele mesmo
//     marcou. São dados do próprio paciente, então é ok exibir a ele.
if ($screenings) {
    $sids = array_map(fn($s) => (int)$s['id'], $screenings);
    $ph   = implode(',', array_fill(0, count($sids), '?'));
    $stmtAns = $pdo->prepare("
        SELECT sa.screening_id, sa.answer, i.lay_label, i.category, i.display_order
        FROM screening_answers sa
        JOIN indicators i ON i.id = sa.indicator_id
        WHERE sa.screening_id IN ($ph)
        ORDER BY i.category, i.display_order
    ");
    $stmtAns->execute($sids);
    $answersByScreening = [];
    foreach ($stmtAns->fetchAll() as $r) {
        $sid = (int)$r['screening_id'];
        if (!isset($answersByScreening[$sid])) {
            $answersByScreening[$sid] = ['development' => [], 'behavioral' => [], 'physical' => []];
        }
        $cat = $r['category'];
        if (!isset($answersByScreening[$sid][$cat])) $answersByScreening[$sid][$cat] = [];
        $answersByScreening[$sid][$cat][] = [
            'lay_label' => $r['lay_label'],
            'answer'    => $r['answer'],
        ];
    }
    foreach ($screenings as &$s) {
        $s['answers_by_category'] = $answersByScreening[(int)$s['id']] ?? null;
    }
    unset($s);
}

// 3) Exames moleculares
$stmt = $pdo->prepare("
    SELECT e.id, e.screening_id, e.exam_type, e.exam_date, e.result, e.laboratory, e.notes,
           u.full_name AS registered_by, e.created_at
    FROM molecular_exams e
    JOIN users u ON u.id = e.registered_by_user_id
    WHERE e.patient_id = :pid AND e.deleted_at IS NULL
    ORDER BY e.exam_date DESC
");
$stmt->execute([':pid' => $patientId]);
$exams = $stmt->fetchAll();
 
// 4) Consultas
$stmt = $pdo->prepare("
    SELECT a.id, a.scheduled_at, a.location, a.status, a.notes, a.meeting_link,
           u.full_name AS doctor_name
    FROM appointments a
    JOIN users u ON u.id = a.doctor_user_id
    WHERE a.patient_id = :pid
    ORDER BY a.scheduled_at DESC
");
$stmt->execute([':pid' => $patientId]);
$appointments = $stmt->fetchAll();

// 5) Respostas + anotações da triagem — APENAS se houver exame registrado
//    Regra de negócio: paciente vê o resultado completo só após exame molecular.
$screeningDetails = null;
if (!empty($exams) && !empty($screenings)) {
    // Pega a triagem mais recente que tenha sido revisada/encerrada
    $screeningId = (int)$screenings[0]['id'];

    $stmt = $pdo->prepare("
        SELECT s.id, s.score, s.threshold_applied, s.priority, s.recommendation,
               s.clinical_notes, s.include_notes_in_report,
               s.status, s.submitted_at, s.reviewed_at,
               u.full_name AS reviewed_by
        FROM screenings s
        LEFT JOIN users u ON u.id = s.reviewed_by_user_id
        WHERE s.id = :sid
    ");
    $stmt->execute([':sid' => $screeningId]);
    $detail = $stmt->fetch();

    // Respostas da triagem
    $stmt = $pdo->prepare("
        SELECT a.answer, a.observation,
               i.code, i.display_name, i.lay_label, i.category, i.display_order
        FROM screening_answers a
        JOIN indicators i ON i.id = a.indicator_id
        WHERE a.screening_id = :sid
        ORDER BY i.category, i.display_order
    ");
    $stmt->execute([':sid' => $screeningId]);
    $rawAnswers = $stmt->fetchAll();

    // Agrupa por categoria
    $byCategory = ['development' => [], 'behavioral' => [], 'physical' => []];
    foreach ($rawAnswers as $ra) {
        $byCategory[$ra['category']][] = [
            'code'         => $ra['code'],
            'display_name' => $ra['display_name'],
            'lay_label'    => $ra['lay_label'],
            'answer'       => $ra['answer'],
            'observation'  => $ra['observation'],
        ];
    }

    $screeningDetails = [
        'screening_id'   => $screeningId,
        'score'          => $detail ? (float)$detail['score']             : null,
        'threshold'      => $detail ? (float)$detail['threshold_applied'] : null,
        'priority'       => $detail['priority']       ?? null,
        'recommendation' => $detail['recommendation'] ?? null,
        // LGPD: anotações clínicas são SEMPRE privadas — nunca retornadas ao paciente.
        // (Antes dependiam do checkbox "incluir no relatório", que foi removido.)
        'clinical_notes' => null,
        'reviewed_by'    => $detail['reviewed_by'] ?? null,
        'reviewed_at'    => $detail['reviewed_at'] ?? null,
        'submitted_at'   => $detail['submitted_at'] ?? null,
        'answers_by_category' => $byCategory,
    ];
}

// Idade
$birth = new DateTime($patient['birth_date']);
$age   = (new DateTime())->diff($birth)->y;

Response::success([
    'patient' => [
        'id'                    => $patientId,
        'full_name'             => $patient['full_name'],
        'birth_date'            => $patient['birth_date'],
        'age'                   => $age,
        'biological_sex'        => $patient['biological_sex'],
        'cpf'                   => $patient['cpf'],
        'guardian_name'         => $patient['guardian_name'],
        'guardian_relationship' => $patient['guardian_relationship'],
        'guardian_phone'        => $patient['guardian_phone'],
        'guardian_email'        => $patient['guardian_email'],
        'user_email'            => $patient['user_email'],
        'last_login_at'         => $patient['last_login_at'],
        'created_at'            => $patient['created_at'],
    ],
    'screenings'        => $screenings,
    'exams'             => $exams,
    'appointments'      => $appointments,
    'screening_details' => $screeningDetails, // só preenchido se há exame registrado
]);