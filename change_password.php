<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';

$auth = new Auth();
$session = Session::getInstance();
$auth->requireLogin();

$userId = (int) $session->getUserId();
$role = (string) $session->get('role');
$mustChange = (bool) $session->get('must_change_password');

// CSRF token
if (!$session->has('csrf_token')) { $session->set('csrf_token', bin2hex(random_bytes(32))); }
$csrfToken = (string) $session->get('csrf_token');

$error = $session->getFlash('error');
$success = $session->getFlash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = (
        stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || (($_POST['ajax'] ?? '') === '1')
    );
    $postedToken = $_POST['csrf_token'] ?? '';
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        if ($wantsJson) {
            // Refresh CSRF
            $session->set('csrf_token', bin2hex(random_bytes(32)));
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Invalid CSRF token.',
                'csrf_token' => (string) $session->get('csrf_token'),
            ]);
            exit();
        } else {
            $session->setFlash('error', 'Invalid CSRF token.');
            header('Location: change_password.php');
            exit();
        }
    }

    $current = trim($_POST['current_password'] ?? '');
    $new = trim($_POST['new_password'] ?? '');
    $confirm = trim($_POST['confirm_password'] ?? '');

    $db = (new Database())->getConnection();
    $stmt = $db->prepare('SELECT id, username, email, cnic, password, role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        $session->setFlash('error', 'User not found.');
    } elseif ($current === '' || !password_verify($current, $userRow['password'])) {
        $session->setFlash('error', 'Current password is incorrect.');
    } elseif ($new === '' || strlen($new) < 8) {
        $session->setFlash('error', 'New password must be at least 8 characters.');
    } elseif (!preg_match('/[a-z]/', $new) || !preg_match('/[A-Z]/', $new) || !preg_match('/\d/', $new) || !preg_match('/[^a-zA-Z0-9]/', $new)) {
        $session->setFlash('error', 'Password must include uppercase, lowercase, number, and symbol.');
    } elseif ($new !== $confirm) {
        $session->setFlash('error', 'New password and confirmation do not match.');
    } elseif (password_verify($new, $userRow['password'])) {
        $session->setFlash('error', 'New password cannot be the same as current password.');
    } elseif (strcasecmp($new, (string)($userRow['username'] ?? '')) === 0 || strcasecmp($new, (string)($userRow['email'] ?? '')) === 0 || strcasecmp($new, (string)($userRow['cnic'] ?? '')) === 0) {
        $session->setFlash('error', 'Password cannot match your username, email, or CNIC.');
    } elseif (($role === 'student' && $new === 'Sostti123+') || $new === 'SuperAdmin@123') {
        $session->setFlash('error', 'Default or weak password cannot be used.');
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
        if ($stmt->execute([$hashed, $userId])) {
            // Clear enforcement flag
            $session->set('must_change_password', false);
            if ($wantsJson) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Password updated successfully.',
                    'redirect' => 'dashboard.php',
                ]);
                exit();
            } else {
                $session->setFlash('success', 'Password updated successfully.');
                header('Location: dashboard.php');
                exit();
            }
        } else {
            if ($wantsJson) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to update password.',
                ]);
                exit();
            } else {
                $session->setFlash('error', 'Failed to update password.');
            }
        }
    }
    // Refresh CSRF
    $session->set('csrf_token', bin2hex(random_bytes(32)));
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $session->getFlash('error') ?? 'Invalid input.',
            'csrf_token' => (string) $session->get('csrf_token'),
        ]);
        exit();
    } else {
        header('Location: change_password.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Change Password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/design-system.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">
  <?php include_once __DIR__ . '/partials/sidebar.php'; ?>
  <main>
    <header class="app-header">
        <div class="search-wrapper">
            <input type="text" placeholder="What do you want to find?">
            <i class="fa-solid fa-search"></i>
        </div>
        <div class="user-profile">
            <i class="fa-solid fa-bell"></i>
            <i class="fa-solid fa-comment-dots"></i>
            <div class="user-info">
                <img src="https://i.pravatar.cc/40?u=<?php echo urlencode($session->getUsername() ?? 'user'); ?>" alt="User Avatar" class="avatar">
                <div class="user-details">
                    <span class="user-name"><?php echo htmlspecialchars($session->getUsername() ?? 'User'); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars(ucfirst($role)); ?></span>
                </div>
            </div>
        </div>
    </header>

    <div class="container py-4" style="max-width: 720px;">
        <h1 class="h3 mb-4">Change Password</h1>

        <?php if ($mustChange && $role === 'student'): ?>
        <div class="alert alert-warning"><strong>Action required:</strong> You are using the default password. Please set a new password now.</div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required minlength="6" autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                        <div class="form-text">Use at least 8 characters with uppercase, lowercase, number, and symbol.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary">Save Password</button>
                </form>
            </div>
        </div>
    </div>

  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
