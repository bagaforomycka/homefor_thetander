<?php
session_start();

// Authentication and Role Authorization
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$errors = [];

// Fetch Recipients List (Admin, Staff, Caretaker) excluding the current user
$recipients = [];
try {
    $stmtUsers = $pdo->prepare("SELECT id, full_name, role FROM users WHERE role IN ('admin', 'staff', 'caretaker') AND id != ? ORDER BY role ASC, full_name ASC");
    $stmtUsers->execute([$_SESSION['user_id']]);
    $recipients = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recipients = [];
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipientId = trim($_POST['recipient_id'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $messageText = trim($_POST['message_text'] ?? '');

    // Form Validations
    if (empty($recipientId)) { $errors[] = 'Please select a recipient.'; }
    if (empty($subject)) { $errors[] = 'Subject is required.'; }
    if (empty($messageText)) { $errors[] = 'Message content is required.'; }

    // Optional File Attachment Handling
    $attachmentPath = null;
    if (empty($errors) && isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $fileName = $file['name'];
        $fileTmpName = $file['tmp_name'];
        $fileSize = $file['size'];
        
        $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'docx'];
        $maxSize = 5 * 1024 * 1024; // 5MB limit

        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileSize <= $maxSize && in_array($fileExt, $allowedExts)) {
            $newFileName = uniqid('msg_', true) . "." . $fileExt;
            $uploadDir = __DIR__ . "/../uploads/attachments/";
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            if (move_uploaded_file($fileTmpName, $uploadDir . $newFileName)) {
                $attachmentPath = "uploads/attachments/" . $newFileName;
            } else {
                $errors[] = "Failed to upload file attachment.";
            }
        } else {
            $errors[] = "Invalid attachment file type or size exceeded (Max 5MB).";
        }
    }

    // Insert Message into Database
    if (empty($errors)) {
        try {
            $preview = substr($messageText, 0, 100) . (strlen($messageText) > 100 ? '...' : '');
            
            $sql = "INSERT INTO messages (sender_id, recipient_id, subject, message_text, preview, attachment_path, status, date_recorded) 
                    VALUES (?, ?, ?, ?, ?, ?, 'unread', NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_SESSION['user_id'], $recipientId, $subject, $messageText, $preview, $attachmentPath]);

            header("Location: messages.php?msg=sent");
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Message | Home for the Aged</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background-color: #f4f8f5;
            color: #304b3b;
            font-family: Arial, Helvetica, sans-serif;
            overflow-x: hidden;
        }

        /* SIDEBAR LOGO */
        .sidebar-logo {
            display: block;
            padding: 10px 4px 6px;
        }

        .sidebar-logo a {
            display: block;
        }

        .sidebar-logo img {
            display: block;
            width: 100%;
            max-width: 300px;
            height: auto;
            margin: 0 auto;
            transform: scale(1.1);
        }

        /* app-main opened in sidebar.php */
        .app-main {
            margin-left: 250px !important;
            width: calc(100% - 250px) !important;
            max-width: none !important;
            min-width: 0;
            min-height: 100vh;
            padding: 16px 24px !important;
            margin-top: 0 !important;
            box-shadow: none !important;
            overflow-x: hidden;
        }

        /* Card container styled like the Add Caretaker interface */
        .form-container-card {
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(24, 75, 42, 0.04);
            overflow: hidden;
            border: 1px solid #e3eee6;
        }

        /* Dark Green Banner Header */
        .card-banner-header {
            background-color: #2d6042;
            color: #ffffff;
            padding: 14px 18px;
            font-size: 1.2rem;
            font-weight: 600;
        }

        /* Section Title Styles */
        .section-title {
            color: #17683a;
            font-weight: 700;
            font-size: 0.95rem;
            background: #edf8ef;
            border-left: 4px solid #28a745;
            border-bottom: 1px solid #dcecdf;
            padding: 8px 12px;
            margin-bottom: 14px;
        }

        .form-label {
            font-weight: 600;
            color: #17683a;
            font-size: 0.85rem;
            margin-bottom: 4px;
        }

        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #cedbd1;
            padding: 6px 12px;
            font-size: 13px;
            min-height: 38px;
        }

        .form-control:focus, .form-select:focus {
            border-color: #33b960;
            box-shadow: 0 0 0 0.15rem rgba(51, 185, 96, 0.15);
        }

        .btn-success {
            background-color: #198754;
            border-color: #198754;
            color: #ffffff;
            font-weight: 600;
            padding: 6px 18px;
            border-radius: 6px;
            font-size: 13px;
        }

        .btn-success:hover {
            background-color: #157347;
            border-color: #146c43;
        }

        .btn-secondary {
            background-color: #ffffff;
            border-color: #87928b;
            color: #65716a;
            font-weight: 500;
            padding: 6px 18px;
            border-radius: 6px;
            font-size: 13px;
        }

        .btn-secondary:hover {
            background-color: #f1f4f2;
            color: #304b3b;
        }

        .sidebar-link.active {
            box-shadow: none !important;
            outline: none !important;
        }

        @media (max-width: 760px) {
            .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 10px !important;
            }
        }
    </style>
