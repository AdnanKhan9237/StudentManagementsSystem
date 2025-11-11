<?php
class Notifier {
    private static function ensureTable(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(64) NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NULL,
            level ENUM('info','warning','error') NOT NULL,
            meta_json JSON NULL,
            created_at DATETIME NOT NULL,
            INDEX (type), INDEX (level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function notify(PDO $pdo, string $type, string $title, string $body = '', string $level = 'info', array $meta = []): void {
        self::ensureTable($pdo);
        $stmt = $pdo->prepare('INSERT INTO notifications (type, title, body, level, meta_json, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $type,
            $title,
            $body,
            $level,
            !empty($meta) ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            date('Y-m-d H:i:s')
        ]);
    }

    public static function notifyAbsenceSpike(PDO $pdo, string $date, int $batchId, int $timingId, int $absentCount): void {
        $title = 'Absence spike detected';
        $body = sprintf('On %s, batch #%d timing #%d recorded %d absences.', $date, $batchId, $timingId, $absentCount);
        self::notify($pdo, 'attendance_anomaly', $title, $body, 'warning', [
            'date' => $date,
            'batch_id' => $batchId,
            'timing_id' => $timingId,
            'absent_count' => $absentCount,
        ]);
    }
}
?>
