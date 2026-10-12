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
        :root {
            --sidebar-bg: #0d3b1e;
            --green: #17683a;
            --light-green: #33b960;
            --pale-green: #edf7ef;
            --text: #304b3b;
        }

        body {
            background-color: #f8fbf8;
            color: var(--text);
            font-family: Arial, sans-serif;
            overflow-x: hidden;
        }

        .main-content {
            margin-left: 10px;
            padding: 30px;
        }

        /* Card container styled like the Add Caretaker interface */
        .form-container-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            overflow: hidden;
            border: 1px solid #e0eee3;
        }

        /* Dark Green Banner Header */
        .card-banner-header {
            background-color: #0d3b1e;
            color: #ffffff;
            padding: 16px 24px;
            font-size: 1.3rem;
            font-weight: bold;
        }

        /* Section Title Styles */
        .section-title {
            color: #17683a;
            font-weight: bold;
            font-size: 1.05rem;
            border-bottom: 1px solid #edf7ef;
            padding-bottom: 8px;
            margin-bottom: 18px;
        }

        .form-label {
            font-weight: 600;
            color: #17683a;
            font-size: 0.9rem;
        }

        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #dcdcdc;
            padding: 10px 14px;
        }

        .form-control:focus, .form-select:focus {
            border-color: #17683a;
            box-shadow: 0 0 0 0.2rem rgba(23, 104, 58, 0.15);
        }

        .btn-success {
            background-color: #17683a;
            border-color: #17683a;
            color: #ffffff;
            font-weight: 600;
            padding: 8px 24px;
            border-radius: 6px;
        }

        .btn-success:hover {
            background-color: #0f4b2a;
            border-color: #0f4b2a;
        }

        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            font-weight: 600;
            padding: 8px 24px;
            border-radius: 6px;
        }
    </style>
</head>
<body>

    <!-- Include Sidebar Navigation -->
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="row justify-content-center">
            <div class="col-xl-10">

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger mb-3">
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

                    <div class="p-4">
                        <form method="POST" enctype="multipart/form-data">
                            
                            <!-- 1. Message Details -->
                            <div class="section-title">Message Details</div>
                            <div class="row g-3 mb-4">
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

                            <!-- 2. Message Body -->
                            <div class="section-title">Message Body</div>
                            <div class="row g-3 mb-4">
                                <div class="col-12">
                                    <label class="form-label">Message Content</label>
                                    <textarea name="message_text" class="form-control" rows="6" placeholder="Type your message content here..." required></textarea>
                                </div>
                            </div>

                            <!-- 3. Attachment -->
                            <div class="section-title">Attachment</div>
                            <div class="row g-3 mb-4">
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
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>