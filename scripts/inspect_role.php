<?php
require_once __DIR__ . '/../classes/Database.php';

$pdo = (new Database())->getConnection();
$stmt = $pdo->query("SELECT id, username, email, HEX(role) AS role_hex, CHAR_LENGTH(role) AS role_len, is_active FROM users");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "id=".$r['id'].", role_len=".$r['role_len'].", role_hex=".$r['role_hex']."\n";
}

