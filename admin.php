<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';

$auth = new Auth();
$session = Session::getInstance();

$auth->requireLogin();
$auth->requireRole('superadmin');

$success = $session->getFlash('success');
$currentUser = $auth->getCurrentUser();
$role = (string) ($session->get('role') ?? (isset($currentUser['role']) ? $currentUser['role'] : 'user'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Panel</title>
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

        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Superadmin Panel</h2>
                </div>
                <div class="card-body">
                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12 col-lg-4">
                            <div class="card h-100">
                                <div class="card-header">System Status</div>
                                <div class="card-body">
                                    <p class="mb-1">Server: <span class="badge bg-success">Running</span></p>
                                    <p class="mb-1">Environment: <span class="badge bg-secondary">Development</span></p>
                                    <p class="text-muted">Superadmin actions are restricted to superadmin.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-4">
                            <div class="card h-100">
                                <div class="card-header">Roles & Permissions</div>
                                <div class="card-body">
                                    <p class="text-muted">Future: configure teacher/accounts permissions.</p>
                                    <a href="manage_users.php" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-user-cog me-1"></i>Manage Users</a>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-4">
                            <div class="card h-100">
                                <div class="card-header">Settings</div>
                                <div class="card-body">
                                    <p class="text-muted">Future: system settings (SMTP/SMS, branding, etc.).</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar.js"></script>
</body>
</html>
