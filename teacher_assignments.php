<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$auth->requireRole('superadmin');

$db = (new Database())->getConnection();

// Mapping tables: teacher to batch (with role) and allowed timings per batch per teacher
$db->exec("CREATE TABLE IF NOT EXISTS teacher_batches (
  teacher_id INT NOT NULL,
  batch_id INT NOT NULL,
  role ENUM('primary','secondary') NOT NULL DEFAULT 'primary',
  assigned_at DATETIME NOT NULL,
  PRIMARY KEY (teacher_id, batch_id),
  INDEX (batch_id), INDEX (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Backfill role column for legacy installs where teacher_batches existed without role
try { $db->exec("ALTER TABLE teacher_batches ADD COLUMN role ENUM('primary','secondary') NOT NULL DEFAULT 'primary' AFTER batch_id"); } catch (Throwable $e) { /* ignore if exists */ }
$db->exec("CREATE TABLE IF NOT EXISTS teacher_batch_timings (
  teacher_id INT NOT NULL,
  batch_id INT NOT NULL,
  timing_id INT NOT NULL,
  PRIMARY KEY (teacher_id, batch_id, timing_id),
  INDEX (teacher_id), INDEX (batch_id), INDEX (timing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Load teachers, batches, timings
$teachers = $db->query("SELECT id, username FROM users WHERE role = 'teacher' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
$batches = $db->query("SELECT id, name, timing_id FROM batches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$timings = $db->query("SELECT id, name, day_of_week, start_time, end_time FROM timings ORDER BY FIELD(day_of_week,'Daily','Mon','Tue','Wed','Thu','Fri','Sat','Sun'), start_time ASC")->fetchAll(PDO::FETCH_ASSOC);

// Build batch -> timing IDs map using junction table, fallback to legacy column
$batchTimingIds = [];
if (!empty($batches)) {
  $batchIds = implode(',', array_map('intval', array_column($batches, 'id')));
  if ($batchIds !== '') {
    $rs = $db->query("SELECT batch_id, timing_id FROM batch_timings WHERE batch_id IN ($batchIds)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rs as $row) {
      $bid = (int)$row['batch_id']; $tid = (int)$row['timing_id'];
      if (!isset($batchTimingIds[$bid])) { $batchTimingIds[$bid] = []; }
      $batchTimingIds[$bid][] = $tid;
    }
  }
  foreach ($batches as $b) {
    $bid = (int)$b['id']; $legacyTid = (int)$b['timing_id'];
    if (empty($batchTimingIds[$bid]) && $legacyTid > 0) { $batchTimingIds[$bid] = [$legacyTid]; }
  }
}

$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'create') {
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $batch_id = (int)($_POST['batch_id'] ?? 0);
    $role = ($_POST['role'] ?? 'primary') === 'secondary' ? 'secondary' : 'primary';
    $timing_ids = $_POST['timing_ids'] ?? [];
    if (!is_array($timing_ids)) { $timing_ids = [$timing_ids]; }
    $timing_ids = array_values(array_filter(array_map('intval', $timing_ids)));

    if ($teacher_id <= 0 || $batch_id <= 0 || empty($timing_ids)) {
      $error = 'Teacher, batch, and at least one timing are required.';
    } else {
      // Validate selected timings belong to the batch
      $allowed = $batchTimingIds[$batch_id] ?? [];
      $invalid = array_diff($timing_ids, $allowed);
      if (!empty($invalid)) {
        $error = 'One or more selected timings are not part of the batch.';
      } else {
        $stmt = $db->prepare("INSERT INTO teacher_batches (teacher_id, batch_id, role, assigned_at) VALUES (?, ?, ?, ?)\n                               ON DUPLICATE KEY UPDATE role = VALUES(role), assigned_at = VALUES(assigned_at)");
        $ok = $stmt->execute([$teacher_id, $batch_id, $role, date('Y-m-d H:i:s')]);
        if ($ok) {
          // Replace timing associations
          $db->prepare('DELETE FROM teacher_batch_timings WHERE teacher_id = ? AND batch_id = ?')->execute([$teacher_id, $batch_id]);
          $ins = $db->prepare('INSERT IGNORE INTO teacher_batch_timings (teacher_id, batch_id, timing_id) VALUES (?, ?, ?)');
          foreach ($timing_ids as $tid) { $ins->execute([$teacher_id, $batch_id, $tid]); }
          $message = 'Assignment saved.';
        } else { $error = 'Failed to save assignment.'; }
      }
    }
  } elseif ($action === 'delete') {
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $batch_id = (int)($_POST['batch_id'] ?? 0);
    if ($teacher_id > 0 && $batch_id > 0) {
      $db->prepare('DELETE FROM teacher_batch_timings WHERE teacher_id = ? AND batch_id = ?')->execute([$teacher_id, $batch_id]);
      $db->prepare('DELETE FROM teacher_batches WHERE teacher_id = ? AND batch_id = ?')->execute([$teacher_id, $batch_id]);
      $message = 'Assignment removed.';
    } else { $error = 'Missing teacher or batch.'; }
  }
}

// Existing assignments
$assignments = $db->query("SELECT tb.teacher_id, tb.batch_id, tb.role, u.username AS teacher_name, b.name AS batch_name\n                           FROM teacher_batches tb\n                           JOIN users u ON u.id = tb.teacher_id\n                           JOIN batches b ON b.id = tb.batch_id\n                           ORDER BY b.name ASC, u.username ASC")->fetchAll(PDO::FETCH_ASSOC);

// Build timing label map
$timingLabel = fn($t)=> (($t['name'] ?? '') !== ''
  ? ($t['name'].' ('.$t['day_of_week'].' '.substr($t['start_time'],0,5).'-'.substr($t['end_time'],0,5).')')
  : ($t['day_of_week'].' '.substr($t['start_time'],0,5).'-'.substr($t['end_time'],0,5)));
$timingMap = [];
foreach ($timings as $t) { $timingMap[(int)$t['id']] = $timingLabel($t); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Assign Teachers to Batches</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/design-system.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
<main class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4>Assign Teachers to Batches</h4>
    <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
  </div>

  <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

  <form method="post" class="card mb-4">
    <div class="card-body">
      <input type="hidden" name="action" value="create">
      <div class="row g-2">
        <div class="col-md-4">
          <label class="form-label">Teacher</label>
          <select name="teacher_id" class="form-select" required>
            <option value="">Select teacher</option>
            <?php foreach ($teachers as $t): ?>
              <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['username']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Batch</label>
          <select id="batchSelect" name="batch_id" class="form-select" required>
            <option value="">Select batch</option>
            <?php foreach ($batches as $b): ?>
              <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Role</label>
          <select name="role" class="form-select" required>
            <option value="primary">Primary</option>
            <option value="secondary">Secondary</option>
          </select>
        </div>
      </div>

      <div class="mt-3">
        <label class="form-label">Allowed Timings for Selected Batch</label>
        <div id="timingsContainer" class="row row-cols-1 row-cols-md-3 g-2"></div>
        <div class="form-text">Teachers can only act on students whose timing matches these selections.</div>
      </div>

      <div class="mt-3">
        <button type="submit" class="btn btn-primary">Save Assignment</button>
      </div>
    </div>
  </form>

  <div class="card">
    <div class="card-body">
      <h6 class="mb-3">Existing Assignments</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>Batch</th><th>Teacher</th><th>Role</th><th>Timings</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($assignments as $a): 
              $btids = $db->prepare('SELECT timing_id FROM teacher_batch_timings WHERE teacher_id = ? AND batch_id = ?');
              $btids->execute([(int)$a['teacher_id'], (int)$a['batch_id']]);
              $timingIds = array_map('intval', array_column($btids->fetchAll(PDO::FETCH_ASSOC), 'timing_id'));
              $labels = array_map(function($id) use ($timingMap){ return $timingMap[$id] ?? ('Timing '.$id); }, $timingIds);
            ?>
            <tr>
              <td><?php echo htmlspecialchars($a['batch_name']); ?></td>
              <td><?php echo htmlspecialchars($a['teacher_name']); ?></td>
              <td><span class="badge bg-secondary"><?php echo htmlspecialchars($a['role']); ?></span></td>
              <td><?php echo htmlspecialchars(implode(', ', $labels)); ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Remove this assignment?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="teacher_id" value="<?php echo (int)$a['teacher_id']; ?>">
                  <input type="hidden" name="batch_id" value="<?php echo (int)$a['batch_id']; ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<script>
  const batchTimingIndex = <?php echo json_encode($batchTimingIds, JSON_UNESCAPED_UNICODE); ?>;
  const timingMap = <?php echo json_encode($timingMap, JSON_UNESCAPED_UNICODE); ?>;
  const batchSel = document.getElementById('batchSelect');
  const container = document.getElementById('timingsContainer');
  function renderTimingCheckboxes(batchId) {
    container.innerHTML = '';
    const allowed = batchTimingIndex[String(batchId)] || [];
    if (!allowed.length) {
      const div = document.createElement('div'); div.className = 'text-danger';
      div.textContent = 'No timings configured for this batch.';
      container.appendChild(div);
      return;
    }
    allowed.forEach(tid => {
      const col = document.createElement('div'); col.className = 'col';
      const label = timingMap[tid] || ('Timing ' + tid);
      col.innerHTML = `
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="timing_ids[]" value="${tid}" id="timing_${tid}">
          <label class="form-check-label" for="timing_${tid}">${label}</label>
        </div>
      `;
      container.appendChild(col);
    });
  }
  batchSel && batchSel.addEventListener('change', (e) => {
    const bid = e.target.value;
    if (bid) renderTimingCheckboxes(bid); else container.innerHTML = '';
  });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

