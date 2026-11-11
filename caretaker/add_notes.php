<?php
session_start();

// 1. Ensure user is logged in and has the proper role
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array(strtolower($_SESSION['role']), ['admin', 'staff', 'caretaker'], true)
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

// 2. Direct non-POST requests back to health_information.php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: health_information.php');
    exit;
}

// 3. Validate CSRF Security Token
$csrf = $_SESSION['caretaker_report_csrf'] ?? '';
$postedToken = $_POST['csrf_token'] ?? '';

if (!is_string($postedToken) || !hash_equals($csrf, $postedToken)) {
    header('Location: health_information.php?error=Invalid+security+token');
    exit;
}

// 4. Sanitize and Validate Inputs
$residentPk = filter_input(INPUT_POST, 'resident_id', FILTER_VALIDATE_INT);
$noteContent = trim($_POST['additional_notes'] ?? '');

if (!$residentPk || $residentPk < 1 || $noteContent === '') {
    header('Location: health_information.php?error=Resident+and+note+content+are+required');
    exit;
}

try {
    // 5. Insert the new note into health_records
    $stmt = $pdo->prepare("
        INSERT INTO health_records (
            resident_id, 
            notes, 
            record_date
        ) VALUES (
            :resident_id, 
            :notes, 
            NOW()
        )
    ");
    
    $stmt->execute([
        'resident_id' => $residentPk,
        'notes'       => '[Caretaker Note]: ' . $noteContent
    ]);

    // 6. Redirect back with success message
    header('Location: health_information.php?success=note_added');
    exit;

} catch (PDOException $e) {
    error_log('Add note error: ' . $e->getMessage());
    header('Location: health_information.php?error=Unable+to+save+note');
    exit;
}