<?php
// Seed a superadmin user into the `users` table
// Usage: php -f scripts/seed_superadmin.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

function ensureSchema(PDO $pdo): void {
    // Create table if not exists
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(255) NULL,
            cnic VARCHAR(20) NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(32) NOT NULL DEFAULT 'user',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=" . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4')
    );

    // Ensure email and is_active columns exist (in case table was created earlier)
    $stmt = $pdo->query('DESCRIBE users');
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $hasEmail = in_array('email', $columns, true);
    $hasRole = in_array('role', $columns, true);
    $hasCnic = in_array('cnic', $columns, true);
    $hasIsActive = in_array('is_active', $columns, true);

    if (!$hasEmail) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL AFTER username");
    }
    if (!$hasCnic) {
        $pdo->exec("ALTER TABLE users ADD COLUMN cnic VARCHAR(20) NULL AFTER email");
    }
    if (!$hasIsActive) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
    }
}

function seedSuperAdmin(PDO $pdo): void {
    $username = 'superadmin';
    $email = 'superadmin@sms.com';
    $passwordPlain = 'SuperAdmin@123';
    $role = 'superadmin';

    // Check existing
    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $checkStmt->execute([$username, $email]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo "Superadmin already exists (id=" . $existing['id'] . ")\n";
        return;
    }

    $hash = password_hash($passwordPlain, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (username, email, cnic, password, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())');
    $stmt->execute([$username, $email, null, $hash, $role]);
    echo "Superadmin user created: username={$username}, email={$email}\n";
}

try {
    $pdo = (new Database())->getConnection();
    ensureSchema($pdo);
    seedSuperAdmin($pdo);
    echo "Seed completed.\n";
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
    if (!$hasRole) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'superadmin' AFTER password");
    }
