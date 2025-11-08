<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole(['superadmin','accounts']);

$db = (new Database())->getConnection();
// Schema: course_enrollments
$db->exec("CREATE TABLE IF NOT EXISTS course_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_session_id INT NOT NULL,
    student_id INT NOT NULL,
    status ENUM('active','completed','dropped') NOT NULL DEFAULT 'active',
    enrolled_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    UNIQUE KEY uniq_course_session_student (course_session_id, student_id),
    INDEX (course_session_id), INDEX (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Helper: CSRF
function csrfToken() { if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf_token']; }
function verifyCsrf($t) { return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t); }

$errors = [];
$success = '';

// Data sources
$sessions = $db->query('SELECT cs.id, c.name AS course_name, cs.year, cs.term FROM course_sessions cs JOIN courses c ON c.id = cs.course_id ORDER BY cs.year DESC, cs.term ASC, c.name ASC')->fetchAll(PDO::FETCH_ASSOC);
$students = $db->query("SELECT id, username FROM users WHERE role = 'student' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = (
        stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || (($_POST['ajax'] ?? '') === '1')
    );
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) { $errors[] = 'Invalid CSRF token.'; }
    else {
        if ($action === 'enroll') {
            $course_session_id = (int)($_POST['course_session_id'] ?? 0);
            $student_id = (int)($_POST['student_id'] ?? 0);
            if ($course_session_id <= 0 || $student_id <= 0) { $errors[] = 'Select session and student.'; }
            else {
                $stmt = $db->prepare('INSERT INTO course_enrollments (course_session_id, student_id, status, enrolled_at) VALUES (?, ?, ?, ?)');
                $ok = $stmt->execute([$course_session_id, $student_id, 'active', date('Y-m-d H:i:s')]);
                if ($ok) { 
                    $success = 'Student enrolled.'; 
                    $newId = (int)$db->lastInsertId();
                    $created = [
                        'id' => $newId,
                        'course_session_id' => $course_session_id,
                        'student_id' => $student_id,
                        'status' => 'active',
                        'enrolled_at' => date('Y-m-d H:i:s'),
                    ];
                } else { $errors[] = 'Failed to enroll student (duplicate?).'; }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            if ($id <= 0 || !in_array($status, ['active','completed','dropped'], true)) { $errors[] = 'Invalid input.'; }
            else {
                $completed_at = ($status === 'completed') ? date('Y-m-d H:i:s') : null;
                $stmt = $db->prepare('UPDATE course_enrollments SET status = ?, completed_at = ? WHERE id = ?');
                $ok = $stmt->execute([$status, $completed_at, $id]);
                if ($ok) { $success = 'Enrollment updated.'; $updated = ['id' => $id, 'status' => $status]; } else { $errors[] = 'Failed to update enrollment.'; }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $errors[] = 'Invalid enrollment ID.'; }
            else { $ok = $db->prepare('DELETE FROM course_enrollments WHERE id = ?')->execute([$id]); if ($ok) { $success = 'Enrollment deleted.'; $deleted = ['id' => $id]; } else { $errors[] = 'Failed to delete enrollment.'; } }
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => empty($errors),
            'message' => empty($errors) ? $success : implode('\n', $errors),
            'csrf_token' => $_SESSION['csrf_token'],
            'created' => $created ?? null,
            'updated' => $updated ?? null,
            'deleted' => $deleted ?? null,
        ]);
        exit();
    }
}

