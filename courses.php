<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole(['superadmin','accounts']);

$db = (new Database())->getConnection();
// Schema: courses
$db->exec("CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    duration_months TINYINT NOT NULL,
    default_fee DECIMAL(10,2) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Remove legacy category column (user requested to remove category)
try {
    $db->exec("ALTER TABLE courses DROP COLUMN category");
} catch (Throwable $e) {
    // Ignore if column does not exist
}

function csrfToken() { if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf_token']; }
function verifyCsrf($t) { return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t); }

$errors = [];
$success = '';
// Detect if the request expects JSON (AJAX)
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
        $resultPayload = [];
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $duration = (int)($_POST['duration_months'] ?? 0);
            $fee = $_POST['default_fee'] !== '' ? (float)$_POST['default_fee'] : null;
            if ($name === '' || !in_array($duration, [3,6], true)) { $errors[] = 'Name and duration (3 or 6) are required.'; }
            else {
                $stmt = $db->prepare('INSERT INTO courses (name, duration_months, default_fee, created_at) VALUES (?, ?, ?, ?)');
                $ok = $stmt->execute([$name, $duration, $fee, date('Y-m-d H:i:s')]);
                if ($ok) {
                    $success = 'Course created.';
                    $resultPayload = ['id' => (int)$db->lastInsertId(), 'name' => $name, 'duration_months' => $duration, 'default_fee' => $fee];
                } else { $errors[] = 'Failed to create course.'; }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $duration = (int)($_POST['duration_months'] ?? 0);
            $fee = $_POST['default_fee'] !== '' ? (float)$_POST['default_fee'] : null;
            if ($id <= 0 || $name === '' || !in_array($duration, [3,6], true)) { $errors[] = 'Invalid input.'; }
            else {
                $stmt = $db->prepare('UPDATE courses SET name = ?, duration_months = ?, default_fee = ?, updated_at = ? WHERE id = ?');
                $ok = $stmt->execute([$name, $duration, $fee, date('Y-m-d H:i:s'), $id]);
                if ($ok) {
                    $success = 'Course updated.';
                    $resultPayload = ['id' => $id, 'name' => $name, 'duration_months' => $duration, 'default_fee' => $fee];
                } else { $errors[] = 'Failed to update course.'; }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $errors[] = 'Invalid course ID.'; }
            else {
                $ok = $db->prepare('DELETE FROM courses WHERE id = ?')->execute([$id]);
                if ($ok) { $success = 'Course deleted.'; $resultPayload = ['id' => $id]; } else { $errors[] = 'Failed to delete course.'; }
            }
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success !== '' && empty($errors),
            'message' => $success !== '' ? $success : (implode("\n", $errors) ?: ''),
            'csrf_token' => (string)($_SESSION['csrf_token'] ?? ''),
            'data' => $resultPayload,
        ]);
        exit();
    }
}

