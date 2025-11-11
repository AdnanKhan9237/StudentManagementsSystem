<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Logger.php';
require_once __DIR__ . '/classes/Validator.php';
require_once __DIR__ . '/classes/RateLimiter.php';
require_once __DIR__ . '/classes/Audit.php';
require_once __DIR__ . '/classes/Notifier.php';

$session = Session::getInstance();
$auth = new Auth();
// Teachers can mark attendance; accounts and superadmin can view/manage
$auth->requireRole(['teacher','accounts','superadmin']);

$db = (new Database())->getConnection();
// Ensure attendance and teacher-batch mapping tables exist
$db->exec("CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NULL,
    student_record_id INT NULL,
    att_date DATE NOT NULL,
    status ENUM('present','absent','leave') NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_student_record_date (student_record_id, att_date),
    INDEX idx_student_id (student_id),
    INDEX idx_student_record_id (student_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
$role = (string) $session->get('role');
$teacherId = (int) $session->getUserId();

// Build allowed student IDs for teacher based on assigned batches
$allowedStudentIds = [];
// Build allowed timings for teacher
$allowedTimingIds = [];
$allowedTimings = [];
// Build allowed batches for teacher
$allowedBatchIds = [];
$allowedBatches = [];
if ($role === 'teacher') {
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
    // Allowed batches for teacher
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
    } catch (Throwable $e) { /* ignore */ }
    // Allowed timing IDs and details
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
    } catch (Throwable $e) { /* ignore */ }
}

// Determine selected batch and timing (server-side filter)
$selectedBatchId = 0;
$selectedTimingId = 0;
if ($role === 'teacher') {
    $selectedBatchId = (int)($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);
    $selectedTimingId = (int)($_GET['timing_id'] ?? $_POST['timing_id'] ?? 0);
    if ($selectedBatchId > 0 && !in_array($selectedBatchId, $allowedBatchIds, true)) { $selectedBatchId = 0; }
    if ($selectedTimingId > 0 && !in_array($selectedTimingId, $allowedTimingIds, true)) {
        // Disallow selecting a timing not assigned to teacher
        $selectedTimingId = 0;
    }
}

