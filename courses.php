<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';

$auth = new Auth();
$session = Session::getInstance();
$db = (new Database())->getConnection();

// Require login
$auth->requireLogin();

// Get current user
$currentUser = $auth->getCurrentUser();
$role = (string) ($session->get('role') ?? (isset($currentUser['role']) ? $currentUser['role'] : 'user'));

// Get flash messages
$success = $session->getFlash('success');

// Fetch courses from the database
$courses = [];
try {
    $stmt = $db->query("SELECT id, name, duration_months, default_fee FROM courses ORDER BY name ASC");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Handle database errors
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Courses</title>
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

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Courses</h2>
                </div>
                <div class="card-body">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Duration</th>
                                <th>Default Fee</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses as $course): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($course['name']); ?></td>
                                    <td><?php echo htmlspecialchars($course['duration_months']); ?> months</td>
                                    <td><?php echo htmlspecialchars($course['default_fee']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar.js"></script>
</body>
</html>
