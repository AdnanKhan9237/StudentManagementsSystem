<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';

$auth = new Auth();
$session = Session::getInstance();

$auth->requireLogin();
$auth->requireRole('superadmin');

$success = $session->getFlash('success');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Panel</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/design-system.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0"><i class="fa-solid fa-toolbox me-2"></i>Superadmin Panel</h2>
        <div class="d-flex gap-2">
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Dashboard</a>
            <a href="manage_users.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-user-cog me-1"></i>Manage Users</a>
        
        </div>
    </div>

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
