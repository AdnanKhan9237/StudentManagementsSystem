<?php
// Remove all users except the designated superadmin account
// Usage: php -f scripts/remove_non_superadmin.php

require_once __DIR__ . '/../classes/Database.php';

try {
    $pdo = (new Database())->getConnection();
    $pdo->beginTransaction();

    // Keep only the superadmin with the known email or username
    $keepEmail = 'superadmin@sms.com';
    $keepUsername = 'superadmin';

    // Delete any users not matching the superadmin
    $stmt = $pdo->prepare("DELETE FROM users WHERE (email IS NULL OR email <> ?) AND (username IS NULL OR username <> ?)");
    $stmt->execute([$keepEmail, $keepUsername]);

    $pdo->commit();
    echo "Removed non-superadmin users.\n";
} catch (Throwable $e) {
    if (isset($pdo)) { $pdo->rollBack(); }
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

