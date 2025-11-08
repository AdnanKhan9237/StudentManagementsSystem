<?php
// Silence dev tooling requests to /@vite/dashboard.php
// Return 204 No Content to avoid console errors without affecting page content.
http_response_code(204);
header('Content-Type: application/javascript');
exit;

