<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';

$auth = new Auth();
$session = Session::getInstance();
$auth->requireRole(['superadmin','accounts']);
$role = (string)$session->get('role');

$db = (new Database())->getConnection();

// Schema: timings
// Create table (include 'Daily' as valid value)
$db->exec("CREATE TABLE IF NOT EXISTS timings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NULL,
    day_of_week ENUM('Daily','Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX (day_of_week), INDEX (is_available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure existing schema includes 'Daily'
try {
    // Ensure 'Daily' exists in enum
    $col = $db->query("SHOW COLUMNS FROM timings LIKE 'day_of_week'" )->fetch(PDO::FETCH_ASSOC);
    if ($col && isset($col['Type']) && stripos($col['Type'], "'Daily'") === false) {
        $db->exec("ALTER TABLE timings MODIFY day_of_week ENUM('Daily','Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL");
    }
    // Ensure 'name' column exists
    $nameCol = $db->query("SHOW COLUMNS FROM timings LIKE 'name'" )->fetch(PDO::FETCH_ASSOC);
    if (!$nameCol) {
        $db->exec("ALTER TABLE timings ADD COLUMN name VARCHAR(100) NULL AFTER id");
    }
} catch (Throwable $e) {
    // Non-fatal: if ALTER fails, creation/validation will guard values
}

function csrfToken(){ if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf_token']; }
function verifyCsrf($t){ return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t); }

// Normalize free-text day/frequency into allowed enum tokens
function normalizeDay(string $input): string {
    $map = [
        'daily' => 'Daily',
        'mon' => 'Mon', 'monday' => 'Mon',
        'tue' => 'Tue', 'tues' => 'Tue', 'tuesday' => 'Tue',
        'wed' => 'Wed', 'weds' => 'Wed', 'wednesday' => 'Wed',
        'thu' => 'Thu', 'thur' => 'Thu', 'thurs' => 'Thu', 'thursday' => 'Thu',
        'fri' => 'Fri', 'friday' => 'Fri',
        'sat' => 'Sat', 'saturday' => 'Sat',
        'sun' => 'Sun', 'sunday' => 'Sun',
    ];
    $key = strtolower(trim($input));
    return $map[$key] ?? '';
}

$errors = [];
$success = '';

// Overlap check: For same day, ensure [start,end] doesn't overlap existing rows
function hasOverlap(PDO $db, string $day, string $start, string $end, ?int $excludeId = null, ?string $name = null): bool {
    // Overlap is only checked within the same day_of_week AND the same name group.
    // If name is null/empty, consider only rows where name IS NULL for overlap:
    // this allows overlapping slots across different names.
    $sql = "SELECT COUNT(*) FROM timings WHERE day_of_week = ? AND (
        (start_time < ? AND end_time > ?) OR
        (start_time >= ? AND start_time < ?) OR
        (end_time > ? AND end_time <= ?)
    )";
    $params = [$day, $end, $start, $start, $end, $start, $end];
    if ($excludeId) { $sql .= " AND id <> ?"; $params[] = $excludeId; }
    if ($name !== null && $name !== '') {
        $sql .= " AND name = ?"; $params[] = $name;
    } else {
        $sql .= " AND name IS NULL"; // Only block overlap against unnamed slots
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ((int)$stmt->fetchColumn()) > 0;
}

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
            $day = normalizeDay($_POST['day_of_week'] ?? '');
            $start = trim($_POST['start_time'] ?? '');
            $end = trim($_POST['end_time'] ?? '');
            $available = (int)($_POST['is_available'] ?? 1) === 1 ? 1 : 0;
            if (!in_array($day, ['Daily','Mon','Tue','Wed','Thu','Fri','Sat','Sun'], true) || $start === '' || $end === '' || $start >= $end) {
                $errors[] = 'Day, valid start and end times are required.';
            } elseif (hasOverlap($db, $day, $start, $end, null, $name !== '' ? $name : null)) {
                $errors[] = 'Timing overlaps within the same name/day.';
            } else {
                $stmt = $db->prepare('INSERT INTO timings (name, day_of_week, start_time, end_time, is_available, created_at) VALUES (?, ?, ?, ?, ?, ?)');
                $ok = $stmt->execute([$name !== '' ? $name : null, $day, $start, $end, $available, date('Y-m-d H:i:s')]);
                if ($ok) { $success = 'Timing created.'; $payload = ['id' => (int)$db->lastInsertId(), 'name' => $name, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end, 'is_available' => $available]; }
                else { $errors[] = 'Failed to create timing.'; }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $day = normalizeDay($_POST['day_of_week'] ?? '');
            $start = trim($_POST['start_time'] ?? '');
            $end = trim($_POST['end_time'] ?? '');
            $available = (int)($_POST['is_available'] ?? 1) === 1 ? 1 : 0;
            if ($id <= 0 || !in_array($day, ['Daily','Mon','Tue','Wed','Thu','Fri','Sat','Sun'], true) || $start === '' || $end === '' || $start >= $end) {
                $errors[] = 'All fields are required and times must be valid.';
            } elseif (hasOverlap($db, $day, $start, $end, $id, $name !== '' ? $name : null)) {
                $errors[] = 'Timing overlaps within the same name/day.';
            } else {
                $stmt = $db->prepare('UPDATE timings SET name = ?, day_of_week = ?, start_time = ?, end_time = ?, is_available = ?, updated_at = ? WHERE id = ?');
                $ok = $stmt->execute([$name !== '' ? $name : null, $day, $start, $end, $available, date('Y-m-d H:i:s'), $id]);
                if ($ok) { $success = 'Timing updated.'; $payload = ['id' => $id, 'name' => $name, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end, 'is_available' => $available]; }
                else { $errors[] = 'Failed to update timing.'; }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $errors[] = 'Invalid timing ID.'; }
            else {
                $stmt = $db->prepare('DELETE FROM timings WHERE id = ?');
                $ok = $stmt->execute([$id]);
                if ($ok) { $success = 'Timing deleted.'; $payload = ['id' => $id]; }
                else { $errors[] = 'Failed to delete timing.'; }
            }
        }
    }
    // Refresh CSRF
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => empty($errors),
            'message' => empty($errors) ? ($success ?: 'OK') : implode('\n', $errors),
            'csrf_token' => (string)$_SESSION['csrf_token'],
            'created' => ($action === 'create' && empty($errors)) ? $payload : null,
            'updated' => ($action === 'update' && empty($errors)) ? $payload : null,
            'deleted' => ($action === 'delete' && empty($errors)) ? $payload : null,
        ]);
        exit();
    }
}