// Load enrollments with names
$enrollments = $db->query('SELECT e.*, c.name AS course_name, cs.year, cs.term, u.username AS student_name FROM course_enrollments e JOIN course_sessions cs ON cs.id = e.course_session_id JOIN courses c ON c.id = cs.course_id JOIN users u ON u.id = e.student_id ORDER BY e.enrolled_at DESC')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enrollments</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4">Enrollments</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) { echo '<div>'.htmlspecialchars($e).'</div>'; } ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">Enroll Student</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Course Session</label>
                        <select name="course_session_id" class="form-select" required>
                            <option value="">Select session</option>
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['course_name']); ?> (<?php echo (int)$s['year']; ?>-<?php echo htmlspecialchars($s['term']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Student</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">Select student</option>
                            <?php foreach ($students as $stu): ?>
                                <option value="<?php echo (int)$stu['id']; ?>"><?php echo htmlspecialchars($stu['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary" name="action" value="enroll" type="submit">Enroll</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">All Enrollments</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead><tr><th>Course</th><th>Session</th><th>Student</th><th>Status</th><th>Enrolled</th><th style="width: 280px;">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($enrollments as $e): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($e['course_name']); ?></td>
                            <td><?php echo (int)$e['year']; ?>-<?php echo htmlspecialchars($e['term']); ?></td>
                            <td><?php echo htmlspecialchars($e['student_name']); ?></td>
                            <td><span class="badge bg-info"><?php echo htmlspecialchars($e['status']); ?></span></td>
                            <td><?php echo htmlspecialchars($e['enrolled_at']); ?></td>
                            <td>
                                <form method="post" class="row g-2 align-items-center">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
                                    <div class="col-md-6">
                                        <select name="status" class="form-select form-select-sm">
                                            <?php foreach (['active','completed','dropped'] as $st): ?>
                                                <option value="<?php echo $st; ?>" <?php echo $st===$e['status']?'selected':''; ?>><?php echo ucfirst($st); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary" name="action" value="update" type="submit">Update</button>
                                        <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this enrollment?');">Delete</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($enrollments)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No enrollments yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// AJAX handlers for enrollment create/update/delete with inline alerts and loading
document.addEventListener('DOMContentLoaded', function(){
  const enrollForm = document.querySelector('.card.mb-4 form[method="post"]');
  const listCard = document.querySelector('.card:last-of-type');
  const tableBody = document.querySelector('table tbody');

  function createAlert(target, type, text) {
    const el = document.createElement('div');
    el.className = `alert alert-${type} mt-2`;
    el.textContent = text;
    target.appendChild(el);
    setTimeout(()=>{ el.remove(); }, 5000);
  }

  function setButtonLoading(btn, loading, textWhenIdle) {
    if (!btn) return;
    btn.disabled = loading;
    btn.innerHTML = loading ? '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Working…' : textWhenIdle;
  }

  if (enrollForm) {
    enrollForm.addEventListener('submit', async function(e){
      e.preventDefault();
      if (!enrollForm.checkValidity()) { enrollForm.classList.add('was-validated'); createAlert(enrollForm.parentElement, 'danger', 'Please select session and student.'); return; }
      const submitBtn = enrollForm.querySelector('button[type="submit"]');
      setButtonLoading(submitBtn, true, 'Enroll');
      const fd = new FormData(enrollForm); fd.append('ajax', '1'); fd.append('action', 'enroll');
      try {
        const res = await fetch('enrollments.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
        const json = await res.json();
        if (json && json.success) {
          createAlert(enrollForm.parentElement, 'success', json.message || 'Student enrolled.');
          // Update CSRF token in form
          const csrfInput = enrollForm.querySelector('input[name="csrf_token"]');
          if (csrfInput && json.csrf_token) csrfInput.value = json.csrf_token;
          // Optimistically append row
          if (tableBody && json.created) {
            const sessionSel = enrollForm.querySelector('select[name="course_session_id"]');
            const studentSel = enrollForm.querySelector('select[name="student_id"]');
            const sessionText = sessionSel ? sessionSel.options[sessionSel.selectedIndex].textContent : 'Session';
            const studentText = studentSel ? studentSel.options[studentSel.selectedIndex].textContent : 'Student';
            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td>${sessionText.split('(')[0].trim()}</td>
              <td>${(sessionText.match(/\((.*?)\)/)||['',''])[1]||''}</td>
              <td>${studentText}</td>
              <td><span class="badge bg-info">active</span></td>
              <td>${json.created.enrolled_at}</td>
              <td>
                <form method="post" class="row g-2 align-items-center">
                  <input type="hidden" name="csrf_token" value="${json.csrf_token}">
                  <input type="hidden" name="id" value="${json.created.id}">
                  <div class="col-md-6">
                    <select name="status" class="form-select form-select-sm">
                      <option value="active" selected>Active</option>
                      <option value="completed">Completed</option>
                      <option value="dropped">Dropped</option>
                    </select>
                  </div>
                  <div class="col-md-6 d-flex gap-2">
                    <button class="btn btn-sm btn-outline-primary" name="action" value="update" type="submit">Update</button>
                    <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit">Delete</button>
                  </div>
                </form>
              </td>`;
            tableBody.prepend(tr);
          }
        } else {
          createAlert(enrollForm.parentElement, 'danger', (json && json.message) || 'Failed to enroll.');
          const csrfInput = enrollForm.querySelector('input[name="csrf_token"]');
          if (csrfInput && json && json.csrf_token) csrfInput.value = json.csrf_token;
        }
      } catch (err) {
        createAlert(enrollForm.parentElement, 'danger', 'Network error. Please try again.');
      } finally {
        setButtonLoading(submitBtn, false, 'Enroll');
      }
    });
  }

  // Delegate submit for inline update/delete forms
  if (listCard) {
    listCard.addEventListener('submit', async function(e){
      const form = e.target.closest('form');
      if (!form) return;
      e.preventDefault();
      const actionBtn = form.querySelector('button[name="action"][type="submit"]:focus') || form.querySelector('button[name="action"][type="submit"]');
      const action = actionBtn ? actionBtn.value : (form.querySelector('button[name="action"]').value);
      const idleText = action === 'update' ? 'Update' : 'Delete';
      setButtonLoading(actionBtn, true, idleText);
      const fd = new FormData(form);
      fd.append('ajax', '1');
      try {
        const res = await fetch('enrollments.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
        const json = await res.json();
        if (json && json.success) {
          createAlert(listCard.querySelector('.card-body'), 'success', json.message || 'Saved.');
          if (json.updated && json.updated.id) {
            const tr = form.closest('tr');
            const badge = tr.querySelector('td:nth-child(4) .badge');
            if (badge) { badge.textContent = json.updated.status; }
          }
          if (json.deleted && json.deleted.id) {
            const tr = form.closest('tr');
            tr.remove();
          }
          const csrfInput = form.querySelector('input[name="csrf_token"]');
          if (csrfInput && json.csrf_token) csrfInput.value = json.csrf_token;
        } else {
          createAlert(listCard.querySelector('.card-body'), 'danger', (json && json.message) || 'Failed.');
          const csrfInput = form.querySelector('input[name="csrf_token"]');
          if (csrfInput && json && json.csrf_token) csrfInput.value = json.csrf_token;
        }
      } catch (err) {
        createAlert(listCard.querySelector('.card-body'), 'danger', 'Network error. Please try again.');
      } finally {
        setButtonLoading(actionBtn, false, idleText);
      }
    });
  }
});
</script>
</body>
</html>
