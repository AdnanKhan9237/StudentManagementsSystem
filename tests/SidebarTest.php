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

echo "Running Layout tests (no sidebar)...\n";

// Capture dashboard output and ensure no sidebar is present.
ob_start();
include __DIR__ . '/../dashboard.php';
$html = ob_get_clean();

assertTrue(strpos($html, '<aside') === false, 'Dashboard does not render a sidebar');
assertTrue(strpos($html, 'id="cmdkOverlay"') !== false, 'Command palette overlay is present');
assertTrue(strpos($html, 'id="cmdkInput"') !== false, 'Command palette input is present');

echo "Layout tests complete.\n";
?>
