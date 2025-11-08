<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';

$auth = new Auth();
$session = Session::getInstance();

// Logout the user
$auth->logout();

// Set success message
$session->setFlash('success', 'You have been logged out successfully.');

// Redirect to login page
header('Location: index.php');
exit();
?>