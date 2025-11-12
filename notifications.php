<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole(['superadmin','accounts']);
$role = (string)$session->get('role');

$db = (new Database())->getConnection();

// Simplified logic for fetching notifications
$notifications = $db->query("SELECT * FROM notifications ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Notifications</title>
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

    <h1 class="h3 mb-3">Notifications</h1>

    <div class="card">
        <div class="card-body">
            <div class="list-group">
                <?php foreach ($notifications as $notification): ?>
                    <div class="list-group-item list-group-item-action">
                        <div class="d-flex w-100 justify-content-between">
                            <h5 class="mb-1"><?php echo htmlspecialchars($notification['title']); ?></h5>
                            <small class="text-muted"><?php echo htmlspecialchars($notification['created_at']); ?></small>
                        </div>
                        <p class="mb-1"><?php echo htmlspecialchars($notification['body']); ?></p>
                        <small class="text-muted">Level: <?php echo htmlspecialchars($notification['level']); ?></small>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($notifications)): ?>
                    <div class="list-group-item">
                        <p class="text-muted text-center mb-0">No notifications found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
