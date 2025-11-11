<?php
class Logger {
    private static function logPath(): string {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        return $dir . '/app.log';
    }

    public static function log(string $level, string $message, array $context = []): void {
        $ts = date('Y-m-d H:i:s');
        $ctx = '';
        if (!empty($context)) {
            // Prevent binary/large values
            foreach ($context as $k => $v) {
                if (is_string($v) && strlen($v) > 500) { $context[$k] = substr($v, 0, 500) . '…'; }
            }
            $ctx = ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $line = sprintf("%s [%s] %s%s\n", $ts, strtoupper($level), $message, $ctx);
        @file_put_contents(self::logPath(), $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void { self::log('info', $message, $context); }
    public static function warning(string $message, array $context = []): void { self::log('warning', $message, $context); }
    public static function error(string $message, array $context = []): void { self::log('error', $message, $context); }
}
?>
