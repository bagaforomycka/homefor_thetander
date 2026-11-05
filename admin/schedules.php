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

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$success = $_GET['success'] ?? '';
$error = '';

$isAdmin = $_SESSION['role'] === 'admin';

$allowedStatuses = ['Scheduled', 'Completed', 'Cancelled'];

/* Create a CSRF token for schedule actions[cite: 12]. */
if (empty($_SESSION['schedules_csrf_token'])) {
    $_SESSION['schedules_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['schedules_csrf_token'];

/* Delete means archive. It does not permanently delete the schedule[cite: 12]. */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only an administrator can archive schedules.');
    }

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token. Please return to Schedules and try again.');
    }

    $deleteId = filter_input(INPUT_POST, 'schedule_id', FILTER_VALIDATE_INT);

    if (!$deleteId || $deleteId < 1) {
        header('Location: schedules.php?success=invalid');
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE schedules
             SET archived_at = NOW()
             WHERE id = :id
               AND archived_at IS NULL'
        );

        $stmt->execute(['id' => $deleteId]);

        if ($stmt->rowCount() > 0) {
            header('Location: schedules.php?success=archived');
            exit;
        }

        header('Location: schedules.php?success=notfound');
        exit;
    } catch (PDOException $e) {
        error_log('Schedule archive error: ' . $e->getMessage());
        $error = 'Unable to archive this schedule. Please check the database and try again.';
    }
}

/* Retrieve active schedules and their optional resident names[cite: 12]. */
$sql = "
    SELECT
        s.*,
        r.resident_id AS resident_code,
        r.first_name,
        r.middle_name,
        r.last_name
    FROM schedules s
    LEFT JOIN residents r ON r.id = s.resident_id
    WHERE s.archived_at IS NULL
";

$params = [];

if ($search !== '') {
    $sql .= " AND (
        s.title LIKE :title
        OR s.assigned_staff LIKE :staff
        OR s.location LIKE :location
        OR r.resident_id LIKE :resident_code
        OR r.first_name LIKE :first_name
        OR r.last_name LIKE :last_name
    )";

    $term = '%' . $search . '%';

    $params = [
        'title' => $term,
        'staff' => $term,
        'location' => $term,
        'resident_code' => $term,
        'first_name' => $term,
        'last_name' => $term
    ];
}

if (in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= ' AND s.status = :status';
    $params['status'] = $statusFilter;
}

