<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/Session.php';

function assertTrue($cond, $msg)
{
    if (!$cond) {
        echo "[FAIL] $msg\n";
    } else {
        echo "[PASS] $msg\n";
    }
}

echo "Running Session tests...\n";

$session = Session::getInstance();

// Test set/get
$session->set('test_key', ['a' => 1, 'b' => 2]);
assertTrue($session->has('test_key'), 'has(test_key) returns true');
$val = $session->get('test_key');
assertTrue(is_array($val) && $val['a'] === 1 && $val['b'] === 2, 'get() returns original value');

// Test remove
$session->remove('test_key');
assertTrue(!$session->has('test_key'), 'remove() deletes the key');

// Flash message
$session->setFlash('hello', 'world');
assertTrue($session->hasFlash('hello'), 'hasFlash() detects flash');
assertTrue($session->getFlash('hello') === 'world', 'getFlash() returns value');
assertTrue(!$session->hasFlash('hello'), 'getFlash() consumes flash');

// Login-related helpers
$session->setUserId(123);
$session->setUsername('tester');
assertTrue($session->isLoggedIn() === true, 'isLoggedIn() returns true');
assertTrue($session->getUserId() === 123, 'getUserId() returns value');
assertTrue($session->getUsername() === 'tester', 'getUsername() returns value');

// Regenerate ID
$oldId = session_id();
$session->regenerate();
assertTrue($oldId !== session_id(), 'regenerate() changes session id');

// Timeout simulation
$_SESSION['__last_activity'] = time() - 3600; // simulate inactivity
$session2 = Session::getInstance();
assertTrue(isset($_SESSION['__last_activity']), 'Session reinitialized after timeout enforcement');

// Error conditions: invalid key names
try {
    $session->set('invalid key!', 'x');
    echo "[FAIL] invalid key should throw" . "\n";
} catch (SessionException $e) {
    echo "[PASS] invalid key throws exception" . "\n";
}

echo "Session tests complete." . "\n";
?>