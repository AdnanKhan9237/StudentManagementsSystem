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
if ($wantsJson && ($_GET['metrics'] ?? '') === 'batch') {
    $rows = $db->query("SELECT b.id, a.status,
      (SELECT COUNT(*) FROM batch_admissions ba WHERE ba.batch_id = b.id AND ba.status = 'active') AS adm_count
      FROM batches b JOIN academic_sessions a ON a.id = b.academic_session_id")->fetchAll(PDO::FETCH_ASSOC);
    $pending = 0; $processing = 0; $completed = 0;
    foreach ($rows as $r) {
        $adm = (int)($r['adm_count'] ?? 0);
        $activeSession = ($r['status'] ?? '') === 'active';
        if (!$activeSession) { $completed++; continue; }
        if ($adm <= 0) { $pending++; } else { $processing++; }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'queues' => ['pending' => $pending, 'processing' => $processing, 'completed' => $completed]]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS System - Dashboard</title>
    <!-- Bootstrap and Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- No custom CSS: Bootstrap-only styling -->
    <style>
      /* Launcher styles inspired by the provided mockup */
      .launcher-card { background: #1c1f27; color: #e9ecef; border-radius: 24px; padding: 18px; box-shadow: inset 0 1px 0 rgba(255,255,255,0.05), 0 10px 30px rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.06); }
      .launcher-header { padding: 6px 8px 16px; }
      .launcher-avatar { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; background: linear-gradient(180deg,#2a2f3a,#1f232b); box-shadow: 0 8px 16px rgba(0,0,0,0.35), inset 0 1px 0 rgba(255,255,255,0.06); font-size: 24px; }
      .launcher-name { font-weight: 600; letter-spacing: 0.2px; }
      .launcher-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 18px; }
      .launcher-tile { text-decoration: none; background: radial-gradient(110% 140% at 30% 20%, #262b35 0%, #1e222a 70%); border-radius: 20px; padding: 18px; box-shadow: 0 10px 22px rgba(0,0,0,0.35), inset 0 1px 0 rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.06); color: #e9ecef; display: flex; flex-direction: column; align-items: center; gap: 12px; transition: transform .15s ease, box-shadow .15s ease; }
      .launcher-tile:hover { transform: translateY(-2px); box-shadow: 0 16px 30px rgba(0,0,0,0.45), inset 0 1px 0 rgba(255,255,255,0.08); }
      .tile-icon { width: 56px; height: 56px; border-radius: 16px; display: grid; place-items: center; background: linear-gradient(180deg,#313743,#232833); box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), 0 8px 16px rgba(0,0,0,0.35); }
      .tile-label { font-weight: 600; font-size: 14px; letter-spacing: .4px; text-transform: uppercase; color: #dbe1e8; }
    </style>
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
<div class="container-fluid px-3 px-lg-4 py-4">

        
            

            <!-- Main Content -->
            
                <?php if ($success): ?>
                    <div class="alert alert-success" role="alert">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h4 mb-0">Dashboard</h2>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-secondary">Role: <?php echo htmlspecialchars(ucfirst($role)); ?></span>
                        <?php if ($role === 'superadmin'): ?>
                          <a href="change_password.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-key me-1"></i>Change Password</a>
                          <a href="logout.php" class="btn btn-danger btn-sm"><i class="fa-solid fa-right-from-bracket me-1"></i>Logout</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                  // Role-aware launcher tiles
                  $username = isset($currentUser['username']) ? (string)$currentUser['username'] : (string)$session->get('username', 'User');
                  $tiles = [];
                  if ($role === 'superadmin') {
                    $tiles = [
                      ['label' => 'Manage Users', 'url' => 'manage_users.php', 'icon' => 'fa-user-cog'],
                      ['label' => 'Students', 'url' => 'students.php', 'icon' => 'fa-user-graduate'],
                      ['label' => 'Add Student', 'url' => 'add_student.php', 'icon' => 'fa-user-plus'],
                      ['label' => 'Courses', 'url' => 'courses.php', 'icon' => 'fa-book'],
                      ['label' => 'Enrollments', 'url' => 'enrollments.php', 'icon' => 'fa-user-plus'],
                      ['label' => 'Fees', 'url' => 'fees.php', 'icon' => 'fa-receipt'],
                      ['label' => 'Results', 'url' => 'results.php', 'icon' => 'fa-clipboard-check'],
                      // Add links to new modules for completeness
                      ['label' => 'Academic Sessions', 'url' => 'academic_sessions.php', 'icon' => 'fa-calendar'],
                      ['label' => 'Timings', 'url' => 'timings.php', 'icon' => 'fa-clock'],
                      ['label' => 'Batches', 'url' => 'batches.php', 'icon' => 'fa-layer-group'],
                      ['label' => 'Admissions', 'url' => 'admissions.php', 'icon' => 'fa-user-check'],
                    ];
                  } elseif ($role === 'accounts') {
                    $tiles = [
                      ['label' => 'Students', 'url' => 'students.php', 'icon' => 'fa-user-graduate'],
                      ['label' => 'Add Student', 'url' => 'add_student.php', 'icon' => 'fa-user-plus'],
                      ['label' => 'Courses', 'url' => 'courses.php', 'icon' => 'fa-book'],
                      ['label' => 'Enrollments', 'url' => 'enrollments.php', 'icon' => 'fa-user-plus'],
                      ['label' => 'Fees', 'url' => 'fees.php', 'icon' => 'fa-receipt'],
                      ['label' => 'Results', 'url' => 'results.php', 'icon' => 'fa-clipboard-check'],
                      ['label' => 'Attendance', 'url' => 'attendance.php', 'icon' => 'fa-calendar-check'],
                    ];
                  } elseif ($role === 'teacher') {
                    $tiles = [
                      ['label' => 'Courses', 'url' => 'courses.php', 'icon' => 'fa-book'],
                      ['label' => 'Sessions', 'url' => 'course_sessions.php', 'icon' => 'fa-folder-tree'],
                      ['label' => 'Students', 'url' => 'teacher_students.php', 'icon' => 'fa-user-graduate'],
                      ['label' => 'Assessments', 'url' => 'assessments.php', 'icon' => 'fa-clipboard-list'],
                      ['label' => 'Results', 'url' => 'results.php', 'icon' => 'fa-clipboard-check'],
                      ['label' => 'Attendance', 'url' => 'attendance.php', 'icon' => 'fa-calendar-check'],
                    ];
                  } elseif ($role === 'student') {
                    $tiles = [
                      ['label' => 'My Courses', 'url' => 'courses.php', 'icon' => 'fa-book-open'],
                      ['label' => 'My Records', 'url' => 'student_records.php', 'icon' => 'fa-folder-open'],
                      ['label' => 'My Results', 'url' => 'results.php', 'icon' => 'fa-clipboard-check'],
                      ['label' => 'My Attendance', 'url' => 'attendance.php', 'icon' => 'fa-calendar-check'],
                    ];
                  }
                  // For non-superadmin roles, add Change Password before Logout
                  if ($role !== 'superadmin') {
                    $tiles[] = ['label' => 'Change Password', 'url' => 'change_password.php', 'icon' => 'fa-key'];
                    $tiles[] = ['label' => 'Logout', 'url' => 'logout.php', 'icon' => 'fa-right-from-bracket'];
                  }
                ?>

                <div class="launcher-card mb-4">
                  <div class="launcher-header d-flex align-items-center gap-3">
                    <div class="launcher-avatar">👤</div>
                    <div>
                      <div class="launcher-name"><?php echo htmlspecialchars($username); ?></div>
                    </div>
                  </div>
                  <div class="launcher-grid">
                    <?php foreach ($tiles as $t): ?>
                      <a class="launcher-tile" href="<?php echo htmlspecialchars($t['url']); ?>">
                        <div class="tile-icon">
                          <i class="fa-solid <?php echo htmlspecialchars($t['icon']); ?> fa-lg"></i>
                        </div>
                        <div class="tile-label"><?php echo htmlspecialchars($t['label']); ?></div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>

                <!-- Stats -->
                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-3 mb-4">
                    <div class="col">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <h6 class="card-title mb-1">Total Messages</h6>
                                        <div class="fs-4 fw-semibold">0</div>
                                    </div>
                                    <i class="fa-regular fa-message fa-2x text-primary"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <h6 class="card-title mb-1">Sent Today</h6>
                                        <div class="fs-4 fw-semibold">0</div>
                                    </div>
                                    <i class="fa-solid fa-paper-plane fa-2x text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <h6 class="card-title mb-1">Contacts</h6>
                                        <div class="fs-4 fw-semibold">0</div>
                                    </div>
                                    <i class="fa-solid fa-address-book fa-2x text-info"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <h6 class="card-title mb-1">System Status</h6>
                                        <div class="fs-6 fw-semibold text-success">Active</div>
                                    </div>
                                    <i class="fa-solid fa-signal fa-2x text-secondary"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                

                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header">Quick Actions</div>
                    <div class="card-body d-flex flex-wrap gap-2"></div>
                </div>

                <?php if ($role === 'superadmin'): ?>
                <div class="card mb-4">
                    <div class="card-header">Student Registration Settings</div>
                    <div class="card-body">
                        <form method="post" class="row g-3 align-items-end">
                            <input type="hidden" name="action" value="set_general_number">
                            <div class="col-md-4">
                                <label class="form-label">General Number Start</label>
                                <input type="number" name="general_number_start" class="form-control" value="<?php echo (int)$generalNext; ?>" min="1" required>
                                <div class="form-text">Current next: <?php echo (int)$generalNext; ?>. Used when a receipt number is entered.</div>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-primary" type="submit">Save</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Activity -->
                <div class="card mb-4">
                    <div class="card-header">Recent Activity</div>
                    <div class="card-body">
                        <p class="text-muted mb-0">No recent activity to display.</p>
                    </div>
                </div>

                <!-- Management -->
                <div class="card">
                    <div class="card-header">Management</div>
                    <div class="card-body d-flex flex-wrap gap-2">
                        <?php if ($role === 'superadmin'): ?>
                            <a href="courses.php" class="btn btn-outline-primary"><i class="fa-solid fa-book me-2"></i>Courses</a>
                            <a href="manage_users.php" class="btn btn-outline-primary"><i class="fa-solid fa-user-cog me-2"></i>Manage Users</a>
                            <a href="students.php" class="btn btn-outline-primary"><i class="fa-solid fa-user-graduate me-2"></i>Student Accounts</a>
                            <a href="fees.php" class="btn btn-outline-primary"><i class="fa-solid fa-receipt me-2"></i>Fees</a>
                        <?php elseif ($role === 'accounts'): ?>
                            <a href="courses.php" class="btn btn-outline-primary"><i class="fa-solid fa-book me-2"></i>Courses</a>
                            <a href="students.php" class="btn btn-outline-primary"><i class="fa-solid fa-user-graduate me-2"></i>Student Accounts</a>
                            <a href="fees.php" class="btn btn-outline-primary"><i class="fa-solid fa-receipt me-2"></i>Fees</a>
                        <?php elseif ($role === 'teacher'): ?>
                            <a href="courses.php" class="btn btn-outline-primary"><i class="fa-solid fa-book me-2"></i>Courses</a>
                            <a href="teacher_students.php" class="btn btn-outline-primary"><i class="fa-solid fa-user-graduate me-2"></i>Students</a>
                            <a href="attendance.php" class="btn btn-outline-primary"><i class="fa-solid fa-calendar-check me-2"></i>Attendance</a>
                            <a href="assessments.php" class="btn btn-outline-primary"><i class="fa-solid fa-clipboard-list me-2"></i>Assessments</a>
                            <a href="results.php" class="btn btn-outline-primary"><i class="fa-solid fa-clipboard-check me-2"></i>Final Results</a>
                        <?php elseif ($role === 'student'): ?>
                            <a href="courses.php" class="btn btn-outline-primary"><i class="fa-solid fa-book-open me-2"></i>My Courses</a>
                            <a href="student_records.php" class="btn btn-outline-primary"><i class="fa-solid fa-folder-open me-2"></i>My Records</a>
                        <?php else: ?>
                            <button class="btn btn-outline-secondary" disabled title="Insufficient permissions">Admin features unavailable</button>
                        <?php endif; ?>
                    </div>
                </div>
        
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    
</body>
</html>
$db->exec("CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(50) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Handle superadmin settings update (General Number start)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($session->get('role') === 'superadmin')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'set_general_number') {
        $start = (int)($_POST['general_number_start'] ?? 0);
        if ($start <= 0) {
            $success = '';
        } else {
            $stmt = $db->prepare('REPLACE INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, ?)');
            $stmt->execute(['general_number_next', (string)$start, date('Y-m-d H:i:s')]);
            $success = 'General number starting value updated.';
        }
    }
}
$generalNext = 0;
try {
    $stmtSettings = $db->prepare('SELECT value FROM settings WHERE `key` = ?');
    $stmtSettings->execute(['general_number_next']);
    $generalNext = (int)($stmtSettings->fetchColumn() ?: 0);
} catch (Throwable $e) { /* ignore */ }
