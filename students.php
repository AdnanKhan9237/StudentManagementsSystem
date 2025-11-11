<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

// Initialize session via singleton and enforce access through Auth
$session = Session::getInstance();
$auth = new Auth();
// Superadmin and accounts can manage student accounts
$auth->requireRole(['superadmin','accounts']);

$db = (new Database())->getConnection();

// Ensure gender column exists on users for discount logic
try {
    $db->exec("ALTER TABLE users ADD COLUMN gender ENUM('male','female','other') NULL AFTER email");
} catch (Throwable $e) {
    // ignore if exists
}

// CSRF helper
function csrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

$errors = [];
$success = '';

// Handle create/update/delete actions, enforce role = 'student'
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
        || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
        || ($_POST['ajax'] ?? '') === '1';
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        if ($action === 'create') {
            $cnic = trim($_POST['cnic'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            if ($gender !== '' && !in_array($gender, ['male','female','other'], true)) { $gender = ''; }
            if ($cnic === '') {
                $errors[] = 'CNIC is required.';
            } else {
                // Ensure CNIC/username/email uniqueness
                $stmt = $db->prepare("SELECT id FROM users WHERE cnic = ? OR username = ? OR (email = ? AND ? != '') LIMIT 1");
                $stmt->execute([$cnic, $cnic, $email, $email]);
                if ($stmt->fetch()) {
                    $errors[] = 'A user with the same CNIC/username/email already exists.';
                } else {
                    $defaultPassword = 'Sostti123+';
                    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
                    $stmt = $db->prepare('INSERT INTO users (username, email, gender, cnic, password, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $ok = $stmt->execute([$cnic, $email !== '' ? $email : null, $gender !== '' ? $gender : null, $cnic, $hash, 'student', 1, date('Y-m-d H:i:s')]);
                    if ($ok) {
                        $newId = (int)$db->lastInsertId();
                        $success = 'Student account created with default password Sostti123+.';
                        $newStudent = ['id' => $newId, 'username' => $cnic, 'email' => $email !== '' ? $email : null, 'gender' => $gender !== '' ? $gender : null];
                    } else { $errors[] = 'Failed to create student account.'; }
                }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            if ($gender !== '' && !in_array($gender, ['male','female','other'], true)) { $gender = ''; }
            // Only allow updates to student accounts
            $check = $db->prepare('SELECT role FROM users WHERE id = ?');
            $check->execute([$id]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $errors[] = 'Account not found.';
            } elseif ($row['role'] !== 'student') {
                $errors[] = 'Only student accounts can be modified here.';
            } else {
                if ($username === '') {
                    $errors[] = 'Username is required.';
                } else {
                    // Keep update simple: username (CNIC) and optional email; password resets should be handled elsewhere
                    $email = trim($_POST['email'] ?? '');
                    $stmt = $db->prepare('UPDATE users SET username = ?, cnic = ?, email = ?, gender = ? WHERE id = ?');
                    $ok = $stmt->execute([$username, $username, $email !== '' ? $email : null, $gender !== '' ? $gender : null, $id]);
                    if ($ok) {
                        $success = 'Student account updated.';
                    } else {
                        $errors[] = 'Failed to update student account.';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $check = $db->prepare('SELECT role FROM users WHERE id = ?');
            $check->execute([$id]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $errors[] = 'Account not found.';
            } elseif ($row['role'] !== 'student') {
                $errors[] = 'Only student accounts can be deleted here.';
            } else {
                $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
                if ($stmt->execute([$id])) {
                    $success = 'Student account deleted.';
                } else {
                    $errors[] = 'Failed to delete student account.';
                }
            }
        }
    }

    if ($wantsJson) {
        header('Content-Type: application/json');
        // Refresh CSRF token for subsequent operations
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        echo json_encode([
            'success' => empty($errors),
            'message' => empty($errors) ? $success : ($errors[0] ?? 'Operation failed'),
            'csrf_token' => $_SESSION['csrf_token'],
            'data' => isset($newStudent) ? $newStudent : null,
        ]);
        exit();
    }
}

// Fetch student accounts
$listStmt = $db->query("SELECT id, username, email, gender, role FROM users WHERE role = 'student' ORDER BY id DESC");
$students = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch student profile data from students table
// Build label indices for joins
$courseIndex = [];
$sessionIndex = [];
$batchIndex = [];
$timingIndex = [];
try {
    foreach ($db->query('SELECT id, name FROM courses ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) as $r) { $courseIndex[(string)$r['id']] = (string)$r['name']; }
} catch (Throwable $e) { /* ignore */ }
try {
    foreach ($db->query('SELECT id, name FROM academic_sessions ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC) as $r) { $sessionIndex[(string)$r['id']] = (string)$r['name']; }
} catch (Throwable $e) { /* ignore */ }
try {
    foreach ($db->query('SELECT id, name FROM batches ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) as $r) { $batchIndex[(string)$r['id']] = (string)$r['name']; }
} catch (Throwable $e) { /* ignore */ }
try {
    foreach ($db->query('SELECT id, day_of_week, start_time, end_time, name FROM timings ORDER BY FIELD(day_of_week, "Daily","Mon","Tue","Wed","Thu","Fri","Sat","Sun"), start_time ASC')->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $label = ($t['name'] ? $t['name'].' - ' : '') . $t['day_of_week'] . ' ' . $t['start_time'] . '–' . $t['end_time'];
        $timingIndex[(string)$t['id']] = $label;
    }
} catch (Throwable $e) { /* ignore */ }

$profiles = $db->query('SELECT id, registration_number, fullname, gender, contact_personal, guardian_name, qualification, course_id, academic_session_id, batch_id, timing_id, admission_date, general_number, picture_path, created_at FROM students ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Accounts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/design-system.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3">Student Accounts</h1>
        <div class="d-flex gap-2">
          <a href="add_student.php" class="btn btn-primary"><i class="fa-solid fa-user-plus me-1"></i>Add Student</a>
          <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?>
                <div><?php echo htmlspecialchars($e); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    

    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <span>Student Accounts</span>
                <input type="text" id="studentFilter" class="form-control form-control-sm" placeholder="Search (username, email)">
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Gender</th>
                            <th>Role</th>
                            <th style="width: 240px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $s): ?>
                        <tr>
                            <td><?php echo (int)$s['id']; ?></td>
                            <td><?php echo htmlspecialchars($s['username']); ?></td>
                            <td><?php echo htmlspecialchars($s['email'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($s['gender'] ?? ''); ?></td>
                            <td><span class="badge bg-info text-dark">student</span></td>
                            <td>
                                <form method="post" class="row g-2 align-items-center">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                    <div class="col-md-4">
                                        <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($s['username']); ?>" placeholder="CNIC (used as username)">
                                    </div>
                                    <div class="col-md-4">
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($s['email'] ?? ''); ?>" placeholder="Email (optional)">
                                    </div>
                                    <div class="col-md-2">
                                        <select name="gender" class="form-select form-select-sm">
                                            <option value="" <?php echo ($s['gender'] ?? '')===''?'selected':''; ?>>Not specified</option>
                                            <option value="male" <?php echo ($s['gender'] ?? '')==='male'?'selected':''; ?>>Male</option>
                                            <option value="female" <?php echo ($s['gender'] ?? '')==='female'?'selected':''; ?>>Female</option>
                                            <option value="other" <?php echo ($s['gender'] ?? '')==='other'?'selected':''; ?>>Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary" name="action" value="update" type="submit">Update</button>
                                        <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this student account?');">Delete</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($students)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No student accounts found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- View Student Modal -->
    <div class="modal fade" id="viewStudentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fa-solid fa-user me-2"></i>Student Profile</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6"><strong>Reg No:</strong> <span id="vReg"></span></div>
              <div class="col-md-6"><strong>GR No:</strong> <span id="vGr"></span></div>
              <div class="col-md-6"><strong>Name:</strong> <span id="vName"></span></div>
              <div class="col-md-6"><strong>Gender:</strong> <span id="vGender"></span></div>
              <div class="col-md-6"><strong>Course:</strong> <span id="vCourse"></span></div>
              <div class="col-md-6"><strong>Session:</strong> <span id="vSession"></span></div>
              <div class="col-md-6"><strong>Batch:</strong> <span id="vBatch"></span></div>
              <div class="col-md-6"><strong>Timing:</strong> <span id="vTiming"></span></div>
              <div class="col-md-6"><strong>Contact:</strong> <span id="vContact"></span></div>
              <div class="col-md-6"><strong>Guardian:</strong> <span id="vGuardian"></span></div>
              <div class="col-md-6"><strong>Admission Date:</strong> <span id="vAdmission"></span></div>
              <div class="col-md-6"><strong>Qualification:</strong> <span id="vQualification"></span></div>
              <div class="col-md-12 text-muted"><small>Created: <span id="vCreated"></span></small></div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <a id="vUpdateLink" class="btn btn-primary" href="#"><i class="fa-solid fa-pen"></i> Update</a>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <span>Student Data</span>
                <input type="text" id="profileFilter" class="form-control form-control-sm" placeholder="Search (reg no, name, course, batch)">
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>Reg No</th>
                            <th>Name</th>
                            <th>Gender</th>
                            <th>Course</th>
                            <th>Session</th>
                            <th>Batch</th>
                            <th>Timing</th>
                            <th>Contact</th>
                            <th>Guardian</th>
                            <th>Admission</th>
                            <th>GR No</th>
                            <th style="width:160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="profilesTbody">
                        <?php foreach ($profiles as $p): ?>
                            <?php
                              $courseLabel = $courseIndex[(string)($p['course_id'] ?? '')] ?? '';
                              $sessionLabel = $sessionIndex[(string)($p['academic_session_id'] ?? '')] ?? '';
                              $batchLabel = $batchIndex[(string)($p['batch_id'] ?? '')] ?? '';
                              $timingLabel = $timingIndex[(string)($p['timing_id'] ?? '')] ?? '';
                            ?>
                            <tr
                              data-id="<?php echo (int)$p['id']; ?>"
                              data-registration-number="<?php echo htmlspecialchars($p['registration_number'] ?? ''); ?>"
                              data-fullname="<?php echo htmlspecialchars($p['fullname'] ?? ''); ?>"
                              data-gender="<?php echo htmlspecialchars($p['gender'] ?? ''); ?>"
                              data-course="<?php echo htmlspecialchars($courseLabel); ?>"
                              data-session="<?php echo htmlspecialchars($sessionLabel); ?>"
                              data-batch="<?php echo htmlspecialchars($batchLabel); ?>"
                              data-timing="<?php echo htmlspecialchars($timingLabel); ?>"
                              data-contact="<?php echo htmlspecialchars($p['contact_personal'] ?? ''); ?>"
                              data-guardian="<?php echo htmlspecialchars($p['guardian_name'] ?? ''); ?>"
                              data-admission="<?php echo htmlspecialchars($p['admission_date'] ?? ''); ?>"
                              data-grno="<?php echo htmlspecialchars($p['general_number'] ?? ''); ?>"
                              data-qualification="<?php echo htmlspecialchars($p['qualification'] ?? ''); ?>"
                              data-created="<?php echo htmlspecialchars($p['created_at'] ?? ''); ?>"
                            >
                                <td><?php echo htmlspecialchars($p['registration_number'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($p['fullname'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($p['gender'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($courseLabel); ?></td>
                                <td><?php echo htmlspecialchars($sessionLabel); ?></td>
                                <td><?php echo htmlspecialchars($batchLabel); ?></td>
                                <td><?php echo htmlspecialchars($timingLabel); ?></td>
                                <td><?php echo htmlspecialchars($p['contact_personal'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($p['guardian_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($p['admission_date'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($p['general_number'] ?? ''); ?></td>
                                <td class="text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary open-view-profile"><i class="fa-solid fa-eye"></i> View</button>
                                    <a class="btn btn-sm btn-outline-primary" href="add_student.php?edit_id=<?php echo (int)$p['id']; ?>"><i class="fa-solid fa-pen"></i> Update</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($profiles)): ?>
                            <tr><td colspan="11" class="text-center text-muted">No student data found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Client-side filter for Student List
(() => {
  const q = document.getElementById('studentFilter');
  const tbody = document.querySelector('table tbody');
  if (!q || !tbody) return;
  function apply() {
    const term = q.value.trim().toLowerCase();
    [...tbody.rows].forEach(row => {
      const text = row.textContent.toLowerCase();
      row.style.display = term === '' || text.includes(term) ? '' : 'none';
    });
  }
  q.addEventListener('input', apply);
})();

// Client-side filter for Student Data profiles table
(() => {
  const q = document.getElementById('profileFilter');
  const tbody = document.getElementById('profilesTbody');
  if (!q || !tbody) return;
  function apply() {
    const term = q.value.trim().toLowerCase();
    [...tbody.rows].forEach(row => {
      const text = row.textContent.toLowerCase();
      row.style.display = term === '' || text.includes(term) ? '' : 'none';
    });
  }
  q.addEventListener('input', apply);
})();

// View modal population
(() => {
  const modalEl = document.getElementById('viewStudentModal');
  if (!modalEl) return;
  const modal = new bootstrap.Modal(modalEl);
  const tbody = document.getElementById('profilesTbody');
  if (!tbody) return;
  tbody.addEventListener('click', (e) => {
    const btn = e.target.closest('.open-view-profile');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (!tr) return;
    const get = (k) => tr.dataset[k] || '';
    document.getElementById('vReg').textContent = get('registrationNumber');
    document.getElementById('vGr').textContent = get('grno');
    document.getElementById('vName').textContent = get('fullname');
    document.getElementById('vGender').textContent = get('gender');
    document.getElementById('vCourse').textContent = get('course');
    document.getElementById('vSession').textContent = get('session');
    document.getElementById('vBatch').textContent = get('batch');
    document.getElementById('vTiming').textContent = get('timing');
    document.getElementById('vContact').textContent = get('contact');
    document.getElementById('vGuardian').textContent = get('guardian');
    document.getElementById('vAdmission').textContent = get('admission');
    document.getElementById('vQualification').textContent = get('qualification');
    document.getElementById('vCreated').textContent = get('created');
    const id = tr.dataset.id || '';
    const updateLink = document.getElementById('vUpdateLink');
    updateLink.href = 'add_student.php?edit_id=' + encodeURIComponent(id);
    modal.show();
  });
})();
</script>
 
</body>
</html>
