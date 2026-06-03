<?php
/**
 * GET /api/screenings/list.php
 *
 * Lista triagens.
 *
 * Regras de acesso:
 *   - admin / nurse / receptionist: vê TODAS as triagens
 *   - doctor: vê APENAS triagens de pacientes vinculados a ele
 *             (com consulta agendada)
 *
 * Query params:
 *   - priority           = high | medium | low
 *   - status             = draft | submitted | reviewed | closed
 *   - appointment_status = none | scheduled | completed
 *       none      = paciente NÃO tem consulta agendada (pendente)
 *       scheduled = paciente tem consulta agendada (status=scheduled)
 *       completed = paciente tem consulta realizada (status=completed)
 *   - from, to           = YYYY-MM-DD
 *   - cpf                = busca por CPF
 *   - page, per_page (default 1, 20)
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('GET')) {
    Response::methodNotAllowed();
}

$user = Auth::requireRole('doctor', 'nurse', 'admin', 'receptionist');

$priority          = Request::query('priority');
$status            = Request::query('status');
$appointmentStatus = Request::query('appointment_status');
$from              = Request::query('from');
$to                = Request::query('to');
$cpf               = Request::query('cpf');
$page              = max(1, (int)Request::query('page', 1));
$perPage           = min(100, max(1, (int)Request::query('per_page', 20)));

$where  = ['s.deleted_at IS NULL'];
$params = [];

// Médico só vê pacientes vinculados via consultas
if ($user['role'] === 'doctor') {
    $where[] = 'p.id IN (
        SELECT DISTINCT a.patient_id
        FROM appointments a
        WHERE a.doctor_user_id = :doctor_uid
    )';
    $params[':doctor_uid'] = (int)$user['id'];
}

if (in_array($priority, ['high', 'medium', 'low'], true)) {
    $where[] = 's.priority = :pr';
    $params[':pr'] = $priority;
}
if (in_array($status, ['draft', 'submitted', 'reviewed', 'closed'], true)) {
    $where[] = 's.status = :st';
    $params[':st'] = $status;
}

// Filtros por status de consulta — chave da tela da recepção
//   'pending' (ou 'none') = sem consulta agendada nem realizada
//   'scheduled'           = tem consulta agendada
//   'completed'           = tem consulta realizada
if ($appointmentStatus === 'none' || $appointmentStatus === 'pending') {
    $where[] = 'NOT EXISTS (
        SELECT 1 FROM appointments a
        WHERE a.patient_id = p.id AND a.status IN ("scheduled", "completed")
    )';
} elseif ($appointmentStatus === 'scheduled') {
    $where[] = 'EXISTS (
        SELECT 1 FROM appointments a
        WHERE a.patient_id = p.id AND a.status = "scheduled"
    )';
} elseif ($appointmentStatus === 'completed') {
    $where[] = 'EXISTS (
        SELECT 1 FROM appointments a
        WHERE a.patient_id = p.id AND a.status = "completed"
    )';
}

if ($from) {
    $where[] = 's.submitted_at >= :from';
    $params[':from'] = $from . ' 00:00:00';
}
if ($to) {
    $where[] = 's.submitted_at <= :to';
    $params[':to'] = $to . ' 23:59:59';
}
if ($cpf) {
    $cpfDigits = preg_replace('/\D+/', '', $cpf);
    $where[] = "REPLACE(REPLACE(REPLACE(p.cpf, '.', ''), '-', ''), ' ', '') LIKE :cpf";
    $params[':cpf'] = '%' . $cpfDigits . '%';
}

$whereSql = implode(' AND ', $where);
$pdo = Database::getConnection();

$countSql = "SELECT COUNT(*) FROM screenings s JOIN patients p ON p.id = s.patient_id WHERE $whereSql";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$sql = "
    SELECT
        s.id, s.score, s.threshold_applied, s.priority, s.recommendation,
        s.status, s.submitted_at, s.created_at,
        p.id        AS patient_id,
        p.full_name AS patient_name,
        p.birth_date,
        p.biological_sex,
        p.cpf,
        u.full_name AS performed_by,
        (SELECT a.id FROM appointments a
         WHERE a.patient_id = p.id AND a.status = 'scheduled'
         ORDER BY a.scheduled_at ASC LIMIT 1) AS scheduled_appointment_id,
        (SELECT a.scheduled_at FROM appointments a
         WHERE a.patient_id = p.id AND a.status = 'scheduled'
         ORDER BY a.scheduled_at ASC LIMIT 1) AS next_appointment_at
    FROM screenings s
    JOIN patients p ON p.id = s.patient_id
    LEFT JOIN users u ON u.id = s.performed_by_user_id
    WHERE $whereSql
    ORDER BY
        FIELD(s.priority, 'high', 'medium', 'low'),
        s.submitted_at DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

Response::success($rows, [
    'page'     => $page,
    'per_page' => $perPage,
    'total'    => $total,
    'pages'    => (int)ceil($total / $perPage),
]);
