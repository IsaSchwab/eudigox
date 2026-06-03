<?php
/**
 * GET /api/v1/indicators
 * Endpoint público — usado pelo wizard de triagem para montar o formulário.
 * 
 * Query params opcionais:
 *   - sex=M|F  → filtra macroorquidismo etc.
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('GET')) {
    Response::methodNotAllowed();
}

$sex = Request::query('sex');
$pdo = Database::getConnection();

$sql = "
    SELECT id, code, display_name, lay_label, clinical_tooltip,
           category, display_order
    FROM indicators
    WHERE is_active = 1
";
$params = [];

if ($sex === 'M' || $sex === 'F') {
    $sql .= " AND (applies_to = 'both' OR applies_to = :sex)";
    $params[':sex'] = $sex;
}

$sql .= " ORDER BY category, display_order";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agrupa por categoria — facilita renderização no wizard
$grouped = ['development' => [], 'behavioral' => [], 'physical' => []];
foreach ($rows as $r) {
    $grouped[$r['category']][] = [
        'id'              => (int)$r['id'],
        'code'            => $r['code'],
        'display_name'    => $r['display_name'],
        'lay_label'       => $r['lay_label'],
        'clinical_tooltip'=> $r['clinical_tooltip'],
        'display_order'   => (int)$r['display_order'],
    ];
}

Response::success([
    'indicators_by_category' => $grouped,
    'total' => count($rows),
]);
