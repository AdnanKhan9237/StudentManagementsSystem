<?php
declare(strict_types=1);

function assertTrue($cond, $msg)
{
    if (!$cond) {
        echo "[FAIL] $msg\n";
    } else {
        echo "[PASS] $msg\n";
    }
}

echo "Running Layout tests (sidebar restored)...\n";

// Capture dashboard output and ensure sidebar is present.
ob_start();
include __DIR__ . '/../dashboard.php';
$html = ob_get_clean();

assertTrue(strpos($html, '<aside') !== false, 'Dashboard renders a sidebar');
assertTrue(strpos($html, 'app-sidebar') !== false, 'Dashboard has app-sidebar class');
assertTrue(strpos($html, 'id="cmdkOverlay"') !== false, 'Command palette overlay is present');
assertTrue(strpos($html, 'id="cmdkInput"') !== false, 'Command palette input is present');

echo "Layout tests complete.\n";
?>
