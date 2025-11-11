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
  course_id INT NULL,
  academic_session_id INT NOT NULL,
  timing_id INT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  INDEX (course_id), INDEX (academic_session_id), INDEX (timing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure course_id exists for legacy databases
try { $db->exec("ALTER TABLE batches ADD COLUMN course_id INT NULL AFTER name"); } catch (Throwable $e) { /* ignore if exists */ }
try { $db->exec("ALTER TABLE batches ADD INDEX (course_id)"); } catch (Throwable $e) { /* ignore if exists */ }

// Junction table: allow multiple timings per batch
$db->exec("CREATE TABLE IF NOT EXISTS batch_timings (
  batch_id INT NOT NULL,
  timing_id INT NOT NULL,
  PRIMARY KEY (batch_id, timing_id),
  INDEX (batch_id), INDEX (timing_id)
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
$courses = $db->query("SELECT id, name FROM courses ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $token = $_POST['csrf_token'] ?? '';
  if (!verifyCsrf($token)) { $errors[] = 'Invalid CSRF token.'; }
  else {
    $payload = [];
    if ($action === 'create') {
      $name = trim($_POST['name'] ?? '');
      $course_id = (int)($_POST['course_id'] ?? 0);
      $session_id = (int)($_POST['academic_session_id'] ?? 0);
      // Support multiple timings via timing_ids[]; fallback to single timing_id
      $timing_ids = $_POST['timing_ids'] ?? [];
      if (!is_array($timing_ids)) { $timing_ids = [$timing_ids]; }
      $timing_ids = array_values(array_filter(array_map('intval', $timing_ids)));
      if (empty($timing_ids) && isset($_POST['timing_id'])) {
        $single = (int)$_POST['timing_id']; if ($single > 0) { $timing_ids = [$single]; }
      }
      if ($name === '' || $course_id <= 0 || $session_id <= 0 || count($timing_ids) === 0) { $errors[] = 'Name, course, session, and at least one timing are required.'; }
      else {
        // Validate course exists
        $cc = $db->prepare("SELECT COUNT(*) FROM courses WHERE id = ?"); $cc->execute([$course_id]); if ((int)$cc->fetchColumn() === 0) { $errors[] = 'Selected course not found.'; }
        // Validate session active
        $st = $db->prepare("SELECT status FROM academic_sessions WHERE id = ?"); $st->execute([$session_id]); $status = (string)$st->fetchColumn();
        if ($status !== 'active') { $errors[] = 'Selected session must be active.'; }
        // Validate timing availability for all requested timings
        if (empty($errors)) {
          $tt = $db->prepare("SELECT is_available FROM timings WHERE id = ?");
          foreach ($timing_ids as $tid) {
            $tt->execute([$tid]); $avail = (int)$tt->fetchColumn();
            if ($avail !== 1) { $errors[] = 'Selected timing is not available: ID '.$tid; break; }
          }
        }
        if (empty($errors)) {
          // Insert batch using first timing for legacy column
          $legacy_tid = $timing_ids[0];
          $stmt = $db->prepare('INSERT INTO batches (name, course_id, academic_session_id, timing_id, created_at) VALUES (?, ?, ?, ?, ?)');
          $ok = $stmt->execute([$name, $course_id, $session_id, $legacy_tid, date('Y-m-d H:i:s')]);
          if ($ok) {
            $batch_id = (int)$db->lastInsertId();
            // Insert junction rows
            $ins = $db->prepare('INSERT IGNORE INTO batch_timings (batch_id, timing_id) VALUES (?, ?)');
            foreach ($timing_ids as $tid) { $ins->execute([$batch_id, $tid]); }
            $success = 'Batch created.';
            $payload = ['id' => $batch_id, 'name' => $name, 'course_id' => $course_id, 'academic_session_id' => $session_id, 'timing_ids' => $timing_ids];
          }
          else { $errors[] = 'Failed to create batch.'; }
        }
      }
    } elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      $course_id = (int)($_POST['course_id'] ?? 0);
      $session_id = (int)($_POST['academic_session_id'] ?? 0);
      // Support multiple timings via timing_ids[]; fallback to single timing_id
      $timing_ids = $_POST['timing_ids'] ?? [];
      if (!is_array($timing_ids)) { $timing_ids = [$timing_ids]; }
      $timing_ids = array_values(array_filter(array_map('intval', $timing_ids)));
      if (empty($timing_ids) && isset($_POST['timing_id'])) { $single = (int)$_POST['timing_id']; if ($single > 0) { $timing_ids = [$single]; } }
      if ($id <= 0 || $name === '' || $course_id <= 0 || $session_id <= 0 || count($timing_ids) === 0) { $errors[] = 'All fields are required.'; }
      else {
        $cc = $db->prepare("SELECT COUNT(*) FROM courses WHERE id = ?"); $cc->execute([$course_id]); if ((int)$cc->fetchColumn() === 0) { $errors[] = 'Selected course not found.'; }
        $st = $db->prepare("SELECT status FROM academic_sessions WHERE id = ?"); $st->execute([$session_id]); $status = (string)$st->fetchColumn();
        if ($status !== 'active') { $errors[] = 'Selected session must be active.'; }
        if (empty($errors)) {
          // Validate availability for all requested timings
          $tt = $db->prepare("SELECT is_available FROM timings WHERE id = ?");
          foreach ($timing_ids as $tid) { $tt->execute([$tid]); $avail = (int)$tt->fetchColumn(); if ($avail !== 1) { $errors[] = 'Selected timing is not available: ID '.$tid; break; } }
        }
        if (empty($errors)) {
          // Update legacy column to first timing; sync junction table
          $legacy_tid = $timing_ids[0];
          $stmt = $db->prepare('UPDATE batches SET name = ?, course_id = ?, academic_session_id = ?, timing_id = ?, updated_at = ? WHERE id = ?');
          $ok = $stmt->execute([$name, $course_id, $session_id, $legacy_tid, date('Y-m-d H:i:s'), $id]);
          if ($ok) {
            // Replace associations
            $db->prepare('DELETE FROM batch_timings WHERE batch_id = ?')->execute([$id]);
            $ins = $db->prepare('INSERT IGNORE INTO batch_timings (batch_id, timing_id) VALUES (?, ?)');
            foreach ($timing_ids as $tid) { $ins->execute([$id, $tid]); }
            $success = 'Batch updated.';
            $payload = ['id' => $id, 'name' => $name, 'course_id' => $course_id, 'academic_session_id' => $session_id, 'timing_ids' => $timing_ids];
          }
          else { $errors[] = 'Failed to update batch.'; }
        }
      }
    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) { $errors[] = 'Invalid batch ID.'; }
      else {
        // Allow batch deletion without cross-entity dependency checks.
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

// Fetch batches base info
$rows = $db->query('SELECT b.id, b.name, b.course_id, b.academic_session_id, b.timing_id, a.name AS session_name, a.status, c.name AS course_name FROM batches b JOIN academic_sessions a ON a.id = b.academic_session_id LEFT JOIN courses c ON c.id = b.course_id ORDER BY a.start_date DESC, b.name ASC')->fetchAll(PDO::FETCH_ASSOC);
// Build timing associations map for listed batches
$batchIds = array_map(fn($r)=> (int)$r['id'], $rows);
$assoc = [];
if (!empty($batchIds)) {
  $in = implode(',', array_map('intval', $batchIds));
  $qt = $db->query("SELECT bt.batch_id, t.id AS timing_id, t.day_of_week, t.start_time, t.end_time FROM batch_timings bt JOIN timings t ON t.id = bt.timing_id WHERE bt.batch_id IN ($in)");
  foreach ($qt->fetchAll(PDO::FETCH_ASSOC) as $a) { $bid = (int)$a['batch_id']; if (!isset($assoc[$bid])) $assoc[$bid] = []; $assoc[$bid][] = $a; }
}
$csrf = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Batches</title>
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
          <label class="form-label">Course</label>
          <select class="form-select" name="course_id" required>
            <option value="">Select course</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
          </select>
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
          <label class="form-label">Timing Slots</label>
          <select class="form-select" name="timing_ids[]" multiple size="5" required>
            <?php foreach ($availableTimings as $t): ?>
              <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['day_of_week'] . ' ' . substr($t['start_time'],0,5) . '-' . substr($t['end_time'],0,5)); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Hold Ctrl (Windows) to select multiple timings.</div>
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
        <thead><tr><th>Name</th><th>Course</th><th>Session</th><th>Timings</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $bid = (int)$r['id'];
            $timingsFor = $assoc[$bid] ?? [];
            $ids = array_map(fn($x)=> (int)$x['timing_id'], $timingsFor);
            // Fallback to legacy single timing if no junction rows
            if (empty($ids) && (int)$r['timing_id'] > 0) { $ids = [(int)$r['timing_id']]; }
            // Build labels using availableTimings (already fetched)
            $timingMap = [];
            foreach ($availableTimings as $t) { $timingMap[(int)$t['id']] = $t['day_of_week'] . ' ' . substr($t['start_time'],0,5) . '-' . substr($t['end_time'],0,5); }
            $labels = array_map(fn($id)=> $timingMap[$id] ?? ('Timing '.$id), $ids);
          ?>
          <tr data-id="<?php echo (int)$r['id']; ?>">
            <td class="cell-name"><?php echo htmlspecialchars($r['name']); ?></td>
            <td class="cell-course"><?php echo htmlspecialchars($r['course_name'] ?? ''); ?></td>
            <td class="cell-session"><?php echo htmlspecialchars($r['session_name']); ?></td>
            <td class="cell-timing"><?php echo htmlspecialchars(implode(', ', $labels)); ?></td>
            <td class="cell-status"><?php echo $r['status']==='active' ? '<span class="badge bg-success">active</span>' : '<span class="badge bg-secondary">inactive</span>'; ?></td>
            <td>
              <button type="button" class="btn btn-sm btn-outline-primary open-update-modal"
                data-id="<?php echo (int)$r['id']; ?>"
                data-name="<?php echo htmlspecialchars($r['name']); ?>"
                data-course-id="<?php echo (int)($r['course_id'] ?? 0); ?>"
                data-session-id="<?php echo (int)$r['academic_session_id']; ?>"
                data-timing-ids="<?php echo htmlspecialchars(implode(',', $ids)); ?>">Update</button>
              <button type="button" class="btn btn-sm btn-outline-danger open-delete-modal" data-id="<?php echo (int)$r['id']; ?>">Delete</button>
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
      // Ensure the action is sent when using AJAX
      const submitter = e.submitter || btn;
      if (submitter && submitter.name) {
        fd.append(submitter.name, submitter.value);
      } else {
        fd.append('action','create');
      }
      try {
        const res = await fetch('batches.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        if (json.success) {
          alertFor(createForm.parentElement, 'success', json.message || 'Batch created.');
          // Append a new row using returned payload
          if (json.created) {
            // Build label maps from server-side lists embedded below
            const tbody = document.querySelector('table tbody');
            if (tbody) {
              const c = json.created;
              const csrfVal = json.csrf_token || (createForm.querySelector('input[name="csrf_token"]')?.value || '');
              const sessionName = window.__sessionsIndex?.[String(c.academic_session_id)] || 'Session';
              const tids = Array.isArray(c.timing_ids) ? c.timing_ids : (c.timing_ids ? [c.timing_ids] : []);
              const timingText = tids.map(id => (window.__timingsIndex?.[String(id)] || ('Timing '+id))).join(', ');
              const tr = document.createElement('tr');
              tr.setAttribute('data-id', String(c.id));
              tr.innerHTML = `
                <td class="cell-name">${c.name}</td>
                <td class="cell-course">${window.__coursesIndex?.[String(c.course_id)] || ''}</td>
                <td class="cell-session">${sessionName}</td>
                <td class="cell-timing">${timingText}</td>
                <td class="cell-status"><span class="badge bg-success">active</span></td>
                <td>
                  <button type="button" class="btn btn-sm btn-outline-primary open-update-modal"
                    data-id="${c.id}"
                    data-name="${c.name}"
                    data-course-id="${c.course_id}"
                    data-session-id="${c.academic_session_id}"
                    data-timing-ids="${tids.join(',')}">Update</button>
                  <button type="button" class="btn btn-sm btn-outline-danger open-delete-modal" data-id="${c.id}">Delete</button>
                </td>
              `;
              tbody.prepend(tr);
              // Wire modal openers for the new row
              const ub = tr.querySelector('.open-update-modal');
              const db = tr.querySelector('.open-delete-modal');
              if (ub) {
                ub.addEventListener('click', ()=>{
                  const uf = document.getElementById('updateBatchModalForm');
                  if (!uf || !window.__updateModal) return;
                  uf.querySelector('input[name="id"]').value = String(c.id);
                  uf.querySelector('input[name="name"]').value = c.name;
                  const sessSel = uf.querySelector('select[name="academic_session_id"]'); if (sessSel) sessSel.value = String(c.academic_session_id);
                  const courseSel = uf.querySelector('select[name="course_id"]'); if (courseSel) courseSel.value = String(c.course_id);
                  const timeSel = uf.querySelector('select[name="timing_ids[]"]'); if (timeSel) { const values = tids.map(v=>String(v)); Array.from(timeSel.options).forEach(opt => { opt.selected = values.includes(opt.value); }); }
                  window.__updateModal.show();
                });
              }
              if (db) {
                db.addEventListener('click', ()=>{
                  const df = document.getElementById('deleteBatchModalForm');
                  if (!df || !window.__deleteModal) return;
                  df.querySelector('input[name="id"]').value = String(c.id);
                  window.__deleteModal.show();
                });
              }
            }
          }
          // Reset form after success
          createForm.reset();
        }
        else { alertFor(createForm.parentElement, 'danger', json.message || 'Failed to create.'); }
        const csrf = createForm.querySelector('input[name="csrf_token"]'); if (csrf && json.csrf_token) csrf.value = json.csrf_token;
      } catch(e){ alertFor(createForm.parentElement, 'danger', 'Network error.'); }
      finally { setLoading(btn, false, 'Create'); }
    });
  }

  // Initialize modals
  const updateModalEl = document.getElementById('updateBatchModal');
  const deleteModalEl = document.getElementById('deleteBatchModal');
  window.__updateModal = updateModalEl ? new bootstrap.Modal(updateModalEl) : null;
  window.__deleteModal = deleteModalEl ? new bootstrap.Modal(deleteModalEl) : null;
  const updateForm = document.getElementById('updateBatchModalForm');
  const deleteForm = document.getElementById('deleteBatchModalForm');

  // Open Update Modal
  document.querySelectorAll('.open-update-modal').forEach((btn)=>{
    btn.addEventListener('click', ()=>{
      if (!window.__updateModal || !updateForm) return;
      updateForm.querySelector('input[name="id"]').value = btn.dataset.id || '';
      updateForm.querySelector('input[name="name"]').value = btn.dataset.name || '';
      const sessSel = updateForm.querySelector('select[name="academic_session_id"]'); if (sessSel) sessSel.value = btn.dataset.sessionId || btn.dataset.sessionId || '';
      const timeSel = updateForm.querySelector('select[name="timing_ids[]"]');
      if (timeSel) {
        const values = (btn.dataset.timingIds || '').split(',').filter(Boolean);
        Array.from(timeSel.options).forEach(opt => { opt.selected = values.includes(opt.value); });
      }
      window.__updateModal.show();
    });
  });

  // Open Delete Modal
  document.querySelectorAll('.open-delete-modal').forEach((btn)=>{
    btn.addEventListener('click', ()=>{
      if (!window.__deleteModal || !deleteForm) return;
      deleteForm.querySelector('input[name="id"]').value = btn.dataset.id || '';
      window.__deleteModal.show();
    });
  });

  // Submit Update
  if (updateForm) {
    updateForm.addEventListener('submit', async function(e){
      e.preventDefault();
      const submitBtn = updateForm.querySelector('button[type="submit"]');
      setLoading(submitBtn, true, 'Save');
      const fd = new FormData(updateForm); fd.append('ajax','1'); fd.append('action','update');
      try {
        const res = await fetch('batches.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        if (json.success) {
          alertFor(updateForm, 'success', json.message || 'Batch updated.');
          const id = updateForm.querySelector('input[name="id"]').value;
          const tr = document.querySelector(`tr[data-id="${id}"]`);
          if (tr && json.updated) {
            tr.querySelector('.cell-name').textContent = json.updated.name;
            tr.querySelector('.cell-course').textContent = window.__coursesIndex?.[String(json.updated.course_id)] || '';
            const sessionName = window.__sessionsIndex?.[String(json.updated.academic_session_id)] || 'Session';
            const tids = Array.isArray(json.updated.timing_ids) ? json.updated.timing_ids : [];
            const timingText = tids.map(id => (window.__timingsIndex?.[String(id)] || ('Timing '+id))).join(', ');
            tr.querySelector('.cell-session').textContent = sessionName;
            tr.querySelector('.cell-timing').textContent = timingText;
            tr.querySelector('.cell-status').innerHTML = '<span class="badge bg-success">active</span>';
            const ub = tr.querySelector('.open-update-modal');
            if (ub) {
              ub.dataset.name = json.updated.name;
              ub.dataset.courseId = String(json.updated.course_id);
              ub.dataset.sessionId = String(json.updated.academic_session_id);
              ub.dataset.timingIds = tids.join(',');
            }
          }
          window.__updateModal && window.__updateModal.hide();
        } else {
          alertFor(updateForm, 'danger', json.message || 'Failed to update.');
        }
        document.querySelectorAll('input[name="csrf_token"]').forEach(inp => { if (json.csrf_token) inp.value = json.csrf_token; });
      } catch(err){ alertFor(updateForm, 'danger', 'Network error.'); }
      finally { setLoading(submitBtn, false, 'Save'); }
    });
  }

  // Submit Delete
  if (deleteForm) {
    deleteForm.addEventListener('submit', async function(e){
      e.preventDefault();
      const submitBtn = deleteForm.querySelector('button[type="submit"]');
      setLoading(submitBtn, true, 'Delete');
      const fd = new FormData(deleteForm); fd.append('ajax','1'); fd.append('action','delete');
      try {
        const res = await fetch('batches.php', { method:'POST', headers:{ 'Accept':'application/json' }, body: fd });
        const json = await res.json();
        if (json.success) {
          alertFor(deleteForm, 'success', json.message || 'Batch deleted.');
          const id = deleteForm.querySelector('input[name="id"]').value;
          const tr = document.querySelector(`tr[data-id="${id}"]`);
          if (tr) tr.remove();
          window.__deleteModal && window.__deleteModal.hide();
        } else {
          alertFor(deleteForm, 'danger', json.message || 'Failed to delete.');
        }
        document.querySelectorAll('input[name="csrf_token"]').forEach(inp => { if (json.csrf_token) inp.value = json.csrf_token; });
      } catch(err){ alertFor(deleteForm, 'danger', 'Network error.'); }
      finally { setLoading(submitBtn, false, 'Delete'); }
    });
  }
});
</script>
</body>

