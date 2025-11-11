<?php
require_once __DIR__ . '/../classes/Database.php';

class RateLimiter {
    private static function ensureTable(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limit (
            user_id INT NOT NULL,
            action VARCHAR(64) NOT NULL,
            window_start DATETIME NOT NULL,
            count INT NOT NULL,
            PRIMARY KEY (user_id, action, window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function allow(PDO $pdo, int $userId, string $action, int $limit, int $windowSeconds): bool {
        self::ensureTable($pdo);
        $now = time();
        $bucketStart = $now - ($now % $windowSeconds);
        $windowStart = date('Y-m-d H:i:s', $bucketStart);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT count FROM rate_limit WHERE user_id = ? AND action = ? AND window_start = ? FOR UPDATE');
            $stmt->execute([$userId, $action, $windowStart]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $count = (int)$row['count'] + 1;
                $upd = $pdo->prepare('UPDATE rate_limit SET count = ? WHERE user_id = ? AND action = ? AND window_start = ?');
                $upd->execute([$count, $userId, $action, $windowStart]);
            } else {
                $count = 1;
                $ins = $pdo->prepare('INSERT INTO rate_limit (user_id, action, window_start, count) VALUES (?, ?, ?, ?)');
                $ins->execute([$userId, $action, $windowStart, $count]);
            }
            $pdo->commit();
            return $count <= $limit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            return true; // fail-open to avoid blocking operations on unexpected errors
        }
    }
}
?>
