<?php
/**
 * POST /api/appointments/book-for-patient.php
 *
 * Recepção (ou admin) agenda uma consulta em nome de um paciente
 * EXISTENTE. Aplica as mesmas regras do book-self.php — dia útil,
 * horário comercial, 1 consulta por dia, conflito — mas permite
 * ignorar a antecedência via force=1 (exceção administrativa).
 *
 * Acesso: receptionist, admin
 *
 * Body: { patient_id, date, time, notes?, force? }
 *
 * Resposta:
 *   { appointment_id, scheduled_at, doctor_name, message }
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

if (!Request::isMethod('POST')) Response::methodNotAllowed();

$user = Auth::requireRole('receptionist', 'admin');
$body = Request::body();

$patientId = (int)($body['patient_id'] ?? 0);
$date      = $body['date'] ?? '';
$time      = $body['time'] ?? '';
$notes     = $body['notes'] ?? null;
$force     = !empty($body['force']);

if ($patientId <= 0) Response::badRequest('Campo patient_id obrigatório.');

// Valida data/hora
$dt = DateTime::createFromFormat('Y-m-d', $date);
if (!$dt || $dt->format('Y-m-d') !== $date) {
    Response::unprocessable('Data inválida.', ['date' => 'Formato esperado: YYYY-MM-DD.']);
}
if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
    Response::unprocessable('Hora inválida.', ['time' => 'Formato esperado: HH:MM.']);
}
if (!in_array($time, appointments_business_hours(), true)) {
    Response::unprocessable('Horário fora do expediente.', [
        'time' => 'Horários disponíveis: ' . implode(', ', appointments_business_hours()) . '.',
    ]);
}
$dow = (int)$dt->format('N');
if ($dow >= 6) {
    Response::unprocessable('Não é possível agendar em fim de semana.', [
        'date' => 'Escolha um dia de segunda a sexta.',
    ]);
}

$pdo = Database::getConnection();

// Confere paciente
$stmt = $pdo->prepare("SELECT id, full_name FROM patients WHERE id = :id AND deleted_at IS NULL LIMIT 1");
$stmt->execute([':id' => $patientId]);
$patient = $stmt->fetch();
if (!$patient) Response::notFound('Paciente não encontrado.');

// Antecedência (a menos que force=1)
if (!$force) {
    $minDate = appointments_min_date_for_patient($pdo, $patientId);
    if ($date < $minDate) {
        Response::unprocessable('Data antes da antecedência mínima da prioridade.', [
            'date'          => "A prioridade do paciente exige agendamento a partir de {$minDate}.",
            'override_with' => 'force',
        ]);
    }
}

// Já tem consulta nesse dia?
$stmt = $pdo->prepare("
    SELECT id FROM appointments
    WHERE patient_id = :pid
      AND DATE(scheduled_at) = :d
      AND status IN ('scheduled', 'completed')
    LIMIT 1
");
$stmt->execute([':pid' => $patientId, ':d' => $date]);
if ($stmt->fetch()) {
    Response::conflict('Este paciente já tem uma consulta marcada nesse dia.');
}

$scheduledAt = $date . ' ' . $time . ':00';

// Transação: escolhe profissional livre e cria
try {
    $pdo->beginTransaction();

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
        Response::conflict('Esse horário acabou de ser ocupado. Escolha outro horário.');
    }

    $defaultNote = 'Agendada pela recepção. Consulta online — link enviado pela clínica antes da data.';
    $finalNote   = $notes !== null && trim((string)$notes) !== '' ? trim((string)$notes) : $defaultNote;

    $stmt = $pdo->prepare("
        INSERT INTO appointments (patient_id, doctor_user_id, scheduled_at, status, notes)
        VALUES (:pid, :did, :sa, 'scheduled', :n)
    ");
    $stmt->execute([
        ':pid' => $patientId,
        ':did' => $chosenDoctor,
        ':sa'  => $scheduledAt,
        ':n'   => $finalNote,
    ]);
    $appointmentId = (int)$pdo->lastInsertId();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $chosenDoctor]);
$doctorName = $stmt->fetch()['full_name'] ?? 'Profissional';

Audit::log('APPOINTMENT_BOOKED_BY_RECEPTION', 'appointment', $appointmentId, [
    'patient_id'    => $patientId,
    'doctor_id'     => $chosenDoctor,
    'scheduled'     => $scheduledAt,
    'booked_by'     => (int)$user['id'],
    'booked_role'   => $user['role'],
    'forced'        => $force,
]);

Response::created([
    'appointment_id' => $appointmentId,
    'scheduled_at'   => $scheduledAt,
    'doctor_name'    => $doctorName,
    'patient_name'   => $patient['full_name'],
    'message'        => 'Consulta agendada com sucesso.',
]);
