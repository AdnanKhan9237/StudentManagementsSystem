<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Session.php';

$auth = new Auth();
$session = Session::getInstance();
$db = (new Database())->getConnection();

$auth->requireLogin();
$auth->requireRole('student');

$userId = (int) $session->getUserId();

// Profile info
$stmt = $db->prepare('SELECT id, username, email, cnic, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

// Attendance for this student
$att = $db->prepare('SELECT att_date, status, note FROM attendance WHERE student_id = ? ORDER BY att_date DESC, id DESC');
$att->execute([$userId]);
$attendance = $att->fetchAll(PDO::FETCH_ASSOC);

// Assessments for this student
$as = $db->prepare('SELECT title, type, score, max_score, assessed_at FROM assessments WHERE student_id = ? ORDER BY assessed_at DESC, id DESC');
$as->execute([$userId]);
$assessments = $as->fetchAll(PDO::FETCH_ASSOC);

// Final results for this student
$fr = $db->prepare('SELECT course, result, remarks, finalized_at FROM final_results WHERE student_id = ? ORDER BY finalized_at DESC, id DESC');
$fr->execute([$userId]);
$finalResults = $fr->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Records</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="fa-solid fa-folder-open me-2"></i>My Records</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back to Dashboard</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header">Profile</div>
                <div class="card-body">
                    <div class="mb-2"><strong>Username:</strong> <?php echo htmlspecialchars($profile['username'] ?? ''); ?></div>
                    <div class="mb-2"><strong>Email:</strong> <?php echo htmlspecialchars($profile['email'] ?? ''); ?></div>
                    <div class="mb-2"><strong>CNIC:</strong> <?php echo htmlspecialchars($profile['cnic'] ?? ''); ?></div>
                    <div class="text-muted small">Joined: <?php echo htmlspecialchars($profile['created_at'] ?? ''); ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header">Summary</div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>Attendance records: <?php echo count($attendance); ?></li>
                        <li>Assessments: <?php echo count($assessments); ?></li>
                        <li>Final results: <?php echo count($finalResults); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-calendar-check me-2"></i>Attendance</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($attendance)): ?>
                            <tr><td colspan="3" class="text-muted">No attendance records yet.</td></tr>
                        <?php else: foreach ($attendance as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['att_date']); ?></td>
                                <td><?php echo htmlspecialchars($row['status']); ?></td>
                                <td><?php echo htmlspecialchars($row['note'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-clipboard-list me-2"></i>Assessments</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Score</th>
                            <th>Max Score</th>
                            <th>Assessed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assessments)): ?>
                            <tr><td colspan="5" class="text-muted">No assessments recorded yet.</td></tr>
                        <?php else: foreach ($assessments as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['title']); ?></td>
                                <td><?php echo htmlspecialchars($row['type']); ?></td>
                                <td><?php echo htmlspecialchars($row['score']); ?></td>
                                <td><?php echo htmlspecialchars($row['max_score']); ?></td>
                                <td><?php echo htmlspecialchars($row['assessed_at']); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-clipboard-check me-2"></i>Final Results</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Result</th>
                            <th>Remarks</th>
                            <th>Finalized At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($finalResults)): ?>
                            <tr><td colspan="4" class="text-muted">No final result available.</td></tr>
                        <?php else: foreach ($finalResults as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['course']); ?></td>
                                <td><?php echo htmlspecialchars($row['result']); ?></td>
                                <td><?php echo htmlspecialchars($row['remarks'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['finalized_at']); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
