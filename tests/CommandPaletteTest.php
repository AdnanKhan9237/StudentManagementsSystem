<?php
declare(strict_types=1);

// This test validates that the command palette includes account actions and theme controls,
// consolidating UX now that the sidebar is removed.

function assertTrue($cond, $msg)
{
    if (!$cond) {
        echo "[FAIL] $msg\n";
    } else {
        echo "[PASS] $msg\n";
    }
}

echo "Running Command Palette tests...\n";

// Buffer the output of the partial to capture its markup and the injected JS structures.
ob_start();
include __DIR__ . '/../partials/command_palette.php';
$output = ob_get_clean();

// Extract the JSON-encoded actions from the script block.
$actionsJson = null;
if (preg_match('/const\s+actions\s*=\s*(\[.*?\]);/s', $output, $m)) {
    $actionsJson = $m[1];
}

assertTrue($actionsJson !== null, 'actions JSON is present in command palette');

// Parse actions to a PHP array
$actions = [];
if ($actionsJson !== null) {
    $decoded = json_decode($actionsJson, true);
    if (is_array($decoded)) {
        $actions = $decoded;
    }
}

// Ensure palette actions include both Change Password and Log Out
$labels = array_map(static fn($a) => $a['label'] ?? '', $actions);
assertTrue(in_array('Change Password', $labels, true), 'Palette includes Change Password');
assertTrue(in_array('Log Out', $labels, true), 'Palette includes Log Out');
// Ensure theme toggle buttons exist in the palette UI
assertTrue(strpos($output, 'id="cmdkThemeDark"') !== false, 'Palette includes Dark theme button');
assertTrue(strpos($output, 'id="cmdkThemeLight"') !== false, 'Palette includes Light theme button');

echo "Command Palette tests complete.\n";
?>
