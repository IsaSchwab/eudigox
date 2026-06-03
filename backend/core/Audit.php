<?php
/**
 * Audit — log de operações sensíveis (RNF005).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Request.php';

class Audit
{
    public static function log(string $action, string $entityType, ?int $entityId = null, array $details = []): void
    {
        try {
            $pdo  = Database::getConnection();
            $user = Auth::user();
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
                VALUES (:uid, :act, :et, :eid, :det, :ip)
            ");
            $stmt->execute([
                ':uid' => $user['id'] ?? null,
                ':act' => $action,
                ':et'  => $entityType,
                ':eid' => $entityId,
                ':det' => empty($details) ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                ':ip'  => Request::ip(),
            ]);
        } catch (Throwable $e) {
            // Audit nunca pode quebrar a request
            error_log('[SGX][Audit] ' . $e->getMessage());
        }
    }
}
