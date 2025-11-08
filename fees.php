<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
// Only superadmin and accounts can manage fees
$auth->requireRole(['superadmin','accounts']);

$db = (new Database())->getConnection();

// Ensure fees table exists (basic schema)
$db->exec("CREATE TABLE IF NOT EXISTS fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    month VARCHAR(20) NOT NULL,
    status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add discount and course linkage columns if missing
try { $db->exec("ALTER TABLE fees ADD COLUMN course_id INT NULL AFTER student_id"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE fees ADD COLUMN applied_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER amount"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE fees ADD COLUMN applied_discount_reason VARCHAR(100) NULL AFTER applied_discount_percent"); } catch (Throwable $e) {}

// CSRF helpers
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

// Fetch students for dropdown
$studentsStmt = $db->query("SELECT id, username FROM users WHERE role = 'student' ORDER BY username ASC");
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
// Fetch courses for dropdown (needed to apply computer course discount)
$coursesStmt = $db->query("SELECT id, name FROM courses ORDER BY name ASC");
$courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = (
        stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || (($_POST['ajax'] ?? '') === '1')
    );
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        if ($action === 'create') {
            $student_id = (int)($_POST['student_id'] ?? 0);
            $amount = trim($_POST['amount'] ?? '');
            $course_id = (int)($_POST['course_id'] ?? 0);
            $month = trim($_POST['month'] ?? '');
            if ($student_id <= 0 || $amount === '' || $month === '') {
                $errors[] = 'Student, amount, and month are required.';
            } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                $errors[] = 'Amount must be a valid number.';
            } else {
                // Determine discount: 50% if student is female and selected course name contains 'computer'
                $discountPercent = 0.0;
                $discountReason = null;
                if ($course_id > 0) {
                    // Fetch student gender
                    $stuStmt = $db->prepare('SELECT gender FROM users WHERE id = ?');
                    $stuStmt->execute([$student_id]);
                    $stu = $stuStmt->fetch(PDO::FETCH_ASSOC);
                    $gender = strtolower(trim((string)($stu['gender'] ?? '')));
                    // Fetch course name
                    $cStmt = $db->prepare('SELECT name FROM courses WHERE id = ?');
                    $cStmt->execute([$course_id]);
                    $courseRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                    $courseName = (string)($courseRow['name'] ?? '');
                    $courseNameLc = strtolower(trim($courseName));
                    if ($gender === 'female' && strpos($courseNameLc, 'computer') !== false) {
                        $discountPercent = 50.0;
                        $discountReason = 'Female 50% Computer Course';
                    }
                }

                $baseAmount = (float)$amount;
                $finalAmount = $discountPercent > 0 ? round($baseAmount * (1.0 - $discountPercent / 100.0), 2) : $baseAmount;

                $stmt = $db->prepare('INSERT INTO fees (student_id, course_id, amount, applied_discount_percent, applied_discount_reason, month, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $ok = $stmt->execute([$student_id, $course_id > 0 ? $course_id : null, $finalAmount, $discountPercent, $discountReason, $month, 'unpaid', date('Y-m-d H:i:s')]);
                if ($ok) {
                    $success = 'Fee record created.';
                    $newId = (int)$db->lastInsertId();
                    $created = [
                        'id' => $newId,
                        'student_id' => $student_id,
                        'amount' => $finalAmount,
                        'applied_discount_percent' => $discountPercent,
                        'applied_discount_reason' => $discountReason,
                        'course_id' => $course_id,
                        'course_name' => $courseName ?? null,
                        'month' => $month,
                        'status' => 'unpaid',
                    ];
                } else { $errors[] = 'Failed to create fee record.'; }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $amount = trim($_POST['amount'] ?? '');
            $month = trim($_POST['month'] ?? '');
            $status = $_POST['status'] ?? 'unpaid';
            if ($id <= 0 || $amount === '' || $month === '') {
                $errors[] = 'Amount and month are required.';
            } elseif (!in_array($status, ['paid','unpaid'], true)) {
                $errors[] = 'Invalid status.';
            } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                $errors[] = 'Amount must be a valid number.';
            } else {
                $stmt = $db->prepare('UPDATE fees SET amount = ?, month = ?, status = ?, updated_at = ? WHERE id = ?');
                $ok = $stmt->execute([$amount, $month, $status, date('Y-m-d H:i:s'), $id]);
                if ($ok) { $success = 'Fee record updated.'; $updated = ['id' => $id, 'amount' => $amount, 'month' => $month, 'status' => $status]; } else { $errors[] = 'Failed to update fee record.'; }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $errors[] = 'Invalid record ID.'; }
            else {
                $stmt = $db->prepare('DELETE FROM fees WHERE id = ?');
                $ok = $stmt->execute([$id]);
                if ($ok) { $success = 'Fee record deleted.'; $deleted = ['id' => $id]; } else { $errors[] = 'Failed to delete fee record.'; }
        }
    }
    // Refresh CSRF and emit JSON for AJAX
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
}

