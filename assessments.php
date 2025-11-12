<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole('teacher');

$db = (new Database())->getConnection();

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
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <div class="wrapper">
        <?php include_once __DIR__ . '/partials/sidebar.php'; ?>
        <div class="main">
            <?php include_once __DIR__ . '/partials/header.php'; ?>
            <main class="content px-3 py-2">
                <div class="container-fluid">
                    <div class="mb-3">
                        <h4>Assessments</h4>
                    </div>
                    <div class="card border-0">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered align-middle">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Student</th>
                                            <th>Title</th>
                                            <th>Type</th>
                                            <th>Score</th>
                                            <th>Max</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($r['assessed_at']); ?></td>
                                            <td><?php echo htmlspecialchars($r['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($r['title']); ?></td>
                                            <td><span
                                                    class="badge bg-info text-dark"><?php echo htmlspecialchars($r['type']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($r['score']); ?></td>
                                            <td><?php echo htmlspecialchars($r['max_score']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($rows)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No records found.</td>
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
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/script.js"></script>
</body>

</html>