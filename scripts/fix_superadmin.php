<?php
// Ensure the superadmin record matches required credentials and status
// Usage: php -f scripts/fix_superadmin.php

require_once __DIR__ . '/../classes/Database.php';

$requiredEmail = 'superadmin@sms.com';
$requiredUsername = 'superadmin';
$requiredPasswordPlain = 'SuperAdmin@123';

try {
    $pdo = (new Database())->getConnection();

    // Find by email or username
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1');
    $stmt->execute([$requiredEmail, $requiredUsername]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo "Superadmin not found. Creating...\n";
        $hash = password_hash($requiredPasswordPlain, PASSWORD_DEFAULT);
        $insert = $pdo->prepare('INSERT INTO users (username, email, password, role, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())');
        $insert->execute([$requiredUsername, $requiredEmail, $hash, 'superadmin']);
        echo "Created superadmin with email {$requiredEmail}.\n";
        exit(0);
    }

    $updates = [];
    $params = [];

    if (empty($user['email']) || $user['email'] !== $requiredEmail) {
        $updates[] = 'email = ?';
        $params[] = $requiredEmail;
    }
    if (empty($user['username']) || $user['username'] !== $requiredUsername) {
        $updates[] = 'username = ?';
        $params[] = $requiredUsername;
    }
    if (empty($user['role']) || $user['role'] !== 'superadmin') {
        $updates[] = 'role = ?';
        $params[] = 'superadmin';
    }
    if (!isset($user['is_active']) || (int)$user['is_active'] !== 1) {
        $updates[] = 'is_active = 1';
    }

    // Verify password; if mismatched, reset to required
    $needsPasswordUpdate = true;
    if (!empty($user['password'])) {
        $needsPasswordUpdate = !password_verify($requiredPasswordPlain, $user['password']);
    }
    if ($needsPasswordUpdate) {
        $updates[] = 'password = ?';
        $params[] = password_hash($requiredPasswordPlain, PASSWORD_DEFAULT);
    }

    // Always enforce role and is_active explicitly to avoid partial updates
    $enforceSql = 'UPDATE users SET role = \'superadmin\', is_active = 1 WHERE id = ?';
    $enforce = $pdo->prepare($enforceSql);
    $enforce->execute([$user['id']]);

    if (!empty($updates)) {
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $params[] = $user['id'];
        $upd = $pdo->prepare($sql);
        $upd->execute($params);
        echo "Superadmin record updated.\n";
    } else {
        echo "Superadmin record already correct.\n";
    }

    echo "Done.\n";
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

