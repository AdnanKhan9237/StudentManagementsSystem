<?php
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';

$auth = new Auth();
$session = Session::getInstance();
$db = (new Database())->getConnection();

// Require login
$auth->requireLogin();

// Get current user
$currentUser = $auth->getCurrentUser();
// Prefer session role (set during login), fallback to DB field
$role = (string) $session->get('role');
if ($role === '') {
    $role = isset($currentUser['role']) ? (string)$currentUser['role'] : 'user';
}

// Get flash messages
$success = $session->getFlash('success');

// Track session start and activity for timing metrics
$loginTime = (int)($session->get('login_time') ?? 0);
if ($loginTime <= 0) {
    $loginTime = time();
    $session->set('login_time', $loginTime);
}
// Do not override internal session timing keys; constructor maintains __last_activity

// Lightweight JSON metrics endpoint for batch queue status
$wantsJson = (
    stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    || (($_GET['ajax'] ?? '') === '1')
);
// Removed legacy metrics endpoint.

// Settings table for system-wide values (e.g., general number start)
$db->exec("CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(50) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Handle superadmin settings update (General Number start)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($role === 'superadmin')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'set_general_number') {
        $start = (int)($_POST['general_number_start'] ?? 0);
        if ($start > 0) {
            $stmt = $db->prepare('REPLACE INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, ?)');
            $stmt->execute(['general_number_next', (string)$start, date('Y-m-d H:i:s')]);
            $success = 'General number starting value updated.';
        }
    }
}

// Read current next general number for display
$generalNext = 0;
try {
    $stmtSettings = $db->prepare('SELECT value FROM settings WHERE `key` = ?');
    $stmtSettings->execute(['general_number_next']);
    $generalNext = (int)($stmtSettings->fetchColumn() ?: 0);
} catch (Throwable $e) { /* ignore */ }

