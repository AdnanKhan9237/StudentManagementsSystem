<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole('teacher');

$db = (new Database())->getConnection();
$db->exec("CREATE TABLE IF NOT EXISTS assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    type ENUM('test','assignment') NOT NULL,
    score DECIMAL(10,2) NOT NULL,
    max_score DECIMAL(10,2) NOT NULL,
    assessed_at DATE NOT NULL,
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
            $title = trim($_POST['title'] ?? '');
            $type = $_POST['type'] ?? 'test';
            $score = trim($_POST['score'] ?? '');
            $max_score = trim($_POST['max_score'] ?? '');
            $assessed_at = trim($_POST['assessed_at'] ?? '');
            if ($student_id <= 0 || $title === '' || !in_array($type, ['test','assignment'], true) || $score === '' || $max_score === '' || $assessed_at === '') {
                $errors[] = 'All fields are required.';
            } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $score) || !preg_match('/^\d+(\.\d{1,2})?$/', $max_score)) {
                $errors[] = 'Scores must be numbers.';
            } elseif (!in_array($student_id, $allowedStudentIds, true)) {
                $errors[] = 'You are not assigned to this student.';
            } else {
                $stmt = $db->prepare('INSERT INTO assessments (student_id, title, type, score, max_score, assessed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $ok = $stmt->execute([$student_id, $title, $type, $score, $max_score, $assessed_at, date('Y-m-d H:i:s')]);
                if ($ok) { $success = 'Assessment added.'; } else { $errors[] = 'Failed to add assessment.'; }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $type = $_POST['type'] ?? 'test';
            $score = trim($_POST['score'] ?? '');
            $max_score = trim($_POST['max_score'] ?? '');
            if ($id <= 0 || $title === '' || !in_array($type, ['test','assignment'], true) || $score === '' || $max_score === '') { $errors[] = 'Invalid input.'; }
            elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $score) || !preg_match('/^\d+(\.\d{1,2})?$/', $max_score)) { $errors[] = 'Scores must be numbers.'; }
            else {
                // Enforce teacher authorization
                $sidStmt = $db->prepare('SELECT student_id FROM assessments WHERE id = ?');
                $sidStmt->execute([$id]);
                $sid = (int)($sidStmt->fetchColumn() ?: 0);
                if ($sid > 0 && !in_array($sid, $allowedStudentIds, true)) {
                    $errors[] = 'You are not assigned to this student.';
                }
                if (empty($errors)) {
                    $stmt = $db->prepare('UPDATE assessments SET title = ?, type = ?, score = ?, max_score = ?, updated_at = ? WHERE id = ?');
                    $ok = $stmt->execute([$title, $type, $score, $max_score, date('Y-m-d H:i:s'), $id]);
                    if ($ok) { $success = 'Assessment updated.'; } else { $errors[] = 'Failed to update assessment.'; }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $errors[] = 'Invalid record ID.'; }
            else {
                // Enforce teacher authorization
                $sidStmt = $db->prepare('SELECT student_id FROM assessments WHERE id = ?');
                $sidStmt->execute([$id]);
                $sid = (int)($sidStmt->fetchColumn() ?: 0);
                if ($sid > 0 && !in_array($sid, $allowedStudentIds, true)) {
                    $errors[] = 'You are not assigned to this student.';
                }
                if (empty($errors)) {
                    $ok = $db->prepare('DELETE FROM assessments WHERE id = ?')->execute([$id]);
                    if ($ok) { $success = 'Assessment deleted.'; } else { $errors[] = 'Failed to delete assessment.'; }
                }
            }
        }
    }
}
// Restrict listing to allowed students
if (!empty($allowedStudentIds)) {
    $in = implode(',', array_fill(0, count($allowedStudentIds), '?'));
    $stmt = $db->prepare("SELECT a.id, a.title, a.type, a.score, a.max_score, a.assessed_at, u.username AS student_name
                           FROM assessments a JOIN users u ON u.id = a.student_id
                           WHERE a.student_id IN ($in)
                           ORDER BY a.assessed_at DESC, a.id DESC");
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
    <title>Assessments</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/design-system.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3">Assessments</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) { echo '<div>'.htmlspecialchars($e).'</div>'; } ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">Add Assessment</div>
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
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Unit Test 1" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="test">Test</option>
                            <option value="assignment">Assignment</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Score</label>
                        <input type="text" name="score" class="form-control" placeholder="e.g. 18" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Max Score</label>
                        <input type="text" name="max_score" class="form-control" placeholder="e.g. 20" required>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="assessed_at" class="form-control" required>
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary" type="submit">Add</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Records</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead><tr><th>Date</th><th>Student</th><th>Title</th><th>Type</th><th>Score</th><th>Max</th><th style="width: 300px;">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['assessed_at']); ?></td>
                            <td><?php echo htmlspecialchars($r['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['title']); ?></td>
                            <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($r['type']); ?></span></td>
                            <td><?php echo htmlspecialchars($r['score']); ?></td>
                            <td><?php echo htmlspecialchars($r['max_score']); ?></td>
                            <td>
                                <form method="post" class="row g-2 align-items-center">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <div class="col-md-3"><input type="text" name="title" class="form-control form-control-sm" value="<?php echo htmlspecialchars($r['title']); ?>" placeholder="Title"></div>
                                    <div class="col-md-2">
                                        <select name="type" class="form-select form-select-sm">
                                            <option value="test" <?php echo $r['type']==='test'?'selected':''; ?>>Test</option>
                                            <option value="assignment" <?php echo $r['type']==='assignment'?'selected':''; ?>>Assignment</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2"><input type="text" name="score" class="form-control form-control-sm" value="<?php echo htmlspecialchars($r['score']); ?>" placeholder="Score"></div>
                                    <div class="col-md-2"><input type="text" name="max_score" class="form-control form-control-sm" value="<?php echo htmlspecialchars($r['max_score']); ?>" placeholder="Max"></div>
                                    <div class="col-md-3 d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary" name="action" value="update" type="submit">Update</button>
                                        <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this record?');">Delete</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="text-center text-muted">No records found.</td></tr>
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
