<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$errorMsg = '';

// Fetch active caretakers to populate the recipient dropdown
$recipients = [];
try {
    $stmt = $pdo->prepare("SELECT id, full_name, employee_id FROM caretakers WHERE archived_at IS NULL ORDER BY full_name ASC");
    $stmt->execute();
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recipients = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_id = trim($_POST['recipient_id'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if (empty($recipient_id) || empty($subject) || empty($body)) {
        $errorMsg = 'Please fill in all required message fields.';
    } else {
        try {
            $sender_id = $_SESSION['user_id'] ?? 1;
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, recipient_id, subject, body, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$sender_id, $recipient_id, $subject, $body]);
            
            header("Location: messages.php?msg=sent");
            exit;
        } catch (PDOException $e) {
            $errorMsg = 'Could not send message. Please check your database structure.';
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
    <style>
        :root {
            --green: #17683a;
            --light-green: #33b960;
            --pale-green: #edf7ef;
            --text: #304b3b;
        }

        body {
            background: #f8fbf8;
            color: var(--text);
            font-family: Arial, sans-serif;
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

        .main-content {
            margin-left: 10px;
            padding: 24px 32px 32px 24px;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .04);
        }

        .card-header-custom {
            background-color: var(--green);
            color: #ffffff;
            padding: 16px 20px;
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .section-heading {
            font-weight: bold;
            color: var(--green);
            font-size: 1rem;
            margin-bottom: 12px;
            margin-top: 10px;
        }

        .form-label {
            font-weight: 600;
            color: var(--green);
            font-size: 0.9rem;
        }

        .btn-success {
            background: var(--green);
            border-color: var(--green);
        }

        .btn-success:hover {
            background: #0f4b2a;
            border-color: #0f4b2a;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 18px 12px;
            }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <div class="row justify-content-center">
        <div class="col-xl-10">
            
            <?php if ($errorMsg !== ''): ?>
                <div class="alert alert-danger mb-3"><?= e($errorMsg) ?></div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-header-custom">
                    New Message
                </div>
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        
                        <div class="section-heading">Message Details</div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="recipient_id" class="form-label">Recipient</label>
                                <select id="recipient_id" name="recipient_id" class="form-select" required>
                                    <option value="" selected disabled>Select Recipient...</option>
                                    <?php foreach ($recipients as $rec): ?>
                                        <option value="<?= (int)$rec['id'] ?>">
                                            <?= e($rec['full_name']) ?> (<?= e($rec['employee_id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="subject" class="form-label">Subject</label>
                                <input type="text" id="subject" name="subject" class="form-control" placeholder="Enter message subject" required>
                            </div>
                        </div>

                        <div class="section-heading">Message Body</div>
                        <div class="mb-4">
                            <label for="body" class="form-label">Message Content</label>
                            <textarea id="body" name="body" class="form-control" rows="6" placeholder="Type your message content here..." required></textarea>
                        </div>

                        <div class="section-heading">Attachment</div>
                        <div class="mb-4">
                            <label for="attachment" class="form-label">Add Attachment (Optional)</label>
                            <input type="file" id="attachment" name="attachment" class="form-control">
                            <div class="form-text text-muted small mt-1">Accepted formats: JPG, PNG, PDF, DOCX (Max size: 5MB)</div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center pt-2">
                            <a href="messages.php" class="btn btn-secondary px-4">Back to List</a>
                            <button type="submit" class="btn btn-success px-4">Send Message</button>
                        </div>

                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector('.sidebar, #sidebar, .sidebar-container, aside, nav');
    if (!sidebar) return;

    var oldTexts = ['home for the aged', 'care management system'];
    sidebar.querySelectorAll('*').forEach(function (el) {
        if (el.children.length === 0 && oldTexts.indexOf(el.textContent.trim().toLowerCase()) !== -1) {
            el.style.display = 'none';
        }
    });

    if (!sidebar.querySelector('.sidebar-logo')) {
        var logo = document.createElement('div');
        logo.className = 'sidebar-logo';
        logo.innerHTML = '<a href="dashboard.php"><img src="../images/white1.png" alt="Home for the Aged"></a>';
        sidebar.insertBefore(logo, sidebar.firstChild);
    }
});
</script>

</body>
</html>