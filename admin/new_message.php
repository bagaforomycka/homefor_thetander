
<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array(strtolower($_SESSION['role']), ['admin', 'caretaker', 'staff'], true)
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

$oldRecipient = $_POST['recipient_id'] ?? '';
$oldSubject = $_POST['subject'] ?? '';
$oldMessage = $_POST['message'] ?? '';

// Fetch recipients from users and the separate caretakers table
try {

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.full_name,
            u.role
        FROM users u

        LEFT JOIN caretakers c
            ON c.username = u.username

        WHERE u.id != ?

        AND (
            LOWER(u.role) != 'caretaker'

            OR (
                c.id IS NOT NULL
                AND LOWER(c.status) = 'active'
            )
        )

        ORDER BY u.full_name ASC
    ");

    $stmt->execute([$_SESSION['user_id']]);

    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    $recipients = [];
    $errorMsg = 'Unable to retrieve recipients. Please check your database.';

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

            // Verify recipient is an eligible account
            $validRecipient = false;

            foreach ($recipients as $recipient) {
                if ((int)$recipient['id'] === $recipient_id) {
                    $validRecipient = true;
                    break;
                }
            }

            if (!$validRecipient) {

                $errorMsg = 'The selected recipient is unavailable.';

            } else {

                $attachment_path = null;

                // Handle optional attachment
                if (
                    isset($_FILES['attachment']) &&
                    $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE
                ) {

                    if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {

                        $errorMsg = 'Unable to upload attachment.';

                    } else {

                        $fileTmp = $_FILES['attachment']['tmp_name'];
                        $fileName = $_FILES['attachment']['name'];
                        $fileSize = $_FILES['attachment']['size'];

                        $fileExt = strtolower(
                            pathinfo($fileName, PATHINFO_EXTENSION)
                        );

                        $allowedExts = [
                            'jpg',
                            'jpeg',
                            'png',
                            'pdf',
                            'docx'
                        ];

                        if (!in_array($fileExt, $allowedExts, true)) {

                            $errorMsg = 'Accepted formats: JPG, PNG, PDF, DOCX.';

                        } elseif ($fileSize > 5 * 1024 * 1024) {

                            $errorMsg = 'File size must not exceed 5MB.';

                        } elseif (!is_uploaded_file($fileTmp)) {

                            $errorMsg = 'Invalid uploaded file.';

                        } else {

                            $uploadDir = __DIR__ . '/../uploads/messages/';

                            if (
                                !is_dir($uploadDir) &&
                                !mkdir($uploadDir, 0755, true)
                            ) {
                                $errorMsg = 'Unable to create upload directory.';

                            } else {

                                $newFileName = bin2hex(random_bytes(16))
                                    . '.' . $fileExt;

                                $destination = $uploadDir . $newFileName;

                                if (move_uploaded_file($fileTmp, $destination)) {

                                    $attachment_path =
                                        'uploads/messages/' . $newFileName;

                                } else {

                                    $errorMsg = 'Unable to save attachment.';

                                }
                            }
                        }
                    }
                }

                // Save message
                if ($errorMsg === '') {

                    try {

                        $stmt = $pdo->prepare("
                            INSERT INTO messages (
                                sender_id,
                                recipient_id,
                                subject,
                                body,
                                attachment,
                                is_read,
                                created_at
                            )
                            VALUES (?, ?, ?, ?, ?, 0, NOW())
                        ");

                        $stmt->execute([
                            $_SESSION['user_id'],
                            $recipient_id,
                            $subject,
                            $message,
                            $attachment_path
                        ]);

                        $_SESSION['success_msg'] =
                            'Message sent successfully.';

                        header('Location: messages.php');
                        exit;

                    } catch (PDOException $e) {

                        if ($attachment_path !== null) {
                            $uploadedFile = __DIR__ . '/../' . $attachment_path;

                            if (is_file($uploadedFile)) {
                                unlink($uploadedFile);
                            }
                        }

                        $errorMsg = 'Unable to send message. Please try again.';
                    }
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

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
        }

        .main-content {
            padding: 20px 28px 32px;
            min-height: 100vh;
            width: 100%;
            min-width: 0;
        }

        .content-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e3eee6;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.05);
            overflow: hidden;
            margin-bottom: 20px;
            width: 100%;
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

        .form-control,
        .form-select {
            width: 100%;
            min-width: 0;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            padding: 9px 12px;
            font-size: 0.9rem;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 0.2rem rgba(23, 104, 58, 0.18);
        }

        textarea.form-control {
            min-height: 180px;
            resize: vertical;
        }

        .form-text {
            font-size: 0.8rem;
        }

        .btn-success {
            background-color: var(--green);
            border-color: var(--green);
        }

        .btn-success:hover {
            background-color: var(--green-dark);
            border-color: var(--green-dark);
        }

        @media (max-width: 767px) {

            .main-content {
                padding: 15px 12px 25px;
            }

            .card-body-custom {
                padding: 18px 15px;
            }

            .card-header-custom {
                padding: 14px 17px;
            }

            .card-body-custom .d-flex {
                flex-wrap: wrap;
            }

            .card-body-custom .d-flex a,
            .card-body-custom .d-flex button {
                flex: 1;
            }

        }

        @media (max-width: 400px) {

            .main-content {
                padding: 12px 9px 20px;
            }

            .card-body-custom {
                padding: 15px 11px;
            }

        }

    </style>

</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">

    <?php if ($errorMsg !== ''): ?>

        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">

            <?= e($errorMsg) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>

    <div class="content-card">

        <div class="card-header-custom">
            New Message
        </div>

        <div class="card-body-custom">

            <form method="POST" enctype="multipart/form-data">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['compose_csrf']) ?>"
                >

                <div class="section-title">
                    Message Details
                </div>

                <div class="row g-3 mb-4">

                    <div class="col-md-6">

                        <label class="form-label">
                            Recipient
                        </label>

                        <select
                            name="recipient_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select Recipient...
                            </option>

                            <?php foreach ($recipients as $rec): ?>

                                <option
                                    value="<?= (int)$rec['id'] ?>"
                                    <?= ((string)$oldRecipient === (string)$rec['id']) ? 'selected' : '' ?>
                                >

                                    <?= e($rec['full_name']) ?>
                                    (<?= ucfirst(e($rec['role'])) ?>)

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <?php if (empty($recipients)): ?>

                            <div class="form-text text-danger mt-2">
                                No available recipients found.
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Subject
                        </label>

                        <input
                            type="text"
                            name="subject"
                            class="form-control"
                            placeholder="Enter message subject"
                            value="<?= e($oldSubject) ?>"
                            required
                        >

                    </div>

                </div>

                <div class="section-title">
                    Message Body
                </div>

                <div class="mb-4">

                    <label class="form-label">
                        Message Content
                    </label>

                    <textarea
                        name="message"
                        class="form-control"
                        placeholder="Type your message content here..."
                        required
                    ><?= e($oldMessage) ?></textarea>

                </div>

                <div class="section-title">
                    Attachment
                </div>

                <div class="mb-4">

                    <label class="form-label">
                        Add Attachment (Optional)
                    </label>

                    <input
                        type="file"
                        name="attachment"
                        class="form-control"
                        accept=".jpg,.jpeg,.png,.pdf,.docx"
                    >

                    <div class="form-text text-muted mt-1">
                        Accepted formats: JPG, PNG, PDF, DOCX (Max size: 5MB)
                    </div>

                </div>

                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top gap-2">

                    <a
                        href="messages.php"
                        class="btn btn-secondary px-4"
                    >
                        Back to List
                    </a>

                    <button
                        type="submit"
                        class="btn btn-success px-4"
                        <?= empty($recipients) ? 'disabled' : '' ?>
                    >
                        <i class="bi bi-send"></i>
                        Send Message
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