<script>
// Embed lookup maps for client-side label resolution
window.__sessionsIndex = <?php echo json_encode(array_column($activeSessions, 'name', 'id'), JSON_UNESCAPED_UNICODE); ?>;
window.__coursesIndex = <?php echo json_encode(array_column($courses, 'name', 'id'), JSON_UNESCAPED_UNICODE); ?>;
window.__timingsIndex = <?php
  $timingMap = [];
  foreach ($availableTimings as $t) {
    $timingMap[$t['id']] = $t['day_of_week'] . ' ' . substr($t['start_time'],0,5) . '-' . substr($t['end_time'],0,5);
  }
  echo json_encode($timingMap, JSON_UNESCAPED_UNICODE);
?>;
</script>

<!-- Update Batch Modal -->
<div class="modal fade" id="updateBatchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Update Batch</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="updateBatchModalForm" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="id" value="">
          <div class="mb-2">
            <label class="form-label">Name</label>
            <input type="text" class="form-control" name="name" required>
          </div>
          <div class="row g-2 mt-1">
            <div class="col">
              <label class="form-label">Course</label>
              <select class="form-select" name="course_id" required>
                <?php foreach ($courses as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col">
              <label class="form-label">Academic Session</label>
              <select class="form-select" name="academic_session_id" required>
                <?php foreach ($activeSessions as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col">
              <label class="form-label">Timing Slots</label>
              <select class="form-select" name="timing_ids[]" multiple size="5" required>
                <?php foreach ($availableTimings as $t): ?>
                  <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['day_of_week'] . ' ' . substr($t['start_time'],0,5) . '-' . substr($t['end_time'],0,5)); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Hold Ctrl (Windows) to select multiple timings.</div>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" form="updateBatchModalForm" class="btn btn-primary">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteBatchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete this batch?</p>
        <form id="deleteBatchModalForm" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="id" value="">
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" form="deleteBatchModalForm" class="btn btn-danger">Delete</button>
      </div>
    </div>
  </div>
</div>
</body>
</html>
