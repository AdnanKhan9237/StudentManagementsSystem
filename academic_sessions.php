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
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/design-system.css" rel="stylesheet">
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
          <tr data-id="<?php echo (int)$r['id']; ?>">
            <td class="cell-name"><?php echo htmlspecialchars($r['name']); ?></td>
            <td class="cell-start"><?php echo htmlspecialchars($r['start_date']); ?></td>
            <td class="cell-end"><?php echo htmlspecialchars($r['end_date']); ?></td>
            <td class="cell-status"><?php if ($r['status'] === 'active'): ?><span class="badge bg-success">active</span><?php else: ?><span class="badge bg-secondary">inactive</span><?php endif; ?></td>
            <td class="cell-capacity"><?php echo (int)$r['capacity']; ?></td>
            <td>
              <button type="button"
                class="btn btn-sm btn-outline-primary open-update-modal"
                data-id="<?php echo (int)$r['id']; ?>"
                data-name="<?php echo htmlspecialchars($r['name']); ?>"
                data-start="<?php echo htmlspecialchars($r['start_date']); ?>"
                data-end="<?php echo htmlspecialchars($r['end_date']); ?>"
                data-status="<?php echo htmlspecialchars($r['status']); ?>"
                data-capacity="<?php echo (int)$r['capacity']; ?>">
                Update
              </button>
              <button type="button"
                class="btn btn-sm btn-outline-danger open-delete-modal"
                data-id="<?php echo (int)$r['id']; ?>">
                Delete
              </button>
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
  function registerRowForm(form){
    if (!form) return;
    form.addEventListener('submit', async function(e){
      e.preventDefault();
      const btn = form.querySelector('button[type="submit"]:focus') || form.querySelector('button[type="submit"]');
      const idle = btn ? btn.textContent.trim() : 'Submit';
      setLoading(btn, true, idle);
      const fd = new FormData(form); fd.append('ajax','1');
      // Include clicked button action in AJAX payload (update/delete)
      const submitter = e.submitter || btn;
      if (submitter && submitter.name) {
        fd.append(submitter.name, submitter.value);
      } else if (btn) {
        fd.append('action', btn.value || 'update');
      }
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
  }

  const createForm = document.getElementById('createSessionForm');
  if (createForm) {
    createForm.addEventListener('submit', async function(e){
      e.preventDefault();
      if (!createForm.checkValidity()) { createForm.classList.add('was-validated'); alertFor(createForm.parentElement, 'danger', 'Please complete required fields.'); return; }
      const btn = createForm.querySelector('button[type="submit"]');
      setLoading(btn, true, 'Create');
      const fd = new FormData(createForm); fd.append('ajax','1');
      // Ensure server receives the intended action when using AJAX
      const submitter = e.submitter || btn;
      if (submitter && submitter.name) {
        fd.append(submitter.name, submitter.value);
      } else {
        fd.append('action', 'create');
      }
      try {
        const res = await fetch('academic_sessions.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        if (json.success) {
          alertFor(createForm.parentElement, 'success', json.message || 'Session created.');
          // If server returned created payload, append a new row to the table
          if (json.created) {
            const tbody = document.querySelector('table tbody');
            if (tbody) {
              const c = json.created;
              const csrf = json.csrf_token || (createForm.querySelector('input[name="csrf_token"]')?.value || '');
              const tr = document.createElement('tr');
              tr.innerHTML = `
                <td>${c.name}</td>
                <td>${c.start_date}</td>
                <td>${c.end_date}</td>
                <td>${c.status==='active' ? '<span class="badge bg-success">active</span>' : '<span class="badge bg-secondary">inactive</span>'}</td>
                <td>${c.capacity}</td>
                <td>
                  <form method="post" class="row g-2 align-items-center">
                    <input type="hidden" name="csrf_token" value="${csrf}">
                    <input type="hidden" name="id" value="${c.id}">
                    <div class="col-md-3"><input type="text" class="form-control form-control-sm" name="name" value="${c.name}" required></div>
                    <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="start_date" value="${c.start_date}" required></div>
                    <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="end_date" value="${c.end_date}" required></div>
                    <div class="col-md-2">
                      <select class="form-select form-select-sm" name="status">
                        <option value="inactive" ${c.status==='inactive'?'selected':''}>Inactive</option>
                        <option value="active" ${c.status==='active'?'selected':''}>Active</option>
                      </select>
                    </div>
                    <div class="col-md-1"><input type="number" min="0" class="form-control form-control-sm" name="capacity" value="${c.capacity}" required></div>
                    <div class="col-md-2">
                      <button class="btn btn-sm btn-outline-primary" type="submit" name="action" value="update">Update</button>
                      <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="delete">Delete</button>
                    </div>
                  </form>
                </td>
              `;
              tbody.prepend(tr);
              // Register AJAX handler for the new row's form
              const newForm = tr.querySelector('form');
              registerRowForm(newForm);
            }
          }
          // Optionally clear the form
          createForm.reset();
        }
        else { alertFor(createForm.parentElement, 'danger', json.message || 'Failed to create.'); }
        const csrf = createForm.querySelector('input[name="csrf_token"]'); if (csrf && json.csrf_token) csrf.value = json.csrf_token;
      } catch(e){ alertFor(createForm.parentElement, 'danger', 'Network error.'); }
      finally { setLoading(btn, false, 'Create'); }
    });
  }

  document.querySelectorAll('table tbody form').forEach(registerRowForm);

  // Modal elements
  const updateModalEl = document.getElementById('updateAcademicSessionModal');
  const deleteModalEl = document.getElementById('deleteAcademicSessionModal');
  const updateModal = updateModalEl ? new bootstrap.Modal(updateModalEl) : null;
  const deleteModal = deleteModalEl ? new bootstrap.Modal(deleteModalEl) : null;
  const updateForm = document.getElementById('updateAcademicSessionModalForm');
  const deleteForm = document.getElementById('deleteAcademicSessionModalForm');

  // Open Update Modal
  document.querySelectorAll('.open-update-modal').forEach((btn)=>{
    btn.addEventListener('click', ()=>{
      if (!updateModal || !updateForm) return;
      updateForm.querySelector('input[name="id"]').value = btn.dataset.id || '';
      updateForm.querySelector('input[name="name"]').value = btn.dataset.name || '';
      updateForm.querySelector('input[name="start_date"]').value = btn.dataset.start || '';
      updateForm.querySelector('input[name="end_date"]').value = btn.dataset.end || '';
      const statusSel = updateForm.querySelector('select[name="status"]');
      if (statusSel) statusSel.value = (btn.dataset.status || 'inactive');
      updateForm.querySelector('input[name="capacity"]').value = btn.dataset.capacity || '0';
      updateModal.show();
    });
  });

  // Open Delete Modal
  document.querySelectorAll('.open-delete-modal').forEach((btn)=>{
    btn.addEventListener('click', ()=>{
      if (!deleteModal || !deleteForm) return;
      deleteForm.querySelector('input[name="id"]').value = btn.dataset.id || '';
      deleteModal.show();
    });
  });

  // Submit Update Modal
  if (updateForm) {
    updateForm.addEventListener('submit', async function(e){
      e.preventDefault();
      const submitBtn = updateForm.querySelector('button[type="submit"]');
      setLoading(submitBtn, true, 'Save');
      const fd = new FormData(updateForm);
      fd.append('ajax','1');
      fd.append('action','update');
      try {
        const res = await fetch('academic_sessions.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        if (json.success) {
          alertFor(updateForm, 'success', json.message || 'Session updated.');
          const id = updateForm.querySelector('input[name="id"]').value;
          const tr = document.querySelector(`tr[data-id="${id}"]`);
          if (tr && json.updated) {
            tr.querySelector('.cell-name').textContent = json.updated.name;
            tr.querySelector('.cell-start').textContent = json.updated.start_date;
            tr.querySelector('.cell-end').textContent = json.updated.end_date;
            tr.querySelector('.cell-status').innerHTML = json.updated.status==='active' ? '<span class="badge bg-success">active</span>' : '<span class="badge bg-secondary">inactive</span>';
            tr.querySelector('.cell-capacity').textContent = json.updated.capacity;
            // Update button data attributes to reflect new values
            const ub = tr.querySelector('.open-update-modal');
            if (ub) {
              ub.dataset.name = json.updated.name;
              ub.dataset.start = json.updated.start_date;
              ub.dataset.end = json.updated.end_date;
              ub.dataset.status = json.updated.status;
              ub.dataset.capacity = json.updated.capacity;
            }
          }
          updateModal && updateModal.hide();
        } else {
          alertFor(updateForm, 'danger', json.message || 'Failed to update.');
        }
        // Refresh CSRF tokens
        const inputs = document.querySelectorAll('input[name="csrf_token"]');
        inputs.forEach(inp => { if (json.csrf_token) inp.value = json.csrf_token; });
      } catch(err) {
        alertFor(updateForm, 'danger', 'Network error.');
      } finally {
        setLoading(submitBtn, false, 'Save');
      }
    });
  }

  // Submit Delete Modal
  if (deleteForm) {
    deleteForm.addEventListener('submit', async function(e){
      e.preventDefault();
      const submitBtn = deleteForm.querySelector('button[type="submit"]');
      setLoading(submitBtn, true, 'Delete');
      const fd = new FormData(deleteForm);
      fd.append('ajax','1');
      fd.append('action','delete');
      try {
        const res = await fetch('academic_sessions.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        if (json.success) {
          alertFor(deleteForm, 'success', json.message || 'Session deleted.');
          const id = deleteForm.querySelector('input[name="id"]').value;
          const tr = document.querySelector(`tr[data-id="${id}"]`);
          if (tr) tr.remove();
          deleteModal && deleteModal.hide();
        } else {
          alertFor(deleteForm, 'danger', json.message || 'Failed to delete.');
        }
        const inputs = document.querySelectorAll('input[name="csrf_token"]');
        inputs.forEach(inp => { if (json.csrf_token) inp.value = json.csrf_token; });
      } catch(err) {
        alertFor(deleteForm, 'danger', 'Network error.');
      } finally {
        setLoading(submitBtn, false, 'Delete');
      }
    });
  }
});
</script>
</body>

