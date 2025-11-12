<?php
// Quick syntax validation test
echo "Testing dashboard.php syntax...\n";

// Check if file exists
if (file_exists('dashboard.php')) {
    echo "✓ dashboard.php file exists\n";
    
    // Try to include the file to check for syntax errors
    ob_start();
    $result = @include 'dashboard.php';
    $error = ob_get_clean();
    
    if (empty($error)) {
        echo "✓ dashboard.php syntax is valid\n";
    } else {
        echo "✗ Syntax error found: " . $error . "\n";
    }
} else {
    echo "✗ dashboard.php file not found\n";
}

echo "\nSyntax validation complete.\n";
?>