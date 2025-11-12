<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole(['superadmin','accounts']);
$role = (string)$session->get('role');

$db = (new Database())->getConnection();

// Simplified schema creation
$db->exec("CREATE TABLE IF NOT EXISTS course_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    year INT NOT NULL,
    term ENUM('H1','H2','Q1','Q2','Q3','Q4') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    teacher_id INT NULL,
    status ENUM('planned','active','completed','archived') NOT NULL DEFAULT 'planned',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX (course_id), INDEX (year), INDEX (term), INDEX (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Fetch data for display
$sessions = $db->query('SELECT cs.*, c.name AS course_name FROM course_sessions cs JOIN courses c ON c.id = cs.course_id ORDER BY cs.year DESC, cs.term ASC, c.name ASC')->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Course Sessions</title>
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

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3">Course Sessions</h1>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createSessionModal">Create Session</button>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Year</th>
                            <th>Term</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $session): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($session['course_name']); ?></td>
                                <td><?php echo htmlspecialchars($session['year']); ?></td>
                                <td><?php echo htmlspecialchars($session['term']); ?></td>
                                <td><?php echo htmlspecialchars($session['start_date']); ?></td>
                                <td><?php echo htmlspecialchars($session['end_date']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($session['status']); ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary">Edit</button>
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sessions)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">No course sessions found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

  </main>
</div>

<!-- Create Session Modal -->
<div class="modal fade" id="createSessionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Create Course Session</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted">Creating and editing course sessions is disabled in this simplified view.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
