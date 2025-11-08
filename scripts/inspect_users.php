<?php
require_once __DIR__ . '/../classes/Database.php';

try {
    $pdo = (new Database())->getConnection();
    $stmt = $pdo->query("SELECT id, username, email, cnic, role, is_active, password FROM users");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        echo "id=" . $row['id'] . ", username=" . $row['username'] . ", email=" . ($row['email'] ?? 'NULL') . ", cnic=" . ($row['cnic'] ?? 'NULL') . ", role=" . $row['role'] . ", is_active=" . $row['is_active'] . "\n";
        $ok = password_verify('SuperAdmin@123', $row['password'] ?? '');
        echo "password_matches=" . ($ok ? 'true' : 'false') . "\n\n";
    }
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}