$rows = $db->query('SELECT * FROM timings ORDER BY FIELD(day_of_week, "Daily","Mon","Tue","Wed","Thu","Fri","Sat","Sun"), start_time ASC')->fetchAll(PDO::FETCH_ASSOC);
$csrf = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Timings</title>
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
    <h1 class="h4 mb-0">Timing Slots</h1>
    <span class="ms-3 small text-muted">Manage timing availability (Daily/Weekly)</span>
  </div>

  <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php echo htmlspecialchars(implode('\n', $errors)); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">Create Timing</div>
    <div class="card-body">
      <form method="post" class="row g-3" id="createTimingForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <div class="col-md-3">
          <label class="form-label">Name</label>
          <input type="text" class="form-control" name="name" placeholder="e.g., 09 to 11" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Day or Frequency</label>
          <select class="form-select" name="day_of_week" required>
            <option value="Daily" selected>Daily</option>
            <?php foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
              <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Start Time</label>
          <input type="time" class="form-control" name="start_time" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">End Time</label>
          <input type="time" class="form-control" name="end_time" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Availability</label>
          <select class="form-select" name="is_available">
            <option value="1" selected>Available</option>
            <option value="0">Unavailable</option>
          </select>
        </div>
        <div class="col-12">
          <button type="submit" name="action" value="create" class="btn btn-primary">Create</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Existing Timings</div>
    <div class="card-body table-responsive">
      <table class="table table-striped table-hover">
        <thead><tr><th>Name/Day</th><th>Start</th><th>End</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (count($rows) === 0): ?>
          <tr><td colspan="5" class="text-center text-muted">No timings found. Create one above.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr data-row-id="<?php echo (int)$r['id']; ?>">
            <td><?php echo htmlspecialchars(($r['name'] ?? '') !== '' ? $r['name'] : $r['day_of_week']); ?></td>
            <td><?php echo htmlspecialchars($r['start_time']); ?></td>
            <td><?php echo htmlspecialchars($r['end_time']); ?></td>
            <td><?php if ((int)$r['is_available'] === 1): ?><span class="badge bg-success">available</span><?php else: ?><span class="badge bg-secondary">unavailable</span><?php endif; ?></td>
            <td>
              <button type="button"
                      class="btn btn-sm btn-outline-primary open-update-modal"
                      data-id="<?php echo (int)$r['id']; ?>"
                      data-name="<?php echo htmlspecialchars($r['name'] ?? '', ENT_QUOTES); ?>"
                      data-day="<?php echo htmlspecialchars($r['day_of_week'], ENT_QUOTES); ?>"
                      data-start="<?php echo htmlspecialchars($r['start_time'], ENT_QUOTES); ?>"
                      data-end="<?php echo htmlspecialchars($r['end_time'], ENT_QUOTES); ?>"
                      data-available="<?php echo (int)$r['is_available']; ?>">Update</button>
              <button type="button"
                      class="btn btn-sm btn-outline-danger open-delete-modal"
                      data-id="<?php echo (int)$r['id']; ?>">Delete</button>
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
// AJAX handlers similar to existing modules
document.addEventListener('DOMContentLoaded', function(){
  function alertFor(target, type, text){ const el=document.createElement('div'); el.className=`alert alert-${type} mt-2`; el.textContent=text; target.prepend(el); setTimeout(()=>el.remove(),5000); }
  function setLoading(btn, on, idle){ if (!btn) return; btn.disabled=on; btn.innerHTML = on ? '<span class="spinner-border spinner-border-sm me-1"></span>Working…' : idle; }

  function bindRowForm(form){
    if (!form) return;
    form.addEventListener('submit', async function(e){
      e.preventDefault();
      const btn = form.querySelector('button[type="submit"]:focus') || form.querySelector('button[type="submit"]');
      const idle = btn ? btn.textContent.trim() : 'Submit';
      setLoading(btn, true, idle);
      const fd = new FormData(form); fd.append('ajax','1');
      const actionBtn = btn && btn.getAttribute('value') ? btn.getAttribute('value') : 'update';
      fd.append('action', actionBtn);
      try {
        const res = await fetch('timings.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        const card = form.closest('.card');
        if (json.success) {
          alertFor(card, 'success', json.message || 'Saved.');
          const tr = form.closest('tr');
          if (json.updated && tr) {
            tr.querySelector('td:nth-child(1)').textContent = (json.updated.name && json.updated.name.length) ? json.updated.name : json.updated.day_of_week;
            tr.querySelector('td:nth-child(2)').textContent = json.updated.start_time;
            tr.querySelector('td:nth-child(3)').textContent = json.updated.end_time;
            const statusCell = tr.querySelector('td:nth-child(4)');
            statusCell.innerHTML = (json.updated.is_available===1) ? '<span class="badge bg-success">available</span>' : '<span class="badge bg-secondary">unavailable</span>';
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

  const createForm = document.getElementById('createTimingForm');
  if (createForm) {
    createForm.addEventListener('submit', async function(e){
      e.preventDefault();
      if (!createForm.checkValidity()) { createForm.classList.add('was-validated'); alertFor(createForm.parentElement, 'danger', 'Please complete required fields.'); return; }
      const btn = createForm.querySelector('button[type="submit"]');
      setLoading(btn, true, 'Create');
      const fd = new FormData(createForm); fd.append('ajax', '1'); fd.append('action','create');
      try {
        const res = await fetch('timings.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        if (json.success) {
          alertFor(createForm.parentElement, 'success', json.message || 'Timing created.');
          const csrf = createForm.querySelector('input[name="csrf_token"]'); if (csrf && json.csrf_token) csrf.value = json.csrf_token;
          // Append new row to table
          if (json.created) {
            const tbody = document.querySelector('table tbody');
            const tr = document.createElement('tr');
            const d = json.created;
            const isAvail = d.is_available === 1;
            const dayOpts = ['Daily','Mon','Tue','Wed','Thu','Fri','Sat','Sun']
              .map(opt => `<option value="${opt}" ${opt===d.day_of_week?'selected':''}>${opt}</option>`)
              .join('');
            tr.innerHTML = `
              <td>${d.name && d.name.length ? d.name : d.day_of_week}</td>
              <td>${d.start_time}</td>
              <td>${d.end_time}</td>
              <td>${isAvail ? '<span class="badge bg-success">available</span>' : '<span class="badge bg-secondary">unavailable</span>'}</td>
              <td>
                <form method="post" class="row g-2 align-items-center">
                  <input type="hidden" name="csrf_token" value="${json.csrf_token}">
                  <input type="hidden" name="id" value="${d.id}">
                  <div class="col-md-2">
                    <input type="text" class="form-control form-control-sm" name="name" value="${d.name || ''}" placeholder="Name">
                  </div>
                  <div class="col-md-2">
                    <select class="form-select form-select-sm" name="day_of_week" required>
                      ${dayOpts}
                    </select>
                  </div>
                  <div class="col-md-3"><input type="time" class="form-control form-control-sm" name="start_time" value="${d.start_time}" required></div>
                  <div class="col-md-3"><input type="time" class="form-control form-control-sm" name="end_time" value="${d.end_time}" required></div>
                  <div class="col-md-2">
                    <select class="form-select form-select-sm" name="is_available">
                      <option value="1" ${isAvail?'selected':''}>Available</option>
                      <option value="0" ${!isAvail?'selected':''}>Unavailable</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <button class="btn btn-sm btn-outline-primary" type="submit" name="action" value="update">Update</button>
                    <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="delete">Delete</button>
                  </div>
                </form>
              </td>`;
            tbody.appendChild(tr);
            tr.setAttribute('data-row-id', String(d.id));
            const actionCell = tr.querySelector('td:last-child');
            if (actionCell) {
              actionCell.innerHTML = `
                <button type="button" class="btn btn-sm btn-outline-primary open-update-modal"
                        data-id="${d.id}" data-name="${d.name || ''}" data-day="${d.day_of_week}"
                        data-start="${d.start_time}" data-end="${d.end_time}" data-available="${isAvail ? '1' : '0'}">Update</button>
                <button type="button" class="btn btn-sm btn-outline-danger open-delete-modal" data-id="${d.id}">Delete</button>
              `;
            }
            bindUpdateButtons();
            bindDeleteButtons();
            const newForm = tr.querySelector('form');
            bindRowForm(newForm);
            createForm.reset();
          }
        } else {
          alertFor(createForm.parentElement, 'danger', json.message || 'Failed to create.');
          const csrf = createForm.querySelector('input[name="csrf_token"]'); if (csrf && json.csrf_token) csrf.value = json.csrf_token;
        }
      } catch(e){ alertFor(createForm.parentElement, 'danger', 'Network error.'); }
      finally { setLoading(btn, false, 'Create'); }
    });
  }
  // Modal elements
  const updateModalEl = document.getElementById('updateTimingModal');
  const deleteModalEl = document.getElementById('deleteTimingModal');
  const updateModal = updateModalEl ? new bootstrap.Modal(updateModalEl) : null;
  const deleteModal = deleteModalEl ? new bootstrap.Modal(deleteModalEl) : null;

  const updateForm = document.getElementById('updateTimingModalForm');
  const deleteForm = document.getElementById('deleteTimingModalForm');

  // Open Update Modal with prefilled data
  function bindUpdateButtons(){
    document.querySelectorAll('.open-update-modal').forEach((btn)=>{
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', ()=>{
        if (!updateModal) return;
        const id = btn.getAttribute('data-id');
        const name = btn.getAttribute('data-name') || '';
        const day = btn.getAttribute('data-day') || 'Daily';
        const start = btn.getAttribute('data-start') || '';
        const end = btn.getAttribute('data-end') || '';
        const available = btn.getAttribute('data-available') === '1' ? '1' : '0';
        updateForm.querySelector('input[name="id"]').value = id;
        updateForm.querySelector('input[name="name"]').value = name;
        updateForm.querySelector('select[name="day_of_week"]').value = day;
        updateForm.querySelector('input[name="start_time"]').value = start;
        updateForm.querySelector('input[name="end_time"]').value = end;
        updateForm.querySelector('select[name="is_available"]').value = available;
        updateModal.show();
      });
    });
  }

  // Open Delete Modal
  function bindDeleteButtons(){
    document.querySelectorAll('.open-delete-modal').forEach((btn)=>{
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', ()=>{
        if (!deleteModal) return;
        const id = btn.getAttribute('data-id');
        deleteForm.querySelector('input[name="id"]').value = id;
        deleteModal.show();
      });
    });
  }

  bindUpdateButtons();
  bindDeleteButtons();

  // Submit Update Modal
  if (updateForm) {
    updateForm.addEventListener('submit', async function(e){
      e.preventDefault();
      const submitBtn = updateForm.querySelector('button[type="submit"]');
      setLoading(submitBtn, true, 'Save Changes');
      const fd = new FormData(updateForm); fd.append('ajax','1'); fd.append('action','update');
      try {
        const res = await fetch('timings.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        const card = document.querySelector('.card:last-of-type');
        if (json.success) {
          alertFor(card, 'success', json.message || 'Updated.');
          // Update row display
          const id = json.updated.id;
          const tr = document.querySelector(`tr[data-row-id="${id}"]`);
          if (tr) {
            tr.querySelector('td:nth-child(1)').textContent = (json.updated.name && json.updated.name.length) ? json.updated.name : json.updated.day_of_week;
            tr.querySelector('td:nth-child(2)').textContent = json.updated.start_time;
            tr.querySelector('td:nth-child(3)').textContent = json.updated.end_time;
            const statusCell = tr.querySelector('td:nth-child(4)');
            statusCell.innerHTML = (json.updated.is_available===1) ? '<span class="badge bg-success">available</span>' : '<span class="badge bg-secondary">unavailable</span>';
            // Update button datasets
            const updateBtn = tr.querySelector('.open-update-modal');
            if (updateBtn) {
              updateBtn.setAttribute('data-name', json.updated.name || '');
              updateBtn.setAttribute('data-day', json.updated.day_of_week);
              updateBtn.setAttribute('data-start', json.updated.start_time);
              updateBtn.setAttribute('data-end', json.updated.end_time);
              updateBtn.setAttribute('data-available', String(json.updated.is_available));
            }
          }
          // Refresh CSRF
          updateForm.querySelectorAll('input[name="csrf_token"]').forEach(inp => { if (json.csrf_token) inp.value = json.csrf_token; });
          updateModal && updateModal.hide();
        } else {
          alertFor(card, 'danger', json.message || 'Failed.');
          updateForm.querySelectorAll('input[name="csrf_token"]').forEach(inp => { if (json.csrf_token) inp.value = json.csrf_token; });
        }
      } catch(err){ alertFor(document.querySelector('.card:last-of-type'), 'danger', 'Network error.'); }
      finally { setLoading(submitBtn, false, 'Save Changes'); }
    });
  }

  // Submit Delete Modal
  if (deleteForm) {
    deleteForm.addEventListener('submit', async function(e){
      e.preventDefault();
      const submitBtn = deleteForm.querySelector('button[type="submit"]');
      setLoading(submitBtn, true, 'Delete');
      const fd = new FormData(deleteForm); fd.append('ajax','1'); fd.append('action','delete');
      try {
        const res = await fetch('timings.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        const card = document.querySelector('.card:last-of-type');
        if (json.success) {
          alertFor(card, 'success', json.message || 'Deleted.');
          const id = json.deleted.id;
          const tr = document.querySelector(`tr[data-row-id="${id}"]`);
          if (tr) tr.remove();
          deleteForm.querySelectorAll('input[name="csrf_token"]').forEach(inp => { if (json.csrf_token) inp.value = json.csrf_token; });
          deleteModal && deleteModal.hide();
        } else {
          alertFor(card, 'danger', json.message || 'Failed.');
          deleteForm.querySelectorAll('input[name="csrf_token"]').forEach(inp => { if (json.csrf_token) inp.value = json.csrf_token; });
        }
      } catch(err){ alertFor(document.querySelector('.card:last-of-type'), 'danger', 'Network error.'); }
      finally { setLoading(submitBtn, false, 'Delete'); }
    });
  }
});
</script>

<!-- Update Timing Modal -->
<div class="modal fade" id="updateTimingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Update Timing</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="updateTimingModalForm" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="id" value="">
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" class="form-control" name="name" placeholder="e.g., 09 to 11">
          </div>
          <div class="mb-3">
            <label class="form-label">Day or Frequency</label>
            <select class="form-select" name="day_of_week" required>
              <?php foreach(['Daily','Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
                <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Start Time</label>
            <input type="time" class="form-control" name="start_time" required>
          </div>
          <div class="mb-3">
            <label class="form-label">End Time</label>
            <input type="time" class="form-control" name="end_time" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Availability</label>
            <select class="form-select" name="is_available">
              <option value="1">Available</option>
              <option value="0">Unavailable</option>
            </select>
          </div>
          <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteTimingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="deleteTimingModalForm" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="id" value="">
          <p>Are you sure you want to delete this timing?</p>
          <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger">Delete</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
