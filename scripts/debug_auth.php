<?php
require_once __DIR__ . '/../classes/Auth.php';

$auth = new Auth();

function tryLogin($id, $pw) {
    global $auth;
    $result = $auth->login($id, $pw);
    echo "Identifier: {$id}\n";
    echo "Result: " . json_encode($result) . "\n\n";
}

// Test with expected superadmin email
tryLogin('superadmin@sms.com', 'SuperAdmin@123');

// If CNIC is set later, uncomment to test CNIC
// tryLogin('12345-1234567-1', 'SuperAdmin@123');

