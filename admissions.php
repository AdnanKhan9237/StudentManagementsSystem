<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';

$auth = new Auth();
$session = Session::getInstance();
$auth->requireRole(['superadmin','accounts']);
$role = (string)$session->get('role');

$db = (new Database())->getConnection();

// Ensure required tables
$db->exec("CREATE TABLE IF NOT EXISTS academic_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
  capacity INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS timings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  day_of_week ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  INDEX (day_of_week), INDEX (is_available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  academic_session_id INT NOT NULL,
  timing_id INT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  INDEX (academic_session_id), INDEX (timing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS batch_admissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NOT NULL,
  student_id INT NOT NULL,
  status ENUM('active','withdrawn') NOT NULL DEFAULT 'active',
  admitted_at DATETIME NOT NULL,
  INDEX (batch_id), INDEX (student_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function csrfToken(){ if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf_token']; }
function verifyCsrf($t){ return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t); }

$errors = [];
$success = '';
$wantsJson = (
  stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
  || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
  || (($_POST['ajax'] ?? '') === '1')
);

// Lookup lists
$activeSessions = $db->query("SELECT id, name, capacity FROM academic_sessions WHERE status = 'active' ORDER BY start_date DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$batches = $db->query("SELECT b.id, b.name, a.name AS session_name, a.id AS session_id FROM batches b JOIN academic_sessions a ON a.id = b.academic_session_id WHERE a.status = 'active' ORDER BY a.start_date DESC, b.name ASC")->fetchAll(PDO::FETCH_ASSOC);
$students = $db->query("SELECT id, username FROM users WHERE role = 'student' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);

// Helper: count admissions in a session
function sessionAdmissionCount(PDO $db, int $sessionId): int {
  $stmt = $db->prepare('SELECT COUNT(*) FROM batch_admissions ba JOIN batches b ON b.id = ba.batch_id WHERE b.academic_session_id = ? AND ba.status = \"active\"');
  $stmt->execute([$sessionId]);
  return (int)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $token = $_POST['csrf_token'] ?? '';
  if (!verifyCsrf($token)) { $errors[] = 'Invalid CSRF token.'; }
  else {
    $payload = [];
    if ($action === 'admit') {
      $batch_id = (int)($_POST['batch_id'] ?? 0);
      $student_id = (int)($_POST['student_id'] ?? 0);
      if ($batch_id <= 0 || $student_id <= 0) { $errors[] = 'Batch and student are required.'; }
      else {
        // Resolve session and capacity
        $st = $db->prepare('SELECT a.id AS session_id, a.capacity AS capacity, a.status AS status FROM batches b JOIN academic_sessions a ON a.id = b.academic_session_id WHERE b.id = ?');
        $st->execute([$batch_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $errors[] = 'Invalid batch.'; }
        elseif ($row['status'] !== 'active') { $errors[] = 'Admissions only allowed into batches of active sessions.'; }
        else {
          $current = sessionAdmissionCount($db, (int)$row['session_id']);
          $capacity = (int)$row['capacity'];
          if ($capacity > 0 && $current >= $capacity) { $errors[] = 'Session capacity reached.'; }
          else {
            // Prevent duplicate active admission
            $dup = $db->prepare('SELECT COUNT(*) FROM batch_admissions WHERE batch_id = ? AND student_id = ? AND status = \"active\"');
            $dup->execute([$batch_id, $student_id]);
            if ((int)$dup->fetchColumn() > 0) { $errors[] = 'Student already admitted to this batch.'; }
            else {
              $stmt = $db->prepare('INSERT INTO batch_admissions (batch_id, student_id, status, admitted_at) VALUES (?, ?, ?, ?)');
              $ok = $stmt->execute([$batch_id, $student_id, 'active', date('Y-m-d H:i:s')]);
              if ($ok) { $success = 'Student admitted.'; $payload = ['id' => (int)$db->lastInsertId(), 'batch_id' => $batch_id, 'student_id' => $student_id, 'admitted_at' => date('Y-m-d H:i:s')]; }
              else { $errors[] = 'Failed to admit student.'; }
            }
          }
        }
      }
    } elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $status = $_POST['status'] ?? 'active';
      if ($id <= 0 || !in_array($status, ['active','withdrawn'], true)) { $errors[] = 'Invalid update request.'; }
      else {
        $stmt = $db->prepare('UPDATE batch_admissions SET status = ?, admitted_at = admitted_at WHERE id = ?');
        $ok = $stmt->execute([$status, $id]);
        if ($ok) { $success = 'Admission updated.'; $payload = ['id' => $id, 'status' => $status]; }
        else { $errors[] = 'Failed to update admission.'; }
      }
    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) { $errors[] = 'Invalid admission ID.'; }
      else {
        $stmt = $db->prepare('DELETE FROM batch_admissions WHERE id = ?');
        $ok = $stmt->execute([$id]);
        if ($ok) { $success = 'Admission deleted.'; $payload = ['id' => $id]; }
        else { $errors[] = 'Failed to delete admission.'; }
      }
    }
  }
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  if ($wantsJson) {
    header('Content-Type: application/json');
    echo json_encode([
      'success' => empty($errors),
      'message' => empty($errors) ? ($success ?: 'OK') : implode("\n", $errors),
      'csrf_token' => (string)$_SESSION['csrf_token'],
      'created' => ($action === 'admit' && empty($errors)) ? $payload : null,
      'updated' => ($action === 'update' && empty($errors)) ? $payload : null,
      'deleted' => ($action === 'delete' && empty($errors)) ? $payload : null,
    ]);
    exit();
  }
}

$admissions = $db->query('SELECT ba.id, ba.status, ba.admitted_at, b.name AS batch_name, a.name AS session_name, u.username AS student FROM batch_admissions ba JOIN batches b ON b.id = ba.batch_id JOIN academic_sessions a ON a.id = b.academic_session_id JOIN users u ON u.id = ba.student_id ORDER BY ba.admitted_at DESC')->fetchAll(PDO::FETCH_ASSOC);
$csrf = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admissions</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
<main class="container mt-4">
  <div class="d-flex justify-content-end mb-2">
    <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
  </div>
  <div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">Admissions</h1>
    <span class="ms-3 small text-muted">Enroll students to batches of active sessions</span>
  </div>

  <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php echo htmlspecialchars(implode('\n', $errors)); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">Admit Student</div>
    <div class="card-body">
      <form method="post" class="row g-3" id="admitForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <div class="col-md-5">
          <label class="form-label">Batch (active sessions)</label>
          <select class="form-select" name="batch_id" required>
            <option value="">Select batch</option>
            <?php foreach ($batches as $b): ?>
              <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['session_id'] . ' - ' . $b['session_name'] . ' / ' . $b['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label">Student</label>
          <select class="form-select" name="student_id" required>
            <option value="">Select student</option>
            <?php foreach ($students as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['username']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 align-self-end">
          <button type="submit" name="action" value="admit" class="btn btn-primary w-100">Admit</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Existing Admissions</div>
    <div class="card-body table-responsive">
      <table class="table table-striped table-hover">
        <thead><tr><th>Session</th><th>Batch</th><th>Student</th><th>Status</th><th>Admitted</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($admissions as $a): ?>
          <tr>
            <td><?php echo htmlspecialchars($a['session_name']); ?></td>
            <td><?php echo htmlspecialchars($a['batch_name']); ?></td>
            <td><?php echo htmlspecialchars($a['student']); ?></td>
            <td><?php echo $a['status']==='active' ? '<span class="badge bg-info">active</span>' : '<span class="badge bg-secondary">withdrawn</span>'; ?></td>
            <td><?php echo htmlspecialchars($a['admitted_at']); ?></td>
            <td>
              <form method="post" class="row g-2 align-items-center">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                <div class="col-md-4">
                  <select class="form-select form-select-sm" name="status">
                    <option value="active" <?php echo $a['status']==='active'?'selected':''; ?>>Active</option>
                    <option value="withdrawn" <?php echo $a['status']==='withdrawn'?'selected':''; ?>>Withdrawn</option>
                  </select>
                </div>
                <div class="col-md-8">
                  <button class="btn btn-sm btn-outline-primary" type="submit" name="action" value="update">Update</button>
                  <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="delete">Delete</button>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  function alertFor(target, type, text){ const el=document.createElement('div'); el.className=`alert alert-${type} mt-2`; el.textContent=text; target.prepend(el); setTimeout(()=>el.remove(),5000); }
  function setLoading(btn, on, idle){ if(!btn)return; btn.disabled=on; btn.innerHTML = on ? '<span class="spinner-border spinner-border-sm me-1"></span>Working…' : idle; }

  const admitForm = document.getElementById('admitForm');
  if (admitForm) {
    admitForm.addEventListener('submit', async function(e){
      e.preventDefault();
      if (!admitForm.checkValidity()) { admitForm.classList.add('was-validated'); alertFor(admitForm.parentElement, 'danger', 'Please select batch and student.'); return; }
      const btn = admitForm.querySelector('button[type="submit"]');
      setLoading(btn, true, 'Admit');
      const fd = new FormData(admitForm); fd.append('ajax','1');
      try {
        const res = await fetch('admissions.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        if (json.success) { alertFor(admitForm.parentElement, 'success', json.message || 'Student admitted.'); }
        else { alertFor(admitForm.parentElement, 'danger', json.message || 'Failed to admit.'); }
        const csrf = admitForm.querySelector('input[name="csrf_token"]'); if (csrf && json.csrf_token) csrf.value = json.csrf_token;
      } catch(e){ alertFor(admitForm.parentElement, 'danger', 'Network error.'); }
      finally { setLoading(btn, false, 'Admit'); }
    });
  }

  document.querySelectorAll('table tbody form').forEach(form => {
    form.addEventListener('submit', async function(e){
      e.preventDefault();
      const btn = form.querySelector('button[type="submit"]:focus') || form.querySelector('button[type="submit"]');
      const idle = btn ? btn.textContent.trim() : 'Submit';
      setLoading(btn, true, idle);
      const fd = new FormData(form); fd.append('ajax','1');
      try {
        const res = await fetch('admissions.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        const card = form.closest('.card');
        if (json.success) {
          alertFor(card, 'success', json.message || 'Saved.');
          const tr = form.closest('tr');
          if (json.updated && tr) {
            tr.querySelector('td:nth-child(4)').innerHTML = json.updated.status==='active' ? '<span class="badge bg-info">active</span>' : '<span class="badge bg-secondary">withdrawn</span>';
          }
          if (json.deleted && form.closest('tr')) { form.closest('tr').remove(); }
          form.querySelectorAll('input[name="csrf_token"]').forEach(inp => { if (json.csrf_token) inp.value = json.csrf_token; });
        } else {
          alertFor(card, 'danger', json.message || 'Failed.');
          form.querySelectorAll('input[name="csrf_token"]').forEach(inp => { if (json.csrf_token) inp.value = json.csrf_token; });
        }
      } catch(e){ alertFor(form.closest('.card'), 'danger', 'Network error.'); }
      finally { setLoading(btn, false, idle); }
    });
  });
});
</script>
</body>
</html>