$courses = $db->query('SELECT * FROM courses ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Courses</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/design-system.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4">Courses</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) { echo '<div>'.htmlspecialchars($e).'</div>'; } ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">Create Course</div>
        <div class="card-body">
            <form method="post" id="createCourseForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Duration (months)</label>
                        <select name="duration_months" class="form-select" required>
                            <option value="">Select duration</option>
                            <option value="3">3 months</option>
                            <option value="6">6 months</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Default Fee (optional)</label>
                        <input type="number" step="0.01" name="default_fee" class="form-control" placeholder="e.g., 500.00">
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary" type="submit">Create</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">All Courses</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead><tr><th>Name</th><th>Duration</th><th>Default Fee</th><th style="width: 260px;">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($courses as $c): ?>
                        <tr data-course-id="<?php echo (int)$c['id']; ?>">
                            <td><?php echo htmlspecialchars($c['name']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo (int)$c['duration_months']; ?> months</span></td>
                            <td><?php echo $c['default_fee'] !== null ? number_format((float)$c['default_fee'], 2) : '-'; ?></td>
                            <td>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary open-update-modal"
                                            data-id="<?php echo (int)$c['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($c['name']); ?>"
                                            data-duration="<?php echo (int)$c['duration_months']; ?>"
                                            data-fee="<?php echo htmlspecialchars($c['default_fee'] ?? ''); ?>">Update</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger open-delete-modal"
                                            data-id="<?php echo (int)$c['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($c['name']); ?>">Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($courses)): ?>
                        <tr><td colspan="4" class="text-center text-muted">No courses yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const updateAllCsrfInputs = (newToken) => {
    if (!newToken) return;
    const tokenInputs = document.querySelectorAll('input[name="csrf_token"]');
    tokenInputs.forEach(i => { i.value = newToken; });
  };

  const submitAjaxForm = async (form, onSuccess) => {
    const fd = new FormData(form);
    fd.append('ajax', '1');
    try {
      const res = await fetch('courses.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
      const json = await res.json();
      if (json && json.csrf_token) updateAllCsrfInputs(json.csrf_token);
      if (json && json.success) {
        if (typeof onSuccess === 'function') onSuccess(json);
        else window.location.href = 'courses.php';
      } else {
        alert((json && json.message) || 'Operation failed');
      }
    } catch (err) {
      alert('Network error while processing request');
    }
  };

  // Create Course via AJAX
  const createForm = document.getElementById('createCourseForm');
  if (createForm) {
    createForm.addEventListener('submit', (e) => {
      e.preventDefault();
      submitAjaxForm(createForm, () => { window.location.href = 'courses.php'; });
    });
  }

  // Modal elements (initialize after DOM is fully parsed)
  const updateModalEl = document.getElementById('updateCourseModal');
  const deleteModalEl = document.getElementById('deleteCourseModal');
  const updateModal = updateModalEl ? new bootstrap.Modal(updateModalEl) : null;
  const deleteModal = deleteModalEl ? new bootstrap.Modal(deleteModalEl) : null;

  const updateForm = document.getElementById('updateCourseModalForm');
  const deleteForm = document.getElementById('deleteCourseModalForm');

  // Open Update Modal with prefilled data
  document.querySelectorAll('.open-update-modal').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!updateModal) return;
      const id = btn.dataset.id;
      const name = btn.dataset.name || '';
      const duration = btn.dataset.duration || '';
      const fee = btn.dataset.fee || '';
      updateForm.querySelector('input[name="id"]').value = id;
      updateForm.querySelector('input[name="name"]').value = name;
      const durSel = updateForm.querySelector('select[name="duration_months"]');
      if (durSel) durSel.value = duration;
      updateForm.querySelector('input[name="default_fee"]').value = fee;
      updateModal.show();
    });
  });

  // Open Delete Modal
  document.querySelectorAll('.open-delete-modal').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!deleteModal) return;
      const id = btn.dataset.id;
      const name = btn.dataset.name || '';
      deleteForm.querySelector('input[name="id"]').value = id;
      const nameHolder = deleteForm.querySelector('.course-name-holder');
      if (nameHolder) nameHolder.textContent = name;
      deleteModal.show();
    });
  });

  // Submit Update via AJAX
  if (updateForm) {
    updateForm.addEventListener('submit', (e) => {
      e.preventDefault();
      submitAjaxForm(updateForm, (json) => {
        updateModal && updateModal.hide();
        const data = json && json.data ? json.data : {};
        const id = data.id || updateForm.querySelector('input[name="id"]').value;
        const row = document.querySelector(`tr[data-course-id="${id}"]`);
        if (row) {
          // Update display cells
          if (row.children[0]) row.children[0].textContent = data.name || row.children[0].textContent;
          if (row.children[1]) row.children[1].innerHTML = `<span class="badge bg-secondary">${data.duration_months} months</span>`;
          if (row.children[2]) {
            const feeVal = (data.default_fee !== null && data.default_fee !== undefined && data.default_fee !== '') ? Number(data.default_fee).toFixed(2) : '-';
            row.children[2].textContent = feeVal;
          }
        }
      });
    });
  }

  // Submit Delete via AJAX
  if (deleteForm) {
    deleteForm.addEventListener('submit', (e) => {
      e.preventDefault();
      submitAjaxForm(deleteForm, (json) => {
        deleteModal && deleteModal.hide();
        const id = deleteForm.querySelector('input[name="id"]').value;
        const row = document.querySelector(`tr[data-course-id="${id}"]`);
        if (row) row.remove();
      });
    });
  }
});
</script>

<!-- Update Course Modal -->
<div class="modal fade" id="updateCourseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Update Course</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="updateCourseModalForm" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="">
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Duration (months)</label>
            <select name="duration_months" class="form-select" required>
              <option value="3">3 months</option>
              <option value="6">6 months</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Default Fee (optional)</label>
            <input type="number" step="0.01" name="default_fee" class="form-control" placeholder="e.g., 500.00">
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
<div class="modal fade" id="deleteCourseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="deleteCourseModalForm" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="">
          <p>Are you sure you want to delete <strong class="course-name-holder"></strong>?</p>
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
