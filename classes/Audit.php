<?php
class Audit {
    private static function ensureTable(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action VARCHAR(64) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id INT NULL,
            before_json JSON NULL,
            after_json JSON NULL,
            note VARCHAR(255) NULL,
            extra_json JSON NULL,
            created_at DATETIME NOT NULL,
            INDEX (user_id), INDEX (action), INDEX (entity_type), INDEX (entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function log(PDO $pdo, int $userId, string $action, string $entityType, ?int $entityId, array $before = null, array $after = null, ?string $note = null, array $extra = null): void {
        self::ensureTable($pdo);
        $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, before_json, after_json, note, extra_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $before ? json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $after ? json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $note,
            $extra ? json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            date('Y-m-d H:i:s')
        ]);
    }
}
?>
