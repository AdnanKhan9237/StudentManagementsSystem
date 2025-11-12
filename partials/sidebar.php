<?php
require_once __DIR__ . '/../classes/Session.php';
$session = Session::getInstance();
$role = (string)$session->get('role');
$current = basename($_SERVER['PHP_SELF']);

function navItem($label, $url, $current, $icon) {
    $active = ($current === basename($url)) ? ' active' : '';
    return '<a class="nav-link' . $active . '" href="' . htmlspecialchars($url) . '">' .
           '<i class="fa-solid ' . $icon . ' fa-fw" aria-hidden="true"></i>' .
           '<span class="label">' . htmlspecialchars($label) . '</span>' .
           '</a>';
}

function navDropdown($label, $icon, $links, $current) {
    $isActive = false;
    foreach ($links as $link) {
        if (basename($link['url']) === $current) {
            $isActive = true;
            break;
        }
    }
    $html = '<div class="nav-dropdown' . ($isActive ? ' active' : '') . '">';
    $html .= '<a href="#" class="nav-link dropdown-toggle">' .
             '<i class="fa-solid ' . $icon . ' fa-fw" aria-hidden="true"></i>' .
             '<span class="label">' . htmlspecialchars($label) . '</span>' .
             '</a>';
    $html .= '<div class="dropdown-menu">';
    foreach ($links as $link) {
        $html .= navItem($link['label'], $link['url'], $current, 'fa-circle');
    }
    $html .= '</div></div>';
    return $html;
}
?>
<aside class="app-sidebar" aria-label="Primary">
  <div class="brand">
    <span class="logo" aria-hidden="true"></span>
    <span class="label">sp!k</span>
  </div>
  <nav>
    <?php echo navItem('Home', 'dashboard.php', $current, 'fa-home'); ?>
    <?php 
        if (in_array($role, ['superadmin', 'admin'], true)) {
            echo navDropdown('Admin', 'fa-user-shield', [
                ['label' => 'Admin', 'url' => 'admin.php'],
                ['label' => 'Students', 'url' => 'students.php'],
                ['label' => 'Manage Users', 'url' => 'manage_users.php'],
                ['label' => 'Audit Logs', 'url' => 'audit_logs.php'],
            ], $current);
        }
    ?>
    <?php echo navDropdown('Students', 'fa-users', [
        ['label' => 'Students', 'url' => 'students.php'],
        ['label' => 'Add Student', 'url' => 'add_student.php'],
        ['label' => 'Student Records', 'url' => 'student_records.php'],
    ], $current); ?>
    <?php echo navDropdown('Courses', 'fa-book', [
        ['label' => 'Courses', 'url' => 'courses.php'],
        ['label' => 'Batches', 'url' => 'batches.php'],
        ['label' => 'Timings', 'url' => 'timings.php'],
        ['label' => 'Academic Sessions', 'url' => 'academic_sessions.php'],
        ['label' => 'Course Sessions', 'url' => 'course_sessions.php'],
    ], $current); ?>
    <?php echo navItem('Attendance', 'attendance.php', $current, 'fa-clipboard-user'); ?>
    <?php echo navDropdown('Results', 'fa-graduation-cap', [
        ['label' => 'Results', 'url' => 'results.php'],
        ['label' => 'Assessments', 'url' => 'assessments.php'],
    ], $current); ?>
    <?php echo navDropdown('Account', 'fa-user-circle', [
        ['label' => 'Change Password', 'url' => 'change_password.php'],
        ['label' => 'Log Out', 'url' => 'logout.php'],
    ], $current); ?>
    <?php echo navItem('Fees', 'fees.php', $current, 'fa-money-bill'); ?>
    <?php echo navItem('Notifications', 'notifications.php', $current, 'fa-bell'); ?>
  </nav>
  <div class="footer">
    <!-- Footer content can go here -->
  </div>
</aside>
<script src="assets/js/sidebar.js"></script>
