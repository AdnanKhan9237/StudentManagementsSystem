<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';

$auth = new Auth();
$session = Session::getInstance();
$auth->requireRole(['superadmin', 'accounts']);
$role = (string)$session->get('role');

$db = (new Database())->getConnection();

// Simplified schema creation
$db->exec("CREATE TABLE IF NOT EXISTS batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  course_id INT NULL,
  academic_session_id INT NOT NULL,
  timing_id INT NOT NULL, -- Legacy, for single timing
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  INDEX (course_id), INDEX (academic_session_id), INDEX (timing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS batch_timings (
  batch_id INT NOT NULL,
  timing_id INT NOT NULL,
  PRIMARY KEY (batch_id, timing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Fetch data for display
$batches = $db->query('SELECT b.id, b.name, c.name AS course_name, a.name AS session_name FROM batches b LEFT JOIN courses c ON c.id = b.course_id LEFT JOIN academic_sessions a ON a.id = b.academic_session_id ORDER BY b.name ASC')->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Batches</title>
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
        <h1 class="h3">Batches</h1>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBatchModal">Create Batch</button>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Course</th>
                            <th>Academic Session</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $batch): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($batch['name']); ?></td>
                                <td><?php echo htmlspecialchars($batch['course_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($batch['session_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary">Edit</button>
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">No batches found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

  </main>
</div>

<!-- Create Batch Modal -->
<div class="modal fade" id="createBatchModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Create Batch</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted">Creating and editing batches is disabled in this simplified view.</p>
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
