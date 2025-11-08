<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';

$auth = new Auth();
$session = Session::getInstance();
$auth->requireRole(['superadmin','accounts']);
$role = (string)$session->get('role');

$db = (new Database())->getConnection();

// Schema: academic_sessions
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

function csrfToken(){ if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf_token']; }
function verifyCsrf($t){ return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t); }

$errors = [];
$success = '';
$wantsJson = (
  stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
  || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
  || (($_POST['ajax'] ?? '') === '1')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $token = $_POST['csrf_token'] ?? '';
  if (!verifyCsrf($token)) { $errors[] = 'Invalid CSRF token.'; }
  else {
    $payload = [];
    if ($action === 'create') {
      $name = trim($_POST['name'] ?? '');
      $start = trim($_POST['start_date'] ?? '');
      $end = trim($_POST['end_date'] ?? '');
      $status = $_POST['status'] ?? 'inactive';
      $capacity = (int)($_POST['capacity'] ?? 0);
      if ($name === '' || $start === '' || $end === '' || $start > $end || !in_array($status, ['active','inactive'], true) || $capacity < 0) {
        $errors[] = 'All fields required and dates/status/capacity must be valid.';
      } else {
        $stmt = $db->prepare('INSERT INTO academic_sessions (name, start_date, end_date, status, capacity, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $ok = $stmt->execute([$name, $start, $end, $status, $capacity, date('Y-m-d H:i:s')]);
        if ($ok) { $success = 'Session created.'; $payload = ['id' => (int)$db->lastInsertId(), 'name' => $name, 'start_date' => $start, 'end_date' => $end, 'status' => $status, 'capacity' => $capacity]; }
        else { $errors[] = 'Failed to create session.'; }
      }
    } elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      $start = trim($_POST['start_date'] ?? '');
      $end = trim($_POST['end_date'] ?? '');
      $status = $_POST['status'] ?? 'inactive';
      $capacity = (int)($_POST['capacity'] ?? 0);
      if ($id <= 0 || $name === '' || $start === '' || $end === '' || $start > $end || !in_array($status, ['active','inactive'], true) || $capacity < 0) {
        $errors[] = 'All fields required and dates/status/capacity must be valid.';
      } else {
        $stmt = $db->prepare('UPDATE academic_sessions SET name = ?, start_date = ?, end_date = ?, status = ?, capacity = ?, updated_at = ? WHERE id = ?');
        $ok = $stmt->execute([$name, $start, $end, $status, $capacity, date('Y-m-d H:i:s'), $id]);
        if ($ok) { $success = 'Session updated.'; $payload = ['id' => $id, 'name' => $name, 'start_date' => $start, 'end_date' => $end, 'status' => $status, 'capacity' => $capacity]; }
        else { $errors[] = 'Failed to update session.'; }
      }
    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) { $errors[] = 'Invalid session ID.'; }
      else {
        // Prevent deleting if referenced by batches
        $cnt = (int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "batches"')->fetchColumn();
        if ($cnt > 0) {
          $hasRef = $db->prepare('SELECT COUNT(*) FROM batches WHERE academic_session_id = ?');
          $hasRef->execute([$id]);
          if ((int)$hasRef->fetchColumn() > 0) { $errors[] = 'Cannot delete: referenced by batches.'; }
        }
        if (empty($errors)) {
          $stmt = $db->prepare('DELETE FROM academic_sessions WHERE id = ?');
          $ok = $stmt->execute([$id]);
          if ($ok) { $success = 'Session deleted.'; $payload = ['id' => $id]; }
          else { $errors[] = 'Failed to delete session.'; }
        }
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
      'created' => ($action === 'create' && empty($errors)) ? $payload : null,
      'updated' => ($action === 'update' && empty($errors)) ? $payload : null,
      'deleted' => ($action === 'delete' && empty($errors)) ? $payload : null,
    ]);
    exit();
  }
}

