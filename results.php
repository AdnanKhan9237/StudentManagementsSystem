<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole('teacher');

$db = (new Database())->getConnection();
$db->exec("CREATE TABLE IF NOT EXISTS final_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course VARCHAR(100) NOT NULL,
    result VARCHAR(50) NOT NULL,
    remarks VARCHAR(255) NULL,
    finalized_at DATE NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Ensure teacher-batch mapping exists
$db->exec("CREATE TABLE IF NOT EXISTS teacher_batches (
    teacher_id INT NOT NULL,
    batch_id INT NOT NULL,
    assigned_at DATETIME NOT NULL,
    PRIMARY KEY (teacher_id, batch_id),
    INDEX (batch_id), INDEX (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Per-teacher allowed timings per batch
$db->exec("CREATE TABLE IF NOT EXISTS teacher_batch_timings (
    teacher_id INT NOT NULL,
    batch_id INT NOT NULL,
    timing_id INT NOT NULL,
    PRIMARY KEY (teacher_id, batch_id, timing_id),
    INDEX (teacher_id), INDEX (batch_id), INDEX (timing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function csrfToken() { if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf_token']; }
function verifyCsrf($t) { return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t); }

$errors = [];
$success = '';

// Build allowed student IDs for this teacher
$teacherId = (int) $session->getUserId();
$allowedStudentIds = [];
try {
    $sql = "SELECT DISTINCT u.id
            FROM users u
            JOIN students s ON s.cnic = u.cnic
            JOIN teacher_batches tb ON tb.batch_id = s.batch_id AND tb.teacher_id = ?
            JOIN teacher_batch_timings tbt ON tbt.teacher_id = tb.teacher_id AND tbt.batch_id = tb.batch_id AND tbt.timing_id = s.timing_id
            WHERE u.role = 'student'";
    $sStmt = $db->prepare($sql);
    $sStmt->execute([$teacherId]);
    $allowedStudentIds = array_map('intval', array_column($sStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
} catch (Throwable $e) { /* ignore */ }

// Dropdown students limited to allowed set
if (!empty($allowedStudentIds)) {
    $in = implode(',', array_fill(0, count($allowedStudentIds), '?'));
    $stmt = $db->prepare("SELECT id, username FROM users WHERE role = 'student' AND id IN ($in) ORDER BY username ASC");
    $stmt->execute($allowedStudentIds);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $students = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) { $errors[] = 'Invalid CSRF token.'; }
    else {
        if ($action === 'create') {
            $student_id = (int)($_POST['student_id'] ?? 0);
            $course = trim($_POST['course'] ?? '');
            $result = trim($_POST['result'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');
            $finalized_at = trim($_POST['finalized_at'] ?? '');
            if ($student_id <= 0 || $course === '' || $result === '' || $finalized_at === '') { $errors[] = 'Student, course, result, and date are required.'; }
            elseif (!in_array($student_id, $allowedStudentIds, true)) { $errors[] = 'You are not assigned to this student.'; }
            else {
                $stmt = $db->prepare('INSERT INTO final_results (student_id, course, result, remarks, finalized_at, created_at) VALUES (?, ?, ?, ?, ?, ?)');
                $ok = $stmt->execute([$student_id, $course, $result, $remarks, $finalized_at, date('Y-m-d H:i:s')]);
                if ($ok) { $success = 'Final result recorded.'; } else { $errors[] = 'Failed to record final result.'; }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $course = trim($_POST['course'] ?? '');
            $result = trim($_POST['result'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');
            if ($id <= 0 || $course === '' || $result === '') { $errors[] = 'Invalid input.'; }
            else {
                // Enforce teacher authorization
                $sidStmt = $db->prepare('SELECT student_id FROM final_results WHERE id = ?');
                $sidStmt->execute([$id]);
                $sid = (int)($sidStmt->fetchColumn() ?: 0);
                if ($sid > 0 && !in_array($sid, $allowedStudentIds, true)) {
                    $errors[] = 'You are not assigned to this student.';
                }
                if (empty($errors)) {
                    $stmt = $db->prepare('UPDATE final_results SET course = ?, result = ?, remarks = ?, updated_at = ? WHERE id = ?');
                    $ok = $stmt->execute([$course, $result, $remarks, date('Y-m-d H:i:s'), $id]);
                    if ($ok) { $success = 'Final result updated.'; } else { $errors[] = 'Failed to update result.'; }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $errors[] = 'Invalid record ID.'; }
            else {
                // Enforce teacher authorization
                $sidStmt = $db->prepare('SELECT student_id FROM final_results WHERE id = ?');
                $sidStmt->execute([$id]);
                $sid = (int)($sidStmt->fetchColumn() ?: 0);
                if ($sid > 0 && !in_array($sid, $allowedStudentIds, true)) {
                    $errors[] = 'You are not assigned to this student.';
                }
                if (empty($errors)) {
                    $ok = $db->prepare('DELETE FROM final_results WHERE id = ?')->execute([$id]);
                    if ($ok) { $success = 'Final result deleted.'; } else { $errors[] = 'Failed to delete result.'; }
                }
            }
        }
    }
}
// Restrict listing to allowed students
if (!empty($allowedStudentIds)) {
    $in = implode(',', array_fill(0, count($allowedStudentIds), '?'));
    $stmt = $db->prepare("SELECT r.id, r.course, r.result, r.remarks, r.finalized_at, u.username AS student_name
                           FROM final_results r JOIN users u ON u.id = r.student_id
                           WHERE r.student_id IN ($in)
                           ORDER BY r.finalized_at DESC, r.id DESC");
    $stmt->execute($allowedStudentIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rows = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Final Results</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/design-system.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3">Final Results</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) { echo '<div>'.htmlspecialchars($e).'</div>'; } ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">Upload Final Result</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Student</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">Select student</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Course</label>
                        <input type="text" name="course" class="form-control" placeholder="e.g. Math 101" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Result</label>
                        <input type="text" name="result" class="form-control" placeholder="e.g. A / 75%" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Finalized Date</label>
                        <input type="date" name="finalized_at" class="form-control" required>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-12">
                        <label class="form-label">Remarks (optional)</label>
                        <input type="text" name="remarks" class="form-control" placeholder="Remarks">
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Records</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead><tr><th>Date</th><th>Student</th><th>Course</th><th>Result</th><th>Remarks</th><th style="width: 280px;">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['finalized_at']); ?></td>
                            <td><?php echo htmlspecialchars($r['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['course']); ?></td>
                            <td><?php echo htmlspecialchars($r['result']); ?></td>
                            <td><?php echo htmlspecialchars($r['remarks'] ?? ''); ?></td>
                            <td>
                                <form method="post" class="row g-2 align-items-center">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <div class="col-md-4"><input type="text" name="course" class="form-control form-control-sm" value="<?php echo htmlspecialchars($r['course']); ?>" placeholder="Course"></div>
                                    <div class="col-md-3"><input type="text" name="result" class="form-control form-control-sm" value="<?php echo htmlspecialchars($r['result']); ?>" placeholder="Result"></div>
                                    <div class="col-md-3"><input type="text" name="remarks" class="form-control form-control-sm" value="<?php echo htmlspecialchars($r['remarks'] ?? ''); ?>" placeholder="Remarks"></div>
                                    <div class="col-md-2 d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary" name="action" value="update" type="submit">Update</button>
                                        <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this record?');">Delete</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No records found.</td></tr>
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
