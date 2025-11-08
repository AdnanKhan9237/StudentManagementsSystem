<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/classes/Session.php';

$session = Session::getInstance();
// Logo path for branding (user can place file at assets/logo.png)
$logoWebPath = 'assets/logo.png';
$logoFsPath = __DIR__ . '/assets/logo.png';
$hasLogo = file_exists($logoFsPath);

// Redirect to dashboard if already logged in
if ($session->isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

// Flash messages from session
$error = $session->getFlash('error');
$success = $session->getFlash('success');

// Fallback to query-string messages for compatibility
if (!$error && isset($_GET['error']) && $_GET['error'] == '1') {
    $error = 'Invalid username or password!';
}
if (!$success && isset($_GET['logout']) && $_GET['logout'] == '1') {
    $success = 'You have been logged out successfully!';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - <?php echo defined('SITE_NAME') ? SITE_NAME : 'SMS System'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
      :root { --brand-blue: #14a0e3; }
      body {
        min-height: 100vh;
        background: linear-gradient(135deg, #7b2ff7 0%, #f25767 100%);
        display: grid;
        place-items: center;
      }
      /* Card header for institute name placed inside box */
      .card-header-box { text-align: center; padding: 18px 22px; border-bottom: 1px solid #f0f2f5; }
      .card-header-title { color: #343a40; font-weight: 800; letter-spacing: .3px; line-height: 1.2; font-size: clamp(22px, 3.5vw, 32px); }
      .card-header-campus { color: #6c757d; font-size: clamp(13px, 2.5vw, 16px); font-weight: 500; }
      .login-shell {
        width: 100%;
        max-width: 960px;
      }
      .login-card {
        background: #ffffff;
        border-radius: 18px;
        box-shadow: 0 12px 32px rgba(0,0,0,0.15);
        overflow: hidden;
      }
      .illustration {
        display: grid;
        place-items: center;
        padding: 32px;
      }
      .illustration .circle {
        width: 220px; height: 220px; border-radius: 50%;
        background: var(--brand-blue);
        display: grid; place-items: center;
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.2), 0 10px 22px rgba(0,0,0,0.18);
      }
      .illustration i { color: #63728a; font-size: 64px; }
      .logo-badge { background: var(--brand-blue); padding: 8px; border-radius: 18px; }
      .logo-img { max-width: 160px; height: auto; display: block; object-fit: contain; background: transparent; }
      .form-side { padding: 36px 32px; }
      .form-side h3 { font-weight: 700; letter-spacing: .2px; }
      .input-group .input-group-text { background: #eef1f4; border-color: #e1e5ea; }
      .form-control { background: #f8f9fb; border-color: #e1e5ea; }
      .helper-links { display: flex; justify-content: space-between; font-size: 0.9rem; }
      @media (max-width: 991.98px) {
        .illustration { display: none; }
      }
    </style>
</head>
<body>
    <div class="login-shell">
      <div class="login-card">
        <div class="card-header-box">
          <div class="card-header-title">SOS Technical Training Institute</div>
          <div class="card-header-campus">(Infaq Foundation Campus)</div>
        </div>
        <div class="row g-0">
          <div class="col-lg-6 illustration">
            <div class="circle">
              <?php if ($hasLogo): ?>
                <div class="logo-badge">
                  <img src="<?php echo htmlspecialchars($logoWebPath); ?>" alt="Institute Logo" class="logo-img">
                </div>
              <?php else: ?>
                <i class="fa-solid fa-laptop"></i>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-lg-6 form-side">
            <h3 class="mb-3">Member Login</h3>
            <?php if (!empty($error)): ?>
              <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
              <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form action="login.php" method="POST" id="loginForm">
              <div class="mb-3">
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
                  <input type="text" class="form-control" id="identifier" name="identifier" required placeholder="Email" autofocus>
                </div>
              </div>

              <div class="mb-3">
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                  <input type="password" class="form-control" id="password" name="password" required placeholder="Password">
                  <button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Show/Hide"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
                </div>
              </div>

              <button type="submit" class="btn btn-success w-100 py-2">
                <i class="fa-solid fa-right-to-bracket me-2"></i>LOGIN
              </button>

            </form>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function(){
            const toggle = document.getElementById('togglePassword');
            if (toggle) {
                toggle.addEventListener('click', function(){
                    const input = document.getElementById('password');
                    if (!input) return;
                    const icon = this.querySelector('i');
                    if (input.type === 'password') {
                        input.type = 'text';
                        if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
                    } else {
                        input.type = 'password';
                        if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
                    }
                });
            }
        })();
    </script>
    <script>
      // AJAX login submission: loading indicator, validation, inline alerts, CSRF-safe
      document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('loginForm');
        if (!form) return;
        const submitBtn = form.querySelector('button[type="submit"]');
        let alertBox = form.querySelector('.alert');
        if (!alertBox) {
          alertBox = document.createElement('div');
          alertBox.className = 'alert d-none mt-2';
          form.appendChild(alertBox);
        }

        function showMessage(type, text) {
          alertBox.className = `alert alert-${type} mt-2`;
          alertBox.textContent = text || '';
        }

        function setLoading(loading) {
          if (!submitBtn) return;
          submitBtn.disabled = loading;
          submitBtn.innerHTML = loading
            ? '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>LOGIN'
            : '<i class="fa-solid fa-right-to-bracket me-2"></i>LOGIN';
        }

        form.addEventListener('submit', async function(e) {
          e.preventDefault();
          if (!form.checkValidity()) {
            form.classList.add('was-validated');
            showMessage('danger', 'Please fill in required fields.');
            return;
          }
          setLoading(true);
          showMessage('secondary', 'Authenticating…');
          const fd = new FormData(form);
          fd.append('ajax', '1');
          try {
            const res = await fetch('login.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
            const json = await res.json();
            if (json && json.success) {
              showMessage('success', 'Login successful. Redirecting…');
              const to = (json.redirect || 'dashboard.php');
              window.location.href = to;
            } else {
              showMessage('danger', (json && json.message) || 'Login failed');
            }
          } catch (err) {
            showMessage('danger', 'Network error. Please try again.');
          } finally {
            setLoading(false);
          }
        });
      });
    </script>
</body>
</html>
