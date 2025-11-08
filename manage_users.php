<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Session.php';

$auth = new Auth();
$session = Session::getInstance();
$db = (new Database())->getConnection();

$auth->requireLogin();
$auth->requireRole('superadmin');

// CSRF token
if (!$session->has('csrf_token')) {
    $session->set('csrf_token', bin2hex(random_bytes(32)));
}
$csrfToken = (string)$session->get('csrf_token');

// Roles we allow managing here
$manageableRoles = ['teacher','accounts'];

function isProtectedUser(array $u): bool {
    $role = isset($u['role']) ? trim((string)$u['role']) : '';
    $email = isset($u['email']) ? trim((string)$u['email']) : '';
    $username = isset($u['username']) ? trim((string)$u['username']) : '';
    return ($role === 'superadmin') || ($email === 'superadmin@sms.com') || ($username === 'superadmin');
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
        || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
        || ($_POST['ajax'] ?? '') === '1';
    $action = $_POST['action'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            exit();
        } else {
            $session->setFlash('error', 'Invalid CSRF token.');
            header('Location: manage_users.php');
            exit();
        }
    }

    try {
        // Track which role view to return to after processing
        $redirectRole = null;
        $resultPayload = [];
        if ($action === 'create') {
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $cnic = trim((string)($_POST['cnic'] ?? ''));
            $role = trim((string)($_POST['role'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $password = (string)($_POST['password'] ?? '');

            if (!in_array($role, $manageableRoles, true)) {
                throw new Exception('Invalid role for management.');
            }
            if ($username === '' || strlen($username) < 3) {
                throw new Exception('Username must be at least 3 characters.');
            }
            if ($password === '' || strlen($password) < 6) {
                throw new Exception('Password must be at least 6 characters.');
            }

            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR (email = ? AND ? != '') OR (cnic = ? AND ? != '') LIMIT 1");
            $stmt->execute([$username, $email, $email, $cnic, $cnic]);
            if ($stmt->fetch()) {
                throw new Exception('A user with the same username/email/CNIC already exists.');
            }

            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, email, cnic, password, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $email !== '' ? $email : null, $cnic !== '' ? $cnic : null, $hashed, $role, $isActive, date('Y-m-d H:i:s')]);
            $session->setFlash('success', ucfirst($role) . ' user created successfully.');
            $resultPayload = ['id' => (int)$db->lastInsertId(), 'username' => $username, 'email' => $email !== '' ? $email : null, 'cnic' => $cnic !== '' ? $cnic : null, 'role' => $role, 'is_active' => $isActive];
            $redirectRole = $role;
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { throw new Exception('Invalid user ID.'); }
            $stmt = $db->prepare('SELECT id, username, email, cnic, role, is_active FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$userRow) { throw new Exception('User not found.'); }
            if (isProtectedUser($userRow)) { throw new Exception('Protected user cannot be edited.'); }

            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $cnic = trim((string)($_POST['cnic'] ?? ''));
            $role = trim((string)($_POST['role'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $password = (string)($_POST['password'] ?? '');

            if (!in_array($role, $manageableRoles, true)) {
                throw new Exception('Invalid role for management.');
            }

            $stmt = $db->prepare("SELECT id FROM users WHERE (username = ? OR (email = ? AND ? != '') OR (cnic = ? AND ? != '')) AND id <> ? LIMIT 1");
            $stmt->execute([$username, $email, $email, $cnic, $cnic, $id]);
            if ($stmt->fetch()) { throw new Exception('Another user with the same username/email/CNIC exists.'); }

            if ($password !== '') {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('UPDATE users SET username = ?, email = ?, cnic = ?, role = ?, is_active = ?, password = ? WHERE id = ?');
                $stmt->execute([$username, $email !== '' ? $email : null, $cnic !== '' ? $cnic : null, $role, $isActive, $hashed, $id]);
            } else {
                $stmt = $db->prepare('UPDATE users SET username = ?, email = ?, cnic = ?, role = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$username, $email !== '' ? $email : null, $cnic !== '' ? $cnic : null, $role, $isActive, $id]);
            }
            $session->setFlash('success', ucfirst($role) . ' user updated successfully.');
            $resultPayload = ['id' => $id, 'username' => $username, 'email' => $email !== '' ? $email : null, 'cnic' => $cnic !== '' ? $cnic : null, 'role' => $role, 'is_active' => $isActive];
            $redirectRole = $role;
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { throw new Exception('Invalid user ID.'); }
            $stmt = $db->prepare('SELECT id, username, email, role FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$userRow) { throw new Exception('User not found.'); }
            if (isProtectedUser($userRow)) { throw new Exception('Protected user cannot be deleted.'); }
            if (!in_array((string)$userRow['role'], $manageableRoles, true)) {
                throw new Exception('Only teacher/accounts users can be deleted here.');
            }
            $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $session->setFlash('success', ucfirst((string)$userRow['role']) . ' user deleted successfully.');
            $resultPayload = ['id' => $id];
            $redirectRole = (string)$userRow['role'];
        }
    } catch (Exception $e) {
        $session->setFlash('error', $e->getMessage());
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit();
        }
    }

    $session->set('csrf_token', bin2hex(random_bytes(32)));
    if ($wantsJson) {
        header('Content-Type: application/json');
        $message = $session->getFlash('success') ?: $session->getFlash('error') ?: '';
        $redirectUrl = isset($redirectRole) && in_array($redirectRole, $manageableRoles, true)
            ? ('manage_users.php?role=' . urlencode($redirectRole))
            : 'manage_users.php';
        echo json_encode([
            'success' => $message !== '' && strpos($message, 'successfully') !== false,
            'message' => $message,
            'redirect' => $redirectUrl,
            'csrf_token' => (string)$session->get('csrf_token'),
            'data' => $resultPayload,
        ]);
        exit();
    } else {
        // Preserve selected role view on redirect when possible
        if (isset($redirectRole) && in_array($redirectRole, $manageableRoles, true)) {
            header('Location: manage_users.php?role=' . urlencode($redirectRole));
        } else {
            header('Location: manage_users.php');
        }
        exit();
    }
}

// Fetch all users combined
$stmt = $db->prepare('SELECT id, username, email, cnic, role, is_active, created_at FROM users ORDER BY id ASC');
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$success = $session->getFlash('success');
$error = $session->getFlash('error');

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editUser = null;
if ($editId > 0) {
    foreach ($users as $u) {
        if ((int)$u['id'] === $editId) { $editUser = $u; break; }
    }
}
// Show form when adding or editing
$showAdd = isset($_GET['add']) && $_GET['add'] === '1';
$showForm = $showAdd || ($editUser !== null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users (Teacher & Accounts)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0"><i class="fa-solid fa-user-cog me-2"></i>Manage Users</h2>
        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Dashboard</a>
            <?php if ($showForm): ?>
                <a href="manage_users.php" class="btn btn-outline-secondary btn-sm">Close</a>
            <?php else: ?>
                <a href="manage_users.php?add=1" class="btn btn-primary btn-sm"><i class="fa-solid fa-user-plus me-1"></i>Add User</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="row g-4">
        <?php if ($showForm): ?>
        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header"><?php echo $editUser ? 'Edit User' : 'Create User'; ?></div>
                <div class="card-body">
                    <form method="post" action="manage_users.php" id="userForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <?php if ($editUser): ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
                        <?php else: ?>
                            <input type="hidden" name="action" value="create">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required minlength="3" value="<?php echo htmlspecialchars($editUser['username'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">CNIC</label>
                            <input type="text" name="cnic" class="form-control" value="<?php echo htmlspecialchars($editUser['cnic'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select" required>
                                <option value="teacher" <?php echo ($editUser['role'] ?? '')==='teacher'?'selected':''; ?>>Teacher</option>
                                <option value="accounts" <?php echo ($editUser['role'] ?? '')==='accounts'?'selected':''; ?>>Accounts</option>
                            </select>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?php echo isset($editUser['is_active']) && (int)$editUser['is_active'] === 1 ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password <?php echo $editUser ? '(leave blank to keep unchanged)' : ''; ?></label>
                            <input type="password" name="password" class="form-control" <?php echo $editUser ? '' : 'required minlength="6"'; ?>>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-2"></i><?php echo $editUser ? 'Save Changes' : 'Create User'; ?></button>
                            <a href="manage_users.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-12 <?php echo $showForm ? 'col-lg-7' : ''; ?>">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>All Users</span>
                        <div class="d-flex gap-2 align-items-center">
                            <input type="text" id="userFilter" class="form-control form-control-sm" placeholder="Search (name, email, CNIC)">
                            <select id="roleFilter" class="form-select form-select-sm" style="max-width: 160px;">
                                <option value="">All Roles</option>
                                <option value="teacher">Teacher</option>
                                <option value="accounts">Accounts</option>
                                <option value="superadmin">Superadmin</option>
                                <option value="student">Student</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>CNIC</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr data-role="<?php echo htmlspecialchars((string)$u['role']); ?>">
                                    <td><?php echo (int)$u['id']; ?></td>
                                    <td><?php echo htmlspecialchars($u['username']); ?></td>
                                    <td><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($u['cnic'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst((string)$u['role'])); ?></td>
                                    <td>
                                        <?php if ((int)($u['is_active'] ?? 0) === 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (isProtectedUser($u) || !in_array((string)$u['role'], $manageableRoles, true)): ?>
                                            <span class="text-muted">Protected</span>
                                        <?php else: ?>
                                            <a href="manage_users.php?edit=<?php echo (int)$u['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen-to-square"></i></a>
                                            <form method="post" action="manage_users.php" class="d-inline delete-user-form" onsubmit="return confirm('Delete this user?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3">Note: Only superadmin can manage Teacher and Accounts users here. Other roles are read-only in this list.</p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Simple client-side filtering for Manage Users table
(() => {
  const q = document.getElementById('userFilter');
  const roleSel = document.getElementById('roleFilter');
  const tbody = document.querySelector('table tbody');
  if (!q || !roleSel || !tbody) return;

  function matches(row, term, role) {
    const text = row.textContent.toLowerCase();
    const r = (row.getAttribute('data-role') || '').toLowerCase();
    const okText = term === '' || text.includes(term);
    const okRole = role === '' || r === role;
    return okText && okRole;
  }

  function apply() {
    const term = q.value.trim().toLowerCase();
    const role = roleSel.value.trim().toLowerCase();
    [...tbody.rows].forEach(row => {
      row.style.display = matches(row, term, role) ? '' : 'none';
    });
  }

  q.addEventListener('input', apply);
  roleSel.addEventListener('change', apply);
})();
</script>
<script>
// AJAX handlers for Manage Users
(() => {
  const userForm = document.getElementById('userForm');
  if (userForm) {
    userForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(userForm);
      fd.append('ajax', '1');
      try {
        const res = await fetch('manage_users.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
        const json = await res.json();
        if (json.csrf_token) {
          const tokenInputs = document.querySelectorAll('input[name="csrf_token"]');
          tokenInputs.forEach(i => i.value = json.csrf_token);
        }
        if (json.success) {
          // Redirect to maintain role filter and reflect updates
          window.location.href = json.redirect || 'manage_users.php';
        } else {
          alert(json.message || 'Operation failed');
        }
      } catch (err) {
        alert('Network error while saving user');
      }
    });
  }

  document.querySelectorAll('form.delete-user-form').forEach(form => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      fd.append('ajax', '1');
      try {
        const res = await fetch('manage_users.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
        const json = await res.json();
        if (json.csrf_token) {
          const tokenInputs = document.querySelectorAll('input[name="csrf_token"]');
          tokenInputs.forEach(i => i.value = json.csrf_token);
        }
        if (json.success) {
          // Remove the row from the table
          const row = form.closest('tr');
          if (row) row.remove();
        } else {
          alert(json.message || 'Delete failed');
        }
      } catch (err) {
        alert('Network error while deleting');
      }
    });
  });
})();
</script>
</body>
</html>
