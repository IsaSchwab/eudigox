<?php
/**
 * GET /api/appointments/calendar-overview.php
 *
 * Visão de calendário pra recepção/admin: contagem de consultas
 * por dia no mês inteiro, opcionalmente filtrada por profissional.
 *
 * Diferente do /calendar.php (uso do paciente):
 *   - não aplica antecedência (recepção vê tudo)
 *   - retorna por dia a CONTAGEM por status, pra dar visibilidade
 *     do "quanto cheio está o dia"
 *
 * Acesso: admin, receptionist, doctor, nurse
 *
 * Query params:
 *   - year=YYYY  (default = atual)
 *   - month=MM   (default = atual)
 *   - doctor=ID  (opcional, filtra só por essa profissional)
 *
 * Resposta:
 *   {
 *     "data": {
 *       "year": 2026, "month": 5,
 *       "days": [
 *         { "date": "2026-05-01", "scheduled": 0, "completed": 0,
 *           "cancelled": 0, "no_show": 0, "is_weekend": true },
 *         { "date": "2026-05-04", "scheduled": 3, "completed": 0,
 *           "cancelled": 1, "no_show": 0, "is_weekend": false },
 *         ...
 *       ]
 *     }
 *   }
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

if (!Request::isMethod('GET')) Response::methodNotAllowed();

Auth::requireRole('admin', 'receptionist', 'doctor', 'nurse');

$year  = (int) Request::query('year',  (int)date('Y'));
$month = (int) Request::query('month', (int)date('n'));
$doctorId = (int) Request::query('doctor', 0);

if ($year < 2020 || $year > 2099 || $month < 1 || $month > 12) {
    Response::badRequest('Ano/mês inválidos.');
}

$pdo = Database::getConnection();

$where = "YEAR(scheduled_at) = :y AND MONTH(scheduled_at) = :m";
$params = [':y' => $year, ':m' => $month];
if ($doctorId > 0) {
    $where .= " AND doctor_user_id = :did";
    $params[':did'] = $doctorId;
}

$stmt = $pdo->prepare("
    SELECT DATE(scheduled_at) AS d, status, COUNT(*) AS c
    FROM appointments
    WHERE $where
    GROUP BY DATE(scheduled_at), status
");
$stmt->execute($params);

// Agrega: $countsByDay[date][status] = count
$countsByDay = [];
foreach ($stmt->fetchAll() as $row) {
    $countsByDay[$row['d']][$row['status']] = (int)$row['c'];
}

$first = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $year, $month));
$daysInMonth = (int)$first->format('t');

$days = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $dt   = DateTime::createFromFormat('Y-m-d', $date);
    $dow  = (int)$dt->format('N');
    $c    = $countsByDay[$date] ?? [];
    $days[] = [
        'date'       => $date,
        'scheduled'  => $c['scheduled'] ?? 0,
        'completed'  => $c['completed'] ?? 0,
        'cancelled'  => $c['cancelled'] ?? 0,
        'no_show'    => $c['no_show']   ?? 0,
        'is_weekend' => $dow >= 6,
    ];
}

Response::success([
    'year'  => $year,
    'month' => $month,
    'doctor_filter' => $doctorId ?: null,
    'days'  => $days,
]);
