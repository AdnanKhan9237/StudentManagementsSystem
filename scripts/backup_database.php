<?php
// Simple automated backup script using mysqldump (preferred) on XAMPP/Windows.
// Usage (CLI): php scripts/backup_database.php
// Creates backups under scripts/backups/<db>_YYYYmmdd_HHMMSS.sql

require_once __DIR__ . '/../config/database.php';

function ensureDir(string $path): void {
    if (!is_dir($path)) { @mkdir($path, 0777, true); }
}

$outDir = __DIR__ . '/backups';
ensureDir($outDir);
$timestamp = date('Ymd_His');
$filename = sprintf('%s_%s.sql', DB_NAME, $timestamp);
$outFile = $outDir . '/' . $filename;

// Prefer XAMPP mysqldump path; fallback to PATH
$candidate = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
$mysqldump = file_exists($candidate) ? $candidate : 'mysqldump';

// Build command; on Windows use cmd /c for redirection to work reliably
$charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
$dumpCmd = sprintf('"%s" --host=%s --user=%s --password=%s --default-character-set=%s "%s" > "%s"',
    $mysqldump, DB_HOST, DB_USER, DB_PASS, $charset, DB_NAME, $outFile
);

$isWindows = stripos(PHP_OS, 'WIN') !== false;
$cmd = $isWindows ? ('cmd /c ' . $dumpCmd) : $dumpCmd;
$exitCode = 0;
system($cmd, $exitCode);

if ($exitCode !== 0 || !file_exists($outFile) || filesize($outFile) === 0) {
    echo "Backup failed. Ensure mysqldump is installed and accessible.\n";
    echo "Tried command: $dumpCmd\n";
    exit(1);
}

echo "Backup created: $outFile\n";

// Optional: Keep only last N backups (basic retention)
$retain = 20;
$files = glob($outDir . '/' . DB_NAME . '_*.sql');
rsort($files);
for ($i = $retain; $i < count($files); $i++) {
    @unlink($files[$i]);
}
?>
