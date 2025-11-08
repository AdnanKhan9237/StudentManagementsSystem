<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Session.php';

class Auth {
    private $db; // PDO connection
    private $session;

    public function __construct() {
        $this->db = (new Database())->getConnection();
        $this->session = Session::getInstance();
    }

    public function login($identifier, $password) {
        if (empty($identifier) || empty($password)) {
            return ['success' => false, 'message' => 'Email or CNIC and password are required.'];
        }

        // Fetch by email or CNIC
        $stmt = $this->db->prepare("SELECT id, username, email, cnic, password, role, is_active FROM users WHERE (email = ? OR cnic = ?) LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user || (isset($user['is_active']) && (int)$user['is_active'] !== 1) || !password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'Invalid credentials.'];
        }

        // Determine role: use DB role; if missing, default to 'teacher'
        $role = isset($user['role']) ? trim((string)$user['role']) : '';
        if ($role === '') {
            $role = 'teacher';
        }

        $this->session->setUserId($user['id']);
        $this->session->setUsername($user['username']);
        if (isset($user['email'])) {
            $this->session->set('email', $user['email']);
        }
        if (isset($user['cnic'])) {
            $this->session->set('cnic', $user['cnic']);
        }
        // Set session role based on resolved role
        $this->session->set('role', $role);

        // If a student logs in with the default password, require password change
        try {
            $isDefault = password_verify('Sostti123+', $user['password'] ?? '');
            if ($role === 'student' && $isDefault) {
                $this->session->set('must_change_password', true);
            } else {
                // Clear flag if not default
                $this->session->set('must_change_password', false);
            }
        } catch (Throwable $e) {
            // Ignore
        }
        $this->session->regenerate();

        return ['success' => true, 'message' => 'Login successful.'];
    }

    public function logout() {
        $this->session->destroy();
        session_start();
    }

    public function isLoggedIn() {
        return $this->session->isLoggedIn();
    }

    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $userId = $this->session->getUserId();
        $stmt = $this->db->prepare("SELECT id, username, role, created_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: index.php');
            exit();
        }

        // Enforce password change for students using default password
        $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $role = (string) $this->session->get('role');
        $must = (bool) $this->session->get('must_change_password');
        if ($role === 'student' && $must && $current !== 'change_password.php') {
            header('Location: change_password.php');
            exit();
        }
    }

    public function requireRole($roles) {
        if (!$this->isLoggedIn()) {
            header('Location: index.php');
            exit();
        }

        // Superadmin can access everything
        $userRole = (string) $this->session->get('role');
        if ($userRole === 'superadmin') {
            return;
        }

        if (!is_array($roles)) {
            $roles = [$roles];
        }

        if (!in_array($userRole, $roles, true)) {
            header('Location: dashboard.php');
            exit();
        }
    }

    /**
     * Check if current user is superadmin.
     *
     * @return bool
     */
    public function isSuperAdmin() {
        return $this->session->get('role') === 'superadmin';
    }

    public function register($username, $password, $role = 'teacher') {
        if (empty($username) || empty($password)) {
            return ['success' => false, 'message' => 'Username and password are required.'];
        }

        if (strlen($username) < 3) {
            return ['success' => false, 'message' => 'Username must be at least 3 characters long.'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'message' => 'Password must be at least 6 characters long.'];
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $existingUser = $stmt->fetch();
        if ($existingUser) {
            return ['success' => false, 'message' => 'Username already exists.'];
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("INSERT INTO users (username, password, role, created_at) VALUES (?, ?, ?, ?)");
        $ok = $stmt->execute([$username, $hashedPassword, $role, date('Y-m-d H:i:s')]);

        if ($ok) {
            return ['success' => true, 'message' => 'Registration successful.'];
        }
        return ['success' => false, 'message' => 'Registration failed. Please try again.'];
    }
}
?>
