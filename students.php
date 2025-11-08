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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Accounts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
    </style>
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3">Student Accounts</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
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
        <div class="card-header">Create Student Account</div>
        <div class="card-body">
            <form method="post" id="studentCreateForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">CNIC</label>
                        <input type="text" name="cnic" class="form-control" required placeholder="e.g. 12345-1234567-1" pattern="^[0-9]{5}-[0-9]{7}-[0-9]$" title="Format: 12345-1234567-1">
                        <div class="form-text">This will be used as the student's username.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email (optional)</label>
                        <input type="email" name="email" class="form-control" placeholder="student@example.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">Not specified</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="student" disabled>
                        <div class="form-text">Default password is <code>Sostti123+</code>. Student must change it on first login.</div>
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary" type="submit">Create</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <span>Student List</span>
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
                        <tr><td colspan="4" class="text-center text-muted">No student accounts found.</td></tr>
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
</script>
<script>
// AJAX create for student accounts
(() => {
  const form = document.getElementById('studentCreateForm');
  const tbody = document.querySelector('table tbody');
  if (!form || !tbody) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    fd.append('ajax', '1');
    try {
      const res = await fetch('students.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
      const json = await res.json();
      if (json.csrf_token) {
        const tokenInputs = document.querySelectorAll('input[name="csrf_token"]');
        tokenInputs.forEach(i => i.value = json.csrf_token);
      }
      if (json.success) {
        // Append new row to the list
        const d = json.data;
        if (d) {
          const tr = document.createElement('tr');
          tr.innerHTML = `<td>${d.id}</td><td>${d.username}</td><td>${d.email ?? ''}</td><td>${d.username}</td>`;
          tbody.prepend(tr);
        }
        form.reset();
      } else {
        alert(json.message || 'Failed to create student');
      }
    } catch (err) {
      alert('Network error while creating student');
    }
  });
})();
</script>
</body>
</html>
