<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole(['superadmin','accounts']);

$db = (new Database())->getConnection();
// Schema: course_sessions and session_meeting_times
$db->exec("CREATE TABLE IF NOT EXISTS course_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    year INT NOT NULL,
    term ENUM('H1','H2','Q1','Q2','Q3','Q4') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    teacher_id INT NULL,
    status ENUM('planned','active','completed','archived') NOT NULL DEFAULT 'planned',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX (course_id), INDEX (year), INDEX (term), INDEX (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS session_meeting_times (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_session_id INT NOT NULL,
    day_of_week ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    location VARCHAR(100) NULL,
    INDEX (course_session_id), INDEX (day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function csrfToken() { if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf_token']; }
function verifyCsrf($t) { return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t); }

$errors = [];
$success = '';

// For selects
$courses = $db->query('SELECT id, name, duration_months FROM courses ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

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
        if ($action === 'create_session') {
            $course_id = (int)($_POST['course_id'] ?? 0);
            $year = (int)($_POST['year'] ?? 0);
            $term = $_POST['term'] ?? '';
            $start_date = trim($_POST['start_date'] ?? '');
            $end_date = trim($_POST['end_date'] ?? '');
            $teacher_id = $_POST['teacher_id'] !== '' ? (int)$_POST['teacher_id'] : null;
            $status = $_POST['status'] ?? 'planned';
            if ($course_id <= 0 || $year <= 2000 || $start_date === '' || $end_date === '' || !in_array($term, ['H1','H2','Q1','Q2','Q3','Q4'], true)) {
                $errors[] = 'Course, year, term, start and end date are required.';
            } else {
                $stmt = $db->prepare('INSERT INTO course_sessions (course_id, year, term, start_date, end_date, teacher_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $ok = $stmt->execute([$course_id, $year, $term, $start_date, $end_date, $teacher_id, $status, date('Y-m-d H:i:s')]);
                if ($ok) { 
                    $success = 'Session created.'; 
                    $newId = (int)$db->lastInsertId();
                    // Fetch course name for display
                    $courseRow = $db->prepare('SELECT name, duration_months FROM courses WHERE id = ?');
                    $courseRow->execute([$course_id]);
                    $c = $courseRow->fetch(PDO::FETCH_ASSOC);
                    $created = [
                        'id' => $newId,
                        'course_id' => $course_id,
                        'course_name' => (string)($c['name'] ?? ''),
                        'duration' => (int)($c['duration_months'] ?? 0),
                        'year' => $year,
                        'term' => $term,
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                        'status' => $status,
                    ];
                } else { $errors[] = 'Failed to create session.'; }
            }
        } elseif ($action === 'update_session') {
            $id = (int)($_POST['id'] ?? 0);
            $course_id = (int)($_POST['course_id'] ?? 0);
            $year = (int)($_POST['year'] ?? 0);
            $term = $_POST['term'] ?? '';
            $start_date = trim($_POST['start_date'] ?? '');
            $end_date = trim($_POST['end_date'] ?? '');
            $teacher_id = $_POST['teacher_id'] !== '' ? (int)$_POST['teacher_id'] : null;
            $status = $_POST['status'] ?? 'planned';
            if ($id <= 0 || $course_id <= 0 || $year <= 2000 || $start_date === '' || $end_date === '' || !in_array($term, ['H1','H2','Q1','Q2','Q3','Q4'], true)) {
                $errors[] = 'Invalid input for session update.';
            } else {
                $stmt = $db->prepare('UPDATE course_sessions SET course_id = ?, year = ?, term = ?, start_date = ?, end_date = ?, teacher_id = ?, status = ?, updated_at = ? WHERE id = ?');
                $ok = $stmt->execute([$course_id, $year, $term, $start_date, $end_date, $teacher_id, $status, date('Y-m-d H:i:s'), $id]);
                if ($ok) { 
                    $success = 'Session updated.'; 
                    // Fetch course name for display
                    $courseRow = $db->prepare('SELECT name, duration_months FROM courses WHERE id = ?');
                    $courseRow->execute([$course_id]);
                    $c = $courseRow->fetch(PDO::FETCH_ASSOC);
                    $updated = [
                        'id' => $id,
                        'course_id' => $course_id,
                        'course_name' => (string)($c['name'] ?? ''),
                        'duration' => (int)($c['duration_months'] ?? 0),
                        'year' => $year,
                        'term' => $term,
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                        'status' => $status,
                    ];
                } else { $errors[] = 'Failed to update session.'; }
            }
        } elseif ($action === 'delete_session') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $errors[] = 'Invalid session ID.'; }
            else {
                $ok = $db->prepare('DELETE FROM course_sessions WHERE id = ?')->execute([$id]);
                if ($ok) { $success = 'Session deleted.'; $deleted = ['id' => $id]; } else { $errors[] = 'Failed to delete session.'; }
            }
        } elseif ($action === 'add_meeting') {
            $session_id = (int)($_POST['course_session_id'] ?? 0);
            $day = $_POST['day_of_week'] ?? '';
            $start = $_POST['start_time'] ?? '';
            $end = $_POST['end_time'] ?? '';
            $location = trim($_POST['location'] ?? '');
            if ($session_id <= 0 || !in_array($day, ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], true) || $start === '' || $end === '') {
                $errors[] = 'Meeting requires day and start/end times.';
            } else {
                $stmt = $db->prepare('INSERT INTO session_meeting_times (course_session_id, day_of_week, start_time, end_time, location) VALUES (?, ?, ?, ?, ?)');
                $ok = $stmt->execute([$session_id, $day, $start, $end, $location]);
                if ($ok) { 
                    $success = 'Meeting time added.'; 
                    $newId = (int)$db->lastInsertId();
                    $created = [
                        'id' => $newId,
                        'course_session_id' => $session_id,
                        'day_of_week' => $day,
                        'start_time' => $start,
                        'end_time' => $end,
                        'location' => $location,
                    ];
                } else { $errors[] = 'Failed to add meeting time.'; }
            }
        } elseif ($action === 'delete_meeting') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $errors[] = 'Invalid meeting ID.'; }
            else { $ok = $db->prepare('DELETE FROM session_meeting_times WHERE id = ?')->execute([$id]); if ($ok) { $success = 'Meeting time deleted.'; $deleted = ['id' => $id]; } else { $errors[] = 'Failed to delete meeting time.'; } }
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

$sessions = $db->query('SELECT cs.*, c.name AS course_name, c.duration_months AS duration FROM course_sessions cs JOIN courses c ON c.id = cs.course_id ORDER BY cs.year DESC, cs.term ASC, c.name ASC')->fetchAll(PDO::FETCH_ASSOC);

// Group meetings by session
$meetingRows = $db->query('SELECT * FROM session_meeting_times ORDER BY course_session_id ASC, FIELD(day_of_week, "Mon","Tue","Wed","Thu","Fri","Sat","Sun"), start_time ASC')->fetchAll(PDO::FETCH_ASSOC);
$meetingsBySession = [];
foreach ($meetingRows as $m) { $meetingsBySession[(int)$m['course_session_id']][] = $m; }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Course Sessions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4">Course Sessions</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) { echo '<div>'.htmlspecialchars($e).'</div>'; } ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">Create Session</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Course</label>
                        <select name="course_id" class="form-select" required>
                            <option value="">Select course</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?> (<?php echo (int)$c['duration_months']; ?>m)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <input type="number" name="year" class="form-control" value="<?php echo date('Y'); ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Term</label>
                        <select name="term" class="form-select" required>
                            <option value="">Select term</option>
                            <option value="H1">H1 (6m)</option>
                            <option value="H2">H2 (6m)</option>
                            <option value="Q1">Q1 (3m)</option>
                            <option value="Q2">Q2 (3m)</option>
                            <option value="Q3">Q3 (3m)</option>
                            <option value="Q4">Q4 (3m)</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Teacher ID (optional)</label>
                        <input type="number" name="teacher_id" class="form-control" placeholder="User ID">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select" required>
                            <option value="planned">Planned</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary" name="action" value="create_session" type="submit">Create</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">All Sessions</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead><tr><th>Course</th><th>Year</th><th>Term</th><th>Duration</th><th>Start</th><th>End</th><th>Status</th><th style="width: 420px;">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['course_name']); ?></td>
                            <td><?php echo (int)$s['year']; ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($s['term']); ?></span></td>
                            <td><?php echo (int)$s['duration']; ?>m</td>
                            <td><?php echo htmlspecialchars($s['start_date']); ?></td>
                            <td><?php echo htmlspecialchars($s['end_date']); ?></td>
                            <td><span class="badge bg-info"><?php echo htmlspecialchars($s['status']); ?></span></td>
                            <td>
                                <form method="post" class="row g-2 align-items-center">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                    <div class="col-md-3">
                                        <select name="course_id" class="form-select form-select-sm" required>
                                            <?php foreach ($courses as $c): ?>
                                                <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$c['id']===(int)$s['course_id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2"><input type="number" name="year" value="<?php echo (int)$s['year']; ?>" class="form-control form-control-sm" required></div>
                                    <div class="col-md-2">
                                        <select name="term" class="form-select form-select-sm" required>
                                            <?php foreach (['H1','H2','Q1','Q2','Q3','Q4'] as $t): ?>
                                                <option value="<?php echo $t; ?>" <?php echo $t===$s['term']?'selected':''; ?>><?php echo $t; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3"><input type="date" name="start_date" value="<?php echo htmlspecialchars($s['start_date']); ?>" class="form-control form-control-sm" required></div>
                                    <div class="col-md-3"><input type="date" name="end_date" value="<?php echo htmlspecialchars($s['end_date']); ?>" class="form-control form-control-sm" required></div>
                                    <div class="col-md-2">
                                        <select name="status" class="form-select form-select-sm" required>
                                            <?php foreach (['planned','active','completed','archived'] as $st): ?>
                                                <option value="<?php echo $st; ?>" <?php echo $st===$s['status']?'selected':''; ?>><?php echo ucfirst($st); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary" name="action" value="update_session" type="submit">Update</button>
                                        <button class="btn btn-sm btn-outline-danger" name="action" value="delete_session" type="submit" onclick="return confirm('Delete this session?');">Delete</button>
                                    </div>
                                </form>

                                <div class="mt-2">
                                    <form method="post" class="row g-2 align-items-center">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                                        <input type="hidden" name="course_session_id" value="<?php echo (int)$s['id']; ?>">
                                        <div class="col-md-2">
                                            <select name="day_of_week" class="form-select form-select-sm" required>
                                                <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
                                                    <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2"><input type="time" name="start_time" class="form-control form-control-sm" required></div>
                                        <div class="col-md-2"><input type="time" name="end_time" class="form-control form-control-sm" required></div>
                                        <div class="col-md-3"><input type="text" name="location" class="form-control form-control-sm" placeholder="Location (optional)"></div>
                                        <div class="col-md-3 d-flex gap-2">
                                            <button class="btn btn-sm btn-outline-success" name="action" value="add_meeting" type="submit">Add Meeting</button>
                                        </div>
                                    </form>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm">
                                            <thead><tr><th>Day</th><th>Start</th><th>End</th><th>Location</th><th>Actions</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($meetingsBySession[(int)$s['id']] ?? [] as $m): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($m['day_of_week']); ?></td>
                                                        <td><?php echo htmlspecialchars($m['start_time']); ?></td>
                                                        <td><?php echo htmlspecialchars($m['end_time']); ?></td>
                                                        <td><?php echo htmlspecialchars($m['location'] ?? ''); ?></td>
                                                        <td>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                                                                <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                                                                <button class="btn btn-sm btn-outline-danger" name="action" value="delete_meeting" type="submit" onclick="return confirm('Delete this meeting time?');">Delete</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($meetingsBySession[(int)$s['id']] ?? [])): ?>
                                                    <tr><td colspan="5" class="text-muted">No meeting times.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sessions)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No sessions yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// AJAX handlers for session and meeting forms with alerts and DOM updates
