<?php
/**
 * GET /api/patients/list.php
 *
 * Lista pacientes — usado em selects/buscas (recepção, admin, etc.).
 * Acesso: doctor, nurse, admin, receptionist
 *
 * Query opcional:
 *   ?q=...    busca por nome OU CPF (digite só os dígitos do CPF,
 *             com ou sem máscara — os dois funcionam)
 *   ?limit=N  default 50, máx 200
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('GET')) Response::methodNotAllowed();

Auth::requireRole('doctor', 'nurse', 'admin', 'receptionist');

$q     = trim((string) Request::query('q', ''));
$limit = (int) Request::query('limit', 50);
if ($limit < 1)   $limit = 1;
if ($limit > 200) $limit = 200;

$pdo = Database::getConnection();

$where  = ['p.deleted_at IS NULL'];
$params = [];

if ($q !== '') {
    // Tem dígitos? trata como possível CPF (busca também por dígitos,
    // ignorando pontos/traços que o usuário tenha digitado ou não).
    $qDigits = preg_replace('/\D/', '', $q);
    if (strlen($qDigits) >= 3) {
        // Compara só os dígitos do CPF do banco (REPLACE pra tirar
        // pontos/traços/espaços) com o que o usuário digitou.
        $where[] = "(p.full_name LIKE :qname
                     OR REPLACE(REPLACE(REPLACE(p.cpf, '.', ''), '-', ''), ' ', '') LIKE :qcpf)";
        $params[':qname'] = '%' . $q . '%';
        $params[':qcpf']  = '%' . $qDigits . '%';
    } else {
        $where[] = 'p.full_name LIKE :qname';
        $params[':qname'] = '%' . $q . '%';
    }
}

$sql = "
    SELECT p.id, p.full_name, p.birth_date, p.biological_sex, p.cpf, p.phone,
           u.email AS user_email
    FROM patients p
    LEFT JOIN users u ON u.id = p.user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.full_name
    LIMIT $limit
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

Response::success($stmt->fetchAll());
