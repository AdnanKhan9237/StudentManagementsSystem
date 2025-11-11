<?php
require_once __DIR__ . '/classes/Session.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$session = Session::getInstance();
$auth = new Auth();
// Only superadmin and accounts can add students
$auth->requireRole(['superadmin','accounts']);

$db = (new Database())->getConnection();

// Settings table for system-wide values (e.g., general number start)
$db->exec("CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(50) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Students table (profile information)
$db->exec("CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  picture_path VARCHAR(255) NULL,
  course_id INT NOT NULL,
  timing_id INT NOT NULL,
  fullname VARCHAR(100) NOT NULL,
  dob DATE NOT NULL,
  gender ENUM('male','female','other') NOT NULL,
  place_of_birth VARCHAR(100) NOT NULL DEFAULT 'Karachi',
  contact_personal VARCHAR(20) NOT NULL,
  contact_parent VARCHAR(20) NULL,
  cnic VARCHAR(25) NULL,
  address VARCHAR(255) NULL,
  guardian_name VARCHAR(100) NOT NULL,
  guardian_type ENUM('father','guardian') NOT NULL,
  last_school VARCHAR(100) NULL,
  qualification ENUM('Nill','Middle','Matric','Inter','Graduation','Masters') NOT NULL,
  total_fee DECIMAL(10,2) NOT NULL,
  submitted_fee DECIMAL(10,2) NOT NULL,
  remaining_fee DECIMAL(10,2) NOT NULL,
  receipt_number VARCHAR(50) NULL,
  general_number VARCHAR(50) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  INDEX (course_id), INDEX (timing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$errors = [];
$success = '';
$created = null;
$wantsJson = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) || (($_POST['ajax'] ?? '') === '1');
// Ensure registration_number column exists for user-provided registration number
try { $db->exec("ALTER TABLE students ADD COLUMN registration_number VARCHAR(50) NULL AFTER id"); } catch (Throwable $e) { /* ignore if exists */ }
try { $db->exec("ALTER TABLE students ADD UNIQUE KEY uniq_registration_number (registration_number)"); } catch (Throwable $e) { /* ignore if exists */ }
// Ensure academic_session_id and batch_id columns and indexes exist on students
try { $db->exec("ALTER TABLE students ADD COLUMN academic_session_id INT NOT NULL AFTER timing_id"); } catch (Throwable $e) { /* ignore if exists */ }
try { $db->exec("ALTER TABLE students ADD COLUMN batch_id INT NOT NULL AFTER academic_session_id"); } catch (Throwable $e) { /* ignore if exists */ }
try { $db->exec("ALTER TABLE students ADD INDEX idx_academic_session_id (academic_session_id)"); } catch (Throwable $e) { /* ignore if exists */ }
try { $db->exec("ALTER TABLE students ADD INDEX idx_batch_id (batch_id)"); } catch (Throwable $e) { /* ignore if exists */ }
// Ensure admission_date column exists to track date of admission
try { $db->exec("ALTER TABLE students ADD COLUMN admission_date DATE NULL AFTER general_number"); } catch (Throwable $e) { /* ignore if exists */ }
// Add trigger to prevent deleting GR No (general_number) once set
try {
  $db->exec("DROP TRIGGER IF EXISTS trg_students_general_number_protect");
  $db->exec(
    "CREATE TRIGGER trg_students_general_number_protect BEFORE UPDATE ON students FOR EACH ROW BEGIN IF (OLD.general_number IS NOT NULL AND NEW.general_number IS NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete GR No once generated'; END IF; END"
  );
} catch (Throwable $e) { /* ignore trigger creation errors */ }
// Default persisted values used to repopulate the form on errors
$old = [
  'registration_number' => '',
  'course_id' => '',
  'timing_id' => '',
  'academic_session_id' => '',
  'batch_id' => '',
  'fullname' => '',
  'dob' => '',
  'gender' => '',
  'place_of_birth' => 'Karachi',
  'contact_personal' => '',
  'contact_parent' => '',
  'cnic' => '',
  'address' => '',
  'guardian_name' => '',
  'guardian_type' => '',
  'last_school' => '',
  'qualification' => '',
  'total_fee' => '',
  'submitted_fee' => '',
  'remaining_fee' => '',
  'receipt_number' => '',
  'captured_image' => ''
  , 'admission_date' => ''
];

// Fetch dropdown data
$courses = $db->query('SELECT id, name FROM courses ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$timings = $db->query('SELECT id, name, day_of_week, start_time, end_time FROM timings ORDER BY FIELD(day_of_week, "Daily","Mon","Tue","Wed","Thu","Fri","Sat","Sun"), start_time ASC')->fetchAll(PDO::FETCH_ASSOC);
// Active academic sessions and all batches (batches link to sessions)
$academicSessions = $db->query('SELECT id, name FROM academic_sessions WHERE status = "active" ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
// Fetch batches including course_id if available (fallback for legacy schemas)
$batches = [];
try {
    $batches = $db->query('SELECT id, name, academic_session_id, timing_id, course_id FROM batches ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $batches = $db->query('SELECT id, name, academic_session_id, timing_id FROM batches ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($batches as &$b) { $b['course_id'] = null; }
unset($b);
}

// Build batch->timing IDs map (from junction table) for client-side filtering
$batchTimingIds = [];
$batchCourseIndex = [];
try {
    $batchIds = array_column($batches, 'id');
    if (!empty($batchIds)) {
        $in = implode(',', array_map('intval', $batchIds));
        $rs = $db->query("SELECT bt.batch_id, bt.timing_id FROM batch_timings bt WHERE bt.batch_id IN ($in)");
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bid = (int)$row['batch_id'];
            $tid = (int)$row['timing_id'];
            if (!isset($batchTimingIds[$bid])) { $batchTimingIds[$bid] = []; }
            $batchTimingIds[$bid][] = $tid;
        }
        // Build batch->course index
        foreach ($batches as $row) {
            $bid = (int)$row['id'];
            $cid = isset($row['course_id']) ? (int)$row['course_id'] : 0;
            if ($cid > 0) { $batchCourseIndex[$bid] = $cid; }
        }
    }
} catch (Throwable $e) { /* junction table may not exist; ignore */ }

// If editing, prefill form with existing student data
$isEdit = false;
$editId = (int)($_GET['edit_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $editId > 0) {
    $stmt = $db->prepare('SELECT id, registration_number, course_id, timing_id, academic_session_id, batch_id, fullname, dob, gender, place_of_birth, contact_personal, contact_parent, cnic, address, guardian_name, guardian_type, last_school, qualification, total_fee, submitted_fee, remaining_fee, receipt_number, general_number, admission_date, picture_path FROM students WHERE id = ?');
    $stmt->execute([$editId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $isEdit = true;
        $old['registration_number'] = (string)($row['registration_number'] ?? '');
        $old['course_id'] = (string)($row['course_id'] ?? '');
        $old['timing_id'] = (string)($row['timing_id'] ?? '');
        $old['academic_session_id'] = (string)($row['academic_session_id'] ?? '');
        $old['batch_id'] = (string)($row['batch_id'] ?? '');
        $old['fullname'] = (string)($row['fullname'] ?? '');
        $old['dob'] = (string)($row['dob'] ?? '');
        $old['gender'] = (string)($row['gender'] ?? '');
        $old['place_of_birth'] = (string)($row['place_of_birth'] ?? 'Karachi');
        $old['contact_personal'] = (string)($row['contact_personal'] ?? '');
        $old['contact_parent'] = (string)($row['contact_parent'] ?? '');
        $old['cnic'] = (string)($row['cnic'] ?? '');
        $old['address'] = (string)($row['address'] ?? '');
        $old['guardian_name'] = (string)($row['guardian_name'] ?? '');
        $old['guardian_type'] = (string)($row['guardian_type'] ?? '');
        $old['last_school'] = (string)($row['last_school'] ?? '');
        $old['qualification'] = (string)($row['qualification'] ?? '');
        $old['total_fee'] = (string)($row['total_fee'] ?? '');
        $old['submitted_fee'] = (string)($row['submitted_fee'] ?? '');
        $old['remaining_fee'] = (string)($row['remaining_fee'] ?? '');
        $old['receipt_number'] = (string)($row['receipt_number'] ?? '');
        $old['admission_date'] = (string)($row['admission_date'] ?? '');
        // picture_path is stored separately; keep captured_image empty by default
    }
}

function computeAge(string $dob): int {
    try {
        $b = new DateTime($dob);
        $n = new DateTime('today');
        return (int)$n->diff($b)->y;
    } catch (Throwable $e) { return -1; }
}

// Generate next registration number for a course on today's date
function nextRegistrationNumber(PDO $db, int $course_id): string {
    $today = date('Y-m-d');
    $dateTag = date('Ymd');
    $seqStmt = $db->prepare('SELECT COUNT(*) FROM students WHERE course_id = ? AND DATE(created_at) = ?');
    $seqStmt->execute([$course_id, $today]);
    $seq = ((int)$seqStmt->fetchColumn()) + 1;
    return $course_id . '-' . $dateTag . '-' . $seq;
}

// CSRF helpers consistent with other modules
function csrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function saveImageFromUploadOrCapture(array $file, ?string $capturedDataUrl): ?string {
    $baseDir = __DIR__ . '/assets/uploads/students';
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0777, true);
    }
    // If captured image provided as data URL, decode and compress to <= 250KB
    if ($capturedDataUrl) {
        $parts = explode(',', $capturedDataUrl, 2);
        if (count($parts) === 2) {
            $binary = base64_decode($parts[1]);
            if ($binary !== false) {
                $im = @imagecreatefromstring($binary);
                if ($im !== false) {
                    $filename = 'captured_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
                    $path = $baseDir . '/' . $filename;
                    // Compress iteratively to try to meet 250KB
                    $quality = 85;
                    for ($i = 0; $i < 6; $i++) {
                        @imagejpeg($im, $path, $quality);
                        if (@filesize($path) <= 250 * 1024) { break; }
                        $quality -= 10;
                        if ($quality < 40) { break; }
                    }
                    imagedestroy($im);
                    if (file_exists($path)) {
                        return 'assets/uploads/students/' . $filename;
                    }
                }
            }
        }
    }
    // Else handle file upload up to 2MB
    if (!empty($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $size = (int)($file['size'] ?? 0);
        if ($size > 2 * 1024 * 1024) { return null; }
        $tmp = $file['tmp_name'] ?? '';
        $ext = '.jpg';
        $name = 'upload_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . $ext;
        $dest = $baseDir . '/' . $name;
        $ok = @move_uploaded_file($tmp, $dest);
        if ($ok) { return 'assets/uploads/students/' . $name; }
    }
    return null;
}

// Lightweight API for previewing the next registration number
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'next_registration') {
    header('Content-Type: application/json');
    $course_id = (int)($_GET['course_id'] ?? 0);
    if ($course_id <= 0) { echo json_encode(['error' => 'course_id required']); exit; }
    echo json_encode(['registration_number' => nextRegistrationNumber($db, $course_id)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $errors[] = 'Invalid CSRF token.';
    } elseif ($action === 'create') {
        $registration_number = trim($_POST['registration_number'] ?? '');
        // Auto-set admission date to today; field is disabled in UI
        $admission_date = date('Y-m-d');
        $course_id = (int)($_POST['course_id'] ?? 0);
        $timing_id = (int)($_POST['timing_id'] ?? 0);
        $academic_session_id = (int)($_POST['academic_session_id'] ?? 0);
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        $fullname = trim($_POST['fullname'] ?? '');
        $dob = trim($_POST['dob'] ?? '');
        $gender = trim(strtolower($_POST['gender'] ?? ''));
        $place_of_birth = trim($_POST['place_of_birth'] ?? 'Karachi');
        $contact_personal = trim($_POST['contact_personal'] ?? '');
        $contact_parent = trim($_POST['contact_parent'] ?? '');
        $cnic = trim($_POST['cnic'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $guardian_name = trim($_POST['guardian_name'] ?? '');
        $guardian_type = trim(strtolower($_POST['guardian_type'] ?? ''));
        $last_school = trim($_POST['last_school'] ?? '');
        $qualification = trim($_POST['qualification'] ?? '');
        $total_fee = (float)($_POST['total_fee'] ?? 0);
        $submitted_fee = (float)($_POST['submitted_fee'] ?? 0);
        $receipt_number = trim($_POST['receipt_number'] ?? '');
        $captured_data = $_POST['captured_image'] ?? null;
        $picture_path = saveImageFromUploadOrCapture($_FILES['picture'] ?? [], $captured_data);

        // Auto-generate registration number based on course and today's date
        if ($course_id > 0) {
            $registration_number = nextRegistrationNumber($db, $course_id);
            $old['registration_number'] = $registration_number;
        } else {
            $registration_number = '';
            $old['registration_number'] = '';
        }

        // Admission date already set to today server-side

        // Preserve entered values for re-render on errors
        $old['course_id'] = $course_id > 0 ? (string)$course_id : '';
        $old['timing_id'] = $timing_id > 0 ? (string)$timing_id : '';
        $old['academic_session_id'] = $academic_session_id > 0 ? (string)$academic_session_id : '';
        $old['batch_id'] = $batch_id > 0 ? (string)$batch_id : '';
        $old['fullname'] = $fullname;
        $old['dob'] = $dob;
        $old['gender'] = $gender;
        $old['place_of_birth'] = $place_of_birth;
        $old['contact_personal'] = $contact_personal;
        $old['contact_parent'] = $contact_parent;
        $old['cnic'] = $cnic;
        $old['address'] = $address;
        $old['guardian_name'] = $guardian_name;
        $old['guardian_type'] = $guardian_type;
        $old['last_school'] = $last_school;
        $old['qualification'] = $qualification;
        $old['total_fee'] = ($total_fee > 0 ? (string)$total_fee : '');
        $old['submitted_fee'] = ($submitted_fee >= 0 ? (string)$submitted_fee : '');
        $old['receipt_number'] = $receipt_number;
        $old['captured_image'] = is_string($captured_data) ? $captured_data : '';
        $old['admission_date'] = $admission_date;

        // Persist entered values for re-render
        $old['registration_number'] = $registration_number;
        $old['admission_date'] = $admission_date;
        $old['course_id'] = $course_id > 0 ? (string)$course_id : '';
        $old['timing_id'] = $timing_id > 0 ? (string)$timing_id : '';
        $old['academic_session_id'] = $academic_session_id > 0 ? (string)$academic_session_id : '';
        $old['batch_id'] = $batch_id > 0 ? (string)$batch_id : '';
        $old['fullname'] = $fullname;
        $old['dob'] = $dob;
        $old['gender'] = $gender;
        $old['place_of_birth'] = $place_of_birth;
        $old['contact_personal'] = $contact_personal;
        $old['contact_parent'] = $contact_parent;
        $old['cnic'] = $cnic;
        $old['address'] = $address;
        $old['guardian_name'] = $guardian_name;
        $old['guardian_type'] = $guardian_type;
        $old['last_school'] = $last_school;
        $old['qualification'] = $qualification;
        $old['receipt_number'] = $receipt_number;
        // Validations
        // Registration number is optional; if provided must be unique (handled by DB constraint)
        if ($course_id <= 0) { $errors[] = 'Course is required.'; }
        if ($timing_id <= 0) { $errors[] = 'Timing is required.'; }
        if ($academic_session_id <= 0) { $errors[] = 'Session is required.'; }
        if ($batch_id <= 0) { $errors[] = 'Batch is required.'; }
        if ($fullname === '') { $errors[] = 'Fullname is required.'; }
        $age = computeAge($dob);
        if ($dob === '' || $age < 10 || $age > 40) { $errors[] = 'Date of birth must result in age between 10 and 40.'; }
        if (!in_array($gender, ['male','female','other'], true)) { $errors[] = 'Gender is invalid.'; }
        if ($contact_personal === '') { $errors[] = 'Personal contact is required.'; }
        if ($guardian_name === '') { $errors[] = 'Father/Guardian name is required.'; }
        if (!in_array($guardian_type, ['father','guardian'], true)) { $errors[] = 'Select whether name is Father or Guardian.'; }
        if (!in_array($qualification, ['Nill','Middle','Matric','Inter','Graduation','Masters'], true)) { $errors[] = 'Qualification is invalid.'; }
        // Admission date is system-set; format guaranteed
        if ($picture_path === null) { $errors[] = 'Picture is required (capture or upload).'; }

        // Ensure selected timing belongs to the chosen batch
        if ($batch_id > 0 && $timing_id > 0) {
            $assocCount = 0;
            try {
                $q = $db->prepare('SELECT COUNT(*) FROM batch_timings WHERE batch_id = ?');
                $q->execute([$batch_id]);
                $assocCount = (int)$q->fetchColumn();
            } catch (Throwable $e) {
                $assocCount = 0; // junction table may not exist
            }
            if ($assocCount > 0) {
                $chk = $db->prepare('SELECT COUNT(*) FROM batch_timings WHERE batch_id = ? AND timing_id = ?');
                $chk->execute([$batch_id, $timing_id]);
                if ((int)$chk->fetchColumn() === 0) {
                    $errors[] = 'Selected timing is not allowed for the chosen batch.';
                }
            } else {
                // Fallback to legacy single timing on batches
                $st = $db->prepare('SELECT timing_id FROM batches WHERE id = ?');
                $st->execute([$batch_id]);
                $legacyTid = (int)$st->fetchColumn();
                if ($legacyTid > 0 && $legacyTid !== $timing_id) {
                    $errors[] = 'Selected timing does not match the batch timing.';
                }
            }
        }
        // Ensure selected batch belongs to the chosen course (if schema supports it)
        if ($batch_id > 0 && $course_id > 0) {
            try {
                $stc = $db->prepare('SELECT course_id FROM batches WHERE id = ?');
                $stc->execute([$batch_id]);
                $batchCourseId = (int)$stc->fetchColumn();
                if ($batchCourseId > 0 && $batchCourseId !== $course_id) {
                    $errors[] = 'Selected batch does not belong to the chosen course.';
                }
            } catch (Throwable $e) { /* legacy schema may not have course_id; skip */ }
        }
        if ($total_fee <= 0) { $errors[] = 'Total fee is required.'; }
        if ($submitted_fee < 0) { $errors[] = 'Submitted fee must be non-negative.'; }
        if ($submitted_fee > 0 && $receipt_number === '') { $errors[] = 'Receipt number is required when a fee is submitted.'; }
        if ($receipt_number !== '' && $submitted_fee <= 0) { $errors[] = 'Submitted fee must be greater than 0 when a receipt number is provided.'; }
        if ($submitted_fee > $total_fee) { $errors[] = 'Submitted fee cannot exceed total fee.'; }
        $remaining_fee = max(0.0, round($total_fee - $submitted_fee, 2));
        $old['total_fee'] = ($total_fee > 0 ? (string)$total_fee : '');
        $old['submitted_fee'] = ($submitted_fee >= 0 ? (string)$submitted_fee : '');
        $old['remaining_fee'] = (string)$remaining_fee;

        // Validate that selected batch belongs to selected session
        if ($academic_session_id > 0 && $batch_id > 0) {
            $stmtBatch = $db->prepare('SELECT academic_session_id FROM batches WHERE id = ?');
            $stmtBatch->execute([$batch_id]);
            $batchRow = $stmtBatch->fetch(PDO::FETCH_ASSOC);
            if (!$batchRow) {
                $errors[] = 'Selected batch does not exist.';
            } elseif ((int)$batchRow['academic_session_id'] !== (int)$academic_session_id) {
                $errors[] = 'Selected batch does not belong to the chosen session.';
            }
        }
        $old['remaining_fee'] = (string)$remaining_fee;

        // Generate general number for admission (independent of receipt/payment)
        $general_number = null;
        {
            $row = $db->prepare('SELECT value FROM settings WHERE `key` = ?');
            $row->execute(['general_number_next']);
            $val = $row->fetch(PDO::FETCH_ASSOC);
            $current = (int)($val['value'] ?? 0);
            if ($current <= 0) { $errors[] = 'General number start value not configured by superadmin.'; }
            else {
                $general_number = (string)$current;
                // increment
                $upd = $db->prepare('REPLACE INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, ?)');
                $upd->execute(['general_number_next', (string)($current + 1), date('Y-m-d H:i:s')]);
            }
        }

        if (empty($errors)) {
            $stmt = $db->prepare('INSERT INTO students (registration_number, user_id, picture_path, course_id, timing_id, academic_session_id, batch_id, fullname, dob, gender, place_of_birth, contact_personal, contact_parent, cnic, address, guardian_name, guardian_type, last_school, qualification, total_fee, submitted_fee, remaining_fee, receipt_number, general_number, admission_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ok = $stmt->execute([
                $registration_number !== '' ? $registration_number : null,
                null, $picture_path, $course_id, $timing_id, $academic_session_id, $batch_id, $fullname, $dob, $gender, $place_of_birth,
                $contact_personal, ($contact_parent !== '' ? $contact_parent : null), ($cnic !== '' ? $cnic : null), ($address !== '' ? $address : null),
                $guardian_name, $guardian_type, ($last_school !== '' ? $last_school : null), $qualification,
                $total_fee, $submitted_fee, $remaining_fee,
                ($receipt_number !== '' ? $receipt_number : null), $general_number,
                $admission_date,
                date('Y-m-d H:i:s')
            ]);
            if ($ok) {
                $newId = (int)$db->lastInsertId();
                $success = 'Student added successfully.';
                $created = ['id' => $newId, 'registration_number' => $registration_number, 'fullname' => $fullname, 'course_id' => $course_id, 'timing_id' => $timing_id, 'general_number' => $general_number, 'admission_date' => $admission_date];
                // Reset form on success
                $old = [
                  'registration_number' => '', 'admission_date' => '', 'course_id' => '', 'timing_id' => '', 'academic_session_id' => '', 'batch_id' => '', 'fullname' => '', 'dob' => '', 'gender' => '', 'place_of_birth' => 'Karachi',
                  'contact_personal' => '', 'contact_parent' => '', 'cnic' => '', 'address' => '', 'guardian_name' => '', 'guardian_type' => '',
                  'last_school' => '', 'qualification' => '', 'total_fee' => '', 'submitted_fee' => '', 'remaining_fee' => '', 'receipt_number' => '', 'captured_image' => ''
                ];
            } else {
                $errors[] = 'Failed to add student.';
            }
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => empty($errors) && $success !== '',
                'message' => empty($errors) ? $success : implode("\n", $errors),
                'csrf_token' => (string)($_SESSION['csrf_token'] ?? ''),
                'created' => $created
            ]);
            exit();
        }
    } elseif ($action === 'update') {
        $edit_id = (int)($_POST['edit_id'] ?? 0);
        if ($edit_id <= 0) {
            $errors[] = 'Missing edit ID.';
        } else {
            // Load existing immutable fields
            $cur = $db->prepare('SELECT registration_number, general_number, admission_date, picture_path FROM students WHERE id = ?');
            $cur->execute([$edit_id]);
            $curRow = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$curRow) {
                $errors[] = 'Student not found.';
            } else {
                // Collect updated fields
                $course_id = (int)($_POST['course_id'] ?? 0);
                $timing_id = (int)($_POST['timing_id'] ?? 0);
                $academic_session_id = (int)($_POST['academic_session_id'] ?? 0);
                $batch_id = (int)($_POST['batch_id'] ?? 0);
                $fullname = trim($_POST['fullname'] ?? '');
                $dob = trim($_POST['dob'] ?? '');
                $gender = trim(strtolower($_POST['gender'] ?? ''));
                $place_of_birth = trim($_POST['place_of_birth'] ?? 'Karachi');
                $contact_personal = trim($_POST['contact_personal'] ?? '');
                $contact_parent = trim($_POST['contact_parent'] ?? '');
                $cnic = trim($_POST['cnic'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $guardian_name = trim($_POST['guardian_name'] ?? '');
                $guardian_type = trim(strtolower($_POST['guardian_type'] ?? ''));
                $last_school = trim($_POST['last_school'] ?? '');
                $qualification = trim($_POST['qualification'] ?? '');
                $total_fee = (float)($_POST['total_fee'] ?? 0);
                $submitted_fee = (float)($_POST['submitted_fee'] ?? 0);
                $remaining_fee = max(0.0, $total_fee - $submitted_fee);
                $receipt_number = trim($_POST['receipt_number'] ?? '');

                // Basic validations
                if ($fullname === '') { $errors[] = 'Fullname is required.'; }
                if ($contact_personal === '') { $errors[] = 'Personal contact is required.'; }
                if ($guardian_name === '') { $errors[] = 'Father/Guardian name is required.'; }
                if ($total_fee <= 0) { $errors[] = 'Total fee is required.'; }
                if ($submitted_fee < 0) { $errors[] = 'Submitted fee must be non-negative.'; }
                if ($submitted_fee > 0 && $receipt_number === '') { $errors[] = 'Receipt number is required when a fee is submitted.'; }
                if ($receipt_number !== '' && $submitted_fee <= 0) { $errors[] = 'Submitted fee must be greater than 0 when a receipt number is provided.'; }
                if ($submitted_fee > $total_fee) { $errors[] = 'Submitted fee cannot exceed total fee.'; }

                // Preserve entered values in $old
                $old['course_id'] = $course_id > 0 ? (string)$course_id : '';
                $old['timing_id'] = $timing_id > 0 ? (string)$timing_id : '';
                $old['academic_session_id'] = $academic_session_id > 0 ? (string)$academic_session_id : '';
                $old['batch_id'] = $batch_id > 0 ? (string)$batch_id : '';
                $old['fullname'] = $fullname;
                $old['dob'] = $dob;
                $old['gender'] = $gender;
                $old['place_of_birth'] = $place_of_birth;
                $old['contact_personal'] = $contact_personal;
                $old['contact_parent'] = $contact_parent;
                $old['cnic'] = $cnic;
                $old['address'] = $address;
                $old['guardian_name'] = $guardian_name;
                $old['guardian_type'] = $guardian_type;
                $old['last_school'] = $last_school;
                $old['qualification'] = $qualification;
                $old['total_fee'] = ($total_fee > 0 ? (string)$total_fee : '');
                $old['submitted_fee'] = ($submitted_fee >= 0 ? (string)$submitted_fee : '');
                $old['remaining_fee'] = (string)$remaining_fee;
                $old['receipt_number'] = $receipt_number;
                $old['registration_number'] = (string)($curRow['registration_number'] ?? '');
                $old['admission_date'] = (string)($curRow['admission_date'] ?? '');

                if (empty($errors)) {
                    $stmt = $db->prepare('UPDATE students SET course_id = ?, timing_id = ?, academic_session_id = ?, batch_id = ?, fullname = ?, dob = ?, gender = ?, place_of_birth = ?, contact_personal = ?, contact_parent = ?, cnic = ?, address = ?, guardian_name = ?, guardian_type = ?, last_school = ?, qualification = ?, total_fee = ?, submitted_fee = ?, remaining_fee = ?, receipt_number = ?, updated_at = ? WHERE id = ?');
                    $ok = $stmt->execute([
                        $course_id, $timing_id, $academic_session_id, $batch_id, $fullname, $dob, $gender, $place_of_birth,
                        $contact_personal, ($contact_parent !== '' ? $contact_parent : null), ($cnic !== '' ? $cnic : null), ($address !== '' ? $address : null),
                        $guardian_name, $guardian_type, ($last_school !== '' ? $last_school : null), $qualification,
                        $total_fee, $submitted_fee, $remaining_fee,
                        ($receipt_number !== '' ? $receipt_number : null),
                        date('Y-m-d H:i:s'),
                        $edit_id
                    ]);
                    if ($ok) {
                        $success = 'Student updated successfully.';
                    } else {
                        $errors[] = 'Failed to update student.';
                    }
                }

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                if ($wantsJson) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => empty($errors) && $success !== '',
                        'message' => empty($errors) ? $success : implode("\n", $errors),
                        'csrf_token' => (string)($_SESSION['csrf_token'] ?? ''),
                        'updated' => ['id' => $edit_id]
                    ]);
                    exit();
                }
            }
        }
    }
}

$csrf = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Add Student</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/design-system.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<?php include_once __DIR__ . '/partials/command_palette.php'; ?>
<main class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?php echo $isEdit ? 'Edit Student' : 'Add Student'; ?></h1>
    <a href="dashboard.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back to Dashboard</a>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <?php foreach ($errors as $e): ?>
        <div><?php echo htmlspecialchars($e); ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">Student Information</div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" id="addStudentForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="action" value="<?php echo $isEdit ? 'update' : 'create'; ?>">
        <?php if ($isEdit): ?>
          <input type="hidden" name="edit_id" value="<?php echo (int)$editId; ?>">
        <?php endif; ?>
        <input type="hidden" name="captured_image" id="capturedImageInput" value="<?php echo htmlspecialchars($old['captured_image']); ?>">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Registration Number</label>
            <input type="text" id="registrationNumberDisplay" class="form-control" value="<?php echo htmlspecialchars($old['registration_number']); ?>" disabled>
            <div class="form-text">Auto-generated from course and date; not editable.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Date of Admission</label>
            <input type="date" id="admissionDateDisplay" class="form-control" value="<?php echo htmlspecialchars($old['admission_date'] !== '' ? $old['admission_date'] : date('Y-m-d')); ?>" disabled>
            <div class="form-text">Auto-set to today; not editable.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Course*</label>
            <select name="course_id" class="form-select" required>
              <option value="">Select course</option>
              <?php foreach ($courses as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>" <?php echo ((string)$c['id'] === (string)$old['course_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Timing*</label>
            <select name="timing_id" class="form-select" required>
              <option value="">Select timing</option>
              <?php foreach ($timings as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo ((string)$t['id'] === (string)$old['timing_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars(($t['name'] ? $t['name'].' - ' : '') . $t['day_of_week'] . ' ' . $t['start_time'] . '–' . $t['end_time']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Session*</label>
            <select name="academic_session_id" id="academicSessionSelect" class="form-select" required>
              <option value="">Select session</option>
              <?php foreach ($academicSessions as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" <?php echo ((string)$s['id'] === (string)$old['academic_session_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Batch*</label>
            <select name="batch_id" id="batchSelect" class="form-select" required>
              <option value="">Select batch</option>
              <?php foreach ($batches as $b): ?>
                <option value="<?php echo (int)$b['id']; ?>" data-session="<?php echo (int)$b['academic_session_id']; ?>" data-course="<?php echo (int)$b['course_id']; ?>" data-legacy-timing="<?php echo (int)$b['timing_id']; ?>" <?php echo ((string)$b['id'] === (string)$old['batch_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Only batches for the selected session are allowed.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Fullname*</label>
            <input type="text" name="fullname" class="form-control" required value="<?php echo htmlspecialchars($old['fullname']); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Date of Birth*</label>
            <input type="date" name="dob" class="form-control" required value="<?php echo htmlspecialchars($old['dob']); ?>">
            <div class="form-text">Age must be 10–40 years.</div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Gender*</label>
            <select name="gender" class="form-select" required>
              <option value="">Select</option>
              <option value="male" <?php echo ($old['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
              <option value="female" <?php echo ($old['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
              <option value="other" <?php echo ($old['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Place of Birth</label>
            <input type="text" name="place_of_birth" class="form-control" value="<?php echo htmlspecialchars($old['place_of_birth']); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Contact (Personal)*</label>
            <input type="text" name="contact_personal" class="form-control" required placeholder="e.g. 03001234567" value="<?php echo htmlspecialchars($old['contact_personal']); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Contact (Parent)</label>
            <input type="text" name="contact_parent" class="form-control" placeholder="optional" value="<?php echo htmlspecialchars($old['contact_parent']); ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label">CNIC</label>
            <input type="text" name="cnic" class="form-control" placeholder="e.g. 12345-1234567-1" value="<?php echo htmlspecialchars($old['cnic']); ?>">
          </div>
          <div class="col-md-8">
            <label class="form-label">Address</label>
            <input type="text" name="address" class="form-control" placeholder="street, area, city" value="<?php echo htmlspecialchars($old['address']); ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label">Father/Guardian Name*</label>
            <input type="text" name="guardian_name" class="form-control" required value="<?php echo htmlspecialchars($old['guardian_name']); ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label d-block">Is this Father or Guardian?*</label>
            <div class="d-flex gap-3">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="guardian_type" id="gtFather" value="father" required <?php echo ($old['guardian_type'] === 'father') ? 'checked' : ''; ?>>
                <label class="form-check-label" for="gtFather">Father</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="guardian_type" id="gtGuardian" value="guardian" required <?php echo ($old['guardian_type'] === 'guardian') ? 'checked' : ''; ?>>
                <label class="form-check-label" for="gtGuardian">Guardian</label>
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Last School Name</label>
            <input type="text" name="last_school" class="form-control" placeholder="optional" value="<?php echo htmlspecialchars($old['last_school']); ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Qualification*</label>
            <select name="qualification" class="form-select" required>
              <option value="">Select</option>
              <option value="Nill" <?php echo ($old['qualification'] === 'Nill') ? 'selected' : ''; ?>>Nill</option>
              <option value="Middle" <?php echo ($old['qualification'] === 'Middle') ? 'selected' : ''; ?>>Middle</option>
              <option value="Matric" <?php echo ($old['qualification'] === 'Matric') ? 'selected' : ''; ?>>Matric</option>
              <option value="Inter" <?php echo ($old['qualification'] === 'Inter') ? 'selected' : ''; ?>>Inter</option>
              <option value="Graduation" <?php echo ($old['qualification'] === 'Graduation') ? 'selected' : ''; ?>>Graduation</option>
              <option value="Masters" <?php echo ($old['qualification'] === 'Masters') ? 'selected' : ''; ?>>Masters</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Total Fee* (PKR)</label>
            <input type="number" step="0.01" name="total_fee" class="form-control" required value="<?php echo htmlspecialchars($old['total_fee']); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Submitted Fee* (PKR)</label>
            <input type="number" step="0.01" name="submitted_fee" class="form-control" required value="<?php echo htmlspecialchars($old['submitted_fee']); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Remaining Fee (auto)</label>
            <input type="text" id="remainingFee" class="form-control" disabled value="<?php echo htmlspecialchars($old['remaining_fee']); ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label">Receipt Number</label>
            <input type="text" name="receipt_number" id="receiptNumber" class="form-control" placeholder="optional" value="<?php echo htmlspecialchars($old['receipt_number']); ?>">
            <div class="form-text">General number is generated only when a receipt number is provided. Once generated, it cannot be deleted; only updated.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">General Number (auto)</label>
            <input type="text" class="form-control" id="generalNumberPreview" disabled placeholder="Will auto-generate on submit if receipt present">
          </div>

          <div class="col-12">
            <label class="form-label">Picture* (Capture or Upload)</label>
            <div class="row g-3">
              <div class="col-md-6">
                <div class="border rounded p-2">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-muted">Live Camera (compresses to ≤250KB)</span>
                    <div class="d-flex gap-2">
                      <button type="button" class="btn btn-sm btn-outline-secondary" id="startCamBtn"><i class="fa-solid fa-camera"></i> Start</button>
                      <button type="button" class="btn btn-sm btn-outline-primary" id="captureBtn"><i class="fa-solid fa-circle-dot"></i> Capture</button>
                    </div>
                  </div>
                  <video id="camVideo" class="w-100" autoplay playsinline muted style="max-height:240px; background:#000"></video>
                  <canvas id="camCanvas" class="d-none"></canvas>
                  <div class="form-text">Use this to capture a photo from the webcam.</div>
                </div>
              </div>
              <div class="col-md-6">
                <input type="file" name="picture" accept="image/*" class="form-control">
                <div class="form-text">If uploading, max file size is 2MB.</div>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-3">
          <button class="btn btn-primary" type="submit">Add Student</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($created): ?>
    <div class="card">
      <div class="card-header">Registration Summary</div>
      <div class="card-body">
        <p class="mb-1">Registration Number: <strong><?php echo htmlspecialchars($created['registration_number'] ?? 'N/A'); ?></strong></p>
        <p class="mb-1">Date of Admission: <strong><?php echo htmlspecialchars($created['admission_date'] ?? ''); ?></strong></p>
        <p class="mb-1">ID: <strong><?php echo (int)$created['id']; ?></strong></p>
        <p class="mb-1">Fullname: <strong><?php echo htmlspecialchars($created['fullname']); ?></strong></p>
        <p class="mb-1">General Number: <strong><?php echo htmlspecialchars($created['general_number'] ?? ''); ?></strong></p>
      </div>
    </div>
  <?php endif; ?>
</main>

<script>
// Compute remaining fee client-side for preview
(() => {
  const total = document.querySelector('input[name="total_fee"]');
  const submitted = document.querySelector('input[name="submitted_fee"]');
  const remaining = document.getElementById('remainingFee');
  function update() {
    const t = parseFloat(total.value || '0');
    const s = parseFloat(submitted.value || '0');
    const r = Math.max(0, (isNaN(t)?0:t) - (isNaN(s)?0:s));
    remaining.value = r.toFixed(2);
  }
  if (total && submitted && remaining) {
    total.addEventListener('input', update);
    submitted.addEventListener('input', update);
  }
})();
// Preview auto-generated Registration Number when course changes
(() => {
  const courseSel = document.querySelector('select[name="course_id"]');
  const regInput = document.getElementById('registrationNumberDisplay');
  async function updateReg() {
    if (!courseSel || !regInput) return;
    const cid = courseSel.value;
    if (!cid) { regInput.value = ''; return; }
    try {
      const res = await fetch('add_student.php?action=next_registration&course_id=' + encodeURIComponent(cid));
      const data = await res.json();
      if (data && data.registration_number) {
        regInput.value = data.registration_number;
      }
    } catch (e) { /* ignore */ }
  }
  if (courseSel) {
    courseSel.addEventListener('change', updateReg);
    updateReg();
  }
})();

// Webcam capture and compression to JPEG (approximate)
(() => {
  const startBtn = document.getElementById('startCamBtn');
  const captureBtn = document.getElementById('captureBtn');
  const video = document.getElementById('camVideo');
  const canvas = document.getElementById('camCanvas');
  const hiddenInput = document.getElementById('capturedImageInput');
  let stream = null;
  async function startCam() {
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
      video.srcObject = stream;
    } catch (e) { alert('Unable to access camera.'); }
  }
  function capture() {
    try {
      if (!video.videoWidth) { alert('Camera not ready'); return; }
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0);
      // Try a few quality levels to keep around 250KB
      let quality = 0.85;
      let dataUrl = canvas.toDataURL('image/jpeg', quality);
      for (let i = 0; i < 4; i++) {
        if (dataUrl.length <= 250 * 1024 * 1.37) break; // base64 expansion factor ~1.37
        quality -= 0.1;
        if (quality < 0.4) break;
        dataUrl = canvas.toDataURL('image/jpeg', quality);
      }
      hiddenInput.value = dataUrl;
      alert('Photo captured.');
    } catch (e) { alert('Failed to capture photo.'); }
  }
  if (startBtn) startBtn.addEventListener('click', startCam);
  if (captureBtn) captureBtn.addEventListener('click', capture);
})();
// Filter batches by selected course and session
(() => {
  const sessionSel = document.getElementById('academicSessionSelect');
  const batchSel = document.getElementById('batchSelect');
  const courseSel = document.querySelector('select[name="course_id"]');
  function filterBatches() {
    if (!sessionSel || !batchSel) return;
    const sid = sessionSel.value;
    const cid = courseSel ? courseSel.value : '';
    const options = Array.from(batchSel.querySelectorAll('option'));
    options.forEach(opt => {
      const ds = opt.getAttribute('data-session');
      const dc = opt.getAttribute('data-course');
      if (!ds) return; // skip placeholder
      const sessionOk = !!sid ? (ds === sid) : true;
      const courseOk = !!cid ? (dc === cid) : true;
      opt.hidden = !(sessionOk && courseOk);
    });
    const selOpt = batchSel.selectedOptions[0];
    if (selOpt && selOpt.hidden) {
      batchSel.value = '';
    }
    // Disable batch until a session is selected
    const hasSession = !!sid;
    batchSel.disabled = !hasSession;
    if (!hasSession) {
      batchSel.value = '';
    }
  }
  if (sessionSel) {
    sessionSel.addEventListener('change', filterBatches);
    filterBatches();
  }
  if (courseSel) {
    courseSel.addEventListener('change', filterBatches);
  }
})();
// When a batch is selected, restrict Course options to that batch's course
(() => {
  const batchSel = document.getElementById('batchSelect');
  const courseSel = document.querySelector('select[name="course_id"]');
  function syncCourseOptions() {
    if (!courseSel) return;
    const bid = batchSel ? batchSel.value : '';
    const bcIndex = (window.__batchCourseIndex || {});
    const cid = bid && bcIndex[bid] ? String(bcIndex[bid]) : '';
    const options = Array.from(courseSel.querySelectorAll('option'));
    if (cid) {
      options.forEach(opt => { if (opt.value) opt.hidden = (opt.value !== cid); });
      courseSel.value = cid;
      courseSel.disabled = false;
    } else {
      options.forEach(opt => { opt.hidden = false; });
      courseSel.value = '';
      courseSel.disabled = true;
    }
  }
  if (batchSel) {
    batchSel.addEventListener('change', syncCourseOptions);
    syncCourseOptions();
  }
})();
// Embed batch->timings index for client-side filtering
window.__batchTimingsIndex = <?php echo json_encode($batchTimingIds, JSON_UNESCAPED_UNICODE); ?>;
window.__batchCourseIndex = <?php echo json_encode($batchCourseIndex, JSON_UNESCAPED_UNICODE); ?>;

// Filter timings by selected batch (uses junction table, falls back to legacy timing)
(() => {
  const batchSel = document.getElementById('batchSelect');
  const timingSel = document.querySelector('select[name="timing_id"]');
  function filterTimings() {
    if (!timingSel) return;
    const bid = batchSel ? batchSel.value : '';
    const allowedList = (bid && window.__batchTimingsIndex && window.__batchTimingsIndex[bid]) ? window.__batchTimingsIndex[bid].map(String) : [];
    // Fallback: legacy single timing per batch if no junction entries
    let legacy = '';
    const selectedBatchOpt = batchSel && batchSel.selectedOptions[0] ? batchSel.selectedOptions[0] : null;
    if (allowedList.length === 0 && selectedBatchOpt) {
      legacy = selectedBatchOpt.getAttribute('data-legacy-timing') || '';
      if (legacy) allowedList.push(String(legacy));
    }
    const options = Array.from(timingSel.querySelectorAll('option'));
    let selectedVisible = false;
    options.forEach(opt => {
      if (!opt.value) { opt.hidden = false; return; }
      const visible = bid && allowedList.length > 0 ? allowedList.includes(opt.value) : false;
      opt.hidden = !visible;
      if (visible && opt.selected) selectedVisible = true;
    });
    timingSel.disabled = !(bid && allowedList.length > 0);
    if (!selectedVisible) {
      timingSel.value = '';
      const firstVisible = options.find(o => !o.hidden && o.value);
      if (firstVisible) timingSel.value = firstVisible.value;
    }
  }
  if (batchSel && timingSel) {
    batchSel.addEventListener('change', filterTimings);
    const sessionSel = document.getElementById('academicSessionSelect');
    if (sessionSel) sessionSel.addEventListener('change', () => setTimeout(filterTimings, 0));
    // initialize
    filterTimings();
  }
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

