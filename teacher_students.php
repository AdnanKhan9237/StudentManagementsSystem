<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole('teacher');

$db = (new Database())->getConnection();
// Mapping table: teacher assigned to batches
$db->exec("CREATE TABLE IF NOT EXISTS teacher_batches (
  teacher_id INT NOT NULL,
  batch_id INT NOT NULL,
  assigned_at DATETIME NOT NULL,
  PRIMARY KEY (teacher_id, batch_id),
  INDEX (batch_id), INDEX (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Mapping of allowed timings per batch per teacher
$db->exec("CREATE TABLE IF NOT EXISTS teacher_batch_timings (
  teacher_id INT NOT NULL,
  batch_id INT NOT NULL,
  timing_id INT NOT NULL,
  PRIMARY KEY (teacher_id, batch_id, timing_id),
  INDEX (teacher_id), INDEX (batch_id), INDEX (timing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$teacherId = (int) $session->getUserId();
$students = [];
try {
    // Find batches assigned to this teacher
    $bStmt = $db->prepare('SELECT batch_id FROM teacher_batches WHERE teacher_id = ?');
    $bStmt->execute([$teacherId]);
    $batchIds = array_map('intval', array_column($bStmt->fetchAll(PDO::FETCH_ASSOC), 'batch_id'));

    if (!empty($batchIds)) {
        // Restrict by both batch and timing assigned to teacher
        $in = implode(',', array_fill(0, count($batchIds), '?'));
        $sql = "SELECT 
                    s.id AS student_id,
                    s.fullname,
                    s.cnic AS student_cnic,
                    s.general_number,
                    u.id AS user_id,
                    u.username,
                    u.email
                FROM students s
                LEFT JOIN users u 
                  ON u.role = 'student' 
                 AND (u.cnic = s.cnic OR u.id = s.user_id)
                WHERE s.batch_id IN ($in)
                  AND EXISTS (
                    SELECT 1 FROM teacher_batch_timings tbt
                    WHERE tbt.teacher_id = ? AND tbt.batch_id = s.batch_id AND tbt.timing_id = s.timing_id
                  )
                ORDER BY COALESCE(u.username, s.fullname) ASC";
        $sStmt = $db->prepare($sql);
        $params = array_merge($batchIds, [$teacherId]);
        $sStmt->execute($params);
        $students = $sStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // Fallback: no students
    $students = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Data</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/design-system.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3">Students</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
    </div>

    <div class="card">
        <div class="card-header">Student List (read-only)</div>
        <div class="card-body">
            <?php if (empty($students)): ?>
                <div class="alert alert-warning">No students found. Ensure you have assigned batches and timings.</div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>CNIC</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $s): ?>
                        <tr>
                            <td><?php echo (int)($s['student_id'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars($s['fullname'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($s['username'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($s['email'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($s['student_cnic'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($students)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No students found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
