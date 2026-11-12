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

if (empty($_SESSION['messages_csrf'])) {
    $_SESSION['messages_csrf'] = bin2hex(random_bytes(32));
}

$errorMsg = '';
$successMsg = '';

// Handle Delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['messages_csrf'], $csrf)) {
        $errorMsg = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'delete' && $id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ? AND recipient_id = ?");
                $stmt->execute([$id, $_SESSION['user_id']]);
                $successMsg = 'Message deleted successfully.';
            } catch (PDOException $e) {
                $errorMsg = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

$tab = $_GET['tab'] ?? 'admin';
$search = trim($_GET['search'] ?? '');

// Counts for tabs filtered by logged-in user as recipient
try {
    $stmtAdmin = $pdo->prepare("SELECT COUNT(*) FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.recipient_id = ? AND u.role = 'admin'");
    $stmtAdmin->execute([$_SESSION['user_id']]);
    $adminCount = (int)$stmtAdmin->fetchColumn();

    $stmtUnread = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0");
    $stmtUnread->execute([$_SESSION['user_id']]);
    $unreadCount = (int)$stmtUnread->fetchColumn();
} catch (Throwable $e) {
    $adminCount = 0;
    $unreadCount = 0;
}

// Fetch messages based on tab, restricted to logged-in user as recipient
$sql = "SELECT m.*, u.full_name as sender_name, u.role as sender_role FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.recipient_id = ?";
$params = [$_SESSION['user_id']];

if ($tab === 'admin') {
    $sql .= " AND u.role = 'admin'";
} elseif ($tab === 'unread') {
    $sql .= " AND m.is_read = 0";
}

if ($search !== '') {
    $sql .= " AND (m.subject LIKE ? OR u.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY m.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages - Inbox | Home for the Aged</title>
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
            margin: 0 0 2px 0;
            color: var(--green);
        }

        .page-banner p {
            font-size: 0.85rem;
            color: #4b5563;
            margin: 0;
        }

        .content-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e3eee6;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.05);
            padding: 24px 28px;
            margin-bottom: 20px;
        }

        /* Clean transparent tab styling without white card background */
        .nav-pills {
            display: flex;
            gap: 8px;
            background: transparent;
            border: none;
            padding: 0;
            margin-bottom: 20px;
        }

        .nav-pills .nav-item {
            margin: 0;
        }

        .nav-pills .nav-link {
            color: var(--green) !important;
            font-weight: 600;
            background-color: transparent !important;
            border: 1px solid var(--green) !important;
            border-radius: 6px !important;
            padding: 8px 18px;
        }

        .nav-pills .nav-link.active {
            background-color: var(--green) !important;
            color: #ffffff !important;
            border-color: var(--green) !important;
        }

        .nav-pills .nav-link .badge {
            background-color: rgba(23, 104, 58, 0.12) !important;
            color: var(--green) !important;
        }

        .nav-pills .nav-link.active .badge {
            background-color: #ffffff !important;
            color: var(--green) !important;
        }

        .form-label {
            font-weight: 700;
            color: var(--green);
            font-size: 0.85rem;
            margin-bottom: 6px;
        }

        .form-control {
            border-radius: 6px;
            border: 1px solid #d1d5db;
            padding: 9px 12px;
            font-size: 0.9rem;
        }

        .form-control:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 0.2rem rgba(23, 104, 58, 0.18);
        }

        .table thead th {
            background-color: var(--section-bg) !important;
            color: var(--green);
            font-weight: 700;
            border-bottom: 2px solid #d1e7dd;
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-banner">
        <div>
            <h2>Messages - Inbox</h2>
            <p>View and manage internal communication and system alerts.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="dashboard.php" class="btn btn-outline-success">← Back to Dashboard</a>
            <a href="compose_message.php" class="btn btn-success">+ New Message</a>
        </div>
    </div>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <?= e($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <?= e($successMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-pills mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'admin' ? 'active' : '' ?>" href="messages.php?tab=admin">
                Admin <span class="badge ms-1"><?= $adminCount ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'unread' ? 'active' : '' ?>" href="messages.php?tab=unread">
                Unread <span class="badge ms-1"><?= $unreadCount ?></span>
            </a>
        </li>
    </ul>

    <!-- Search Card -->
    <div class="content-card">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="col-md-10">
                <label class="form-label">Search by subject or sender</label>
                <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Enter keyword...">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-success w-100">Search</button>
                <a href="messages.php?tab=<?= e($tab) ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <!-- Messages List Card -->
    <div class="content-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold m-0" style="color: var(--green);">
                <?= $tab === 'unread' ? 'Unread Messages' : 'Admin Messages' ?>
            </h5>
            <span class="text-muted small">Total: <?= count($messages) ?></span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sender</th>
                        <th>Subject</th>
                        <th>Preview</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No messages found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($messages as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= e($row['sender_name']) ?></div>
                                    <div class="text-muted small text-capitalize"><?= e($row['sender_role']) ?></div>
                                </td>
                                <td><?= e($row['subject']) ?></td>
                                <td class="text-muted"><?= e(substr($row['body'] ?? $row['message'] ?? '', 0, 40)) ?>...</td>
                                <td><?= e(date('M j, Y', strtotime($row['created_at']))) ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="view_message.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-success">View</a>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this message?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['messages_csrf']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>