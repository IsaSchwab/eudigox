<?php
/**
 * GET /api/appointments/available-slots.php
 *
 * Retorna os horários do dia, com flag "available" agregando TODAS
 * as profissionais ativas: o slot é "available" se pelo menos UMA
 * delas está livre naquele horário. O sistema decide na hora do
 * book-self qual delas vai pegar.
 *
 * Acesso:
 *   - paciente logado: vê os slots agregados do dia. O backend
 *     também respeita a janela de antecedência da prioridade dele.
 *   - admin / nurse / doctor / receptionist: vêem os slots agregados
 *     pra qualquer dia (sem janela de antecedência).
 *
 * Query params:
 *   - date = YYYY-MM-DD (obrigatório)
 *
 * Resposta:
 *   {
 *     "data": {
 *       "date": "2026-06-04",
 *       "slots": [
 *         { "time": "09:00", "available": true  },
 *         { "time": "10:00", "available": false },
 *         ...
 *       ]
 *     }
 *   }
 *
 * Compatibilidade: o endpoint antigo aceitava ?doctor_user_id=X e
 * retornava por médico. Esse parâmetro foi REMOVIDO porque a regra
 * nova é agregada. Se algum cliente antigo passar doctor_user_id,
 * é ignorado.
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

if (!Request::isMethod('GET')) Response::methodNotAllowed();

$user = Auth::requireUser();

$date = Request::query('date');
if (!$date) Response::badRequest('Parâmetro date obrigatório.');

$dt = DateTime::createFromFormat('Y-m-d', $date);
if (!$dt || $dt->format('Y-m-d') !== $date) {
    Response::unprocessable('Data inválida. Formato esperado: YYYY-MM-DD.');
}

$pdo = Database::getConnection();

// Se for paciente, valida antecedência da prioridade dele
if ($user['role'] === 'patient') {
    $stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':uid' => (int)$user['id']]);
    $row = $stmt->fetch();
    if (!$row) Response::notFound('Paciente não encontrado.');
    $patientId = (int)$row['id'];

    $minDate = appointments_min_date_for_patient($pdo, $patientId);
    if ($date < $minDate) {
        // Devolve todos como "indisponível" — o front nem deveria estar
        // mostrando esse dia, mas é uma camada extra de segurança.
        Response::success([
            'date'  => $date,
            'slots' => array_map(fn($h) => ['time' => $h, 'available' => false],
                                 appointments_business_hours()),
        ]);
    }
}

// Fim de semana → sem slots
$dow = (int)$dt->format('N');
if ($dow >= 6) {
    Response::success([
        'date'  => $date,
        'slots' => array_map(fn($h) => ['time' => $h, 'available' => false],
                             appointments_business_hours()),
    ]);
}

$slots = appointments_slots_for_date($pdo, $date);
$out = [];
foreach ($slots as $time => $info) {
    $out[] = ['time' => $time, 'available' => $info['available']];
}

Response::success([
    'date'  => $date,
    'slots' => $out,
]);
