<?php
/**
 * Helpers compartilhados pelos endpoints de agendamento.
 * Centralizam as regras de negócio (horários, capacidade, antecedência).
 */

/** Horários comerciais (de 1h cada). Almoço 12:00-13:00 fora. */
function appointments_business_hours(): array
{
    return ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00'];
}

/** Quantos slots de horário existem por profissional, por dia. */
function appointments_slots_per_day(): int
{
    return count(appointments_business_hours());
}

/** Conta profissionais ativos com role 'doctor' (Luz María e Sonia Mara). */
function appointments_count_active_doctors(PDO $pdo): int
{
    $stmt = $pdo->query("
        SELECT COUNT(*) AS c
        FROM users
        WHERE role = 'doctor' AND is_active = 1 AND deleted_at IS NULL
    ");
    return (int)$stmt->fetch()['c'];
}

/**
 * Devolve, em ordem de id, os IDs de todas as profissionais ativas com
 * role 'doctor'. Usado pra atribuir automaticamente quem fica responsável
 * pela consulta agendada pelo paciente.
 */
function appointments_active_doctor_ids(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id
        FROM users
        WHERE role = 'doctor' AND is_active = 1 AND deleted_at IS NULL
        ORDER BY id ASC
    ");
    return array_map(fn($r) => (int)$r['id'], $stmt->fetchAll());
}

/**
 * Calcula a antecedência mínima permitida para o paciente:
 *   - high   → hoje
 *   - medium → hoje + 3 dias
 *   - low    → hoje + 7 dias
 *   - (sem triagem) → hoje + 7 dias (cautela)
 *
 * O paciente NÃO vê essa data nem o motivo do bloqueio — apenas dias
 * "antes do permitido" aparecem cinza no calendário.
 *
 * Retorna data no formato 'Y-m-d'.
 */
function appointments_min_date_for_patient(PDO $pdo, int $patientId): string
{
    $stmt = $pdo->prepare("
        SELECT priority
        FROM screenings
        WHERE patient_id = :pid AND deleted_at IS NULL
        ORDER BY submitted_at DESC
        LIMIT 1
    ");
    $stmt->execute([':pid' => $patientId]);
    $row = $stmt->fetch();
    $priority = $row['priority'] ?? null;

    $today = new DateTime('today');
    $offsetDays = match ($priority) {
        'high'   => 0,
        'medium' => 3,
        'low'    => 7,
        default  => 7,
    };
    if ($offsetDays > 0) {
        $today->modify("+{$offsetDays} day");
    }
    return $today->format('Y-m-d');
}

/**
 * Para um dia específico, retorna os slots de horário com status:
 *   [
 *     '09:00' => ['available' => true,  'doctor_id' => null],
 *     '10:00' => ['available' => false, 'doctor_id' => null], // ambas ocupadas
 *     ...
 *   ]
 *
 * "available" = pelo menos uma profissional livre nesse horário.
 *
 * O endpoint NÃO retorna pro paciente qual profissional vai pegá-lo —
 * isso é decidido só no momento do book-self.
 */
function appointments_slots_for_date(PDO $pdo, string $date): array
{
    $doctorIds = appointments_active_doctor_ids($pdo);
    if (empty($doctorIds)) return [];

    $placeholders = implode(',', array_fill(0, count($doctorIds), '?'));
    $stmt = $pdo->prepare("
        SELECT doctor_user_id, scheduled_at
        FROM appointments
        WHERE doctor_user_id IN ($placeholders)
          AND DATE(scheduled_at) = ?
          AND status IN ('scheduled', 'completed')
    ");
    $stmt->execute([...$doctorIds, $date]);

    // [hora][doctor_id] = true se ocupado
    $occupied = [];
    foreach ($stmt->fetchAll() as $row) {
        $h = (new DateTime($row['scheduled_at']))->format('H:i');
        $occupied[$h][(int)$row['doctor_user_id']] = true;
    }

    $slots = [];
    foreach (appointments_business_hours() as $h) {
        $totalDocs = count($doctorIds);
        $busyDocs  = isset($occupied[$h]) ? count($occupied[$h]) : 0;
        $slots[$h] = [
            'available' => $busyDocs < $totalDocs,
        ];
    }
    return $slots;
}

/**
 * Escolhe qual profissional vai atender uma consulta NEW agendada
 * pelo paciente. Pega a primeira ativa que esteja livre naquele
 * horário. Retorna NULL se ninguém está livre.
 *
 * IMPORTANTE: esta função NÃO bloqueia o banco. O endpoint que
 * agenda deve usar transação + verificar de novo dentro do
 * `INSERT` pra evitar corrida (dois pacientes pegando o mesmo
 * slot no mesmo milissegundo).
 */
function appointments_pick_doctor_for_slot(PDO $pdo, string $dateTime): ?int
{
    $doctorIds = appointments_active_doctor_ids($pdo);
    if (empty($doctorIds)) return null;

    $placeholders = implode(',', array_fill(0, count($doctorIds), '?'));
    $stmt = $pdo->prepare("
        SELECT DISTINCT doctor_user_id
        FROM appointments
        WHERE doctor_user_id IN ($placeholders)
          AND scheduled_at = ?
          AND status IN ('scheduled', 'completed')
    ");
    $stmt->execute([...$doctorIds, $dateTime]);
    $busy = array_map(fn($r) => (int)$r['doctor_user_id'], $stmt->fetchAll());

    foreach ($doctorIds as $id) {
        if (!in_array($id, $busy, true)) return $id;
    }
    return null; // todas ocupadas
}
