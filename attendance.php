<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole('teacher');

$db = (new Database())->getConnection();

$teacherId = (int) $session->getUserId();
$rows = [];

try {
    $sql = "SELECT a.id, u.username AS student_name, a.status, a.recorded_at
            FROM attendance a
            JOIN users u ON u.id = a.student_id
            JOIN students s ON s.cnic = u.cnic
            JOIN teacher_batches tb ON tb.batch_id = s.batch_id AND tb.teacher_id = ?
            ORDER BY a.recorded_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$teacherId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // For simplicity, we'll just show an empty list if something goes wrong
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance</title>
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
                        <h4>Attendance</h4>
                    </div>
                    <div class="card border-0">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered align-middle">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $row['status'] === 'present' ? 'success' : 'danger'; ?>">
                                                    <?php echo htmlspecialchars(ucfirst($row['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($row['recorded_at']))); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($rows)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted">No attendance records found.</td>
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