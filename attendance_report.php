<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
// Teachers, accounts, and superadmin can view attendance reports
$auth->requireRole(['teacher', 'accounts', 'superadmin']);

$db = (new Database())->getConnection();

$role = (string)$session->get('role');
$teacherId = (int)$session->getUserId();
$errors = [];

// Build allowed batches and timings for teachers
$allowedBatchIds = [];
$allowedBatches = [];
$allowedTimingIds = [];
$allowedTimings = [];
if ($role === 'teacher') {
    try {
        $bStmt = $db->prepare('SELECT batch_id FROM teacher_batches WHERE teacher_id = ?');
        $bStmt->execute([$teacherId]);
        $allowedBatchIds = array_map('intval', array_column($bStmt->fetchAll(PDO::FETCH_ASSOC), 'batch_id'));
        if (!empty($allowedBatchIds)) {
            $in = implode(',', array_fill(0, count($allowedBatchIds), '?'));
            $bxStmt = $db->prepare("SELECT id, name FROM batches WHERE id IN ($in) ORDER BY name ASC");
            $bxStmt->execute($allowedBatchIds);
            $allowedBatches = $bxStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) { /* ignore */
    }
    try {
        $tStmt = $db->prepare('SELECT DISTINCT timing_id FROM teacher_batch_timings WHERE teacher_id = ?');
        $tStmt->execute([$teacherId]);
        $allowedTimingIds = array_map('intval', array_column($tStmt->fetchAll(PDO::FETCH_ASSOC), 'timing_id'));
        if (!empty($allowedTimingIds)) {
            $in = implode(',', array_fill(0, count($allowedTimingIds), '?'));
            $timStmt = $db->prepare("SELECT id, name, day_of_week, start_time, end_time FROM timings WHERE id IN ($in) ORDER BY FIELD(day_of_week, 'Daily','Mon','Tue','Wed','Thu','Fri','Sat','Sun'), start_time ASC");
            $timStmt->execute($allowedTimingIds);
            $allowedTimings = $timStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) { /* ignore */
    }
} else {
    // Non-teachers can choose any batch/timing
    try {
        $allowedBatches = $db->query('SELECT id, name FROM batches ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
        $allowedTimings = $db->query("SELECT id, name, day_of_week, start_time, end_time FROM timings ORDER BY FIELD(day_of_week, 'Daily','Mon','Tue','Wed','Thu','Fri','Sat','Sun'), start_time ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* ignore */
    }
}

// Filters
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$from = $_GET['from'] ?? $monthStart;
$to = $_GET['to'] ?? $today;
$batchId = (int)($_GET['batch_id'] ?? 0);
$timingId = (int)($_GET['timing_id'] ?? 0);
$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');

if ($role === 'teacher') {
    if ($batchId > 0 && !in_array($batchId, $allowedBatchIds, true)) {
        $batchId = 0;
    }
    if ($timingId > 0 && !in_array($timingId, $allowedTimingIds, true)) {
        $timingId = 0;
    }
}

// Build query
$params = [];
$where = [];
$where[] = 'a.att_date BETWEEN ? AND ?';
$params[] = $from;
$params[] = $to;
if ($batchId > 0) {
    $where[] = 's.batch_id = ?';
    $params[] = $batchId;
}
if ($timingId > 0) {
    $where[] = 's.timing_id = ?';
    $params[] = $timingId;
}
if (in_array($status, ['present', 'absent', 'leave'], true)) {
    $where[] = 'a.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = 's.fullname LIKE ?';
    $params[] = '%' . $q . '%';
}
if ($role === 'teacher') {
    // Restrict rows to teacher's assignments
    $where[] = 'EXISTS (SELECT 1 FROM teacher_batches tb WHERE tb.teacher_id = ? AND tb.batch_id = s.batch_id)';
    $params[] = $teacherId;
    $where[] = 'EXISTS (SELECT 1 FROM teacher_batch_timings tbt WHERE tbt.teacher_id = ? AND tbt.batch_id = s.batch_id AND tbt.timing_id = s.timing_id)';
    $params[] = $teacherId;
}

// Include both roster-linked (student_record_id) and legacy user-linked entries (mapped via CNIC)
$baseWhere = implode(' AND ', $where);
$sql = "SELECT x.student_record_id, x.fullname, x.batch_name, x.timing_name, x.start_time, x.end_time,
                SUM(x.present_count) AS present_count,
                SUM(x.absent_count) AS absent_count,
                SUM(x.leave_count) AS leave_count,
                SUM(x.total_count) AS total_count
        FROM (
            SELECT s.id AS student_record_id,
                   s.fullname,
                   b.name AS batch_name,
                   t.name AS timing_name,
                   t.start_time, t.end_time,
                   SUM(a.status=\'present\') AS present_count,
                   SUM(a.status=\'absent\') AS absent_count,
                   SUM(a.status=\'leave\') AS leave_count,
                   COUNT(*) AS total_count
            FROM attendance a
            JOIN students s ON s.id = a.student_record_id
            LEFT JOIN batches b ON b.id = s.batch_id
            LEFT JOIN timings t ON t.id = s.timing_id
            WHERE $baseWhere AND a.student_record_id IS NOT NULL
            GROUP BY s.id
            UNION ALL
            SELECT s.id AS student_record_id,
                   s.fullname,
                   b.name AS batch_name,
                   t.name AS timing_name,
                   t.start_time, t.end_time,
                   SUM(a.status=\'present\') AS present_count,
                   SUM(a.status=\'absent\') AS absent_count,
                   SUM(a.status=\'leave\') AS leave_count,
                   COUNT(*) AS total_count
            FROM attendance a
            JOIN users u ON u.id = a.student_id
            JOIN students s ON s.cnic = u.cnic
            LEFT JOIN batches b ON b.id = s.batch_id
            LEFT JOIN timings t ON t.id = s.timing_id
            WHERE $baseWhere AND a.student_record_id IS NULL
            GROUP BY s.id
        ) x
        GROUP BY x.student_record_id
        ORDER BY x.fullname ASC";

$rows = [];
try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Failed to load report: ' . $e->getMessage();
}

// CSV export
if ((isset($_GET['export']) && $_GET['export'] === 'csv')) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student', 'Batch', 'Timing', 'Present', 'Absent', 'Leave', 'Total', 'Attendance %']);
    foreach ($rows as $r) {
        $total = (int)$r['total_count'];
        $present = (int)$r['present_count'];
        $absent = (int)$r['absent_count'];
        $leave = (int)$r['leave_count'];
        $pct = $total > 0 ? round(($present / $total) * 100, 1) : 0.0;
        $timingLabel = trim(($r['timing_name'] ?? '') . ' ' . ($r['start_time'] ?? '') . ' - ' . ($r['end_time'] ?? ''));
        fputcsv($out, [
            $r['fullname'] ?? '',
            $r['batch_name'] ?? '',
            $timingLabel,
            $present,
            $absent,
            $leave,
            $total,
            $pct . '%'
        ]);
    }
    fclose($out);
    exit;
}

?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Attendance Report</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/design-system.css" rel="stylesheet">
    <style>
        body {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        .sidebar {
            width: 250px;
            background-color: #f8f9fa;
            padding: 20px;
            overflow-y: auto;
        }

        .main-content {
            flex-grow: 1;
            overflow-y: auto;
            padding: 20px;
        }
    </style>
</head>

<body>
    <?php include './partials/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="mb-0">Attendance Report</h3>
                <div>
                    <a class="btn btn-outline-secondary" href="attendance.php">Back to Attendance</a>
                </div>
            </div>
            <?php if (!empty($errors)) : ?>
                <div class="alert alert-danger"><?php foreach ($errors as $e) {
                                                    echo '<div>' . htmlspecialchars($e) . '</div>';
                                                } ?></div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">Filters</div>
                <div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">From</label>
                            <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">To</label>
                            <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Batch</label>
                            <select name="batch_id" class="form-select">
                                <option value="">All batches</option>
                                <?php foreach ($allowedBatches as $b) : ?>
                                    <?php $bid = (int)$b['id']; ?>
                                    <option value="<?php echo $bid; ?>" <?php echo ($batchId === $bid) ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name'] ?? ('Batch #' . $bid)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Timing</label>
                            <select name="timing_id" class="form-select">
                                <option value="">All timings</option>
                                <?php foreach ($allowedTimings as $t) : ?>
                                    <?php
                                    $tid = (int)$t['id'];
                                    $label = htmlspecialchars(($t['name'] ?? '') . ' ' . ($t['day_of_week'] ?? '') . ' ' . ($t['start_time'] ?? '') . ' - ' . ($t['end_time'] ?? ''));
                                    ?>
                                    <option value="<?php echo $tid; ?>" <?php echo ($timingId === $tid) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">Any</option>
                                <option value="present" <?php echo ($status === 'present') ? 'selected' : ''; ?>>Present</option>
                                <option value="absent" <?php echo ($status === 'absent') ? 'selected' : ''; ?>>Absent</option>
                                <option value="leave" <?php echo ($status === 'leave') ? 'selected' : ''; ?>>Leave</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Search student</label>
                            <input type="text" name="q" class="form-control" placeholder="Name contains..." value="<?php echo htmlspecialchars($q); ?>">
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-primary" type="submit">Apply</button>
                            <a class="btn btn-outline-success" href="attendance_report.php?<?php
                                                                                                $qp = $_GET;
                                                                                                $qp['export'] = 'csv';
                                                                                                echo htmlspecialchars(http_build_query($qp));
                                                                                                ?>">Export CSV</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Report</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Batch</th>
                                    <th>Timing</th>
                                    <th class="text-center">Present</th>
                                    <th class="text-center">Absent</th>
                                    <th class="text-center">Leave</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Attendance %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)) : ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No records found</td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ($rows as $r) : ?>
                                        <?php
                                        $total = (int)$r['total_count'];
                                        $present = (int)$r['present_count'];
                                        $pct = $total > 0 ? round(($present / $total) * 100, 1) : 0.0;
                                        $timingLabel = trim(($r['timing_name'] ?? '') . ' ' . ($r['start_time'] ?? '') . ' - ' . ($r['end_time'] ?? ''));
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($r['fullname'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($r['batch_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($timingLabel); ?></td>
                                            <td class="text-center"><?php echo $present; ?></td>
                                            <td class="text-center"><?php echo htmlspecialchars($r['absent_count'] ?? 0); ?></td>
                                            <td class="text-center"><?php echo htmlspecialchars($r['leave_count'] ?? 0); ?></td>
                                            <td class="text-center"><?php echo $total; ?></td>
                                            <td class="text-center <?php echo $pct < 75 ? 'text-danger fw-bold' : 'text-success'; ?>"><?php echo $pct; ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>