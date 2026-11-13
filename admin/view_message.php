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

$message_id = (int)($_GET['id'] ?? 0);
$errorMsg = '';
$message = null;

if ($message_id <= 0) {
    header('Location: messages.php');
    exit;
}

try {
    // Fetch message and ensure it belongs to the logged-in user as recipient
    $stmt = $pdo->prepare("
        SELECT m.*, 
               COALESCE(sender.full_name, 'Unknown Sender') as sender_name, 
               COALESCE(sender.role, 'user') as sender_role,
               COALESCE(recipient.full_name, 'Recipient') as recipient_name,
               COALESCE(recipient.role, 'user') as recipient_role
        FROM messages m
        LEFT JOIN users sender ON m.sender_id = sender.id
        LEFT JOIN users recipient ON m.recipient_id = recipient.id
        WHERE m.id = ? AND m.recipient_id = ?
    ");
    $stmt->execute([$message_id, $_SESSION['user_id']]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        header('Location: messages.php');
        exit;
    }

    // Mark message as read if unread
    if ((int)$message['is_read'] === 0) {
        $updateStmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ?");
        $updateStmt->execute([$message_id]);
    }
} catch (Throwable $e) {
    $errorMsg = 'Database error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>View Message Details | Home for the Aged</title>
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

        .page-banner {
            background-color: var(--section-bg);
            border: 1px solid #d1e7dd;
            color: var(--green);
            border-radius: 12px;
            padding: 20px 28px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.03);
        }

        .page-banner h2 {
            font-size: 1.45rem;
            font-weight: 700;
            margin: 0;
            color: var(--green);
        }

        .content-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e3eee6;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.05);
            padding: 24px 28px;
            margin-bottom: 20px;
        }

        .info-label {
            font-weight: 700;
            color: var(--green);
            font-size: 0.9rem;
            width: 120px;
            flex-shrink: 0;
        }

        .info-value {
            color: #1f2937;
            font-size: 0.95rem;
        }

        .message-body-box {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            color: #1f2937;
            white-space: pre-wrap;
            line-height: 1.6;
            min-height: 150px;
            font-size: 0.95rem;
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-banner">
        <h2>View Message Details</h2>
        <a href="messages.php" class="btn btn-outline-success">← Back to Messages</a>
    </div>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <?= e($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="content-card">
            <!-- Sender Header Info -->
            <div class="d-flex justify-content-between align-items-start pb-3 mb-4 border-bottom">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-secondary bg-opacity-25 d-flex align-items-center justify-content-center text-secondary fw-bold" style="width: 48px; height: 48px; font-size: 1.2rem;">
                        <i class="bi bi-person-fill"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0" style="color: var(--green);"><?= e($message['sender_name']) ?></h5>
                        <span class="text-muted small text-capitalize"><?= e($message['sender_role']) ?></span>
                    </div>
                </div>
                <div class="text-muted small text-end">
                    Received: <?= e(date('M j, Y, g:i A', strtotime($message['created_at']))) ?>
                </div>
            </div>

            <!-- Recipient Field -->
            <div class="d-flex align-items-center mb-3 pb-2 border-bottom">
                <div class="info-label">To:</div>
                <div class="info-value"><?= e($message['recipient_name']) ?> <span class="text-muted small text-capitalize">(<?= e($message['recipient_role']) ?>)</span></div>
            </div>

            <!-- Subject Field -->
            <div class="d-flex align-items-center mb-4 pb-2 border-bottom">
                <div class="info-label">Subject:</div>
                <div class="info-value fw-bold"><?= e($message['subject']) ?></div>
            </div>

            <!-- Message Content -->
            <div class="mb-4">
                <div class="info-label mb-2">Message Content:</div>
                <div class="message-body-box"><?= e($message['body'] ?? $message['message'] ?? '') ?></div>
            </div>

            <!-- Attachments (if any) -->
            <?php if (!empty($message['attachment'])): ?>
                <div class="pt-3 border-top">
                    <div class="info-label mb-2">Attachments:</div>
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-paperclip text-success"></i>
                        <a href="../<?= e($message['attachment']) ?>" target="_blank" class="text-success text-decoration-none fw-semibold">
                            <?= e(basename($message['attachment'])) ?>
                        </a>
                        <a href="../<?= e($message['attachment']) ?>" download class="btn btn-sm btn-outline-success ms-2">Download</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>