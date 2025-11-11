<?php
require_once __DIR__ . '/../classes/Session.php';
$session = Session::getInstance();
$role = (string)$session->get('role');
$current = basename($_SERVER['PHP_SELF']);
function navItem($label, $url, $current) {
  $active = ($current === basename($url)) ? ' active' : '';
  return '<a class="nav-link'.$active.'" href="'.htmlspecialchars($url).'">'
       .'<i class="fa-solid fa-circle fa-xs" aria-hidden="true"></i>'
       .'<span class="label">'.htmlspecialchars($label).'</span>'
       .'</a>';
}
?>
<aside class="app-sidebar" aria-label="Primary">
  <div class="brand">
    <span class="logo" aria-hidden="true"></span>
    <span class="label">SOS Technical Training</span>
  </div>
  <nav>
    <div class="nav-section">Menu</div>
    <?php echo navItem('Dashboard', 'dashboard.php', $current); ?>
    <?php echo navItem('Attendance', 'attendance.php', $current); ?>
    <?php echo navItem('Students', 'students.php', $current); ?>
    <?php echo navItem('Student Records', 'student_records.php', $current); ?>
    <?php echo navItem('Courses', 'courses.php', $current); ?>
    <?php echo navItem('Course Sessions', 'course_sessions.php', $current); ?>
    <?php echo navItem('Academic Sessions', 'academic_sessions.php', $current); ?>
    <?php echo navItem('Batches', 'batches.php', $current); ?>
    <?php echo navItem('Timings', 'timings.php', $current); ?>
    <?php echo navItem('Assessments', 'assessments.php', $current); ?>
    <?php echo navItem('Results', 'results.php', $current); ?>
    <?php echo navItem('Notifications', 'notifications.php', $current); ?>
    <?php if (in_array($role, ['superadmin','accounts'], true)) { echo navItem('Manage Users', 'manage_users.php', $current); echo navItem('Admin Panel', 'admin.php', $current);} ?>
    <div class="nav-section">Account</div>
    <?php echo navItem('Change Password', 'change_password.php', $current); ?>
    <?php echo navItem('Log Out', 'logout.php', $current); ?>
  </nav>
  <div class="footer">
    <div class="theme-toggle">
      <button id="themeDark" class="btn btn-sm btn-outline-secondary" type="button">Dark</button>
      <button id="themeLight" class="btn btn-sm btn-outline-secondary" type="button">Light</button>
    </div>
  </div>
</aside>
<script src="assets/js/sidebar.js"></script>

