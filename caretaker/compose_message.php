<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'caretaker', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['compose_csrf'])) {
    $_SESSION['compose_csrf'] = bin2hex(random_bytes(32));
}

$errorMsg = '';
$successMsg = '';

// Fetch users for recipient dropdown (excluding current user)
try {
    $stmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id != ? ORDER BY full_name ASC");
    $stmt->execute([$_SESSION['user_id']]);
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recipients = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['compose_csrf'], $csrf)) {
        $errorMsg = 'Invalid CSRF token.';
    } else {
        $recipient_id = (int)($_POST['recipient_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($recipient_id <= 0) {
            $errorMsg = 'Please select a valid recipient.';
        } elseif ($subject === '') {
            $errorMsg = 'Please enter a message subject.';
        } elseif ($message === '') {
            $errorMsg = 'Please enter message content.';
        } else {
            // Optional file attachment handling (not required)
            $attachment_path = null;
            if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['attachment']['tmp_name'];
                $fileName = $_FILES['attachment']['name'];
                $fileSize = $_FILES['attachment']['size'];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'docx'];
                if (!in_array($fileExt, $allowedExts, true)) {
                    $errorMsg = 'Accepted formats: JPG, PNG, PDF, DOCX.';
                } elseif ($fileSize > 5 * 1024 * 1024) {
                    $errorMsg = 'File size must not exceed 5MB.';
                } else {
                    $uploadDir = __DIR__ . '/../uploads/messages/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $newFileName = uniqid('msg_', true) . '.' . $fileExt;
                    $destination = $uploadDir . $newFileName;
                    if (move_uploaded_file($fileTmp, $destination)) {
                        $attachment_path = 'uploads/messages/' . $newFileName;
                    }
                }
            }

            if ($errorMsg === '') {
                try {
                    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, recipient_id, subject, body, attachment, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
                    $stmt->execute([$_SESSION['user_id'], $recipient_id, $subject, $message, $attachment_path]);
                    
                    $_SESSION['success_msg'] = 'Message sent successfully.';
                    header('Location: messages.php');
                    exit;
                } catch (PDOException $e) {
                    $errorMsg = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Message | Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --green: #17683a;
            --green-dark: #13522e;
            --section-bg: #e8f5eb;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
        }

        .main-content {
            padding: 20px 28px 32px;
            min-height: 100vh;
        }

        .content-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e3eee6;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.05);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .card-header-custom {
            background-color: var(--green);
            color: #ffffff;
            padding: 16px 24px;
            font-size: 1.15rem;
            font-weight: 700;
        }

        .card-body-custom {
            padding: 24px;
        }

        .section-title {
            font-weight: 700;
            color: var(--green);
            font-size: 0.95rem;
            margin-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 6px;
        }

        .form-label {
            font-weight: 700;
            color: var(--green);
            font-size: 0.85rem;
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #d1d5db;
            padding: 9px 12px;
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 0.2rem rgba(23, 104, 58, 0.18);
        }

        textarea.form-control {
            min-height: 180px;
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <?= e($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="content-card">
        <div class="card-header-custom">
            New Message
        </div>
        <div class="card-body-custom">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['compose_csrf']) ?>">

                <div class="section-title">Message Details</div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Recipient</label>
                        <select name="recipient_id" class="form-select" required>
                            <option value="">Select Recipient...</option>
                            <?php foreach ($recipients as $rec): ?>
                                <option value="<?= (int)$rec['id'] ?>">
                                    <?= e($rec['full_name']) ?> (<?= ucfirst(e($rec['role'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control" placeholder="Enter message subject" required>
                    </div>
                </div>

                <div class="section-title">Message Body</div>
                <div class="mb-4">
                    <label class="form-label">Message Content</label>
                    <textarea name="message" class="form-control" placeholder="Type your message content here..." required></textarea>
                </div>

                <div class="section-title">Attachment</div>
                <div class="mb-4">
                    <label class="form-label">Add Attachment (Optional)</label>
                    <input type="file" name="attachment" class="form-control">
                    <div class="form-text text-muted mt-1" style="font-size: 0.8rem;">Accepted formats: JPG, PNG, PDF, DOCX (Max size: 5MB)</div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4 pt-2 border-top">
                    <a href="messages.php" class="btn btn-secondary px-4">Back to List</a>
                    <button type="submit" class="btn btn-success px-4">Send Message</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>