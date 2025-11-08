<?php
// Command Palette partial: Ctrl+K opens a searchable, role-aware navigator.
// Include this near the top of the <body> in any page where you want overlay navigation.

// Ensure we have session and role context (project uses global classes without namespaces)
require_once __DIR__ . '/../classes/Session.php';
require_once __DIR__ . '/../classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
$role = (string) $session->get('role', '');
if ($role === '') { $role = 'teacher'; }

// Build role-based destinations
$destinations = [];
if ($role === 'superadmin' || $role === 'accounts') {
$destinations[] = ['label' => 'Dashboard', 'url' => 'dashboard.php'];
$destinations[] = ['label' => 'Courses', 'url' => 'courses.php'];
$destinations[] = ['label' => 'Enrollments', 'url' => 'enrollments.php'];
$destinations[] = ['label' => 'Fees', 'url' => 'fees.php'];
$destinations[] = ['label' => 'Assessments', 'url' => 'assessments.php'];
$destinations[] = ['label' => 'Results', 'url' => 'results.php'];
$destinations[] = ['label' => 'Attendance', 'url' => 'attendance.php'];
$destinations[] = ['label' => 'Students', 'url' => 'students.php'];
$destinations[] = ['label' => 'Add Student', 'url' => 'add_student.php'];
$destinations[] = ['label' => 'Student Records', 'url' => 'student_records.php'];
$destinations[] = ['label' => 'Manage Users', 'url' => 'manage_users.php'];
$destinations[] = ['label' => 'Course Sessions', 'url' => 'course_sessions.php'];
$destinations[] = ['label' => 'Academic Sessions', 'url' => 'academic_sessions.php'];
$destinations[] = ['label' => 'Timings', 'url' => 'timings.php'];
$destinations[] = ['label' => 'Batches', 'url' => 'batches.php'];
$destinations[] = ['label' => 'Admissions', 'url' => 'admissions.php'];
$destinations[] = ['label' => 'Teacher & Students', 'url' => 'teacher_students.php'];
$destinations[] = ['label' => 'Admin Panel', 'url' => 'admin.php'];
}
if ($role === 'teacher') {
$destinations[] = ['label' => 'Dashboard', 'url' => 'dashboard.php'];
$destinations[] = ['label' => 'Courses', 'url' => 'courses.php'];
$destinations[] = ['label' => 'Course Sessions', 'url' => 'course_sessions.php'];
$destinations[] = ['label' => 'Teacher & Students', 'url' => 'teacher_students.php'];
$destinations[] = ['label' => 'Assessments', 'url' => 'assessments.php'];
$destinations[] = ['label' => 'Results', 'url' => 'results.php'];
$destinations[] = ['label' => 'Attendance', 'url' => 'attendance.php'];
}
if ($role === 'student') {
$destinations[] = ['label' => 'Dashboard', 'url' => 'dashboard.php'];
$destinations[] = ['label' => 'My Courses', 'url' => 'courses.php'];
$destinations[] = ['label' => 'My Results', 'url' => 'results.php'];
$destinations[] = ['label' => 'My Attendance', 'url' => 'attendance.php'];
}

// Always include session actions
$actions = [
    ['label' => 'Change Password', 'url' => 'change_password.php'],
    ['label' => 'Log Out', 'url' => 'logout.php'],
];

?>