<!-- Update Academic Session Modal -->
<div class="modal fade" id="updateAcademicSessionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Update Session</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="updateAcademicSessionModalForm" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="id" value="">
          <div class="mb-2">
            <label class="form-label">Name</label>
            <input type="text" class="form-control" name="name" required>
          </div>
          <div class="row g-2">
            <div class="col">
              <label class="form-label">Start Date</label>
              <input type="date" class="form-control" name="start_date" required>
            </div>
            <div class="col">
              <label class="form-label">End Date</label>
              <input type="date" class="form-control" name="end_date" required>
            </div>
          </div>
          <div class="row g-2 mt-1">
            <div class="col">
              <label class="form-label">Status</label>
              <select class="form-select" name="status">
                <option value="inactive">Inactive</option>
                <option value="active">Active</option>
              </select>
            </div>
            <div class="col">
              <label class="form-label">Capacity</label>
              <input type="number" min="0" class="form-control" name="capacity" required>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" form="updateAcademicSessionModalForm" class="btn btn-primary">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteAcademicSessionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete this session?</p>
        <form id="deleteAcademicSessionModalForm" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="id" value="">
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" form="deleteAcademicSessionModalForm" class="btn btn-danger">Delete</button>
      </div>
    </div>
  </div>
</div>
</body>
</html>
