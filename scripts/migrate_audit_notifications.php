<?php
require_once __DIR__ . '/../classes/Database.php';

try {
    $db = (new Database())->getConnection();
    // Create audit_logs table
    $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
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

    // Create notifications table
    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(64) NOT NULL,
        title VARCHAR(255) NOT NULL,
        body TEXT NULL,
        level ENUM('info','warning','error') NOT NULL,
        meta_json JSON NULL,
        created_at DATETIME NOT NULL,
        INDEX (type), INDEX (level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "Audit and notifications migrations completed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
?>

