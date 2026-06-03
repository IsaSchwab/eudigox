<?php
/**
 * GET /api/appointments/calendar.php
 *
 * Retorna, para um mês inteiro, o status de cada dia útil:
 *   - dentro da janela de antecedência do paciente
 *   - quantos slots já estão ocupados
 *   - total de slots do dia (TOTAL_SLOTS_PER_DAY)
 *   - "available": tem ao menos 1 slot livre E o dia não está bloqueado
 *
 * Acesso: paciente logado.
 *   - O paciente vê o calendário com a antecedência da própria prioridade.
 *
 * Query params:
 *   - year=YYYY  (opcional, default = ano atual)
 *   - month=MM   (opcional, default = mês atual)
 *
 * Resposta:
 *   {
 *     "data": {
 *       "year": 2026, "month": 5,
 *       "min_date": "2026-06-04",   // primeira data agendável p/ esse paciente
 *       "days": [
 *         { "date": "2026-05-01", "available": false, "occupied": 0, "total": 14, "reason": "weekend" },
 *         { "date": "2026-05-02", "available": false, "occupied": 0, "total": 14, "reason": "before_min" },
 *         { "date": "2026-05-04", "available": true,  "occupied": 3, "total": 14, "reason": null },
 *         ...
 *       ]
 *     }
 *   }
 *
 * O cliente NÃO precisa interpretar "reason" — basta usar "available"
 * pra liberar/bloquear o dia visualmente. O reason é só pra debug.
 *
 * Regras de capacidade:
 *   - Horário comercial: 09:00-12:00 e 13:00-17:00 (sem 12:00)
 *   - 1h de duração por consulta
 *   - 7 slots por dia por profissional × N profissionais ativas
 *   - TOTAL_SLOTS_PER_DAY = 7 * (nº profissionais ativas com role 'doctor')
 *
 * Antecedência por prioridade (da última triagem do paciente):
 *   high   = a partir de hoje
 *   medium = a partir de hoje + 3 dias
 *   low    = a partir de hoje + 7 dias
 *   (sem triagem) = a partir de hoje + 7 dias (cautela)
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

if (!Request::isMethod('GET')) Response::methodNotAllowed();

$user = Auth::requireUser();

// Pega o patient_id do usuário logado (se for paciente)
$pdo = Database::getConnection();
$patientId = null;
if ($user['role'] === 'patient') {
    $stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':uid' => (int)$user['id']]);
    $row = $stmt->fetch();
    if (!$row) Response::notFound('Paciente não encontrado para este usuário.');
    $patientId = (int)$row['id'];
} else {
    // Outros perfis (admin, doctor, nurse, receptionist) — calendário visto
    // como "panorama do mês" sem janela de antecedência.
    // Aceita ?patient_id=X pra simular o calendário de um paciente.
    $maybe = (int) Request::query('patient_id', 0);
    if ($maybe > 0) $patientId = $maybe;
}

// Mês alvo
$year  = (int) Request::query('year',  (int)date('Y'));
$month = (int) Request::query('month', (int)date('n'));
if ($year < 2020 || $year > 2099 || $month < 1 || $month > 12) {
    Response::badRequest('Ano/mês inválidos.');
}

// Profissionais ativos
$numDoctors = appointments_count_active_doctors($pdo);
if ($numDoctors === 0) {
    Response::serverError('Nenhuma profissional ativa cadastrada no sistema.');
}
$totalSlotsPerDay = appointments_slots_per_day() * $numDoctors;

// Antecedência da prioridade
$minDate = $patientId
    ? appointments_min_date_for_patient($pdo, $patientId)
    : (new DateTime('today'))->format('Y-m-d');

// Monta dias do mês
$first = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $year, $month));
$daysInMonth = (int)$first->format('t');
$today = (new DateTime('today'))->format('Y-m-d');

// Conta consultas marcadas por dia no mês inteiro, em uma só query
$stmt = $pdo->prepare("
    SELECT DATE(scheduled_at) AS d, COUNT(*) AS c
    FROM appointments
    WHERE status IN ('scheduled', 'completed')
      AND YEAR(scheduled_at)  = :y
      AND MONTH(scheduled_at) = :m
    GROUP BY DATE(scheduled_at)
");
$stmt->execute([':y' => $year, ':m' => $month]);
$busyByDay = [];
foreach ($stmt->fetchAll() as $row) $busyByDay[$row['d']] = (int)$row['c'];

$days = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $dt   = DateTime::createFromFormat('Y-m-d', $date);
    $dow  = (int)$dt->format('N'); // 1=seg ... 7=dom
    $isWeekend = ($dow >= 6);
    $isPast    = ($date < $today);
    $beforeMin = ($date < $minDate);

    $occupied  = $busyByDay[$date] ?? 0;
    $available = !$isWeekend && !$isPast && !$beforeMin && $occupied < $totalSlotsPerDay;

    $reason = null;
    if ($isPast)         $reason = 'past';
    elseif ($isWeekend)  $reason = 'weekend';
    elseif ($beforeMin)  $reason = 'before_min';
    elseif ($occupied >= $totalSlotsPerDay) $reason = 'full';

    $days[] = [
        'date'      => $date,
        'available' => $available,
        'occupied'  => $occupied,
        'total'     => $totalSlotsPerDay,
        'reason'    => $reason,
    ];
}

Response::success([
    'year'     => $year,
    'month'    => $month,
    'min_date' => $minDate,
    'days'     => $days,
]);