$sql .= ' ORDER BY s.schedule_date DESC, s.start_time DESC, s.id DESC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Schedule retrieval error: ' . $e->getMessage());
    $schedules = [];
    $error = 'Unable to load schedules. Please check the schedules and residents table structure.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedules - Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #eef1ee;
            font-family: Arial, sans-serif;
            color: #303830;
            display: flex;
            min-height: 100vh;
        }

        /* MAIN CONTENT LAYOUT */
        .main-content, .app-main {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            padding: 20px;
            flex-grow: 1;
            transition: margin-left 0.3s ease;
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

        .schedule-header {
            background: #eaf6ee;
            border: 1px solid #d8eadc;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 22px;
        }

        .schedule-header h1 {
            color: #17683a;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .schedule-header p {
            color: #61796a;
            margin-bottom: 0;
        }

        .schedule-panel {
            background: #fff;
            border: 1px solid #e3eee6;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 16px rgba(24,75,42,.05);
        }

        .schedule-search {
            background: #f5faf6;
        }

        .schedule-card {
            background: #fff;
            border: 1px solid #e0ebe3;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 14px;
        }

        .schedule-card h3 {
            color: #17683a;
            font-size: 19px;
            font-weight: 700;
        }

        .schedule-meta {
            color: #66786c;
            font-size: 13px;
            line-height: 1.8;
        }

        .schedule-description {
            background: #f7faf8;
            border: 1px solid #e5ede7;
            border-radius: 9px;
            padding: 12px;
            overflow-wrap: anywhere;
        }

        .schedule-status {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-scheduled {
            color: #17683a;
            background: #e5f5e9;
        }

        .status-completed {
            color: #1553a0;
            background: #e8f1ff;
        }

        .status-cancelled {
            color: #b02a37;
            background: #fbe9eb;
        }

        .btn-schedule-view {
            color: #198754;
            background: #fff;
            border: 1px solid #198754;
        }

        .btn-schedule-view:hover {
            color: #fff;
            background: #198754;
        }

        .btn-schedule-edit {
            color: #0d6efd;
            background: #fff;
            border: 1px solid #0d6efd;
        }

        .btn-schedule-edit:hover {
            color: #fff;
            background: #0d6efd;
        }

        .btn-schedule-delete {
            color: #dc3545;
            background: #fff;
            border: 1px solid #dc3545;
        }

        .btn-schedule-delete:hover {
            color: #fff;
            background: #dc3545;
        }

        .schedule-actions {
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 14px;
        }

        .schedule-actions .btn {
            font-size: 13px;
            padding: 5px 10px;
        }

        @media (max-width: 576px) {
            .schedule-header,
            .schedule-panel {
                padding: 15px;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">

<div class="schedule-header d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <h1>Schedules</h1>
        <p>Manage resident activities, appointments, and daily schedules.</p>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="dashboard.php" class="btn btn-outline-success">
            ← Back to Dashboard
        </a>

        <a href="add_schedule.php" class="btn btn-success">
            + Add Schedule
        </a>
    </div>
</div>

<?php if ($success === 'added'): ?>
    <div class="alert alert-success">Schedule added successfully.</div>
<?php elseif ($success === 'updated'): ?>
    <div class="alert alert-success">Schedule updated successfully.</div>
<?php elseif ($success === 'archived'): ?>
    <div class="alert alert-success">
        Schedule archived successfully. You can restore it from Archive & Restore in the sidebar.
    </div>
<?php elseif ($success === 'notfound'): ?>
    <div class="alert alert-warning">
        This schedule was not found or has already been archived.
    </div>
<?php elseif ($success === 'invalid'): ?>
    <div class="alert alert-warning">Please select a valid schedule.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="schedule-panel schedule-search">
    <form method="GET" action="schedules.php"
          class="d-flex flex-wrap gap-2 align-items-center">

        <input
            type="text"
            name="search"
            class="form-control flex-grow-1"
            style="min-width:220px"
            placeholder="Search title, resident, staff, or location..."
            value="<?= h($search) ?>"
        >

        <select name="status" class="form-select"
                style="width:auto;min-width:170px">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                All statuses
            </option>

            <?php foreach ($allowedStatuses as $status): ?>
                <option value="<?= h($status) ?>"
                    <?= $statusFilter === $status ? 'selected' : '' ?>>
                    <?= h($status) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-success">
            Search / Filter
        </button>

        <a href="schedules.php" class="btn btn-outline-secondary">
            Reset
        </a>
    </form>
</div>

<div class="schedule-panel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Schedule List</h2>
        <span class="text-secondary">
            <?= count($schedules) ?> schedule(s)
        </span>
    </div>

    <?php if (empty($schedules)): ?>
        <div class="alert alert-info mb-0">
            No active schedules found. Click Add Schedule to create one.
        </div>
    <?php else: ?>

        <?php foreach ($schedules as $item): ?>
            <?php
            $residentName = trim(
                ($item['first_name'] ?? '') . ' ' .
                ($item['middle_name'] ?? '') . ' ' .
                ($item['last_name'] ?? '')
            );

            $residentName = preg_replace('/\s+/', ' ', $residentName);

            $itemStatus = $item['status'] ?? 'Scheduled';

            $statusClass = match ($itemStatus) {
                'Completed' => 'status-completed',
                'Cancelled' => 'status-cancelled',
                default => 'status-scheduled'
            };

            $startTime = !empty($item['start_time'])
                ? strtotime($item['start_time'])
                : false;

            $endTime = !empty($item['end_time'])
                ? strtotime($item['end_time'])
                : false;
            ?>

            <div class="schedule-card">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h3 class="mb-1"><?= h($item['title'] ?? '') ?></h3>

                        <div class="schedule-meta">
                            Type: <?= h($item['schedule_type'] ?? '') ?>
                             | 
                            Date: <?= h($item['schedule_date'] ?? '') ?>
                             | 
                            Time:
                            <?= $startTime !== false ? h(date('g:i A', $startTime)) : 'Not specified' ?>

                            <?php if ($endTime !== false): ?>
                                – <?= h(date('g:i A', $endTime)) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <span class="schedule-status <?= h($statusClass) ?>">
                        <?= h($itemStatus) ?>
                    </span>
                </div>

                <div class="row g-2 mt-2">
                    <div class="col-md-6">
                        <div class="schedule-description">
                            <strong>Resident:</strong>

                            <?= h(
                                $residentName !== ''
                                    ? $residentName
                                    : (!empty($item['resident_id'])
                                        ? 'Resident not found'
                                        : 'Not assigned')
                            ) ?>

                            <?php if (!empty($item['resident_code'])): ?>
                                (<?= h($item['resident_code']) ?>)
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="schedule-description">
                            <strong>Assigned staff:</strong>
                            <?= h($item['assigned_staff'] ?: 'Not assigned') ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="schedule-description">
                            <strong>Location:</strong>
                            <?= h($item['location'] ?: 'Not specified') ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="schedule-description">
                            <strong>Description:</strong>
                            <?= h($item['description'] ?: 'No description') ?>
                        </div>
                    </div>
                </div>

                <div class="schedule-actions">
                    <a href="view_schedule.php?id=<?= (int)$item['id'] ?>"
                       class="btn btn-schedule-view">
                        View
                    </a>

                    <a href="add_schedule.php?edit=<?= (int)$item['id'] ?>"
                       class="btn btn-schedule-edit">
                        Edit
                    </a>

                    <?php if ($isAdmin): ?>
                        <form method="POST"
                              action="schedules.php"
                              class="d-inline"
                              onsubmit="return confirm('Delete this schedule from the active list? It will be moved to Archive & Restore and can be restored later.');">

                            <input type="hidden" name="action" value="delete">

                            <input type="hidden"
                                   name="schedule_id"
                                   value="<?= (int)$item['id'] ?>">

                            <input type="hidden"
                                   name="csrf_token"
                                   value="<?= h($csrfToken) ?>">

                            <button type="submit"
                                    class="btn btn-schedule-delete">
                                Delete
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>

</div>

<!-- Hamburger Toggle & Sidebar Behavior -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('sidebarOverlay');
    var sidebar = document.querySelector(
        '.sidebar, #sidebar, .sidebar-container, aside, .app-sidebar'
    );

    if (!sidebar) {
        return;
    }

    // Hide old text title and subtitle
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

    // Insert Hamburger Toggle button at the top left, right above the logo
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