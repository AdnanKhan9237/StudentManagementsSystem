<?php
declare(strict_types=1);

require_once __DIR__ . '/SessionException.php';
require_once __DIR__ . '/../config/app.php';

/**
 * Session management with security, encryption, and utility helpers.
 *
 * Follows PSR-12 standards and maintains backward compatibility.
 */
class Session
{
    /** @var Session|null */
    private static ?Session $instance = null;

    /** @var int Session inactivity timeout in seconds */
    private int $timeout = 1800; // 30 minutes

    /** @var bool Whether HTTPS is used for secure cookies */
    private bool $secure = false;

    /** @var string Encryption key for session data */
    private string $encryptionKey;

    /**
     * Constructor.
     * Initializes secure cookie parameters, starts session safely,
     * enforces timeout, and regenerates session ID to prevent fixation.
     *
     * @return void
     */
    private function __construct()
    {
        $this->secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) === 'on';
        $this->encryptionKey = defined('APP_KEY') ? (string) APP_KEY : 'change_this_key';

        // Configure secure session cookies before session_start
        $cookieParams = [
            'lifetime' => $this->timeout,
            'path' => '/',
            'domain' => '',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        session_set_cookie_params($cookieParams);

        // Convert warnings during session_start to exceptions
        $previousHandler = set_error_handler(function ($severity, $message, $file, $line) {
            throw new SessionException('Session start error: ' . $message);
        });

        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
        } catch (SessionException $e) {
            if ($previousHandler !== null) {
                set_error_handler($previousHandler);
            } else {
                restore_error_handler();
            }
            throw $e;
        }

        // Restore original error handler
        if ($previousHandler !== null) {
            set_error_handler($previousHandler);
        } else {
            restore_error_handler();
        }

