<?php
/**
 * POST /api/appointments/update.php
 *
 * Atualiza uma consulta existente.
 *
 * Regras de acesso:
 *   - admin / receptionist: podem alterar TODOS os campos
 *     (scheduled_at, doctor_user_id, location, notes, status) de
 *     QUALQUER consulta.
 *   - doctor: pode alterar APENAS o status, e SÓ em suas próprias consultas.
 *
 * Body: {
 *   id,
 *   scheduled_at?,         // "YYYY-MM-DD HH:MM[:SS]" ou ISO
 *   doctor_user_id?,       // troca de profissional
 *   location?,
 *   notes?,
 *   status?,
 *   force?                 // bool: ignora regra de antecedência (uso restrito)
 * }
 *
 * Validações quando admin/recepção altera dia/hora ou profissional:
 *   - dia útil (seg-sex), horário comercial (09-17 sem 12)
 *   - sem conflito com outra consulta marcada
 *   - respeita antecedência mínima da prioridade do paciente,
 *     a menos que force=1
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

if (!Request::isMethod('POST') && !Request::isMethod('PATCH')) {
    Response::methodNotAllowed();
}

$user = Auth::requireRole('admin', 'receptionist', 'doctor');
$body = Request::body();

$id = (int)($body['id'] ?? 0);
if ($id <= 0) Response::badRequest('Campo id obrigatório.');

$force = !empty($body['force']);

$pdo = Database::getConnection();
$stmt = $pdo->prepare("
    SELECT id, patient_id, doctor_user_id, scheduled_at, status
    FROM appointments
    WHERE id = :id
");
$stmt->execute([':id' => $id]);
$appt = $stmt->fetch();
if (!$appt) Response::notFound('Consulta não encontrada.');

// === Regra para médico ===
if ($user['role'] === 'doctor') {
    if ((int)$appt['doctor_user_id'] !== (int)$user['id']) {
        Response::forbidden('Você só pode alterar suas próprias consultas.');
    }
    // Só pode mexer no campo status
    $allowedFields = ['id', 'status'];
    $body = array_intersect_key($body, array_flip($allowedFields));
    if (empty($body['status'])) {
        Response::badRequest('Médico só pode alterar o status da consulta.');
    }
}

$updates = [];
$params  = [':id' => $id];

// Novo horário (se enviado)
$newScheduledAt = null;
if (!empty($body['scheduled_at'])) {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $body['scheduled_at'])
       ?: DateTime::createFromFormat('Y-m-d H:i',   $body['scheduled_at'])
       ?: DateTime::createFromFormat('Y-m-d\TH:i',  $body['scheduled_at'])
       ?: DateTime::createFromFormat('Y-m-d\TH:i:s',$body['scheduled_at']);
    if (!$dt) Response::unprocessable('Data/hora inválida.');
    $newScheduledAt = $dt->format('Y-m-d H:i:s');
}

// Novo profissional (se enviado)
$newDoctorId = null;
if (array_key_exists('doctor_user_id', $body) && $body['doctor_user_id'] !== null && $body['doctor_user_id'] !== '') {
    $newDoctorId = (int)$body['doctor_user_id'];
    // Verifica que existe, está ativo e tem role 'doctor'
    $stmt = $pdo->prepare("
        SELECT id FROM users
        WHERE id = :id AND role = 'doctor'
          AND is_active = 1 AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $newDoctorId]);
    if (!$stmt->fetch()) Response::notFound('Profissional não encontrado ou inativa.');
}

// Se mudou horário OU profissional, valida regras de negócio
if ($newScheduledAt !== null || $newDoctorId !== null) {
    // Recepção/admin: precisa validar tudo
    if (in_array($user['role'], ['admin', 'receptionist'], true)) {
        $finalScheduledAt = $newScheduledAt ?? $appt['scheduled_at'];
        $finalDoctorId    = $newDoctorId    ?? (int)$appt['doctor_user_id'];

        $dt = new DateTime($finalScheduledAt);
        $dow  = (int)$dt->format('N');         // 1=seg ... 7=dom
        $time = $dt->format('H:i');

        // Dia útil
        if ($dow >= 6) {
            Response::unprocessable('Não é possível agendar em fim de semana.', [
                'scheduled_at' => 'Escolha um dia de segunda a sexta.',
            ]);
        }
        // Horário comercial
        if (!in_array($time, appointments_business_hours(), true)) {
            Response::unprocessable('Horário fora do expediente.', [
                'scheduled_at' => 'Horários: ' . implode(', ', appointments_business_hours()) . '.',
            ]);
        }
        // Antecedência da prioridade (ignorável com force=1)
        if (!$force) {
            $minDate = appointments_min_date_for_patient($pdo, (int)$appt['patient_id']);
            $newDate = $dt->format('Y-m-d');
            if ($newDate < $minDate) {
                Response::unprocessable('Data antes da antecedência mínima da prioridade.', [
                    'scheduled_at'   => "A prioridade do paciente exige agendamento a partir de {$minDate}.",
                    'override_with'  => 'force', // hint pro front: pode reenviar com force=1
                ]);
            }
        }
        // Conflito: alguém já marcado nesse horário com essa profissional?
        $stmt = $pdo->prepare("
            SELECT id FROM appointments
            WHERE doctor_user_id = :did
              AND scheduled_at = :sa
              AND status IN ('scheduled', 'completed')
              AND id <> :self
            LIMIT 1
        ");
        $stmt->execute([
            ':did'  => $finalDoctorId,
            ':sa'   => $finalScheduledAt,
            ':self' => $id,
        ]);
        if ($stmt->fetch()) {
            Response::conflict('Já existe uma consulta nesse horário para essa profissional.');
        }

        // Paciente não pode ter outra consulta no mesmo dia
        $stmt = $pdo->prepare("
            SELECT id FROM appointments
            WHERE patient_id = :pid
              AND DATE(scheduled_at) = DATE(:sa)
              AND status IN ('scheduled', 'completed')
              AND id <> :self
            LIMIT 1
        ");
        $stmt->execute([
            ':pid'  => (int)$appt['patient_id'],
            ':sa'   => $finalScheduledAt,
            ':self' => $id,
        ]);
        if ($stmt->fetch()) {
            Response::conflict('O paciente já tem outra consulta marcada nesse dia.');
        }
    }

    if ($newScheduledAt !== null) {
        $updates[] = 'scheduled_at = :sa';
        $params[':sa'] = $newScheduledAt;
    }
    if ($newDoctorId !== null) {
        $updates[] = 'doctor_user_id = :did';
        $params[':did'] = $newDoctorId;
    }
}

if (array_key_exists('location', $body)) {
    $updates[] = 'location = :loc'; $params[':loc'] = $body['location'];
}
if (array_key_exists('meeting_link', $body)) {
    // Link da reunião online (Google Meet etc.). Aceita string vazia pra apagar.
    $link = $body['meeting_link'];
    if ($link !== null && $link !== '') {
        $link = trim((string)$link);
        // Sanidade básica: precisa parecer URL http(s). Não validamos a fundo
        // (a clínica pode colocar links de várias ferramentas).
        if (!preg_match('#^https?://#i', $link)) {
            Response::unprocessable('Link inválido.', [
                'meeting_link' => 'O link precisa começar com http:// ou https://'
            ]);
        }
        if (strlen($link) > 500) {
            Response::unprocessable('Link muito longo.', [
                'meeting_link' => 'Máximo de 500 caracteres.'
            ]);
        }
    } else {
        $link = null; // permite apagar o link existente
    }
    $updates[] = 'meeting_link = :ml'; $params[':ml'] = $link;
}
if (array_key_exists('notes', $body)) {
    $updates[] = 'notes = :n'; $params[':n'] = $body['notes'];
}
if (!empty($body['status'])) {
    if (!in_array($body['status'], ['scheduled','completed','cancelled','no_show'], true)) {
        Response::badRequest('Status inválido.');
    }
    $updates[] = 'status = :st'; $params[':st'] = $body['status'];
}

if (empty($updates)) Response::badRequest('Nenhuma alteração informada.');

$pdo->prepare("UPDATE appointments SET " . implode(', ', $updates) . " WHERE id = :id")
    ->execute($params);

Audit::log('APPOINTMENT_UPDATED', 'appointment', $id, [
    'updated_by_user' => (int)$user['id'],
    'updated_by_role' => $user['role'],
    'fields' => array_keys(array_intersect_key($body,
        array_flip(['scheduled_at','doctor_user_id','location','meeting_link','notes','status']))),
    'forced' => $force,
]);

Response::success(['message' => 'Consulta atualizada.']);
