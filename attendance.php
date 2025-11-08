<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
// Teachers can mark attendance; superadmin has full access; accounts can view
$auth->requireRole(['teacher','accounts']);

$db = (new Database())->getConnection();
$db->exec("CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    att_date DATE NOT NULL,
    status ENUM('present','absent') NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_student_date (student_id, att_date),
    INDEX (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function csrfToken() { if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf_token']; }
function verifyCsrf($t) { return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t); }

$errors = [];
$success = '';
$role = (string) $session->get('role');

$students = $db->query("SELECT id, username FROM users WHERE role = 'student' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
        || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
        || ($_POST['ajax'] ?? '') === '1';
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) { 
        $errors[] = 'Invalid CSRF token.'; 
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
            if ($student_id <= 0 || $att_date === '' || !in_array($status, ['present','absent'], true)) {
                $errors[] = 'Student, date, and status are required.';
            } else {
                $stmt = $db->prepare('INSERT INTO attendance (student_id, att_date, status, note, created_at) VALUES (?, ?, ?, ?, ?)');
                $ok = $stmt->execute([$student_id, $att_date, $status, $note, date('Y-m-d H:i:s')]);
                if ($ok) { 
                    $success = 'Attendance recorded.'; 
                    $newId = (int)$db->lastInsertId();
                    // Resolve student name from preloaded list
                    $student_name = '';
                    foreach ($students as $s) { if ((int)$s['id'] === $student_id) { $student_name = (string)$s['username']; break; } }
                    $resultPayload = ['id' => $newId, 'att_date' => $att_date, 'status' => $status, 'note' => $note, 'student_name' => $student_name];
                } else { $errors[] = 'Failed to record attendance.'; }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'present';
            $note = trim($_POST['note'] ?? '');
            if ($id <= 0 || !in_array($status, ['present','absent'], true)) { $errors[] = 'Invalid input.'; }
            else {
                $stmt = $db->prepare('UPDATE attendance SET status = ?, note = ?, updated_at = ? WHERE id = ?');
                $ok = $stmt->execute([$status, $note, date('Y-m-d H:i:s'), $id]);
                if ($ok) { $success = 'Attendance updated.'; $resultPayload = ['id' => $id, 'status' => $status, 'note' => $note]; } else { $errors[] = 'Failed to update attendance.'; }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $errors[] = 'Invalid record ID.'; }
            else { $ok = $db->prepare('DELETE FROM attendance WHERE id = ?')->execute([$id]); if ($ok) { $success = 'Attendance deleted.'; $resultPayload = ['id' => $id]; } else { $errors[] = 'Failed to delete attendance.'; } }
        }
    }

    if ($wantsJson) {
        header('Content-Type: application/json');
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        echo json_encode([
            'success' => empty($errors),
            'message' => empty($errors) ? $success : ($errors[0] ?? 'Operation failed'),
            'csrf_token' => $_SESSION['csrf_token'],
            'data' => $resultPayload,
        ]);
        exit();
    }
}

$rows = $db->query("SELECT a.id, a.att_date, a.status, a.note, u.username AS student_name FROM attendance a JOIN users u ON u.id = a.student_id ORDER BY a.att_date DESC, a.id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Date</label>
                            <input type="date" name="att_date" class="form-control" required>
                        </div>
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
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Note (optional)</label>
                            <input type="text" name="note" class="form-control" placeholder="Remark">
                        </div>
                    </div>
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
                                <span class="badge <?php echo $r['status']==='present'?'bg-success':'bg-danger'; ?>"><?php echo htmlspecialchars($r['status']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($r['note'] ?? ''); ?></td>
                            <td>
                                <?php if (in_array($role, ['teacher','superadmin'], true)): ?>
                                    <form method="post" class="row g-2 align-items-center attendance-row-form" data-id="<?php echo (int)$r['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <div class="col-md-5">
                                            <select name="status" class="form-select form-select-sm">
                                                <option value="present" <?php echo $r['status']==='present'?'selected':''; ?>>Present</option>
                                                <option value="absent" <?php echo $r['status']==='absent'?'selected':''; ?>>Absent</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4"><input type="text" name="note" class="form-control form-control-sm" value="<?php echo htmlspecialchars($r['note'] ?? ''); ?>" placeholder="Note"></div>
                                        <div class="col-md-3 d-flex gap-2">
                                            <button class="btn btn-sm btn-outline-primary" name="action" value="update" type="submit">Update</button>
                                            <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this record?');">Delete</button>
                                        </div>
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
          const action = fd.get('action');
          const row = form.closest('tr');
          if (action === 'delete') {
            if (row) row.remove();
          } else if (action === 'update' && row && json.data) {
            // Update status badge and note text
            const statusCell = row.children[2];
            const noteCell = row.children[3];
            const status = json.data.status;
            if (statusCell) {
              statusCell.innerHTML = `<span class="badge ${status==='present'?'bg-success':'bg-danger'}">${status}</span>`;
            }
            if (noteCell) { noteCell.textContent = json.data.note || ''; }
          }
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