// Populate student dropdown or roster according to role
$roster = [];
if ($role === 'teacher') {
    if ($selectedBatchId > 0 && $selectedTimingId > 0) {
        // Build roster from students table for selected batch + timing
        $stmt = $db->prepare(
            "SELECT s.id AS student_record_id, s.fullname
             FROM students s
             WHERE s.batch_id = ? AND s.timing_id = ?
               AND EXISTS (
                 SELECT 1 FROM teacher_batches tb WHERE tb.teacher_id = ? AND tb.batch_id = s.batch_id
               )
               AND EXISTS (
                 SELECT 1 FROM teacher_batch_timings tbt WHERE tbt.teacher_id = ? AND tbt.batch_id = s.batch_id AND tbt.timing_id = s.timing_id
               )
             ORDER BY s.fullname ASC"
        );
        $stmt->execute([$selectedBatchId, $selectedTimingId, $teacherId, $teacherId]);
        $roster = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // In roster mode, hide single-student dropdown
    $students = [];
} else {
    $students = $db->query("SELECT id, username FROM users WHERE role = 'student' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
        || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
        || ($_POST['ajax'] ?? '') === '1';
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) { 
        $errors[] = 'Invalid CSRF token.'; 
        Logger::error('attendance.csrf_failed', ['user_id' => $session->getUserId(), 'action' => $action]);
        if ($wantsJson) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']); exit(); }
    }
    // Only teachers (and superadmin) may create/update/delete attendance
    elseif (!in_array($role, ['teacher','superadmin'], true)) {
        $errors[] = 'Insufficient permissions to modify attendance.';
    } else {
        $resultPayload = [];
        if ($action === 'create') {
            $student_id = (int)($_POST['student_id'] ?? 0);
            $att_date = trim($_POST['att_date'] ?? '');
            $status = $_POST['status'] ?? 'present';
            $note = trim($_POST['note'] ?? '');
            $timing_id = (int)($_POST['timing_id'] ?? 0);

            if (!RateLimiter::allow($db, (int)$session->getUserId(), 'attendance.create', 100, 600)) {
                $errors[] = 'Too many attendance actions. Try again later.';
                Logger::warning('attendance.create.rate_limited', ['user_id' => $session->getUserId()]);
            }

            if (!Validator::intId($student_id) || !Validator::date($att_date) || !Validator::status($status)) {
                $errors[] = 'Student, date, and valid status are required.';
                Logger::warning('attendance.create.invalid_input', ['user_id' => $session->getUserId(), 'student_id' => $student_id, 'date' => $att_date, 'status' => $status]);
            }
            if ($status === 'leave') {
                if ($note === '' || !Validator::note($note)) {
                    $errors[] = 'Leave requires a remark (max 255 chars).';
                    Logger::warning('attendance.create.leave_requires_note', ['user_id' => $session->getUserId(), 'student_id' => $student_id]);
                }
            }

            if (empty($errors)) {
                if ($role === 'teacher') {
                    if (!Validator::intId($timing_id) || !in_array($timing_id, $allowedTimingIds, true)) {
                        $errors[] = 'Select a valid timing to record attendance.';
                    } elseif (!in_array($student_id, $allowedStudentIds, true)) {
                        $errors[] = 'You are not assigned to this student.';
                    } else {
                        $chk = $db->prepare(
                            "SELECT COUNT(*) FROM students s
                             JOIN users u ON u.cnic = s.cnic
                             WHERE u.id = ? AND s.timing_id = ?"
                        );
                        $chk->execute([$student_id, $timing_id]);
                        if ((int)$chk->fetchColumn() === 0) {
                            $errors[] = 'Selected student is not in the chosen timing.';
                        }
                    }
                } else {
                    try {
                        $stmt = $db->prepare('INSERT INTO attendance (student_id, att_date, status, note, created_at) VALUES (?, ?, ?, ?, ?)');
                        $ok = $stmt->execute([$student_id, $att_date, $status, $note, date('Y-m-d H:i:s')]);
                    } catch (Throwable $e) {
                        $ok = false;
                        Logger::error('attendance.create.failed', ['user_id' => $session->getUserId(), 'error' => $e->getMessage()]);
                    }
                    if ($ok) {
                        $success = 'Attendance recorded.';
                        $newId = (int)$db->lastInsertId();
                        $student_name = '';
                        foreach ($students as $s) { if ((int)$s['id'] === $student_id) { $student_name = (string)$s['username']; break; } }
                        $resultPayload = ['id' => $newId, 'att_date' => $att_date, 'status' => $status, 'note' => $note, 'student_name' => $student_name];
                        Logger::info('attendance.create.success', ['user_id' => $session->getUserId(), 'id' => $newId, 'student_id' => $student_id, 'date' => $att_date, 'status' => $status]);
                        Audit::log($db, (int)$session->getUserId(), 'create', 'attendance', $newId, null, [
                            'student_id' => $student_id,
                            'att_date' => $att_date,
                            'status' => $status,
                            'note' => $note
                        ]);
                    } else { $errors[] = 'Failed to record attendance.'; }
                }
            }
        } elseif ($action === 'bulk_create') {
            $att_date = trim($_POST['att_date'] ?? '');
            $batch_id = (int)($_POST['batch_id'] ?? 0);
            $timing_id = (int)($_POST['timing_id'] ?? 0);
            $items = $_POST['attendance'] ?? [];
            $notes = $_POST['notes'] ?? [];

            if (!RateLimiter::allow($db, (int)$session->getUserId(), 'attendance.bulk_create', 200, 600)) {
                $errors[] = 'Too many attendance actions. Try again later.';
                Logger::warning('attendance.bulk_create.rate_limited', ['user_id' => $session->getUserId()]);
            }

            if (!Validator::date($att_date) || !Validator::intId($batch_id) || !Validator::intId($timing_id) || empty($items)) {
                $errors[] = 'Date, batch, timing, and attendance items are required.';
                Logger::warning('attendance.bulk_create.invalid_input', ['user_id' => $session->getUserId(), 'date' => $att_date, 'batch_id' => $batch_id, 'timing_id' => $timing_id]);
            } elseif ($role === 'teacher') {
                if (!in_array($batch_id, $allowedBatchIds, true) || !in_array($timing_id, $allowedTimingIds, true)) {
                    $errors[] = 'Invalid batch or timing selection.';
                }
            }
            if (empty($errors)) {
                foreach ($items as $sid => $st) {
                    if (!Validator::status((string)$st)) { $errors[] = 'One or more statuses are invalid.'; break; }
                    if ($st === 'leave') {
                        $n = trim($notes[$sid] ?? '');
                        if ($n === '' || !Validator::note($n)) { $errors[] = 'Leave requires a remark for some students.'; break; }
                    }
                }
            }
            if (empty($errors)) {
                try {
                    $stmt = $db->prepare('INSERT INTO attendance (student_record_id, att_date, status, note, created_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), updated_at = VALUES(created_at)');
                    $now = date('Y-m-d H:i:s');
                    $saved = 0;
                    $absentCount = 0;
                    foreach ($items as $sid => $st) {
                        $student_record_id = (int)$sid;
                        $status = (in_array($st, ['present','absent','leave'], true)) ? $st : 'present';
                        $note = trim($notes[$sid] ?? '');
                        $chk = $db->prepare("SELECT COUNT(*) FROM students s WHERE s.id = ? AND s.batch_id = ? AND s.timing_id = ?");
                        $chk->execute([$student_record_id, $batch_id, $timing_id]);
                        if ((int)$chk->fetchColumn() === 0) { continue; }
                        $ok = $stmt->execute([$student_record_id, $att_date, $status, $note, $now]);
                        if ($ok) { $saved++; if ($status === 'absent') { $absentCount++; } }
                    }
                    if ($saved > 0) {
                        $success = "Saved attendance for $saved student(s).";
                        Logger::info('attendance.bulk_create.success', ['user_id' => $session->getUserId(), 'date' => $att_date, 'batch_id' => $batch_id, 'timing_id' => $timing_id, 'saved' => $saved]);
                        Audit::log($db, (int)$session->getUserId(), 'bulk_create', 'attendance', null, null, [
                            'date' => $att_date,
                            'batch_id' => $batch_id,
                            'timing_id' => $timing_id,
                            'saved' => $saved
                        ]);
                        if ($absentCount >= 10) {
                            Notifier::notifyAbsenceSpike($db, $att_date, $batch_id, $timing_id, $absentCount);
                        }
                    } else {
                        $errors[] = 'No attendance records saved.';
                        Logger::warning('attendance.bulk_create.none_saved', ['user_id' => $session->getUserId(), 'date' => $att_date]);
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Failed to save bulk attendance.';
                    Logger::error('attendance.bulk_create.failed', ['user_id' => $session->getUserId(), 'error' => $e->getMessage()]);
                }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'present';
            $note = trim($_POST['note'] ?? '');
            if (!RateLimiter::allow($db, (int)$session->getUserId(), 'attendance.update', 100, 600)) {
                $errors[] = 'Too many attendance actions. Try again later.';
                Logger::warning('attendance.update.rate_limited', ['user_id' => $session->getUserId(), 'id' => $id]);
            }
            if (!Validator::intId($id) || !Validator::status($status)) { $errors[] = 'Invalid input.'; }
            elseif ($status === 'leave' && ($note === '' || !Validator::note($note))) { $errors[] = 'Leave requires a remark.'; }
            else {
                // Enforce teacher authorization on record (student_record_id first, fallback to student_id)
                if ($role === 'teacher') {
                    $sidStmt = $db->prepare('SELECT student_record_id, student_id FROM attendance WHERE id = ?');
                    $sidStmt->execute([$id]);
                    $row = $sidStmt->fetch(PDO::FETCH_ASSOC);
                    $srid = (int)($row['student_record_id'] ?? 0);
                    $uid = (int)($row['student_id'] ?? 0);
                    if ($srid > 0) {
                        // Check teacher assignment via students table
                        $chk = $db->prepare("SELECT COUNT(*) FROM students s
                                             WHERE s.id = ? AND EXISTS (SELECT 1 FROM teacher_batches tb WHERE tb.teacher_id = ? AND tb.batch_id = s.batch_id)
                                               AND EXISTS (SELECT 1 FROM teacher_batch_timings tbt WHERE tbt.teacher_id = ? AND tbt.batch_id = s.batch_id AND tbt.timing_id = s.timing_id)");
                        $chk->execute([$srid, $teacherId, $teacherId]);
                        if ((int)$chk->fetchColumn() === 0) {
                            $errors[] = 'You are not assigned to this student.';
                        }
                    } elseif ($uid > 0) {
                        if (!in_array($uid, $allowedStudentIds, true)) {
                            $errors[] = 'You are not assigned to this student.';
                        }
                    } else {
                        $errors[] = 'Unable to validate record ownership.';
                    }
                }
                if (empty($errors)) {
                    // Capture before snapshot for audit
                    $beforeStmt = $db->prepare('SELECT id, student_id, student_record_id, att_date, status, note FROM attendance WHERE id = ?');
                    $beforeStmt->execute([$id]);
                    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    try {
                        $stmt = $db->prepare('UPDATE attendance SET status = ?, note = ?, updated_at = ? WHERE id = ?');
                        $ok = $stmt->execute([$status, $note, date('Y-m-d H:i:s'), $id]);
                    } catch (Throwable $e) {
                        $ok = false;
                        Logger::error('attendance.update.failed', ['user_id' => $session->getUserId(), 'id' => $id, 'error' => $e->getMessage()]);
                    }
                    if ($ok) { 
                        $success = 'Attendance updated.'; 
                        $resultPayload = ['id' => $id, 'status' => $status, 'note' => $note];
                        Logger::info('attendance.update.success', ['user_id' => $session->getUserId(), 'id' => $id, 'status' => $status]);
                        Audit::log($db, (int)$session->getUserId(), 'update', 'attendance', $id, $before ?: null, [
                            'id' => $id,
                            'status' => $status,
                            'note' => $note
                        ]);
                    } else { $errors[] = 'Failed to update attendance.'; }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if (!RateLimiter::allow($db, (int)$session->getUserId(), 'attendance.delete', 100, 600)) {
                $errors[] = 'Too many attendance actions. Try again later.';
                Logger::warning('attendance.delete.rate_limited', ['user_id' => $session->getUserId(), 'id' => $id]);
            }
            if ($id <= 0) { $errors[] = 'Invalid record ID.'; }
            else {
                // Enforce teacher authorization on delete (student_record_id first)
                if ($role === 'teacher') {
                    $sidStmt = $db->prepare('SELECT student_record_id, student_id FROM attendance WHERE id = ?');
                    $sidStmt->execute([$id]);
                    $row = $sidStmt->fetch(PDO::FETCH_ASSOC);
                    $srid = (int)($row['student_record_id'] ?? 0);
                    $uid = (int)($row['student_id'] ?? 0);
                    if ($srid > 0) {
                        $chk = $db->prepare("SELECT COUNT(*) FROM students s
                                             WHERE s.id = ? AND EXISTS (SELECT 1 FROM teacher_batches tb WHERE tb.teacher_id = ? AND tb.batch_id = s.batch_id)
                                               AND EXISTS (SELECT 1 FROM teacher_batch_timings tbt WHERE tbt.teacher_id = ? AND tbt.batch_id = s.batch_id AND tbt.timing_id = s.timing_id)");
                        $chk->execute([$srid, $teacherId, $teacherId]);
                        if ((int)$chk->fetchColumn() === 0) { $errors[] = 'You are not assigned to this student.'; }
                    } elseif ($uid > 0) {
                        if (!in_array($uid, $allowedStudentIds, true)) { $errors[] = 'You are not assigned to this student.'; }
                    } else { $errors[] = 'Unable to validate record ownership.'; }
                }
                if (empty($errors)) {
                    // Capture before snapshot for audit
                    $beforeStmt = $db->prepare('SELECT id, student_id, student_record_id, att_date, status, note FROM attendance WHERE id = ?');
                    $beforeStmt->execute([$id]);
                    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    try {
                        $ok = $db->prepare('DELETE FROM attendance WHERE id = ?')->execute([$id]);
                    } catch (Throwable $e) {
                        $ok = false;
                        Logger::error('attendance.delete.failed', ['user_id' => $session->getUserId(), 'id' => $id, 'error' => $e->getMessage()]);
                    }
                    if ($ok) { 
                        $success = 'Attendance deleted.'; 
                        $resultPayload = ['id' => $id]; 
                        Logger::info('attendance.delete.success', ['user_id' => $session->getUserId(), 'id' => $id]);
                        Audit::log($db, (int)$session->getUserId(), 'delete', 'attendance', $id, $before ?: null, null);
                    } else { $errors[] = 'Failed to delete attendance.'; }
                }
            }
        }
    }

    if ($wantsJson) {
        header('Content-Type: application/json');
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        // Log final outcome if errors exist
        if (!empty($errors)) { Logger::warning('attendance.action.errors', ['user_id' => $session->getUserId(), 'action' => $action, 'errors' => $errors]); }
        echo json_encode([
            'success' => empty($errors),
            'message' => empty($errors) ? $success : ($errors[0] ?? 'Operation failed'),
            'csrf_token' => $_SESSION['csrf_token'],
            'data' => $resultPayload,
        ]);
        exit();
    }
}

// Load attendance rows according to role
if ($role === 'teacher') {
    // Include both user-linked and roster-linked records scoped to teacher assignments
    $params = [];
    $whereParts = [];
    if (!empty($allowedStudentIds)) {
        $in = implode(',', array_fill(0, count($allowedStudentIds), '?'));
        $whereParts[] = "a.student_id IN ($in)";
        $params = array_merge($params, $allowedStudentIds);
    }
    // Roster-linked records (by student_record_id) must belong to teacher's assigned batch+timing
    $whereParts[] = "(a.student_record_id IS NOT NULL AND EXISTS (
                        SELECT 1 FROM students s
                        WHERE s.id = a.student_record_id
                          AND EXISTS (SELECT 1 FROM teacher_batches tb WHERE tb.teacher_id = ? AND tb.batch_id = s.batch_id)
                          AND EXISTS (SELECT 1 FROM teacher_batch_timings tbt WHERE tbt.teacher_id = ? AND tbt.batch_id = s.batch_id AND tbt.timing_id = s.timing_id)
                      ))";
    $params[] = $teacherId;
    $params[] = $teacherId;
    $where = implode(' OR ', $whereParts);
    $sql = "SELECT a.id, a.att_date, a.status, a.note, COALESCE(s2.fullname, u.username) AS student_name
            FROM attendance a
            LEFT JOIN users u ON u.id = a.student_id
            LEFT JOIN students s2 ON s2.id = a.student_record_id
            WHERE $where
            ORDER BY a.att_date DESC, a.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rows = $db->query("SELECT a.id, a.att_date, a.status, a.note, COALESCE(s2.fullname, u.username) AS student_name FROM attendance a LEFT JOIN users u ON u.id = a.student_id LEFT JOIN students s2 ON s2.id = a.student_record_id ORDER BY a.att_date DESC, a.id DESC")->fetchAll(PDO::FETCH_ASSOC);
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
<link href="assets/css/design-system.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3">Attendance</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) { echo '<div>'.htmlspecialchars($e).'</div>'; } ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (in_array($role, ['teacher','superadmin'], true)): ?>
        <div class="card mb-4">
            <div class="card-header">Record Attendance</div>
            <div class="card-body">
                <form method="post" id="attendanceForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                    <input type="hidden" name="action" value="<?php echo ($role==='teacher') ? 'bulk_create' : 'create'; ?>">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Date</label>
                            <input type="date" name="att_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <?php if ($role === 'teacher'): ?>
                        <div class="col-md-3">
                            <label class="form-label">Batch</label>
                            <select name="batch_id" class="form-select" id="batchSelect">
                                <option value="">Select batch</option>
                                <?php foreach ($allowedBatches as $b): ?>
                                    <?php $bid = (int)$b['id']; ?>
                                    <option value="<?php echo $bid; ?>" <?php echo ($selectedBatchId === $bid) ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name'] ?? ('Batch #' . $bid)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Timing</label>
                            <select name="timing_id" class="form-select" id="timingSelect">
                                <option value="">Select timing</option>
                                <?php foreach ($allowedTimings as $t): ?>
                                    <?php 
                                        $tid = (int)$t['id']; 
                                        $label = htmlspecialchars(($t['name'] ?? '') . ' ' . ($t['day_of_week'] ?? '') . ' ' . ($t['start_time'] ?? '') . ' - ' . ($t['end_time'] ?? ''));
                                    ?>
                                    <option value="<?php echo $tid; ?>" <?php echo ($selectedTimingId === $tid) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php if ($role !== 'teacher'): ?>
                        <div class="col-md-3">
                            <label class="form-label">Student</label>
                            <select name="student_id" class="form-select" required>
                                <option value="">Select student</option>
                                <?php foreach ($students as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                                <option value="leave">Leave</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Note (optional)</label>
                            <input type="text" name="note" class="form-control" placeholder="Remark">
                        </div>
                    </div>
                    <?php if ($role === 'teacher'): ?>
                    <hr>
                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 class="mb-0">Roster</h5>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="markAll('present')">Mark all Present</button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="markAll('absent')">Mark all Absent</button>
                            </div>
                        </div>
                        <?php if (empty($roster)): ?>
                            <div class="alert alert-warning">Select batch and timing to load students.</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th class="text-center">Present</th>
                                        <th class="text-center">Absent</th>
                                        <th class="text-center">Leave</th>
                                        <th>Remark (required for leave)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($roster as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['fullname'] ?? ''); ?></td>
                                        <td class="text-center"><input type="radio" name="attendance[<?php echo (int)$r['student_record_id']; ?>]" value="present" checked></td>
                                        <td class="text-center"><input type="radio" name="attendance[<?php echo (int)$r['student_record_id']; ?>]" value="absent"></td>
                                        <td class="text-center"><input type="radio" name="attendance[<?php echo (int)$r['student_record_id']; ?>]" value="leave" class="leave-radio"></td>
                                        <td>
                                            <input type="text" name="notes[<?php echo (int)$r['student_record_id']; ?>]" class="form-control form-control-sm leave-note" placeholder="Enter remark for leave" disabled>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    <script>
                      function markAll(val) {
                        document.querySelectorAll('input[type=radio]').forEach(i => { if (i.value === val) i.checked = true; });
                      }
                      // Toggle required remark for Leave selections
                      (function(){
                        const table = document.querySelector('table');
                        if (!table) return;
                        table.addEventListener('change', (e) => {
                          const target = e.target;
                          if (target && target.type === 'radio' && target.name.startsWith('attendance[')) {
                            const sid = target.name.match(/attendance\[(\d+)\]/);
                            if (!sid) return;
                            const id = sid[1];
                            const row = target.closest('tr');
                            if (!row) return;
                            const note = row.querySelector('input.leave-note');
                            if (!note) return;
                            const isLeave = row.querySelector('input[type=radio][name="attendance['+id+']"][value="leave"]').checked;
                            note.disabled = !isLeave;
                            note.required = isLeave;
                            if (!isLeave) note.value = '';
                          }
                        });
                      })();
                      // Reload the page via GET when selecting batch/timing to avoid accidental POST validation
                      (function(){
                        const b = document.getElementById('batchSelect');
                        const t = document.getElementById('timingSelect');
                        function reload() {
                          const params = new URLSearchParams(window.location.search);
                          if (b) {
                            const bv = b.value || '';
                            if (bv) params.set('batch_id', bv); else params.delete('batch_id');
                          }
                          if (t) {
                            const tv = t.value || '';
                            if (tv) params.set('timing_id', tv); else params.delete('timing_id');
                          }
                          const qs = params.toString();
                          window.location.search = qs;
                        }
                        if (b) b.addEventListener('change', reload);
                        if (t) t.addEventListener('change', reload);
                      })();
                    </script>
                    <?php endif; ?>
                    <div class="mt-3">
                        <button class="btn btn-primary" type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info">View-only: Teachers and superadmins can modify attendance.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <span>Recent Attendance</span>
                <div class="d-flex gap-2 align-items-center">
                    <input type="text" id="attendanceFilter" class="form-control form-control-sm" placeholder="Search (student, note)">
                    <select id="statusFilter" class="form-select form-select-sm" style="max-width: 160px;">
                        <option value="">All Status</option>
                        <option value="present">Present</option>
                        <option value="absent">Absent</option>
                        <option value="leave">Leave</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead><tr><th>Date</th><th>Student</th><th>Status</th><th>Note</th><th style="width: 260px;">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['att_date']); ?></td>
                            <td><?php echo htmlspecialchars($r['student_name']); ?></td>
                            <td>
                                <span class="badge <?php echo $r['status']==='present'?'bg-success':($r['status']==='leave'?'bg-warning text-dark':'bg-danger'); ?>"><?php echo htmlspecialchars($r['status']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($r['note'] ?? ''); ?></td>
                            <td>
                                <?php if (in_array($role, ['teacher','superadmin'], true)): ?>
                                    <form method="post" class="attendance-row-form d-flex gap-2" data-id="<?php echo (int)$r['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this record?');">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">View-only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No attendance records.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Client-side filter for Attendance table
(() => {
  const q = document.getElementById('attendanceFilter');
  const statusSel = document.getElementById('statusFilter');
  const tbody = document.querySelector('table tbody');
  if (!q || !statusSel || !tbody) return;
  function matches(row, term, status) {
    const text = row.textContent.toLowerCase();
    const statusText = (row.querySelector('td:nth-child(3) .badge')?.textContent || '').toLowerCase();
    const okText = term === '' || text.includes(term);
    const okStatus = status === '' || statusText === status;
    return okText && okStatus;
  }
  function apply() {
    const term = q.value.trim().toLowerCase();
    const status = statusSel.value.trim().toLowerCase();
    [...tbody.rows].forEach(row => {
      row.style.display = matches(row, term, status) ? '' : 'none';
    });
  }
  q.addEventListener('input', apply);
  statusSel.addEventListener('change', apply);
})();
</script>
<script>
// AJAX for Attendance create/update/delete
(() => {
  const createForm = document.getElementById('attendanceForm');
  if (createForm) {
    createForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(createForm);
      fd.append('ajax', '1');
      try {
        const res = await fetch('attendance.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
        const json = await res.json();
        if (json.csrf_token) {
          document.querySelectorAll('input[name="csrf_token"]').forEach(i => i.value = json.csrf_token);
        }
        if (json.success) {
          // Reload to reflect the new record and keep filters intact
          window.location.href = 'attendance.php';
        } else {
          alert(json.message || 'Failed to record attendance');
        }
      } catch (err) {
        alert('Network error while recording attendance');
      }
    });
  }

  document.querySelectorAll('form.attendance-row-form').forEach(form => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      fd.append('ajax', '1');
      try {
        const res = await fetch('attendance.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
        const json = await res.json();
        if (json.csrf_token) {
          document.querySelectorAll('input[name="csrf_token"]').forEach(i => i.value = json.csrf_token);
        }
        if (json.success) {
          const row = form.closest('tr');
          if (row) row.remove();
        } else {
          alert(json.message || 'Operation failed');
        }
      } catch (err) {
        alert('Network error while updating attendance');
      }
    });
  });
})();
</script>
</body>
</html>
