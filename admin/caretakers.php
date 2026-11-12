<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['caretakers_csrf'])) {
    $_SESSION['caretakers_csrf'] = bin2hex(random_bytes(32));
}

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$errorMsg = '';

/* Archive caretaker using POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
    $token = $_POST['csrf_token'] ?? '';
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if (!is_string($token) || !hash_equals($_SESSION['caretakers_csrf'], $token)) {
        $errorMsg = 'Security check failed. Please refresh the page and try again.';
    } elseif (!$id || $id < 1) {
        $errorMsg = 'Invalid caretaker record.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE caretakers
                 SET archived_at = NOW()
                 WHERE id = :id
                   AND archived_at IS NULL'
            );
            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() > 0) {
                /* Archived caretakers cannot log in until restored. */
                $pdo->prepare(
                    "UPDATE users u
                     INNER JOIN caretakers c ON c.username = u.username
                     SET u.status = 'inactive'
                     WHERE c.id = :id
                       AND u.role = 'caretaker'"
                )->execute(['id' => $id]);

                $pdo->commit();
                header('Location: caretakers.php?msg=archived');
                exit;
            }

            $pdo->rollBack();

            $errorMsg = 'Caretaker not found or already archived.';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Caretaker archive error: ' . $e->getMessage());
            $errorMsg = 'Could not archive this caretaker.';
        }
    }
}

/* Fetch active caretakers (using DISTINCT to prevent accidental row duplication) */
$records = [];

try {
    $sql = 'SELECT DISTINCT * FROM caretakers WHERE archived_at IS NULL';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (employee_id LIKE :search_id OR full_name LIKE :search_name)';
        $params['search_id'] = '%' . $search . '%';
        $params['search_name'] = '%' . $search . '%';
    }

    if ($status !== '') {
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }

    $sql .= ' ORDER BY id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Caretaker list error: ' . $e->getMessage());
    $errorMsg = 'Could not load caretakers database table.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Caretaker Management | Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --green: #17683a;
            --light-green: #33b960;
            --pale-green: #edf7ef;
            --text: #0c2717;
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
            width: 100%;
            max-width: 100%;
            min-width: 0;
            padding: 24px 32px 32px 24px;
            flex-grow: 1;
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
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
           <h2 class="fw-bold mb-1 text-success">Caretaker Management</h2>
            <p class="text-muted mb-0">View and manage caretaker profiles and status.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
           
            <a href="add_caretaker.php" class="btn btn-success">+ Add Caretaker</a>
        </div>
    </div>

    <?php if (($_GET['msg'] ?? '') === 'archived'): ?>
        <div class="alert alert-success">
            Caretaker archived successfully.
        </div>
    <?php endif; ?>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger"><?= e($errorMsg) ?></div>
    <?php endif; ?>

    <div class="card p-3 mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="search" class="form-label">Search by employee ID or name</label>
                <input
                    id="search"
                    type="search"
                    name="search"
                    class="form-control"
                    value="<?= e($search) ?>"
                    placeholder="Enter ID or name"
                >
            </div>

            <div class="col-md-4">
                <label for="status" class="form-label">Filter by status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All statuses</option>
                    <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="On Leave" <?= $status === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                    <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-success flex-fill">Search</button>
                <a href="caretakers.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="fw-bold mb-0">Caretaker List</h5>
            <span class="text-muted small">Total: <?= count($records) ?></span>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Full Name</th>
                        <th>Contact</th>
                        <th>Position</th>
                        <th>Shift</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$records): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No active caretakers found matching your criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $row): ?>
                        <tr>
                            <td><?= e($row['employee_id'] ?? '') ?></td>
                            <td><?= e($row['full_name'] ?? '') ?></td>
                            <td><?= e($row['contact'] ?? '') ?></td>
                            <td><?= e($row['position'] ?? 'N/A') ?></td>
                            <td><?= e($row['shift'] ?? '') ?></td>
                            <td>
                                <?php
                                $badge = ($row['status'] ?? '') === 'Active'
                                    ? 'success'
                                    : ((($row['status'] ?? '') === 'On Leave') ? 'warning text-dark' : 'secondary');
                                ?>
                                <span class="badge bg-<?= e($badge) ?>">
                                    <?= e($row['status'] ?? '') ?>
                                </span>
                            </td>
                            <td>
                                 <div class="d-flex gap-1 flex-wrap">
                                     <a
                                        href="view_profile_cs.php?id=<?= (int)$row['id'] ?>"
                                        class="btn btn-sm btn-outline-success"
                                    >View</a>

                                     <a
                                        href="edit_profile_cs.php?id=<?= (int)$row['id'] ?>"
                                        class="btn btn-sm btn-outline-warning"
                                    >Edit</a>

                                    <form method="POST" class="d-inline"
                                        onsubmit="return confirm('Archive this caretaker? You can restore them later from Archive & Restore.');">
                                       <input type="hidden" name="csrf_token" value="<?= e($_SESSION['caretakers_csrf']) ?>">
                                       <input type="hidden" name="action" value="archive">
                                       <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                       <button type="submit" class="btn btn-sm btn-outline-danger">
                                           Archive
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

<!-- Hamburger Toggle & Sidebar Behavior Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('sidebarOverlay');
    var sidebar = document.querySelector(
        '.sidebar, #sidebar, .sidebar-container, aside, .app-sidebar'
    );

    if (!sidebar) {
        return;
    }

    var oldTexts = ['home for the aged', 'care management system'];

    sidebar.querySelectorAll('*').forEach(function (el) {
        if (
            el.children.length === 0 &&
            oldTexts.indexOf(el.textContent.trim().toLowerCase()) !== -1
        ) {
            el.style.display = 'none';
        }
    });

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