</head>
<body>

    <!-- Include Sidebar Navigation -->
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="row justify-content-center">
        <div class="col-12">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger py-2 px-3 mb-3 fs-6">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Form Outer Card -->
            <div class="form-container-card">
                
                <!-- Top Dark Green Header -->
                <div class="card-banner-header">
                    New Message
                </div>

                <div class="p-3 p-md-4">
                    <form method="POST" enctype="multipart/form-data">
                        
                        <!-- 1. Message Details -->
                        <div class="section-title">Message Details</div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Recipient</label>
                                <select name="recipient_id" class="form-select" required>
                                    <option value="" disabled selected>Select Recipient...</option>
                                    <?php 
                                    $currentRole = '';
                                    foreach ($recipients as $recipient): 
                                        if ($recipient['role'] !== $currentRole) {
                                            if ($currentRole !== '') echo "</optgroup>";
                                            $currentRole = $recipient['role'];
                                            echo "<optgroup label='" . ucfirst($recipient['role']) . "s'>";
                                        }
                                    ?>
                                        <option value="<?= e($recipient['id']) ?>"><?= e($recipient['full_name']) ?> (<?= ucfirst(e($recipient['role'])) ?>)</option>
                                    <?php endforeach; ?>
                                    <?php if ($currentRole !== '') echo "</optgroup>"; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Subject</label>
                                <input type="text" name="subject" class="form-control" placeholder="Enter message subject" required>
                            </div>
                        </div>

                        <!-- 2. Message Content -->                            
                        <div class="row g-2 mb-3">
                            <div class="col-12">
                                <label class="form-label">Message Content</label>
                                <textarea name="message_text" class="form-control" rows="4" placeholder="Type your message content here..." required></textarea>
                            </div>
                        </div>

                        <!-- 3. Attachment -->
                        <div class="section-title">Attachment</div>
                        <div class="row g-2 mb-3">
                            <div class="col-12">
                                <label class="form-label">Add Attachment (Optional)</label>
                                <input type="file" name="attachment" class="form-control">
                                <div class="form-text small text-muted mt-1">Accepted formats: JPG, PNG, PDF, DOCX (Max size: 5MB)</div>
                            </div>
                        </div>

                        <!-- Bottom Action Buttons -->
                        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                            <a href="messages.php" class="btn btn-secondary">Back to List</a>
                            <button type="submit" class="btn btn-success">Send Message</button>
                        </div>

                    </form>
                </div>

            </div>

        </div>
    </div>

    <!-- Puts the logo at the top of the sidebar and hides the old text title -->
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var sidebar = document.querySelector(
            '.app-sidebar, .sidebar, #sidebar, .sidebar-container, aside, nav'
        );

        if (!sidebar) {
            return;
        }

        var oldTexts = ['home for the aged', 'care management system'];

        sidebar.querySelectorAll('*').forEach(function (el) {
            if (
                el.children.length === 0 &&
                oldTexts.indexOf(el.textContent.trim().toLowerCase()) !== -1
            ) {
                el.style.display = 'none';
            }
        });

        if (!sidebar.querySelector('.sidebar-logo')) {
            var logo = document.createElement('div');
            logo.className = 'sidebar-logo';
            logo.innerHTML =
                '<a href="/homefor_thetander/admin/dashboard.php">' +
                '<img src="/homefor_thetander/images/white1.png" ' +
                'alt="Home for the Aged - Care, Comfort, Dignity">' +
                '</a>';

            sidebar.insertBefore(logo, sidebar.firstChild);
        }
    });
    </script>

    <!-- sidebar.php already opened <main class="app-main">, close it here -->
    </main>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>