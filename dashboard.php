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

// Student gender distribution
$studentGender = ['Male' => 0, 'Female' => 0];
try {
    // Assuming a 'gender' column exists in the 'students' table
    $stmt = $db->query("SELECT gender, COUNT(*) as c FROM students WHERE gender IN ('Male', 'Female') AND general_number IS NOT NULL GROUP BY gender");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $studentGender[$r['gender']] = (int)$r['c'];
    }
} catch (Throwable $e) { /* ignore */ }

// Star Students
$starStudents = [];
try {
    // This is a placeholder query. You will need to adjust this based on your database schema.
    $starStudents = $db->query("SELECT s.first_name, s.last_name, s.general_number as id, r.marks, r.percentage, r.year FROM students s JOIN results r ON s.id = r.student_id ORDER BY r.percentage DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* ignore */ }

// New Students
$newStudents = [];
try {
    // This is a placeholder query. You will need to adjust this based on your database schema.
    $newStudents = $db->query("SELECT first_name, last_name, general_number as id, course_id, batch_id FROM students ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* ignore */ }


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
$countTeachers = countTable($db, 'teachers');
$countParents = countTable($db, 'parents');
$totalEarnings = 0;
try {
    $totalEarnings = (float)($db->query("SELECT SUM(amount) FROM payments")->fetchColumn() ?: 0);
} catch (Throwable $e) { $totalEarnings = 0; }
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
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>
<div class="app-shell">
  <?php include_once __DIR__ . '/partials/sidebar.php'; ?>
  <main id="main-content">
    <header class="app-header">
        <div class="search-wrapper">
            <input type="text" placeholder="What do you want to find?">
            <i class="fa-solid fa-search"></i>
        </div>
        <div class="user-profile">
            <i class="fa-solid fa-bell"></i>
            <i class="fa-solid fa-comment-dots"></i>
            <div class="user-info">
                <img src="https://i.pravatar.cc/40?u=<?php echo urlencode($session->getUsername() ?? 'user'); ?>" alt="User Avatar" class="avatar">
                <div class="user-details">
                    <span class="user-name"><?php echo htmlspecialchars($session->getUsername() ?? 'User'); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars(ucfirst($role)); ?></span>
                </div>
            </div>
        </div>
    </header>

    <div class="content-wrapper">
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
        <div class="page-header">
            <h1>Dashboard</h1>
            <p class="text-muted">Welcome back, <?php echo htmlspecialchars($session->getUsername() ?? 'User'); ?></p>
        </div>
        <div class="main-content">
            <div class="content-grid">
                <div class="main-column">
                    <div class="card-group">
                        <div class="card card-stat">
                            <div class="card-body">
                                <div class="card-icon">
                                    <i class="fa-solid fa-user-graduate"></i>
                                </div>
                                <div class="card-content">
                                    <h5 class="card-title">Students</h5>
                                    <p class="card-text"><?php echo (int)$countActiveStudents; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="card card-stat">
                            <div class="card-body">
                                <div class="card-icon">
                                    <i class="fa-solid fa-chalkboard-user"></i>
                                </div>
                                <div class="card-content">
                                    <h5 class="card-title">Teachers</h5>
                                    <p class="card-text"><?php echo (int)$countTeachers; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="card card-stat">
                            <div class="card-body">
                                <div class="card-icon">
                                    <i class="fa-solid fa-user-friends"></i>
                                </div>
                                <div class="card-content">
                                    <h5 class="card-title">Parents</h5>
                                    <p class="card-text"><?php echo (int)$countParents; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="card card-stat">
                            <div class="card-body">
                                <div class="card-icon">
                                    <i class="fa-solid fa-dollar-sign"></i>
                                </div>
                                <div class="card-content">
                                    <h5 class="card-title">Earnings</h5>
                                    <p class="card-text">$<?php echo number_format($totalEarnings, 2); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h2 class="h5 mb-0">All Exam Results</h2>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Time Period Selection">
                                <input type="radio" class="btn-check" name="period" id="monthly" autocomplete="off" checked>
                                <label class="btn btn-outline-primary" for="monthly">Monthly</label>
                                <input type="radio" class="btn-check" name="period" id="weekly" autocomplete="off">
                                <label class="btn btn-outline-primary" for="weekly">Weekly</label>
                                <input type="radio" class="btn-check" name="period" id="yearly" autocomplete="off">
                                <label class="btn btn-outline-primary" for="yearly">Yearly</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <canvas id="examChart" height="300" aria-label="Exam Results Chart"></canvas>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h2 class="card-title mb-0 h5">Star Students</h2>
                            <a href="students.php" class="btn btn-sm btn-outline-primary" aria-label="View all students">View All</a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" role="table" aria-label="Star Students Table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Name</th>
                                            <th scope="col">ID</th>
                                            <th scope="col">Marks</th>
                                            <th scope="col">Percent</th>
                                            <th scope="col">Year</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($starStudents)): ?>
                                            <?php foreach ($starStudents as $student): ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <img src="https://i.pravatar.cc/40?u=<?php echo urlencode($student['first_name']); ?>" alt="User Avatar" class="user-avatar">
                                                            <div class="user-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($student['id']); ?></td>
                                                    <td><?php echo htmlspecialchars($student['marks']); ?></td>
                                                    <td><span class="badge bg-success" aria-label="Percentage: <?php echo htmlspecialchars($student['percentage']); ?>%"><?php echo htmlspecialchars($student['percentage']); ?>%</span></td>
                                                    <td><?php echo htmlspecialchars($student['year']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">No star students found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="side-column">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h2>Students</h2>
                            <i class="fa-solid fa-ellipsis"></i>
                        </div>
                        <div class="card-body">
                            <canvas id="studentsChart" height="200"></canvas>
                            <div class="chart-legend">
                                <div class="legend-item"><span class="legend-color" style="background-color: #a96eff;"></span> Male</div>
                                <div class="legend-item"><span class="legend-color" style="background-color: #ffc107;"></span> Female</div>
                            </div>
                        </div>
                    </div>
                    <div class="card exam-results-container">
                        <div class="exam-results-header">
                            <h2>All Exam Results</h2>
                            <i class="fa-solid fa-ellipsis"></i>
                        </div>
                        <div class="exam-result-item">
                            <div class="exam-result-icon" style="background-color: #e0f7fa;">
                                <i class="fa-solid fa-user-graduate" style="color: #00bcd4;"></i>
                            </div>
                            <div class="exam-result-details">
                                <div class="exam-result-title">New Teacher</div>
                                <div class="exam-result-description">It is a long established readable.</div>
                            </div>
                            <div class="exam-result-time">Just now</div>
                        </div>
                        <div class="exam-result-item">
                            <div class="exam-result-icon" style="background-color: #fce4ec;">
                                <i class="fa-solid fa-file-invoice-dollar" style="color: #e91e63;"></i>
                            </div>
                            <div class="exam-result-details">
                                <div class="exam-result-title">Fees Structure</div>
                                <div class="exam-result-description">It is a long established readable.</div>
                            </div>
                            <div class="exam-result-time">Today</div>
                        </div>
                        <div class="exam-result-item">
                            <div class="exam-result-icon" style="background-color: #e8f5e9;">
                                <i class="fa-solid fa-book" style="color: #4caf50;"></i>
                            </div>
                            <div class="exam-result-details">
                                <div class="exam-result-title">New Course</div>
                                <div class="exam-result-description">It is a long established readable.</div>
                            </div>
                            <div class="exam-result-time">24 Sep 2023</div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h2 class="panel-title">Settings</h2>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="set_general_number">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="col-auto">
                                        <label class="form-label">Next General Number</label>
                                        <input type="number" name="general_number_start" class="form-control" value="<?php echo $generalNext; ?>">
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-primary">Save</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  // Chart initialization with error handling
  document.addEventListener('DOMContentLoaded', function() {
    try {
      // Students gender distribution chart
      const studentsChartCtx = document.getElementById('studentsChart');
      if (studentsChartCtx) {
        new Chart(studentsChartCtx, {
          type: 'doughnut',
          data: {
            labels: ['Male', 'Female'],
            datasets: [{
              label: 'Students',
              data: [<?php echo (int)$studentGender['Male']; ?>, <?php echo (int)$studentGender['Female']; ?>],
              backgroundColor: ['#a96eff', '#ffc107'],
              borderWidth: 0
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                display: false
              }
            }
          }
        });
      }

      // Exam results chart
      const examCtx = document.getElementById('examChart');
      if (examCtx) {
        new Chart(examCtx, {
          type: 'line',
          data: {
            labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            datasets: [{
              label: 'Teacher',
              data: [65, 59, 80, 81, 56, 55, 40],
              borderColor: '#a96eff',
              backgroundColor: 'rgba(169, 110, 255, 0.1)',
              tension: 0.3,
              fill: true
            }, {
              label: 'Students',
              data: [28, 48, 40, 19, 86, 27, 90],
              borderColor: '#63c7ff',
              backgroundColor: 'rgba(99, 199, 255, 0.1)',
              tension: 0.3,
              fill: true
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                position: 'top'
              }
            },
            scales: {
              y: {
                beginAtZero: true
              }
            }
          }
        });
      }
    } catch (error) {
      console.error('Chart initialization error:', error);
    }
  });
</script>
</body>
</html>