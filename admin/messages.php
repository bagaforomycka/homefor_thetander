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
$tab = trim($_GET['tab'] ?? 'caretakers');

$messages = [];

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
            margin: 0;
        }

        /* SIDEBAR TOGGLE BUTTON STYLES */
        .sidebar-toggle-btn {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 6px;
            padding: 6px;
            cursor: pointer;
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 4px;
            width: 36px;
            height: 36px;
            margin: 12px 0;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .sidebar-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
        }

        .sidebar-toggle-btn .hamburger-line {
            display: block;
            width: 18px;
            height: 2px;
            background-color: #ffffff;
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        .sidebar-toggle-wrapper {
            padding-left: 15px;
            width: 100%;
            display: flex;
            justify-content: flex-start;
        }

        /* SIDEBAR OVERLAY FOR MOBILE */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.4);
            z-index: 998;
            display: none;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* SIDEBAR DEFAULT & COMPACT/FOLDED BEHAVIOR */
        .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar {
            width: 260px;
            min-width: 260px;
            transition: width 0.3s ease, transform 0.3s ease;
            z-index: 999;
            flex-shrink: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* DESKTOP FOLDED STATE */
        @media (min-width: 993px) {
            body.sidebar-folded .sidebar,
            body.sidebar-folded #sidebar,
            body.sidebar-folded .sidebar-container,
            body.sidebar-folded aside,
            body.sidebar-folded .app-sidebar {
                width: 70px;
                min-width: 70px;
            }

            /* Hide everything except the toggle button wrapper when folded */
            body.sidebar-folded .sidebar > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded #sidebar > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded .sidebar-container > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded aside > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded .app-sidebar > *:not(.sidebar-toggle-wrapper) {
                display: none !important;
            }

            /* Center button in the 70px bar when folded */
            body.sidebar-folded .sidebar-toggle-wrapper {
                display: flex !important;
                justify-content: center !important;
                padding-left: 0 !important;
                width: 100% !important;
                margin-top: 12px !important;
            }
        }

        /* MOBILE SIDEBAR BEHAVIOR */
        @media (max-width: 992px) {
            .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100%;
                transform: translateX(-100%);
                width: 260px !important;
            }

            body.sidebar-open .sidebar,
            body.sidebar-open #sidebar,
            body.sidebar-open .sidebar-container,
            body.sidebar-open aside,
            body.sidebar-open .app-sidebar {
                transform: translateX(0);
            }
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
            width: 100% !important;
            max-width: none !important;
            min-width: 0;
            padding: 24px 32px 32px 24px;
            flex-grow: 1;
            box-shadow: none !important;
            overflow-x: hidden;
            transition: margin-left 0.3s ease;
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
            <h2 class="fw-bold mb-1 text-success">Messages - Inbox</h2>
            <p class="text-muted mb-0">View and manage internal communication and system alerts.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            
            <a href="new_message.php" class="btn btn-success">+ New Message</a>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="mb-4">
        <a href="messages.php?tab=caretakers" class="btn <?= $tab === 'caretakers' ? 'btn-success' : 'btn-outline-success' ?> px-4">Caretakers</a>
        <a href="messages.php?tab=unread" class="btn <?= $tab === 'unread' ? 'btn-success' : 'btn-outline-success' ?> px-4">Unread</a>
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
                            No messages found
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

<!-- Hamburger Toggle & Sidebar Behavior Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('sidebarOverlay');
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>