        // Establish fingerprint to validate integrity
        if (!isset($_SESSION['__fingerprint'])) {
            $_SESSION['__fingerprint'] = hash('sha256', ($this->encryptionKey ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
        }

        // Enforce session timeout
        $now = time();
        if (isset($_SESSION['__last_activity']) && ($now - (int) $_SESSION['__last_activity']) > $this->timeout) {
            $this->destroy();
            session_start();
        }
        $_SESSION['__last_activity'] = $now;

        // Prevent session fixation: regenerate ID at controlled intervals
        // Unconditional regeneration on every request can cause race conditions with concurrent AJAX.
        $now = time();
        $lastRegen = isset($_SESSION['__last_regen']) ? (int) $_SESSION['__last_regen'] : 0;
        // Regenerate at most every 5 minutes, and ensure it runs once after initial login
        if ($lastRegen === 0 || ($now - $lastRegen) > 300) {
            session_regenerate_id(true);
            $_SESSION['__last_regen'] = $now;
        }
    }

    /**
     * Singleton accessor.
     *
     * @return Session
     */
    public static function getInstance(): Session
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Validate a session key name against allowed pattern.
     *
     * @param string $key
     * @return void
     * @throws SessionException
     */
    private function validateKey(string $key): void
    {
        if ($key === '' || !preg_match('/^[a-zA-Z0-9_\.:-]+$/', $key)) {
            throw new SessionException('Invalid session key name.');
        }
    }

    /**
     * Encrypt arbitrary data for storage.
     *
     * @param mixed $value
     * @return string Base64-encoded payload (iv:ciphertext)
     */
    private function encrypt(mixed $value): string
    {
        $plain = json_encode($value, JSON_UNESCAPED_UNICODE);
        $iv = random_bytes(16);
        $cipher = 'AES-256-CBC';
        // Ensure key is 32 bytes
        $key = hash('sha256', $this->encryptionKey, true);
        $encrypted = openssl_encrypt($plain ?: '', $cipher, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv) . ':' . base64_encode($encrypted ?: '');
    }

    /**
     * Decrypt stored payload back to original value.
     *
     * @param string $payload Base64-encoded payload (iv:ciphertext)
     * @return mixed
     */
    private function decrypt(string $payload): mixed
    {
        [$bIv, $bEnc] = array_pad(explode(':', $payload, 2), 2, '');
        $iv = base64_decode($bIv, true) ?: '';
        $enc = base64_decode($bEnc, true) ?: '';
        $cipher = 'AES-256-CBC';
        $key = hash('sha256', $this->encryptionKey, true);
        $plain = openssl_decrypt($enc, $cipher, $key, OPENSSL_RAW_DATA, $iv) ?: '';
        $decoded = json_decode($plain, true);
        return $decoded === null && $plain !== '' ? $plain : $decoded;
    }

    /**
     * Set a session value (encrypted at rest).
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->validateKey($key);
        $_SESSION[$key] = ['__enc' => true, 'v' => $this->encrypt($value)];
    }

    /**
     * Get a session value (decrypts if necessary).
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        if (!isset($_SESSION[$key])) {
            return $default;
        }
        $val = $_SESSION[$key];
        if (is_array($val) && isset($val['__enc'], $val['v']) && $val['__enc'] === true) {
            return $this->decrypt((string) $val['v']);
        }
        // Backward compatibility for any plain values
        return $val;
    }

    /**
     * Check if a session key exists.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a specific session key.
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void
    {
        $this->validateKey($key);
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Destroy the current session and its data.
     *
     * @return void
     */
    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    /**
     * Regenerate the session ID.
     *
     * @return void
     */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Get current user ID.
     *
     * @return mixed
     */
    public function getUserId(): mixed
    {
        return $this->get('user_id');
    }

    /**
     * Set current user ID.
     *
     * @param mixed $userId
     * @return void
     */
    public function setUserId(mixed $userId): void
    {
        $this->set('user_id', $userId);
    }

    /**
     * Get current username.
     *
     * @return mixed
     */
    public function getUsername(): mixed
    {
        return $this->get('username');
    }

    /**
     * Set current username.
     *
     * @param string $username
     * @return void
     */
    public function setUsername(string $username): void
    {
        $this->set('username', $username);
    }

    /**
     * Check if the user is logged in.
     *
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        return $this->has('user_id') && $this->has('username');
    }

    /**
     * Set a flash message (removed after first read).
     *
     * @param string $key
     * @param mixed $message
     * @return void
     */
    public function setFlash(string $key, mixed $message): void
    {
        $this->validateKey($key);
        $_SESSION['flash'][$key] = ['__enc' => true, 'v' => $this->encrypt($message)];
    }

    /**
     * Get and consume a flash message.
     *
     * @param string $key
     * @return mixed
     */
    public function getFlash(string $key): mixed
    {
        $this->validateKey($key);
        if (isset($_SESSION['flash'][$key])) {
            $entry = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            if (is_array($entry) && isset($entry['__enc'], $entry['v']) && $entry['__enc'] === true) {
                return $this->decrypt((string) $entry['v']);
            }
            return $entry;
        }
        return null;
    }

    /**
     * Check if a flash message exists.
     *
     * @param string $key
     * @return bool
     */
    public function hasFlash(string $key): bool
    {
        $this->validateKey($key);
        return isset($_SESSION['flash'][$key]);
    }

    /**
     * Prevent unsafe serialization; reinitialize on wakeup and validate fingerprint.
     *
     * @return array
     */
    public function __sleep(): array
    {
        // Do not serialize any properties
        return [];
    }

    /**
     * Reinitialize session state and validate integrity.
     *
     * @return void
     */
    public function __wakeup(): void
    {
        // Validate fingerprint and reinitialize
        $expected = hash('sha256', ($this->encryptionKey ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
        if (isset($_SESSION['__fingerprint']) && $_SESSION['__fingerprint'] !== $expected) {
            $this->destroy();
            session_start();
        }
    }
}
?>