// Aggregate counts for dashboard metrics
function countTable(PDO $db, string $table): int {
    try { return (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
// Active students are those admitted with a GR number assigned
$countActiveStudents = 0;
try {
    $countActiveStudents = (int)$db->query("SELECT COUNT(*) FROM students WHERE general_number IS NOT NULL")->fetchColumn();
} catch (Throwable $e) { $countActiveStudents = 0; }
$countCourses = countTable($db, 'courses');
$countTimings = countTable($db, 'timings');
$countBatches = countTable($db, 'batches');
$countSessions = countTable($db, 'academic_sessions');
// Unacknowledged notifications count for admin roles
$unackNotifications = 0;
try {
    $unackNotifications = (int)($db->query('SELECT COUNT(*) FROM notifications WHERE acknowledged = 0')->fetchColumn() ?: 0);
} catch (Throwable $e) { $unackNotifications = 0; }
// Scope metrics to teacher assignments when role is teacher
if ($role === 'teacher') {
    $teacherId = (int)$session->getUserId();
    // Students with GR number that this teacher can access
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM students s
             WHERE s.general_number IS NOT NULL
               AND EXISTS (
                 SELECT 1 FROM teacher_batches tb
                 WHERE tb.teacher_id = ? AND tb.batch_id = s.batch_id
               )
               AND EXISTS (
                 SELECT 1 FROM teacher_batch_timings tbt
                 WHERE tbt.teacher_id = ? AND tbt.batch_id = s.batch_id AND tbt.timing_id = s.timing_id
               )"
        );
        $stmt->execute([$teacherId, $teacherId]);
        $countActiveStudents = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) { $countActiveStudents = 0; }

    // Distinct courses among teacher-visible students
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT s.course_id) FROM students s
             WHERE EXISTS (
               SELECT 1 FROM teacher_batches tb
               WHERE tb.teacher_id = ? AND tb.batch_id = s.batch_id
             )
             AND EXISTS (
               SELECT 1 FROM teacher_batch_timings tbt
               WHERE tbt.teacher_id = ? AND tbt.batch_id = s.batch_id AND tbt.timing_id = s.timing_id
             )"
        );
        $stmt->execute([$teacherId, $teacherId]);
        $countCourses = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) { $countCourses = 0; }

    // Assigned timings
    try {
        $stmt = $db->prepare('SELECT COUNT(DISTINCT timing_id) FROM teacher_batch_timings WHERE teacher_id = ?');
        $stmt->execute([$teacherId]);
        $countTimings = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) { $countTimings = 0; }

    // Assigned batches
    try {
        $stmt = $db->prepare('SELECT COUNT(DISTINCT batch_id) FROM teacher_batches WHERE teacher_id = ?');
        $stmt->execute([$teacherId]);
        $countBatches = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) { $countBatches = 0; }

    // Distinct academic sessions among teacher-visible students
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT s.academic_session_id) FROM students s
             WHERE EXISTS (
               SELECT 1 FROM teacher_batches tb
               WHERE tb.teacher_id = ? AND tb.batch_id = s.batch_id
             )
             AND EXISTS (
               SELECT 1 FROM teacher_batch_timings tbt
               WHERE tbt.teacher_id = ? AND tbt.batch_id = s.batch_id AND tbt.timing_id = s.timing_id
             )"
        );
        $stmt->execute([$teacherId, $teacherId]);
        $countSessions = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) { $countSessions = 0; }
}
// Attendance analytics and recent alerts for charts
$today = date('Y-m-d');
$attToday = ['present'=>0,'absent'=>0,'leave'=>0];
try {
    $stmt = $db->prepare('SELECT status, COUNT(*) AS c FROM attendance WHERE att_date = ? GROUP BY status');
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $attToday[$r['status']] = (int)$r['c']; }
} catch (Throwable $e) { /* ignore */ }
$trendLabels = []; $trendCounts = [];
try {
    $stmt = $db->query("SELECT att_date AS d, COUNT(*) AS c FROM attendance WHERE att_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY att_date ORDER BY att_date");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $trendLabels[] = $r['d']; $trendCounts[] = (int)$r['c']; }
} catch (Throwable $e) { /* ignore */ }
$recentAlerts = [];
try {
    $recentAlerts = $db->query("SELECT id, title, level, created_at FROM notifications ORDER BY created_at DESC, id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recentAlerts = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/design-system.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <style>
    :root { --bg:#111317; --panel:#1a1d24; --muted:#8b93a6; --text:#e6e9ef; --accent:#4c75ff; --success:#24c58a; --warning:#e3b341; --danger:#ff6b6b; }
    body { background: var(--bg); color: var(--text); }
    body.light { --bg:#f6f7fb; --panel:#ffffff; --muted:#5f6677; --text:#1b1e24; --accent:#2f59ff; --success:#1fa876; --warning:#c99a27; --danger:#d74f4f; }
    .app-shell { display:grid; grid-template-columns: 280px 1fr; min-height:100vh; }
    .brand { font-weight:700; letter-spacing:.4px; margin-bottom:16px; }
    .nav-link { color: var(--muted); border-radius:10px; padding:10px 12px; display:flex; align-items:center; gap:10px; }
    .nav-link.active, .nav-link:hover { color: var(--text); background: rgba(255,255,255,0.06); }
    main { padding:20px; }
    .topbar { display:flex; gap:14px; align-items:center; justify-content:space-between; margin-bottom:18px; }
    .topbar .search { flex:1; }
    .topbar input { background:#0e1013; border:1px solid rgba(255,255,255,0.08); color:var(--text); }
    .card { background: var(--panel); color: var(--text); border: 1px solid rgba(255,255,255,0.06); }
    .metric-title { color: var(--muted); font-size:.85rem; }
    .metric-value { font-size:1.6rem; font-weight:700; }
    .panel-title { font-weight:600; }
    .table { color: var(--text); }
    .table thead { color: var(--muted); }
    .table td, .table th { border-color: rgba(255,255,255,0.08) !important; }
  </style>
</head>
<body>
<div class="app-shell">
  <?php include_once __DIR__ . '/partials/sidebar.php'; ?>
  <main>
    <div class="topbar">
      <div class="search">
        <input class="form-control form-control-lg" placeholder="Search…" />
      </div>
      <div class="d-flex align-items-center gap-3">
        <a href="notifications.php" class="btn btn-outline-light position-relative"><i class="fa-solid fa-bell"></i><?php if ($unackNotifications>0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?php echo (int)$unackNotifications; ?></span><?php endif; ?></a>
        <div class="btn btn-outline-light"><?php echo htmlspecialchars($session->getUsername() ?? 'User'); ?></div>
      </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="row g-3 mb-3">
      <div class="col-6 col-md-4 col-lg-2"><div class="card p-3"><div class="metric-title">Students</div><div class="metric-value"><?php echo (int)$countActiveStudents; ?></div></div></div>
      <div class="col-6 col-md-4 col-lg-2"><div class="card p-3"><div class="metric-title">Courses</div><div class="metric-value"><?php echo (int)$countCourses; ?></div></div></div>
      <div class="col-6 col-md-4 col-lg-2"><div class="card p-3"><div class="metric-title">Timings</div><div class="metric-value"><?php echo (int)$countTimings; ?></div></div></div>
      <div class="col-6 col-md-4 col-lg-2"><div class="card p-3"><div class="metric-title">Batches</div><div class="metric-value"><?php echo (int)$countBatches; ?></div></div></div>
      <div class="col-6 col-md-4 col-lg-2"><div class="card p-3"><div class="metric-title">Sessions</div><div class="metric-value"><?php echo (int)$countSessions; ?></div></div></div>
      <div class="col-6 col-md-4 col-lg-2"><div class="card p-3"><div class="metric-title">Unack Alerts</div><div class="metric-value text-danger"><?php echo (int)$unackNotifications; ?></div></div></div>
    </div>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card p-3 mb-3">
          <div class="d-flex justify-content-between align-items-center">
            <div class="panel-title">Attendance Trend (14 days)</div>
            <div class="d-flex gap-2">
              <a href="attendance_report.php" class="btn btn-sm btn-outline-light">Report</a>
              <a href="scripts/export_attendance_trend.php" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-file-arrow-down me-1"></i>Export CSV</a>
            </div>
          </div>
          <canvas id="trendChart" height="120"></canvas>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card p-3 mb-3"><div class="panel-title mb-2">Today’s Attendance</div><canvas id="donutChart" height="160"></canvas></div>
        <div class="card p-3">
          <div class="panel-title mb-2">Recent Alerts</div>
          <table class="table table-sm"><thead><tr><th>Date</th><th>Title</th><th>Level</th></tr></thead><tbody>
            <?php foreach ($recentAlerts as $a): ?>
              <tr>
                <td><?php echo htmlspecialchars($a['created_at']); ?></td>
                <td><?php echo htmlspecialchars($a['title']); ?></td>
                <td><span class="badge <?php echo $a['level']==='info'?'bg-info':($a['level']==='warning'?'bg-warning text-dark':'bg-danger'); ?>"><?php echo htmlspecialchars($a['level']); ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($recentAlerts)): ?><tr><td colspan="3" class="text-muted">No alerts.</td></tr><?php endif; ?>
          </tbody></table>
        </div>
      </div>
    </div>

    <?php if ($role === 'superadmin'): ?>
      <div class="card mt-3">
        <div class="card-header">Student Registration Settings</div>
        <div class="card-body">
          <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="set_general_number">
            <div class="col-md-4">
              <label class="form-label">General Number Start</label>
              <input type="number" name="general_number_start" class="form-control" value="<?php echo (int)$generalNext; ?>" min="1" required>
              <div class="form-text">Current next: <?php echo (int)$generalNext; ?>. Used to assign GR number on admission.</div>
            </div>
            <div class="col-md-3"><button class="btn btn-primary" type="submit">Save</button></div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php include_once __DIR__ . '/partials/command_palette.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  const trendLabels = <?php echo json_encode($trendLabels); ?>;
  const trendCounts = <?php echo json_encode($trendCounts); ?>;
  const donutData = <?php echo json_encode(array_values($attToday)); ?>;
  const donutLabels = ['Present','Absent','Leave'];
  if (document.getElementById('trendChart')) {
    new Chart(document.getElementById('trendChart'), { type:'line', data:{ labels:trendLabels, datasets:[{ label:'Attendance records', data:trendCounts, borderColor:'#4c75ff', tension:.3, fill:false }] }, options:{ plugins:{ legend:{ display:false } }, scales:{ x:{ ticks:{ color:'#8b93a6' } }, y:{ ticks:{ color:'#8b93a6' } } } } });
  }
  if (document.getElementById('donutChart')) {
    new Chart(document.getElementById('donutChart'), { type:'doughnut', data:{ labels:donutLabels, datasets:[{ data:donutData, backgroundColor:['#24c58a','#ff6b6b','#e3b341'] }] }, options:{ plugins:{ legend:{ labels:{ color:'#e6e9ef' } } } } });
  }
</script>
</body>
</html>
