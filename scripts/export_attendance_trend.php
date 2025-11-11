<?php
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Session.php';
require_once __DIR__ . '/../classes/Database.php';

$auth = new Auth();
$session = Session::getInstance();
$db = (new Database())->getConnection();

// Require login for export
$auth->requireLogin();

// Prepare 14-day attendance trend
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="attendance_trend.csv"');

// Output CSV header
echo "date,count\n";

try {
    $stmt = $db->query(
        "SELECT att_date AS d, COUNT(*) AS c
         FROM attendance
         WHERE att_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
         GROUP BY att_date
         ORDER BY att_date"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Basic CSV line
        $date = (string)$row['d'];
        $count = (int)$row['c'];
        echo $date . "," . $count . "\n";
    }
} catch (Throwable $e) {
    // If query fails, still produce a single informative line
    echo date('Y-m-d') . ",0\n";
}
?>