document.addEventListener('DOMContentLoaded', function(){
  const createForm = document.querySelector('.card.mb-4 form[method="post"]');
  const sessionsCard = document.querySelector('.card:last-of-type');
  const sessionsTbody = document.querySelector('.card:last-of-type table tbody');

  function alertFor(target, type, text) {
    const el = document.createElement('div');
    el.className = `alert alert-${type} mt-2`;
    el.textContent = text;
    target.appendChild(el);
    setTimeout(()=>{ el.remove(); }, 5000);
  }
  function setLoading(btn, loading, idleText) {
    if (!btn) return; btn.disabled = loading;
    btn.innerHTML = loading ? '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Working…' : idleText;
  }

  if (createForm) {
    createForm.addEventListener('submit', async function(e){
      e.preventDefault();
      if (!createForm.checkValidity()) { createForm.classList.add('was-validated'); alertFor(createForm.parentElement, 'danger', 'Please complete required fields.'); return; }
      const submitBtn = createForm.querySelector('button[type="submit"]');
      setLoading(submitBtn, true, 'Create');
      const fd = new FormData(createForm); fd.append('ajax', '1'); fd.append('action', 'create_session');
      try {
        const res = await fetch('course_sessions.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
        const json = await res.json();
        if (json && json.success) {
          alertFor(createForm.parentElement, 'success', json.message || 'Session created.');
          const csrfInput = createForm.querySelector('input[name="csrf_token"]');
          if (csrfInput && json.csrf_token) csrfInput.value = json.csrf_token;
          if (sessionsTbody && json.created) {
            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td>${json.created.course_name}</td>
              <td>${json.created.year}</td>
              <td><span class="badge bg-secondary">${json.created.term}</span></td>
              <td>${json.created.duration}m</td>
              <td>${json.created.start_date}</td>
              <td>${json.created.end_date}</td>
              <td><span class="badge bg-info">${json.created.status}</span></td>
              <td><span class="text-muted">Use refresh to manage new row.</span></td>`;
            sessionsTbody.prepend(tr);
          }
        } else {
          alertFor(createForm.parentElement, 'danger', (json && json.message) || 'Failed to create session.');
          const csrfInput = createForm.querySelector('input[name="csrf_token"]');
          if (csrfInput && json && json.csrf_token) csrfInput.value = json.csrf_token;
        }
      } catch (err) {
        alertFor(createForm.parentElement, 'danger', 'Network error. Please try again.');
      } finally { setLoading(submitBtn, false, 'Create'); }
    });
  }

  // Delegate update/delete session forms and add/delete meeting forms
  if (sessionsCard) {
    sessionsCard.addEventListener('submit', async function(e){
      const form = e.target.closest('form');
      if (!form) return;
      e.preventDefault();
      const actionBtn = form.querySelector('button[name="action"][type="submit"]:focus') || form.querySelector('button[name="action"][type="submit"]');
      const action = actionBtn ? actionBtn.value : (form.querySelector('button[name="action"]').value);
      const idleText = actionBtn ? actionBtn.textContent.trim() : 'Submit';
      setLoading(actionBtn, true, idleText);
      const fd = new FormData(form); fd.append('ajax', '1');
      try {
        const res = await fetch('course_sessions.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
        const json = await res.json();
        const cardBody = sessionsCard.querySelector('.card-body');
        if (json && json.success) {
          alertFor(cardBody, 'success', json.message || 'Saved.');
          if (json.updated && json.updated.id) {
            const tr = form.closest('tr');
            tr.querySelector('td:nth-child(1)').textContent = json.updated.course_name;
            tr.querySelector('td:nth-child(2)').textContent = json.updated.year;
            const termBadge = tr.querySelector('td:nth-child(3) .badge');
            if (termBadge) termBadge.textContent = json.updated.term;
            tr.querySelector('td:nth-child(4)').textContent = `${json.updated.duration}m`;
            tr.querySelector('td:nth-child(5)').textContent = json.updated.start_date;
            tr.querySelector('td:nth-child(6)').textContent = json.updated.end_date;
            const statusBadge = tr.querySelector('td:nth-child(7) .badge');
            if (statusBadge) statusBadge.textContent = json.updated.status;
          }
          if (json.deleted && json.deleted.id) {
            const tr = form.closest('tr'); tr.remove();
          }
          if (json.created && json.created.day_of_week) {
            // Add meeting row into the nearest meetings table
            const meetingsTable = form.closest('td').querySelector('table tbody');
            if (meetingsTable) {
              const mr = document.createElement('tr');
              mr.innerHTML = `
                <td>${json.created.day_of_week}</td>
                <td>${json.created.start_time}</td>
                <td>${json.created.end_time}</td>
                <td>${json.created.location || ''}</td>
                <td>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="${json.csrf_token}">
                    <input type="hidden" name="id" value="${json.created.id}">
                    <button class="btn btn-sm btn-outline-danger" name="action" value="delete_meeting" type="submit">Delete</button>
                  </form>
                </td>`;
              meetingsTable.prepend(mr);
            }
          }
          const csrfInps = form.querySelectorAll('input[name="csrf_token"]');
          csrfInps.forEach(inp => { if (json.csrf_token) inp.value = json.csrf_token; });
        } else {
          alertFor(cardBody, 'danger', (json && json.message) || 'Failed.');
          const csrfInps = form.querySelectorAll('input[name="csrf_token"]');
          csrfInps.forEach(inp => { if (json && json.csrf_token) inp.value = json.csrf_token; });
        }
      } catch (err) {
        alertFor(sessionsCard.querySelector('.card-body'), 'danger', 'Network error. Please try again.');
      } finally { setLoading(actionBtn, false, idleText); }
    });
  }
});
</script>
</body>
</html>