<style>
  /* Palette styles: minimal, modern, and responsive */
  .cmdk-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.35); z-index: 1050; display: none; }
  .cmdk-container { position: fixed; top: 10vh; left: 50%; transform: translateX(-50%); width: min(800px, 92vw); z-index: 1060; display: none; }
  .cmdk-card { background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.18); overflow: hidden; border: 1px solid rgba(0,0,0,0.08); }
  .cmdk-header { padding: 10px 12px; border-bottom: 1px solid rgba(0,0,0,0.06); display: flex; align-items: center; gap: 8px; }
  .cmdk-input { border: none; outline: none; flex: 1; font-size: 16px; padding: 8px; }
  .cmdk-list { max-height: 50vh; overflow: auto; }
  .cmdk-item { padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; border-bottom: 1px solid rgba(0,0,0,0.04); }
  .cmdk-item:hover, .cmdk-item.active { background: #f6f8fa; }
  .cmdk-section { font-weight: 600; font-size: 12px; color: #6c757d; padding: 8px 12px; background: #fafafa; border-top: 1px solid rgba(0,0,0,0.06); }
  .cmdk-hint { font-size: 12px; color: #6c757d; }
</style>

<div id="cmdkOverlay" class="cmdk-overlay"></div>
<div id="cmdkContainer" class="cmdk-container">
  <div class="cmdk-card">
    <div class="cmdk-header">
      <span class="cmdk-hint">Press Ctrl+K to search</span>
      <input id="cmdkInput" class="cmdk-input" type="text" placeholder="Search pages and actions..." aria-label="Command palette" />
    </div>
    <div id="cmdkList" class="cmdk-list" role="listbox" aria-label="Available destinations"></div>
  </div>
  
  <div class="cmdk-section">Shortcuts: Enter to open · Esc to close · ↑/↓ to navigate</div>
</div>

<script>
  (() => {
    const destinations = <?php echo json_encode($destinations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const actions = <?php echo json_encode($actions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    const overlay = document.getElementById('cmdkOverlay');
    const container = document.getElementById('cmdkContainer');
    const input = document.getElementById('cmdkInput');
    const list = document.getElementById('cmdkList');

    let open = false;
    let items = [];
    let activeIndex = -1;

    function openPalette() {
      open = true;
      overlay.style.display = 'block';
      container.style.display = 'block';
      input.value = '';
      activeIndex = -1;
      renderList('');
      setTimeout(() => input.focus(), 0);
    }
    function closePalette() {
      open = false;
      overlay.style.display = 'none';
      container.style.display = 'none';
    }

    function renderList(query) {
      const q = query.trim().toLowerCase();
      const merged = [
        ...destinations.map(d => ({ ...d, type: 'dest' })),
        ...actions.map(a => ({ ...a, type: 'action' }))
      ];
      const filtered = q
        ? merged.filter(item => item.label.toLowerCase().includes(q))
        : merged;
      items = filtered;
      activeIndex = filtered.length ? 0 : -1;

      list.innerHTML = '';
      filtered.forEach((item, idx) => {
        const div = document.createElement('div');
        div.className = 'cmdk-item' + (idx === activeIndex ? ' active' : '');
        div.setAttribute('role', 'option');
        const left = document.createElement('span');
        left.textContent = item.label;
        const right = document.createElement('span');
        right.className = 'cmdk-hint';
        right.textContent = item.type === 'dest' ? 'Page' : 'Action';
        div.appendChild(left);
        div.appendChild(right);
        div.addEventListener('click', () => navigateTo(item));
        list.appendChild(div);
      });
    }

    function navigateTo(item) {
      if (!item || !item.url) return;
      window.location.href = item.url;
      closePalette();
    }

    document.addEventListener('keydown', (e) => {
      // Ctrl+K toggles
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        open ? closePalette() : openPalette();
        return;
      }
      if (!open) return;
      if (e.key === 'Escape') {
        e.preventDefault();
        closePalette();
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (items.length) {
          activeIndex = Math.min(items.length - 1, activeIndex + 1);
          updateActive();
        }
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (items.length) {
          activeIndex = Math.max(0, activeIndex - 1);
          updateActive();
        }
        return;
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        if (activeIndex >= 0 && activeIndex < items.length) {
          navigateTo(items[activeIndex]);
        }
        return;
      }
    });

    function updateActive() {
      const children = list.querySelectorAll('.cmdk-item');
      children.forEach((el, idx) => {
        if (idx === activeIndex) el.classList.add('active');
        else el.classList.remove('active');
      });
    }

    input.addEventListener('input', (e) => renderList(e.target.value));
    overlay.addEventListener('click', closePalette);
  })();
</script>
