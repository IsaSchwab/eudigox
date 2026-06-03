<?php
/**
 * GET /api/appointments/my-appointments.php
 *
 * Lista as consultas do paciente logado (próximas e passadas).
 *
 * Acesso: APENAS paciente logado.
 *
 * Resposta:
 *   {
 *     "data": {
 *       "upcoming": [ { id, scheduled_at, status, doctor_name, ... } ],
 *       "past":     [ ... ]
 *     }
 *   }
 *
 * "upcoming" = scheduled OU completed com data futura ainda
 *              (status scheduled e scheduled_at >= agora; ou status cancelled
 *              não entra em upcoming).
 * "past"     = qualquer outra (já passou, ou foi cancelada).
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('GET')) Response::methodNotAllowed();

$user = Auth::requireRole('patient');

$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = :uid AND deleted_at IS NULL LIMIT 1");
$stmt->execute([':uid' => (int)$user['id']]);
$row = $stmt->fetch();
if (!$row) Response::notFound('Paciente não encontrado.');
$patientId = (int)$row['id'];

$stmt = $pdo->prepare("
    SELECT a.id, a.scheduled_at, a.status, a.notes, a.meeting_link,
           u.full_name AS doctor_name
    FROM appointments a
    JOIN users u ON u.id = a.doctor_user_id
    WHERE a.patient_id = :pid
    ORDER BY a.scheduled_at DESC
");
$stmt->execute([':pid' => $patientId]);
$all = $stmt->fetchAll();

$upcoming = [];
$past     = [];
$nowTs = time();
foreach ($all as $a) {
    $isFuture = strtotime($a['scheduled_at']) >= $nowTs;
    $isLive   = $a['status'] === 'scheduled' && $isFuture;
    if ($isLive) {
        $upcoming[] = $a;
    } else {
        $past[] = $a;
    }
}
// upcoming em ordem cronológica (mais próxima primeiro)
usort($upcoming, fn($a, $b) => strcmp($a['scheduled_at'], $b['scheduled_at']));

Response::success([
    'upcoming' => $upcoming,
    'past'     => $past,
]);
