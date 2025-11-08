<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';

$session = Session::getInstance();

// Redirect to dashboard if already logged in
if ($session->isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = (
        stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || (($_POST['ajax'] ?? '') === '1')
    );
    $identifier = trim($_POST['identifier'] ?? ''); // email or CNIC
    $password = trim($_POST['password'] ?? '');
    
    $auth = new Auth();
    $result = $auth->login($identifier, $password);
    
    if ($result['success']) {
        // Redirect based on default-password enforcement
        $role = (string) $session->get('role');
        $must = (bool) $session->get('must_change_password');
        $redirect = ($role === 'student' && $must) ? 'change_password.php' : 'dashboard.php';
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Login successful',
                'redirect' => $redirect,
            ]);
            exit();
        } else {
            header('Location: ' . $redirect);
            exit();
        }
    } else {
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Login failed',
            ]);
            exit();
        } else {
            // Set error message and redirect back to login
            $session->setFlash('error', $result['message']);
            header('Location: index.php');
            exit();
        }
    }
} else {
    // Redirect to login page if accessed directly
    header('Location: index.php');
    exit();
}
?>
