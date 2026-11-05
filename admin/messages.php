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

$search = trim($_GET['search'] ?? '');

/* Archive a message using POST. */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'archive'
) {
    $token = $_POST['csrf_token'] ?? '';
    $messageId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['messages_csrf'], $token)
    ) {
        $errors[] = 'Security check failed. Refresh the page and try again.';
    } elseif (!$messageId || $messageId < 1) {
        $errors[] = 'Invalid message ID.';
    } else {
        try {
            /*
             * The existing messages table uses status = 'deleted'
             * as its archive marker.
             *
             * Only the recipient can archive their own inbox copy.
             */
            $stmt = $pdo->prepare(
                "UPDATE messages
                 SET status = 'deleted'
                 WHERE id = :id
                   AND recipient_id = :recipient_id
                   AND status <> 'deleted'"
            );

            $stmt->execute([
                'id' => $messageId,
                'recipient_id' => $_SESSION['user_id']
            ]);

            if ($stmt->rowCount() > 0) {
                header(
                    'Location: messages.php?tab=' .
                    urlencode($tab) .
                    '&msg=archived'
                );
                exit;
            }

            $errors[] = 'Message not found or it has already been archived.';
        } catch (PDOException $ex) {
            error_log('Message archive error: ' . $ex->getMessage());
            $errors[] = 'Unable to archive the message. Please check the messages table.';
        }
    }
}

/* Display feedback after redirect. */
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'sent') {
        $successMessage = 'Message successfully sent!';
    } elseif ($_GET['msg'] === 'archived') {
        $successMessage = 'Message moved to Archive & Restore, if supported there.';
    }
}

/* Fetch messages for the selected inbox tab. */
$messages = [];
$errorMsg = '';

try {
    $sql = "SELECT m.*, c.full_name as sender_name 
            FROM messages m 
            LEFT JOIN caretakers c ON m.sender_id = c.id 
            WHERE 1=1";
    $params = [];

    if ($tab === 'unread') {
        $sql .= " AND (m.is_read = 0 OR m.is_read IS NULL)";
    }

    if ($search !== '') {
        $sql .= " AND (m.subject LIKE :search OR m.body LIKE :search OR c.full_name LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY m.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $messages = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages - Inbox | Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --green: #17683a;
            --light-green: #33b960;
            --pale-green: #edf7ef;
            --text: #304b3b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: #f8fbf8;
            color: var(--text);
            font-family: Arial, sans-serif;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* SIDEBAR LOGO (injected at top of sidebar) */
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
            padding: 30px;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .04);
        }

        .btn-success {
            background: var(--green);
            border-color: var(--green);
        }

        .btn-success:hover {
            background: #0f4b2a;
            border-color: #0f4b2a;
        }

        .table thead th {
            background: var(--pale-green);
            color: var(--text);
            white-space: nowrap;
        }

        .table th, .table td {
            padding: 12px 14px;
            vertical-align: middle;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 18px 12px;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div>
            <h2 class="fw-bold mb-1">Messages - Inbox</h2>
            <p class="text-muted mb-0">View and manage internal communication and system alerts.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="dashboard.php" class="btn btn-outline-success">
                &larr; Back to Dashboard
            </a>
            <a href="new_message.php" class="btn btn-success">
                + New Message
            </a>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="mb-4">
        <div class="btn-group flex-wrap" role="group" aria-label="Message tabs">
            <a href="messages.php?tab=admin_staff"
               class="btn btn-outline-success <?= $tab === 'admin_staff' ? 'active' : '' ?>">
                Admin &amp; Staff
            </a>

            <a href="messages.php?tab=caretakers"
               class="btn btn-outline-success <?= $tab === 'caretakers' ? 'active' : '' ?>">
                Caretakers
            </a>

            <a href="messages.php?tab=unread"
               class="btn btn-outline-success <?= $tab === 'unread' ? 'active' : '' ?>">
                Unread
            </a>
        </div>
    </div>

    <div class="card p-3 mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="col-md-9">
                <label for="search" class="form-label">Search by subject or sender</label>
                <input
                    id="search"
                    type="search"
                    name="search"
                    class="form-control"
                    value="<?= e($search) ?>"
                    placeholder="Enter keyword..."
                >
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-success flex-fill">Search</button>
                <a href="messages.php?tab=<?= e($tab) ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="fw-bold mb-0">Caretaker Messages</h5>
            <span class="text-muted small">Total: <?= count($messages) ?></span>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
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
                <?php if (!$messages): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            No messages found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <tr>
                            <td><?= e($msg['sender_name'] ?? 'Unknown') ?></td>
                            <td><?= e($msg['subject'] ?? 'No Subject') ?></td>
                            <td><?= e(substr($msg['body'] ?? '', 0, 50)) ?>...</td>
                            <td><?= e($msg['created_at'] ?? '') ?></td>
                            <td>
                                <a href="view_message.php?id=<?= (int)$msg['id'] ?>" class="btn btn-sm btn-outline-info">View</a>
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

<!-- Puts the logo at the top of the sidebar and hides the old text title -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector(
        '.sidebar, #sidebar, .sidebar-container, aside, nav'
    );

    if (!sidebar) {
        return;
    }

    // Hide the old text title and subtitle
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

    if (!sidebar.querySelector('#sidebarToggle')) {
        var toggleWrapper = document.createElement('div');
        toggleWrapper.className = 'sidebar-toggle-wrapper';
        toggleWrapper.innerHTML = `
            <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Toggle Navigation Sidebar">
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
            </button>
        `;
        sidebar.insertBefore(toggleWrapper, sidebar.firstChild);
    }

    var toggleBtn = document.getElementById('sidebarToggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            if (window.innerWidth <= 992) {
                document.body.classList.toggle('sidebar-open');
                if (overlay) overlay.classList.toggle('active');
            } else {
                document.body.classList.toggle('sidebar-folded');
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            document.body.classList.remove('sidebar-open');
            overlay.classList.remove('active');
        });
    }
});
</script>

</body>
</html>
