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

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic validation
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid name and email are required.';
    } else {
        try {
            // In a real app, you would hash the password and create a user record
            $stmt = $db->prepare("INSERT INTO students (name, email, created_at) VALUES (?, ?, NOW())");
            if ($stmt->execute([$name, $email])) {
                $success = "Student '{$name}' added successfully!";
                // Clear form by redirecting
                header("Location: add_student.php");
                $session->setFlash('success', $success);
                exit();
            } else {
                $errors[] = "Failed to add student. Please try again.";
            }
        } catch (PDOException $e) {
            // Check for duplicate email
            if ($e->getCode() == '23000') {
                $errors[] = "A student with this email already exists.";
            } else {
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    }
}

$success = $session->getFlash('success');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Student</title>
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
                    <h2 class="card-title">Add New Student</h2>
                </div>
                <div class="card-body">
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $error): ?>
                                <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="add_student.php">
                        <div class="mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Add Student</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar.js"></script>
</body>
</html>
