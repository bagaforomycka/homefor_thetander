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

if (empty($_SESSION['messages_csrf'])) {
    $_SESSION['messages_csrf'] = bin2hex(random_bytes(32));
}

$tab = $_GET['tab'] ?? 'admin_staff';

if (!in_array($tab, ['admin_staff', 'caretakers', 'unread'], true)) {
    $tab = 'admin_staff';
}

$errors = [];
$successMessage = '';
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

try {
    $roleFilters = [
        'admin_staff' => ['admin', 'staff'],
        'caretakers'  => ['caretaker'],
        'unread'      => ['admin', 'staff', 'caretaker']
    ];

    $allowedRoles = $roleFilters[$tab];
    $placeholders = implode(',', array_fill(0, count($allowedRoles), '?'));

    $sql = "
        SELECT
            m.id,
            u.full_name AS sender_name,
            u.role AS sender_role,
            m.subject,
            m.preview,
            m.date_recorded AS message_date,
            m.status
        FROM messages AS m
        INNER JOIN users AS u ON m.sender_id = u.id
        WHERE m.recipient_id = ?
          AND m.status <> 'deleted'
          AND u.role IN ($placeholders)
    ";

    $params = array_merge(
        [$_SESSION['user_id']],
        $allowedRoles
    );

    if ($tab === 'unread') {
        $sql .= " AND m.status = ?";
        $params[] = 'unread';
    }

    if ($search !== '') {
        $sql .= " AND (m.subject LIKE ? OR u.full_name LIKE ?)";
        $wildcardSearch = '%' . $search . '%';
        $params[] = $wildcardSearch;
        $params[] = $wildcardSearch;
    }

    $sql .= ' ORDER BY m.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    error_log('Message list error: ' . $ex->getMessage());
    $errors[] = 'Could not load messages. Check your messages and users database tables.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages | Home for the Aged</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        :root {
            --green: #17683a;
            --pale-green: #edf7ef;
            --text: #304b3b;
        }

        body {
            background: #f8fbf8;
            color: var(--text);
            font-family: Arial, sans-serif;
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
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .05);
            background: #fff;
        }

        .form-label {
            font-weight: 600;
            color: var(--green);
            font-size: .9rem;
        }

        .form-control,
        .form-select {
            border-radius: 6px;
            border: 1px solid #dcdcdc;
            padding: 10px 14px;
        }

        .btn-success {
            background: var(--green);
            border-color: var(--green);
            color: #fff;
            font-weight: 600;
            border-radius: 6px;
        }

        .btn-success:hover {
            background: #0f4b2a;
            border-color: #0f4b2a;
        }

        .table thead th {
            background: var(--pale-green);
            color: var(--text);
            font-weight: 600;
            border-bottom: 1px solid #e0eee3;
            padding: 12px 16px;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 14px 16px;
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

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= e($successMessage) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div>
            <h2 class="fw-bold mb-1">Messages - Inbox</h2>
            <p class="text-muted mb-0">
                View and manage internal communication and system alerts.
            </p>
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
                <label for="search" class="form-label mb-1">
                    Search by subject or sender
                </label>
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
                <button type="submit" class="btn btn-success flex-fill">
                    Search
                </button>
                <a href="messages.php?tab=<?= e($tab) ?>"
                   class="btn btn-outline-secondary">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="fw-bold mb-0">
                <?php
                echo match ($tab) {
                    'caretakers' => 'Caretaker Messages',
                    'unread' => 'Unread Messages',
                    default => 'Admin & Staff Messages'
                };
                ?>
            </h5>

            <span class="text-muted small">
                Total: <?= count($messages) ?>
            </span>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0 table-hover">
                <thead>
                    <tr>
                        <th>Sender</th>
                        <th>Subject</th>
                        <th>Preview</th>
                        <th>Date</th>
                        <th class="text-center">Actions</th>
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
                    <?php foreach ($messages as $row): ?>
                        <tr class="<?= ($row['status'] ?? '') === 'unread' ? 'fw-bold' : '' ?>">
                            <td>
                                <div><?= e($row['sender_name']) ?></div>
                                <small class="text-muted">
                                    <?= e(ucfirst((string)$row['sender_role'])) ?>
                                </small>
                            </td>

                            <td><?= e($row['subject']) ?></td>

                            <td class="text-muted small"
                                style="max-width: 250px; overflow-wrap: anywhere;">
                                <?= e($row['preview']) ?>
                            </td>

                            <td class="small text-muted">
                                <?php
                                $dateValue = $row['message_date'] ?? '';
                                $timestamp = $dateValue !== '' ? strtotime($dateValue) : false;
                                echo $timestamp !== false ? e(date('M d, Y', $timestamp)) : '—';
                                ?>
                            </td>

                            <td class="text-center">
                                <div class="d-inline-flex gap-1 flex-wrap">
                                    <a
                                        href="view_message.php?id=<?= (int)$row['id'] ?>"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        View
                                    </a>

                                    <form
                                        method="POST"
                                        class="d-inline"
                                        onsubmit="return confirm('Move this message to Archive & Restore?');"
                                    >
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['messages_csrf']) ?>">
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="id"
                                               value="<?= (int)$row['id'] ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-danger">
                                            Delete
                                        </button>
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
        if (
            el.children.length === 0 &&
            oldTexts.indexOf(el.textContent.trim().toLowerCase()) !== -1
        ) {
            el.style.display = 'none';
        }
    });

    // Add the logo
    if (!sidebar.querySelector('.sidebar-logo')) {
        var logo = document.createElement('div');
        logo.className = 'sidebar-logo';
        logo.innerHTML =
            '<a href="dashboard.php">' +
            '<img src="../images/white1.png" ' +
            'alt="Home for the Aged - Care, Comfort, Dignity">' +
            '</a>';

        sidebar.insertBefore(logo, sidebar.firstChild);
    }
});
</script>

</body>
</html>