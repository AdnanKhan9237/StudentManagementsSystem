<?php
require_once __DIR__ . '/../classes/Database.php';

$db = (new Database())->getConnection();

echo "Starting attendance v2 migration...\n";
try {
    $db->exec("ALTER TABLE attendance MODIFY COLUMN student_id INT NULL");
    echo "- Made student_id nullable\n";
} catch (Throwable $e) { echo "- Skipped: student_id already nullable\n"; }

try {
    $db->exec("ALTER TABLE attendance MODIFY COLUMN status ENUM('present','absent','leave') NOT NULL");
    echo "- Ensured status includes 'leave'\n";
} catch (Throwable $e) { echo "- Skipped: status already includes 'leave'\n"; }

try {
    $db->exec("ALTER TABLE attendance ADD COLUMN student_record_id INT NULL");
    echo "- Added student_record_id column\n";
} catch (Throwable $e) { echo "- Skipped: student_record_id exists\n"; }
try { $db->exec("ALTER TABLE attendance ADD INDEX idx_student_record_id (student_record_id)"); echo "- Added idx_student_record_id\n"; } catch (Throwable $e) { echo "- Skipped: idx_student_record_id exists\n"; }
try { $db->exec("ALTER TABLE attendance ADD UNIQUE KEY uniq_student_record_date (student_record_id, att_date)"); echo "- Added uniq_student_record_date\n"; } catch (Throwable $e) { echo "- Skipped: uniq_student_record_date exists\n"; }

// Drop legacy unique on (student_id, att_date) if present
try { $db->exec("ALTER TABLE attendance DROP INDEX uniq_student_date"); echo "- Dropped uniq_student_date\n"; } catch (Throwable $e) { echo "- Skipped: uniq_student_date absent\n"; }
// Backfill student_record_id using CNIC mapping
try {
    $sql = "UPDATE attendance a
            JOIN users u ON u.id = a.student_id
            JOIN students s ON s.cnic = u.cnic
            SET a.student_record_id = s.id
            WHERE a.student_record_id IS NULL";
    $count = $db->exec($sql);
    echo "- Backfilled student_record_id for $count rows\n";
} catch (Throwable $e) { echo "- Skipped: backfill failed: " . $e->getMessage() . "\n"; }

echo "Migration complete.\n";
?>

