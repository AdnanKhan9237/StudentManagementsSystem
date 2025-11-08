<?php
declare(strict_types=1);

// Application configuration
// IMPORTANT: Change APP_KEY to a long, random string for production.
// Example generation (CLI): php -r "echo bin2hex(random_bytes(32));"
define('APP_KEY', 'change_this_key_to_a_random_32byte_hex_string');

// Optional: Default session timeout in seconds (can be adjusted in Session class)
define('APP_SESSION_TIMEOUT', 1800);

// Site metadata (used in titles and redirects)
define('SITE_NAME', 'Student Management System');
define('SITE_URL', 'http://localhost/sms/');
?>