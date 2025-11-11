<?php
require_once __DIR__ . '/../classes/Database.php';

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $db = (new Database())->getConnection();
    $added = [];

    if (!columnExists($db, 'notifications', 'acknowledged')) {
        $db->exec("ALTER TABLE notifications ADD COLUMN acknowledged TINYINT(1) NOT NULL DEFAULT 0 AFTER level");
        $added[] = 'notifications.acknowledged';
    }
    if (!columnExists($db, 'notifications', 'acknowledged_by')) {
        $db->exec("ALTER TABLE notifications ADD COLUMN acknowledged_by INT NULL AFTER acknowledged");
        $added[] = 'notifications.acknowledged_by';
    }
    if (!columnExists($db, 'notifications', 'acknowledged_at')) {
        $db->exec("ALTER TABLE notifications ADD COLUMN acknowledged_at DATETIME NULL AFTER acknowledged_by");
        $added[] = 'notifications.acknowledged_at';
    }
    // Helpful index for querying unacknowledged
    $db->exec("ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_acknowledged (acknowledged)");

    echo "Notifications acknowledgment migration completed. Added: " . (empty($added) ? 'none' : implode(', ', $added)) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
?>

