<?php
/**
 * POST /api/appointments/book-self.php
 *
 * Paciente agenda a própria consulta.
 *
 * Acesso: APENAS o paciente logado.
 *
 * Regras de negócio (validadas aqui no servidor — não dá pra confiar no front):
 *   - Data tem que ser >= antecedência mínima da prioridade do paciente.
 *   - Tem que ser dia útil (seg-sex).
 *   - Horário tem que estar dentro da janela 09:00-12:00 ou 13:00-17:00,
 *     em ponto (sem 12:00).
 *   - Tem que existir profissional livre naquele exato horário.
 *   - Paciente NÃO pode ter outra consulta no mesmo dia (status scheduled/completed).
 *
 * Body: { date: "YYYY-MM-DD", time: "HH:MM" }
 *
 * Resposta:
 *   { appointment_id, scheduled_at, doctor_name, message }
 *
 * A consulta vai como status='scheduled' (confirmada). A clínica/recepção
 * pode reagendar/cancelar depois.
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

if (!Request::isMethod('POST')) Response::methodNotAllowed();

$user = Auth::requireRole('patient');
$body = Request::body();

$date = $body['date'] ?? '';
$time = $body['time'] ?? '';

// Valida formato
$dt = DateTime::createFromFormat('Y-m-d', $date);
if (!$dt || $dt->format('Y-m-d') !== $date) {
    Response::unprocessable('Data inválida.', ['date' => 'Formato esperado: YYYY-MM-DD.']);
}
if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
    Response::unprocessable('Hora inválida.', ['time' => 'Formato esperado: HH:MM.']);
}

// Valida que está na janela comercial
if (!in_array($time, appointments_business_hours(), true)) {
    Response::unprocessable('Horário fora do expediente.', [
        'time' => 'Horários disponíveis: ' . implode(', ', appointments_business_hours()) . '.',
    ]);
}

// Valida dia útil
$dow = (int)$dt->format('N');
if ($dow >= 6) {
    Response::unprocessable('Não é possível agendar em fim de semana.', [
        'date' => 'Escolha um dia de segunda a sexta.',
    ]);
}

// Horário de hoje que já passou não pode ser marcado.
if (appointments_slot_is_past($date, $time)) {
    Response::unprocessable('Esse horário já passou. Escolha um horário mais tarde ou outro dia.', [
        'time' => 'Escolha um horário que ainda não passou.',
    ]);
}

// Motivo da consulta (obrigatório): primeira consulta / retorno / outro.
$visitReason      = $body['visit_reason'] ?? '';
$visitReasonOther = isset($body['visit_reason_other']) ? trim((string)$body['visit_reason_other']) : '';
if (!in_array($visitReason, ['first_visit', 'return', 'other'], true)) {
    Response::unprocessable('Selecione o motivo da consulta.', [
        'visit_reason' => 'Escolha o motivo: primeira consulta, retorno ou outro.',
    ]);
}
if ($visitReason === 'other' && $visitReasonOther === '') {
    Response::unprocessable('Descreva o motivo da consulta.', [
        'visit_reason_other' => 'Obrigatório quando o motivo é "Outro".',
    ]);
}
$visitReasonOtherDb = $visitReason === 'other' ? mb_substr($visitReasonOther, 0, 255) : null;

$pdo = Database::getConnection();

// Pega o paciente
$stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1");
$stmt->execute([':uid' => (int)$user['id']]);
$row = $stmt->fetch();
if (!$row) Response::notFound('Paciente não encontrado para este usuário.');
$patientId = (int)$row['id'];

// Antecedência da prioridade
$minDate = appointments_min_date_for_patient($pdo, $patientId);
if ($date < $minDate) {
    // Mensagem genérica: o paciente não vê a prioridade.
    Response::unprocessable('Este dia não está disponível para agendamento.', [
        'date' => 'Escolha outro dia no calendário.',
    ]);
}

// Já tem consulta marcada nesse dia?
$stmt = $pdo->prepare("
    SELECT id FROM appointments
    WHERE patient_id = :pid
      AND DATE(scheduled_at) = :d
      AND status IN ('scheduled', 'completed')
    LIMIT 1
");
$stmt->execute([':pid' => $patientId, ':d' => $date]);
if ($stmt->fetch()) {
    Response::conflict('Você já tem uma consulta marcada para este dia. Cancele a anterior antes de remarcar.');
}

$scheduledAt = $date . ' ' . $time . ':00';

// Transação: escolhe profissional e cria a consulta numa só "rodada".
try {
    $pdo->beginTransaction();

    // Bloqueia leitura nas linhas relevantes pra evitar corrida.
    // (No MySQL/InnoDB com SELECT ... FOR UPDATE.)
    $doctorIds = appointments_active_doctor_ids($pdo);
    if (empty($doctorIds)) {
        $pdo->rollBack();
        Response::serverError('Nenhuma profissional ativa cadastrada.');
    }

    $placeholders = implode(',', array_fill(0, count($doctorIds), '?'));
    $stmt = $pdo->prepare("
        SELECT DISTINCT doctor_user_id
        FROM appointments
        WHERE doctor_user_id IN ($placeholders)
          AND scheduled_at = ?
          AND status IN ('scheduled', 'completed')
        FOR UPDATE
    ");
    $stmt->execute([...$doctorIds, $scheduledAt]);
    $busy = array_map(fn($r) => (int)$r['doctor_user_id'], $stmt->fetchAll());

    $chosenDoctor = null;
    foreach ($doctorIds as $id) {
        if (!in_array($id, $busy, true)) { $chosenDoctor = $id; break; }
    }
    if ($chosenDoctor === null) {
        $pdo->rollBack();
        Response::conflict('Esse horário acabou de ser ocupado. Escolha outro horário no calendário.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO appointments
            (patient_id, doctor_user_id, scheduled_at, status, notes, visit_reason, visit_reason_other)
        VALUES
            (:pid, :did, :sa, 'scheduled', :n, :vr, :vro)
    ");
    $stmt->execute([
        ':pid' => $patientId,
        ':did' => $chosenDoctor,
        ':sa'  => $scheduledAt,
        ':n'   => 'Agendada pelo próprio paciente. Consulta online — link enviado pela clínica antes da data.',
        ':vr'  => $visitReason,
        ':vro' => $visitReasonOtherDb,
    ]);
    $appointmentId = (int)$pdo->lastInsertId();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

// Busca o nome da profissional pra retornar pro paciente
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $chosenDoctor]);
$doctorName = $stmt->fetch()['full_name'] ?? 'Profissional';

Audit::log('APPOINTMENT_SELF_BOOKED', 'appointment', $appointmentId, [
    'patient_id'  => $patientId,
    'doctor_id'   => $chosenDoctor,
    'scheduled'   => $scheduledAt,
]);

Response::created([
    'appointment_id' => $appointmentId,
    'scheduled_at'   => $scheduledAt,
    'doctor_name'    => $doctorName,
    'message'        => 'Consulta agendada com sucesso!',
]);
