<?php
require_once __DIR__ . '/../classes/Database.php';

function indexExists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $db = (new Database())->getConnection();
    $added = [];

    if (!indexExists($db, 'attendance', 'idx_att_date')) {
        $db->exec('ALTER TABLE attendance ADD INDEX idx_att_date (att_date)');
        $added[] = 'attendance.idx_att_date';
    }
    if (!indexExists($db, 'attendance', 'idx_status')) {
        $db->exec("ALTER TABLE attendance ADD INDEX idx_status (status)");
        $added[] = 'attendance.idx_status';
    }

    echo "Index migration completed. Added: " . (empty($added) ? 'none' : implode(', ', $added)) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
?>

