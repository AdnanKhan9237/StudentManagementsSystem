<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';

$auth = new Auth();
$session = Session::getInstance();
$auth->requireRole(['superadmin','accounts']);
$role = (string)$session->get('role');

$db = (new Database())->getConnection();

// Ensure dependent tables exist
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

// Batches table
$db->exec("CREATE TABLE IF NOT EXISTS batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  academic_session_id INT NOT NULL,
  timing_id INT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  INDEX (academic_session_id), INDEX (timing_id)
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
$activeSessions = $db->query("SELECT id, name FROM academic_sessions WHERE status = 'active' ORDER BY start_date DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$availableTimings = $db->query("SELECT id, day_of_week, start_time, end_time FROM timings WHERE is_available = 1 ORDER BY FIELD(day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat','Sun'), start_time ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $token = $_POST['csrf_token'] ?? '';
  if (!verifyCsrf($token)) { $errors[] = 'Invalid CSRF token.'; }
  else {
    $payload = [];
    if ($action === 'create') {
      $name = trim($_POST['name'] ?? '');
      $session_id = (int)($_POST['academic_session_id'] ?? 0);
      $timing_id = (int)($_POST['timing_id'] ?? 0);
      if ($name === '' || $session_id <= 0 || $timing_id <= 0) { $errors[] = 'Name, session, and timing are required.'; }
      else {
        // Validate session active
        $st = $db->prepare("SELECT status FROM academic_sessions WHERE id = ?"); $st->execute([$session_id]); $status = (string)$st->fetchColumn();
        if ($status !== 'active') { $errors[] = 'Selected session must be active.'; }
        // Validate timing availability
        $tt = $db->prepare("SELECT is_available FROM timings WHERE id = ?"); $tt->execute([$timing_id]); $avail = (int)$tt->fetchColumn();
        if (empty($errors) && $avail !== 1) { $errors[] = 'Selected timing is not available.'; }
        if (empty($errors)) {
          $stmt = $db->prepare('INSERT INTO batches (name, academic_session_id, timing_id, created_at) VALUES (?, ?, ?, ?)');
          $ok = $stmt->execute([$name, $session_id, $timing_id, date('Y-m-d H:i:s')]);
          if ($ok) { $success = 'Batch created.'; $payload = ['id' => (int)$db->lastInsertId(), 'name' => $name, 'academic_session_id' => $session_id, 'timing_id' => $timing_id]; }
          else { $errors[] = 'Failed to create batch.'; }
        }
      }
    } elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      $session_id = (int)($_POST['academic_session_id'] ?? 0);
      $timing_id = (int)($_POST['timing_id'] ?? 0);
      if ($id <= 0 || $name === '' || $session_id <= 0 || $timing_id <= 0) { $errors[] = 'All fields are required.'; }
      else {
        $st = $db->prepare("SELECT status FROM academic_sessions WHERE id = ?"); $st->execute([$session_id]); $status = (string)$st->fetchColumn();
        if ($status !== 'active') { $errors[] = 'Selected session must be active.'; }
        $tt = $db->prepare("SELECT is_available FROM timings WHERE id = ?"); $tt->execute([$timing_id]); $avail = (int)$tt->fetchColumn();
        if (empty($errors) && $avail !== 1) { $errors[] = 'Selected timing is not available.'; }
        if (empty($errors)) {
          $stmt = $db->prepare('UPDATE batches SET name = ?, academic_session_id = ?, timing_id = ?, updated_at = ? WHERE id = ?');
          $ok = $stmt->execute([$name, $session_id, $timing_id, date('Y-m-d H:i:s'), $id]);
          if ($ok) { $success = 'Batch updated.'; $payload = ['id' => $id, 'name' => $name, 'academic_session_id' => $session_id, 'timing_id' => $timing_id]; }
          else { $errors[] = 'Failed to update batch.'; }
        }
      }
    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) { $errors[] = 'Invalid batch ID.'; }
      else {
        // Prevent delete if admissions exist
        $existsAdmissions = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_admissions'")->fetchColumn();
        if ((int)$existsAdmissions > 0) {
          $hasAdm = $db->prepare('SELECT COUNT(*) FROM batch_admissions WHERE batch_id = ?');
          $hasAdm->execute([$id]);
          if ((int)$hasAdm->fetchColumn() > 0) { $errors[] = 'Cannot delete: admissions exist for this batch.'; }
        }
        if (empty($errors)) {
          $stmt = $db->prepare('DELETE FROM batches WHERE id = ?');
          $ok = $stmt->execute([$id]);
          if ($ok) { $success = 'Batch deleted.'; $payload = ['id' => $id]; }
          else { $errors[] = 'Failed to delete batch.'; }
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

// Fetch batches with joins to show names
$rows = $db->query('SELECT b.id, b.name, a.name AS session_name, a.status, t.day_of_week, t.start_time, t.end_time FROM batches b JOIN academic_sessions a ON a.id = b.academic_session_id JOIN timings t ON t.id = b.timing_id ORDER BY a.start_date DESC, b.name ASC')->fetchAll(PDO::FETCH_ASSOC);
$csrf = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Batches</title>
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
    <h1 class="h4 mb-0">Batches</h1>
    <span class="ms-3 small text-muted">Associate active sessions with available timings</span>
  </div>

  <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php echo htmlspecialchars(implode('\n', $errors)); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">Create Batch</div>
    <div class="card-body">
      <form method="post" class="row g-3" id="createBatchForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <div class="col-md-4">
          <label class="form-label">Name</label>
          <input type="text" class="form-control" name="name" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Academic Session</label>
          <select class="form-select" name="academic_session_id" required>
            <option value="">Select active session</option>
            <?php foreach ($activeSessions as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Timing Slot</label>
          <select class="form-select" name="timing_id" required>
            <option value="">Select available timing</option>
            <?php foreach ($availableTimings as $t): ?>
              <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['day_of_week'] . ' ' . substr($t['start_time'],0,5) . '-' . substr($t['end_time'],0,5)); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <button type="submit" name="action" value="create" class="btn btn-primary">Create</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Existing Batches</div>
    <div class="card-body table-responsive">
      <table class="table table-striped table-hover">
        <thead><tr><th>Name</th><th>Session</th><th>Timing</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['name']); ?></td>
            <td><?php echo htmlspecialchars($r['session_name']); ?></td>
            <td><?php echo htmlspecialchars($r['day_of_week'] . ' ' . substr($r['start_time'],0,5) . '-' . substr($r['end_time'],0,5)); ?></td>
            <td><?php echo $r['status']==='active' ? '<span class="badge bg-success">active</span>' : '<span class="badge bg-secondary">inactive</span>'; ?></td>
            <td>
              <form method="post" class="row g-2 align-items-center">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <div class="col-md-3"><input type="text" class="form-control form-control-sm" name="name" value="<?php echo htmlspecialchars($r['name']); ?>" required></div>
                <div class="col-md-4">
                  <select class="form-select form-select-sm" name="academic_session_id" required>
                    <?php foreach ($activeSessions as $s): ?>
                      <option value="<?php echo (int)$s['id']; ?>" <?php echo $r['session_name']===$s['name']?'selected':''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <select class="form-select form-select-sm" name="timing_id" required>
                    <?php foreach ($availableTimings as $t): ?>
                      <option value="<?php echo (int)$t['id']; ?>" <?php echo (strpos($r['day_of_week'].' '.substr($r['start_time'],0,5).'-'.substr($r['end_time'],0,5), $t['day_of_week'])===0 && substr($r['start_time'],0,5)===substr($t['start_time'],0,5)) ? 'selected':''; ?>><?php echo htmlspecialchars($t['day_of_week'] . ' ' . substr($t['start_time'],0,5) . '-' . substr($t['end_time'],0,5)); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
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

  const createForm = document.getElementById('createBatchForm');
  if (createForm) {
    createForm.addEventListener('submit', async function(e){
      e.preventDefault();
      if (!createForm.checkValidity()) { createForm.classList.add('was-validated'); alertFor(createForm.parentElement, 'danger', 'Please complete required fields.'); return; }
      const btn = createForm.querySelector('button[type="submit"]');
      setLoading(btn, true, 'Create');
      const fd = new FormData(createForm); fd.append('ajax','1');
      try {
        const res = await fetch('batches.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        if (json.success) { alertFor(createForm.parentElement, 'success', json.message || 'Batch created.'); }
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
        const res = await fetch('batches.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        const card = form.closest('.card');
        if (json.success) {
          alertFor(card, 'success', json.message || 'Saved.');
          const tr = form.closest('tr');
          if (json.updated && tr) {
            tr.querySelector('td:nth-child(1)').textContent = json.updated.name;
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