// Fetch fee records
$feesStmt = $db->query("SELECT f.id, f.student_id, u.username AS student_name, f.amount, f.month, f.status, f.applied_discount_percent, f.applied_discount_reason, c.name AS course_name FROM fees f JOIN users u ON u.id = f.student_id LEFT JOIN courses c ON c.id = f.course_id ORDER BY f.id DESC");
$fees = $feesStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fees Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
 

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3">Fees Management</h1>
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
        <div class="card-header">Add Fee Record</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Student</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">Select student</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Course</label>
                        <select name="course_id" class="form-select">
                            <option value="">(optional) Select course</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Selecting a Computer course applies 50% discount for females.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Amount (PKR)</label>
                        <input type="text" name="amount" class="form-control" placeholder="e.g. 2500" required>
                        <div class="form-text">Currency: PKR</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Month</label>
                        <input type="text" name="month" class="form-control" placeholder="e.g. Jan 2025" required>
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary" type="submit">Add</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Fee Records</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Student</th>
                            <th>Amount (PKR)</th>
                            <th>Discount</th>
                            <th>Course</th>
                            <th>Month</th>
                            <th>Status</th>
                            <th style="width: 300px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fees as $f): ?>
                        <tr>
                            <td><?php echo (int)$f['id']; ?></td>
                            <td><?php echo htmlspecialchars($f['student_name']); ?></td>
                            <td><?php echo 'PKR ' . number_format((float)$f['amount'], 2); ?></td>
                            <td>
                                <?php if ((float)($f['applied_discount_percent'] ?? 0) > 0): ?>
                                    <span class="badge bg-info text-dark"><?php echo (float)$f['applied_discount_percent']; ?>%<?php echo $f['applied_discount_reason'] ? ' - '.htmlspecialchars($f['applied_discount_reason']) : ''; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($f['course_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($f['month']); ?></td>
                            <td>
                                <span class="badge <?php echo $f['status'] === 'paid' ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                    <?php echo htmlspecialchars($f['status']); ?>
                                </span>
                            </td>
                            <td>
                                <form method="post" class="row g-2 align-items-center">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$f['id']; ?>">
                                    <div class="col-md-3">
                                        <input type="text" name="amount" class="form-control" value="<?php echo htmlspecialchars($f['amount']); ?>" placeholder="Amount (PKR)">
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" name="month" class="form-control" value="<?php echo htmlspecialchars($f['month']); ?>" placeholder="Month">
                                    </div>
                                    <div class="col-md-3">
                                        <select name="status" class="form-select">
                                            <option value="unpaid" <?php echo $f['status']==='unpaid'?'selected':''; ?>>Unpaid</option>
                                            <option value="paid" <?php echo $f['status']==='paid'?'selected':''; ?>>Paid</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3 d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary" name="action" value="update" type="submit">Update</button>
                                        <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this fee record?');">Delete</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($fees)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No fee records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// AJAX handlers for fee create/update/delete with inline alerts and DOM updates
document.addEventListener('DOMContentLoaded', function(){
  const addForm = document.querySelector('.card.mb-4 form[method="post"]');
  const listCard = document.querySelector('.card:last-of-type');
  const tableBody = document.querySelector('table tbody');

  function alertFor(target, type, text) {
    const el = document.createElement('div');
    el.className = `alert alert-${type} mt-2`;
    el.textContent = text;
    target.appendChild(el);
    setTimeout(()=>{ el.remove(); }, 5000);
  }

  function setLoading(btn, loading, idleText) {
    if (!btn) return;
    btn.disabled = loading;
    btn.innerHTML = loading ? '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Working…' : idleText;
  }

  if (addForm) {
    addForm.addEventListener('submit', async function(e){
      e.preventDefault();
      if (!addForm.checkValidity()) { addForm.classList.add('was-validated'); alertFor(addForm.parentElement, 'danger', 'Please fill required fields.'); return; }
      const submitBtn = addForm.querySelector('button[type="submit"]');
      setLoading(submitBtn, true, 'Add');
      const fd = new FormData(addForm); fd.append('ajax', '1');
      try {
        const res = await fetch('fees.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
        const json = await res.json();
            if (json && json.success) {
          alertFor(addForm.parentElement, 'success', json.message || 'Created.');
          // Update CSRF
          const csrfInput = addForm.querySelector('input[name="csrf_token"]');
          if (csrfInput && json.csrf_token) csrfInput.value = json.csrf_token;
          // Append new row
          if (tableBody && json.created) {
            const studentSel = addForm.querySelector('select[name="student_id"]');
            const studentText = studentSel ? studentSel.options[studentSel.selectedIndex].textContent : '';
            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td>${json.created.id}</td>
              <td>${studentText}</td>
              <td>PKR ${(parseFloat(json.created.amount) || 0).toFixed(2)}</td>
              <td>${json.created.applied_discount_percent > 0 ? `<span class="badge bg-info text-dark">${json.created.applied_discount_percent}%${json.created.applied_discount_reason ? ' - ' + json.created.applied_discount_reason : ''}</span>` : '<span class="text-muted">None</span>'}</td>
              <td>${json.created.course_name || ''}</td>
              <td>${json.created.month}</td>
              <td><span class="badge ${json.created.status === 'paid' ? 'bg-success' : 'bg-warning text-dark'}">${json.created.status}</span></td>
              <td>
                <form method="post" class="row g-2 align-items-center">
                  <input type="hidden" name="csrf_token" value="${json.csrf_token}">
                  <input type="hidden" name="id" value="${json.created.id}">
                  <div class="col-md-3"><input type="text" name="amount" class="form-control" value="${json.created.amount}" placeholder="Amount (PKR)"></div>
                  <div class="col-md-3"><input type="text" name="month" class="form-control" value="${json.created.month}" placeholder="Month"></div>
                  <div class="col-md-3">
                    <select name="status" class="form-select">
                      <option value="unpaid" selected>Unpaid</option>
                      <option value="paid">Paid</option>
                    </select>
                  </div>
                  <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-sm btn-outline-primary" name="action" value="update" type="submit">Update</button>
                    <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit">Delete</button>
                  </div>
                </form>
              </td>`;
            tableBody.prepend(tr);
          }
        } else {
          alertFor(addForm.parentElement, 'danger', (json && json.message) || 'Failed to create.');
          const csrfInput = addForm.querySelector('input[name="csrf_token"]');
          if (csrfInput && json && json.csrf_token) csrfInput.value = json.csrf_token;
        }
      } catch (err) {
        alertFor(addForm.parentElement, 'danger', 'Network error. Please try again.');
      } finally {
        setLoading(submitBtn, false, 'Add');
      }
    });
  }

  if (listCard) {
    listCard.addEventListener('submit', async function(e){
      const form = e.target.closest('form');
      if (!form) return;
      e.preventDefault();
      const actionBtn = form.querySelector('button[name="action"][type="submit"]:focus') || form.querySelector('button[name="action"][type="submit"]');
      const action = actionBtn ? actionBtn.value : (form.querySelector('button[name="action"]').value);
      const idleText = action === 'update' ? 'Update' : 'Delete';
      setLoading(actionBtn, true, idleText);
      const fd = new FormData(form); fd.append('ajax', '1');
      try {
        const res = await fetch('fees.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
        const json = await res.json();
        if (json && json.success) {
          alertFor(listCard.querySelector('.card-body'), 'success', json.message || 'Saved.');
            if (json.updated && json.updated.id) {
              const tr = form.closest('tr');
            tr.querySelector('td:nth-child(3)').textContent = `PKR ${(parseFloat(json.updated.amount)||0).toFixed(2)}`;
              tr.querySelector('td:nth-child(6)').textContent = json.updated.month;
              const badgeCell = tr.querySelector('td:nth-child(7) .badge');
              if (badgeCell) {
                badgeCell.textContent = json.updated.status;
                badgeCell.className = `badge ${json.updated.status === 'paid' ? 'bg-success' : 'bg-warning text-dark'}`;
              }
            }
          if (json.deleted && json.deleted.id) {
            const tr = form.closest('tr'); tr.remove();
          }
          const csrfInput = form.querySelector('input[name="csrf_token"]');
          if (csrfInput && json.csrf_token) csrfInput.value = json.csrf_token;
        } else {
          alertFor(listCard.querySelector('.card-body'), 'danger', (json && json.message) || 'Failed.');
          const csrfInput = form.querySelector('input[name="csrf_token"]');
          if (csrfInput && json && json.csrf_token) csrfInput.value = json.csrf_token;
        }
      } catch (err) {
        alertFor(listCard.querySelector('.card-body'), 'danger', 'Network error. Please try again.');
      } finally {
        setLoading(actionBtn, false, idleText);
      }
    });
  }
});
</script>
</body>
</html>
