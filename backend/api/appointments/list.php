<?php
/**
 * GET /api/appointments/list.php
 *
 * Lista consultas agendadas.
 * Acesso: doctor, nurse, admin
 *
 * Regras:
 *   - admin / nurse: vê todas
 *   - doctor: vê apenas as suas (doctor_user_id = id do médico logado)
 *             a menos que passe o parâmetro all=1 (admin override — usado em casos especiais)
 *
 * Query opcionais:
 *   - status   = scheduled | completed | cancelled | no_show
 *   - patient  = patient_id (id numérico)
 *   - doctor   = doctor_user_id (admin only)
 *   - period   = today | upcoming | past   (filtros pré-definidos)
 *   - from, to = YYYY-MM-DD (intervalo manual)
 *   - cpf      = busca por CPF
 *   - name     = busca por nome do paciente
 *   - priority = high | medium | low  (filtra pela prioridade da última triagem do paciente)
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('GET')) Response::methodNotAllowed();

$user = Auth::requireRole('doctor', 'nurse', 'admin', 'receptionist');

$status   = Request::query('status');
$patient  = Request::query('patient');
$doctor   = Request::query('doctor');
$period   = Request::query('period');
$from     = Request::query('from');
$to       = Request::query('to');
$cpf      = Request::query('cpf');
$name     = Request::query('name');
$priority = Request::query('priority');

$where  = [];
$params = [];

// Médico só vê as próprias consultas (a menos que admin tenha passado doctor=X)
if ($user['role'] === 'doctor') {
    $where[] = 'a.doctor_user_id = :doctor_uid';
    $params[':doctor_uid'] = (int)$user['id'];
} else if ($doctor) {
    // admin/nurse pode filtrar por médico específico
    $where[] = 'a.doctor_user_id = :did';
    $params[':did'] = (int)$doctor;
}

if (in_array($status, ['scheduled','completed','cancelled','no_show'], true)) {
    $where[] = 'a.status = :st';
    $params[':st'] = $status;
}

if ($patient) {
    $where[] = 'a.patient_id = :pid';
    $params[':pid'] = (int)$patient;
}

// Período pré-definido
//   today    = SOMENTE consultas de hoje (qualquer horário)
//   upcoming = consultas a partir de amanhã (NÃO inclui hoje)
//   past     = consultas até ontem (NÃO inclui hoje)
if ($period === 'today') {
    $where[] = 'DATE(a.scheduled_at) = CURDATE()';
} elseif ($period === 'upcoming') {
    $where[] = 'DATE(a.scheduled_at) > CURDATE()';
} elseif ($period === 'past') {
    $where[] = 'DATE(a.scheduled_at) < CURDATE()';
}

if ($from) {
    $where[] = 'a.scheduled_at >= :from';
    $params[':from'] = $from . ' 00:00:00';
}
if ($to) {
    $where[] = 'a.scheduled_at <= :to';
    $params[':to'] = $to . ' 23:59:59';
}

if ($cpf) {
    $cpfDigits = preg_replace('/\D+/', '', $cpf);
    $where[] = "REPLACE(REPLACE(REPLACE(p.cpf, '.', ''), '-', ''), ' ', '') LIKE :cpf";
    $params[':cpf'] = '%' . $cpfDigits . '%';
}

if ($name) {
    $where[] = 'p.full_name LIKE :name';
    $params[':name'] = '%' . $name . '%';
}

if (in_array($priority, ['high','medium','low'], true)) {
    // Filtra pacientes cuja triagem mais recente tem essa prioridade
    $where[] = '(
        SELECT s.priority
        FROM screenings s
        WHERE s.patient_id = p.id AND s.deleted_at IS NULL
        ORDER BY s.submitted_at DESC LIMIT 1
    ) = :prio';
    $params[':prio'] = $priority;
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
$pdo = Database::getConnection();

// Direção da ordenação:
// - upcoming: do mais próximo para o mais distante (ASC)
// - past: do mais recente para o mais antigo (DESC)
// - today + default: ASC
$orderDir = $period === 'past' ? 'DESC' : ($period === 'upcoming' ? 'ASC' : 'ASC');

$sql = "
    SELECT a.id, a.patient_id, a.doctor_user_id, a.scheduled_at,
           a.location, a.status, a.notes, a.meeting_link, a.created_at,
           p.full_name AS patient_name, p.birth_date, p.cpf, p.biological_sex,
           u.full_name AS doctor_name,
           (SELECT s.priority FROM screenings s
            WHERE s.patient_id = p.id AND s.deleted_at IS NULL
            ORDER BY s.submitted_at DESC LIMIT 1) AS last_screening_priority,
           (SELECT s.status FROM screenings s
            WHERE s.patient_id = p.id AND s.deleted_at IS NULL
            ORDER BY s.submitted_at DESC LIMIT 1) AS last_screening_status
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    JOIN users    u ON u.id = a.doctor_user_id
    $whereSql
    ORDER BY a.scheduled_at $orderDir
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

Response::success($stmt->fetchAll());