$rows = $db->query('SELECT * FROM academic_sessions ORDER BY start_date DESC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
$csrf = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Academic Sessions</title>
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
    <h1 class="h4 mb-0">Academic Sessions</h1>
    <span class="ms-3 small text-muted">Manage session dates, status, and capacity</span>
  </div>

  <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php echo htmlspecialchars(implode('\n', $errors)); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">Create Session</div>
    <div class="card-body">
      <form method="post" class="row g-3" id="createSessionForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <div class="col-md-4">
          <label class="form-label">Name</label>
          <input type="text" class="form-control" name="name" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Start Date</label>
          <input type="date" class="form-control" name="start_date" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">End Date</label>
          <input type="date" class="form-control" name="end_date" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <option value="inactive">Inactive</option>
            <option value="active">Active</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Capacity</label>
          <input type="number" min="0" class="form-control" name="capacity" value="0" required>
        </div>
        <div class="col-12">
          <button type="submit" name="action" value="create" class="btn btn-primary">Create</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Existing Sessions</div>
    <div class="card-body table-responsive">
      <table class="table table-striped table-hover">
        <thead><tr><th>Name</th><th>Start</th><th>End</th><th>Status</th><th>Capacity</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['name']); ?></td>
            <td><?php echo htmlspecialchars($r['start_date']); ?></td>
            <td><?php echo htmlspecialchars($r['end_date']); ?></td>
            <td><?php if ($r['status'] === 'active'): ?><span class="badge bg-success">active</span><?php else: ?><span class="badge bg-secondary">inactive</span><?php endif; ?></td>
            <td><?php echo (int)$r['capacity']; ?></td>
            <td>
              <form method="post" class="row g-2 align-items-center">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <div class="col-md-3"><input type="text" class="form-control form-control-sm" name="name" value="<?php echo htmlspecialchars($r['name']); ?>" required></div>
                <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo htmlspecialchars($r['start_date']); ?>" required></div>
                <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo htmlspecialchars($r['end_date']); ?>" required></div>
                <div class="col-md-2">
                  <select class="form-select form-select-sm" name="status">
                    <option value="inactive" <?php echo $r['status']==='inactive'?'selected':''; ?>>Inactive</option>
                    <option value="active" <?php echo $r['status']==='active'?'selected':''; ?>>Active</option>
                  </select>
                </div>
                <div class="col-md-1"><input type="number" min="0" class="form-control form-control-sm" name="capacity" value="<?php echo (int)$r['capacity']; ?>" required></div>
                <div class="col-md-2">
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

  const createForm = document.getElementById('createSessionForm');
  if (createForm) {
    createForm.addEventListener('submit', async function(e){
      e.preventDefault();
      if (!createForm.checkValidity()) { createForm.classList.add('was-validated'); alertFor(createForm.parentElement, 'danger', 'Please complete required fields.'); return; }
      const btn = createForm.querySelector('button[type="submit"]');
      setLoading(btn, true, 'Create');
      const fd = new FormData(createForm); fd.append('ajax','1');
      try {
        const res = await fetch('academic_sessions.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        if (json.success) { alertFor(createForm.parentElement, 'success', json.message || 'Session created.'); }
        else { alertFor(createForm.parentElement, 'danger', json.message || 'Failed to create.'); }
        const csrf = createForm.querySelector('input[name="csrf_token"]'); if (csrf && json.csrf_token) csrf.value = json.csrf_token;
      } catch(e){ alertFor(createForm.parentElement, 'danger', 'Network error.'); }
      finally { setLoading(btn, false, 'Create'); }
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
        const res = await fetch('academic_sessions.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        const card = form.closest('.card');
        if (json.success) {
          alertFor(card, 'success', json.message || 'Saved.');
          const tr = form.closest('tr');
          if (json.updated && tr) {
            tr.querySelector('td:nth-child(1)').textContent = json.updated.name;
            tr.querySelector('td:nth-child(2)').textContent = json.updated.start_date;
            tr.querySelector('td:nth-child(3)').textContent = json.updated.end_date;
            tr.querySelector('td:nth-child(4)').innerHTML = json.updated.status==='active' ? '<span class="badge bg-success">active</span>' : '<span class="badge bg-secondary">inactive</span>';
            tr.querySelector('td:nth-child(5)').textContent = json.updated.capacity;
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
