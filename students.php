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

// Fetch students from the database
$students = [];
try {
    $stmt = $db->query("SELECT id, name, general_number, created_at FROM students ORDER BY created_at DESC");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Handle database errors, e.g., log the error
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/design-system.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>
<div class="app-shell">
    <?php include_once __DIR__ . '/partials/sidebar.php'; ?>
    <main id="main-content">
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
            <div class="page-header">
                <h1 class="h3">Students</h1>
            </div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title mb-0 h5">Student List</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" role="table" aria-label="Student List Table">
                            <thead>
                                <tr>
                                    <th scope="col">
                                        <input type="checkbox" aria-label="Select all students">
                                    </th>
                                    <th scope="col">Name</th>
                                    <th scope="col">ID</th>
                                    <th scope="col">Marks</th>
                                    <th scope="col">Percent</th>
                                    <th scope="col">Year</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($students)): ?>
                                    <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" aria-label="Select <?php echo htmlspecialchars($student['name']); ?>">
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <img src="https://i.pravatar.cc/32?u=<?php echo urlencode($student['name']); ?>" alt="<?php echo htmlspecialchars($student['name']); ?>" class="avatar me-2">
                                                    <?php echo htmlspecialchars($student['name']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($student['general_number']); ?></td>
                                            <td>1185</td>
                                            <td><span class="badge bg-success">98%</span></td>
                                            <td><?php echo date('Y', strtotime($student['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No students found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
