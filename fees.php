<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole(['superadmin','accounts']);
$role = (string)$session->get('role');

$db = (new Database())->getConnection();

// Simplified schema for demonstration
$db->exec("CREATE TABLE IF NOT EXISTS fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('unpaid','paid','overdue') NOT NULL DEFAULT 'unpaid',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX (student_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Fetch data for display
$fees = $db->query("SELECT f.*, CONCAT(u.first_name, ' ', u.last_name) AS student_name FROM fees f JOIN users u ON u.id = f.student_id ORDER BY f.due_date ASC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fees Management</title>
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
        <h1 class="h3">Fees Management</h1>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createFeeModal">Record Fee</button>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fees as $fee): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($fee['student_name']); ?></td>
                                <td>$<?php echo htmlspecialchars(number_format($fee['amount'], 2)); ?></td>
                                <td><?php echo htmlspecialchars($fee['due_date']); ?></td>
                                <td><span class="badge bg-<?php echo $fee['status'] === 'paid' ? 'success' : ($fee['status'] === 'overdue' ? 'danger' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($fee['status'])); ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary">Edit</button>
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($fees)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">No fee records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

  </main>
</div>

<!-- Create Fee Modal -->
<div class="modal fade" id="createFeeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Record Fee</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted">Creating and editing fees is disabled in this simplified view.</p>
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
