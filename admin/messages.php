
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

if (empty($_SESSION['messages_csrf'])) {
    $_SESSION['messages_csrf'] = bin2hex(random_bytes(32));
}

$errorMsg = '';
$successMsg = '';

$tab = ($_GET['tab'] ?? 'all') === 'unread' ? 'unread' : 'all';
$search = trim($_GET['search'] ?? '');

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['messages_csrf'], $csrf)) {
        $errorMsg = 'Invalid CSRF token.';
    } else {

        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'delete' && $id > 0) {
            try {
                $stmt = $pdo->prepare("
                    DELETE FROM messages
                    WHERE id = ? AND recipient_id = ?
                ");

                $stmt->execute([$id, $_SESSION['user_id']]);

                $successMsg = $stmt->rowCount() > 0
                    ? 'Message deleted successfully.'
                    : 'Message not found or already deleted.';

            } catch (PDOException $ex) {
                $errorMsg = 'Unable to delete message. Please try again.';
            }
        }
    }
}

// Message counts
try {

    $stmtAll = $pdo->prepare("
        SELECT COUNT(*)
        FROM messages
        WHERE recipient_id = ?
    ");

    $stmtAll->execute([$_SESSION['user_id']]);
    $allCount = (int)$stmtAll->fetchColumn();

    $stmtUnread = $pdo->prepare("
        SELECT COUNT(*)
        FROM messages
        WHERE recipient_id = ? AND is_read = 0
    ");

    $stmtUnread->execute([$_SESSION['user_id']]);
    $unreadCount = (int)$stmtUnread->fetchColumn();

} catch (PDOException $ex) {

    $allCount = 0;
    $unreadCount = 0;
    $errorMsg = 'Unable to retrieve message counts.';
}

// Fetch messages
$sql = "
    SELECT
        m.*,
        COALESCE(u.full_name, 'Unknown Sender') AS sender_name,
        COALESCE(u.role, 'user') AS sender_role
    FROM messages m
    LEFT JOIN users u ON m.sender_id = u.id
    WHERE m.recipient_id = ?
";

$params = [$_SESSION['user_id']];

if ($tab === 'unread') {
    $sql .= " AND m.is_read = 0";
}

if ($search !== '') {
    $sql .= " AND (
        m.subject LIKE ?
        OR u.full_name LIKE ?
    )";

    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY m.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    $messages = [];
    $errorMsg = 'Unable to load messages. Please try again later.';
}

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
            --green-dark: #104b2b;
            --green-light: #e3f3e9;
            --green-border: #b9dcc6;
            --section-bg: #e8f5eb;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            max-width: 100%;
            min-height: 100%;
            margin: 0;
            overflow-x: hidden;
            font-family: Arial, sans-serif;
        }

        body {
            background-color: transparent;
        }

        /* MAIN CONTENT */

        .main-content {
            display: block;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            min-height: 100vh;
            margin: 0;
            padding: 20px 24px 35px;
            overflow-x: hidden;
        }

        /* PAGE HEADER */

        .page-banner {
            width: 100%;
            min-width: 0;
            background: var(--section-bg);
            border: 1px solid #d1e7dd;
            border-radius: 12px;
            padding: 22px 28px;
            margin-bottom: 22px;

            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;

            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.05);
        }

        .page-banner h2 {
            color: var(--green);
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 5px;
        }

        .page-banner p {
            color: #59645d;
            font-size: 15px;
            margin: 0;
        }

        .header-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .header-actions .btn {
            white-space: nowrap;
        }

        /* CONTENT CARDS */

        .content-card {
            display: block;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            padding: 24px 28px;
            margin-bottom: 22px;

            background: #ffffff;
            border: 1px solid #e3eee6;
            border-radius: 14px;

            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.06);
        }

        /* MESSAGE TABS */

        .message-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 0;
            margin: 0 0 22px;
            list-style: none;
        }

        .message-tabs .nav-link {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 9px;

            min-height: 45px;
            padding: 10px 20px;

            color: var(--green-dark) !important;
            background: #dff1e5 !important;
            border: 1px solid #a9d3b7 !important;
            border-radius: 9px !important;

            font-size: 15px;
            font-weight: 700;
            text-decoration: none;

            transition: 0.2s ease;
        }

        /* Visible inactive Unread button */

        .message-tabs .nav-link:not(.active) {
            background: #dff1e5 !important;
            color: #104b2b !important;
            border-color: #9acbab !important;
        }

        .message-tabs .nav-link:not(.active):hover {
            background: #c5e8d0 !important;
            border-color: var(--green) !important;
            color: #063b18 !important;
        }

        /* Active tab */

        .message-tabs .nav-link.active {
            background: var(--green) !important;
            color: #ffffff !important;
            border-color: var(--green) !important;
        }

        .message-tabs .nav-link.active:hover {
            background: var(--green-dark) !important;
        }

        .message-tabs .badge {
            min-width: 24px;
            padding: 5px 8px;
            border-radius: 6px;
            background: #ffffff !important;
            color: var(--green-dark) !important;
            font-size: 12px;
            font-weight: 700;
        }

        .message-tabs .nav-link.active .badge {
            background: #ffffff !important;
            color: var(--green-dark) !important;
        }

        /* SEARCH */

        .search-card {
            padding: 24px 28px;
        }

        .search-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
            gap: 14px;
            width: 100%;
        }

        .search-field {
            min-width: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: var(--green);
            font-size: 15px;
            font-weight: 700;
        }

        .form-control {
            width: 100%;
            min-width: 0;
            min-height: 46px;
            padding: 10px 14px;

            border: 1px solid #d1d5db;
            border-radius: 8px;

            font-size: 15px;
        }

        .form-control:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(23, 104, 58, 0.15);
        }

        .search-buttons {
            display: flex;
            gap: 8px;
        }

        .search-buttons .btn {
            min-height: 46px;
            padding: 9px 18px;
            white-space: nowrap;
        }

        /* INBOX */

        .inbox-card {
            padding: 24px 28px;
        }

        .inbox-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }

        .inbox-heading h5 {
            margin: 0;
            color: var(--green);
            font-size: 22px;
            font-weight: 700;
        }

        .inbox-total {
            color: #6c757d;
            font-size: 14px;
        }

        /* TABLE RESPONSIVENESS */

        .table-container {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            border-radius: 8px;
        }

        .inbox-table {
            width: 100%;
            min-width: 760px;
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .inbox-table thead th {
            padding: 15px 12px;
            background: var(--section-bg) !important;
            color: var(--green);
            font-size: 15px;
            font-weight: 700;
            white-space: nowrap;
            border-bottom: 2px solid #d1e7dd;
        }

        .inbox-table tbody td {
            padding: 15px 12px;
            color: #333;
            font-size: 14px;
            vertical-align: middle;
            border-bottom: 1px solid #e5e7eb;
        }

        .inbox-table tbody tr:hover {
            background: #f5faf6;
        }

        .sender-name {
            color: #111;
            font-weight: 700;
            font-size: 15px;
            overflow-wrap: anywhere;
        }

        .sender-role {
            display: block;
            margin-top: 4px;
            color: #777;
            font-size: 13px;
            text-transform: capitalize;
        }

        .message-subject {
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .message-preview {
            display: block;
            max-width: 220px;
            overflow: hidden;
            color: #6c757d;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .message-date {
            white-space: nowrap;
            color: #6c757d;
        }

        .message-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .message-actions .btn {
            font-size: 12px;
            padding: 6px 11px;
        }

        /* UNREAD MESSAGE INDICATOR */

        .unread-row {
            background: #f0faf3;
        }

        .unread-row .message-subject {
            color: var(--green-dark);
            font-weight: 700;
        }

        /* EMPTY STATE */

        .empty-state {
            padding: 45px 15px;
            text-align: center;
            color: #777;
        }

        .empty-state i {
            display: block;
            margin-bottom: 12px;
            color: #9cc9aa;
            font-size: 42px;
        }

        .empty-state h6 {
            color: var(--green);
            font-size: 17px;
            font-weight: 700;
        }

        /* ALERTS */

        .alert {
            overflow-wrap: anywhere;
            border-radius: 9px;
        }

        /* TABLET */

        @media (max-width: 991.98px) {

            .main-content {
                padding: 20px 18px 30px;
            }

            .page-banner {
                padding: 20px;
            }

            .page-banner h2 {
                font-size: 25px;
            }

            .content-card {
                padding: 20px;
            }

            .search-card {
                padding: 20px;
            }
        }

        /* MOBILE */

        @media (max-width: 767.98px) {

            .main-content {
                width: 100%;
                padding: 15px 12px 25px;
            }

            .page-banner {
                padding: 18px;
                margin-bottom: 16px;
                gap: 14px;
            }

            .page-banner h2 {
                font-size: 22px;
            }

            .page-banner p {
                font-size: 13px;
                line-height: 1.5;
            }

            .header-actions {
                width: 100%;
            }

            .header-actions .btn {
                flex: 1;
                font-size: 12px;
                padding: 9px 8px;
            }

            .message-tabs {
                gap: 8px;
                margin-bottom: 16px;
            }

            .message-tabs .nav-link {
                min-height: 42px;
                padding: 9px 14px;
                font-size: 13px;
            }

            .content-card {
                padding: 17px 14px;
                margin-bottom: 16px;
                border-radius: 11px;
            }

            .search-card {
                padding: 18px 15px;
            }

            .search-form {
                grid-template-columns: minmax(0, 1fr);
                gap: 12px;
            }

            .search-buttons {
                width: 100%;
            }

            .search-buttons .btn {
                flex: 1;
            }

            .inbox-card {
                padding: 18px 14px;
            }

            .inbox-heading h5 {
                font-size: 19px;
            }

            .inbox-total {
                font-size: 12px;
            }

            .inbox-table {
                min-width: 720px;
            }

            .inbox-table thead th,
            .inbox-table tbody td {
                padding: 12px 10px;
            }
        }

        /* SMALL MOBILE */

        @media (max-width: 400px) {

            .main-content {
                padding: 12px 9px 20px;
            }

            .page-banner {
                padding: 15px;
            }

            .page-banner h2 {
                font-size: 20px;
            }

            .content-card {
                padding: 14px 11px;
            }

            .message-tabs .nav-link {
                padding: 8px 12px;
                font-size: 12px;
            }

            .inbox-heading h5 {
                font-size: 17px;
            }
        }

    </style>

</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">

    <!-- PAGE HEADER -->

    <div class="page-banner">

        <div>
            <h2>Messages - Inbox</h2>
            <p>View and manage internal communication and system alerts.</p>
        </div>

        <div class="header-actions">

           

            <a href="new_message.php" class="btn btn-success">
                <i class="bi bi-plus-lg"></i>
                New Message
            </a>

        </div>

    </div>

    <!-- ALERTS -->

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

    <!-- MESSAGE TABS -->

    <ul class="nav nav-pills message-tabs">

        <li class="nav-item">

            <a
                class="nav-link <?= $tab === 'all' ? 'active' : '' ?>"
                href="messages.php?tab=all"
            >

                <i class="bi bi-inbox-fill"></i>

                All Inbox

                <span class="badge">
                    <?= $allCount ?>
                </span>

            </a>

        </li>

        <li class="nav-item">

            <a
                class="nav-link <?= $tab === 'unread' ? 'active' : '' ?>"
                href="messages.php?tab=unread"
            >

                <i class="bi bi-envelope-fill"></i>

                Unread

                <span class="badge">
                    <?= $unreadCount ?>
                </span>

            </a>

        </li>

    </ul>

    <!-- SEARCH CARD -->

    <div class="content-card search-card">

        <form method="GET" class="search-form">

            <input
                type="hidden"
                name="tab"
                value="<?= e($tab) ?>"
            >

            <div class="search-field">

                <label class="form-label">
                    Search by subject or sender
                </label>

                <input
                    type="text"
                    name="search"
                    class="form-control"
                    value="<?= e($search) ?>"
                    placeholder="Enter keyword..."
                >

            </div>

            <div class="search-buttons">

                <button type="submit" class="btn btn-success">
                    <i class="bi bi-search"></i>
                    Search
                </button>

                <a
                    href="messages.php?tab=<?= e($tab) ?>"
                    class="btn btn-outline-secondary"
                >
                    Reset
                </a>

            </div>

        </form>

    </div>

    <!-- INBOX CARD -->

    <div class="content-card inbox-card">

        <div class="inbox-heading">

            <h5>
                <?= $tab === 'unread' ? 'Unread Messages' : 'Inbox Messages' ?>
            </h5>

            <span class="inbox-total">
                Total: <?= count($messages) ?>
            </span>

        </div>

        <!-- RESPONSIVE TABLE -->

        <div class="table-container">

            <table class="table table-hover align-middle inbox-table">

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

                        <tr>

                            <td colspan="5">

                                <div class="empty-state">

                                    <i class="bi bi-envelope-open"></i>

                                    <h6>No Messages Found</h6>

                                    <p class="mb-0">
                                        There are currently no messages in this inbox.
                                    </p>

                                </div>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($messages as $row): ?>

                            <tr class="<?= (int)($row['is_read'] ?? 0) === 0 ? 'unread-row' : '' ?>">

                                <!-- SENDER -->

                                <td>

                                    <div class="sender-name">
                                        <?= e($row['sender_name']) ?>
                                    </div>

                                    <span class="sender-role">
                                        <?= e($row['sender_role']) ?>
                                    </span>

                                </td>

                                <!-- SUBJECT -->

                                <td>

                                    <div class="message-subject">
                                        <?= e($row['subject'] ?? 'No Subject') ?>
                                    </div>

                                </td>

                                <!-- PREVIEW -->

                                <td>

                                    <span class="message-preview">

                                        <?php
                                        $preview = $row['body'] ?? $row['message'] ?? '';
                                        $preview = function_exists('mb_substr')
                                            ? mb_substr($preview, 0, 45)
                                            : substr($preview, 0, 45);

                                        echo e($preview);
                                        ?>

                                        <?= strlen((string)($row['body'] ?? $row['message'] ?? '')) > 45 ? '...' : '' ?>

                                    </span>

                                </td>

                                <!-- DATE -->

                                <td>

                                    <span class="message-date">

                                        <?php if (!empty($row['created_at'])): ?>

                                            <?= e(date('M j, Y', strtotime($row['created_at']))) ?>

                                        <?php else: ?>

                                            N/A

                                        <?php endif; ?>

                                    </span>

                                </td>

                                <!-- ACTIONS -->

                                <td>

                                    <div class="message-actions">

                                        <a
                                            href="view_message.php?id=<?= (int)$row['id'] ?>"
                                            class="btn btn-sm btn-outline-success"
                                        >
                                            <i class="bi bi-eye"></i>
                                            View
                                        </a>

                                        <form
                                            method="POST"
                                            class="d-inline"
                                            onsubmit="return confirm('Delete this message?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($_SESSION['messages_csrf']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$row['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                            >
                                                <i class="bi bi-trash"></i>
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

</body>
</html>
