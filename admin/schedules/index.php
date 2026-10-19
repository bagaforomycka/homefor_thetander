<?php
session_start();

require_once __DIR__ . '/../config/db.php';

if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dashboardCount($pdo, $sql) {
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function latestRecordVersion($pdo, $sql) {
    try {
        $value = $pdo->query($sql)->fetchColumn();
        return $value ? (string) $value : '';
    } catch (PDOException $e) {
        return '';
    }
}

function notificationDot($version, $key) {
    if ($version === '') {
        return '';
    }

    return '<button type="button"
        class="notification-dot"
        data-notification-key="' . h($key) . '"
        data-notification-version="' . h($version) . '"
        aria-label="Dismiss notification"
        title="Dismiss notification"></button>';
}

$totalResidents = dashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM residents"
);

$totalCaretakers = dashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM users
     WHERE role = 'caretaker' AND status = 'Active'"
);

$totalStaff = dashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM users
     WHERE role = 'staff' AND status = 'Active'"
);

$availableRooms = dashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM rooms WHERE status = 'Available'"
);

$totalHealthRecords = dashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM health_records"
);

$newResidents = latestRecordVersion(
    $pdo,
    "SELECT MAX(created_at) FROM residents
     WHERE created_at >= NOW() - INTERVAL 1 DAY"
);

$newHealthRecords = latestRecordVersion(
    $pdo,
    "SELECT MAX(created_at) FROM health_records
     WHERE created_at >= NOW() - INTERVAL 1 DAY"
);

$newRooms = latestRecordVersion(
    $pdo,
    "SELECT MAX(created_at) FROM rooms
     WHERE created_at >= NOW() - INTERVAL 1 DAY"
);

$newCaretakers = latestRecordVersion(
    $pdo,
    "SELECT MAX(created_at) FROM users
     WHERE role = 'caretaker'
     AND status = 'Active'
     AND created_at >= NOW() - INTERVAL 1 DAY"
);

$newStaff = latestRecordVersion(
    $pdo,
    "SELECT MAX(created_at) FROM users
     WHERE role = 'staff'
     AND status = 'Active'
     AND created_at >= NOW() - INTERVAL 1 DAY"
);

$newAnnouncements = latestRecordVersion(
    $pdo,
    "SELECT MAX(created_at) FROM announcements
     WHERE status = 'Published'
     AND created_at >= NOW() - INTERVAL 1 DAY"
);

$newSchedules = latestRecordVersion(
    $pdo,
    "SELECT MAX(created_at) FROM scheduled_tasks
     WHERE created_at >= NOW() - INTERVAL 1 DAY"
);

try {
    $announcementStmt = $pdo->query(
        "SELECT title, created_at
         FROM announcements
         WHERE status = 'Published'
         ORDER BY created_at DESC
         LIMIT 3"
    );

    $dashboardAnnouncements =
        $announcementStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dashboardAnnouncements = [];
}

try {
    $scheduleStmt = $pdo->query(
        "SELECT task_name, scheduled_date, status
         FROM scheduled_tasks
         ORDER BY scheduled_date DESC
         LIMIT 3"
    );

    $dashboardSchedules =
        $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dashboardSchedules = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard - Home for the Aged</title>

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
        }

        .main-content {
            width: 100%;
            max-width: 100%;
            min-width: 0;
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

        .page-header {
            margin-bottom: 20px;
        }

        .page-header h2 {
            color: #174d2b;
            font-size: 25px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .page-header p {
            color: #626b63;
            font-size: 13px;
            margin: 0;
        }

        .stat-card {
            position: relative;
            height: 100%;
            min-height: 112px;
            padding: 17px;
            background: #ffffff;
            border: 2px solid #aab8ac;
            border-radius: 10px;
            box-shadow: none;
            transition: border-color 0.2s ease,
                        background 0.2s ease;
        }

        .stat-card:hover {
            background: #f7faf7;
            border-color: #174d2b;
            box-shadow: none;
            transform: none;
        }

        .stat-card h6 {
            color: #454d46;
            font-size: 12px;
            font-weight: 600;
            margin: 0 0 12px;
            padding-right: 10px;
        }

        .stat-card h3 {
            color: #174d2b;
            font-size: 25px;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
        }

        .stat-link {
            display: block;
            height: 100%;
            color: inherit;
            text-decoration: none;
        }

        .notification-dot {
            position: absolute;
            top: 12px;
            right: 12px;
            display: block;
            width: 10px;
            height: 10px;
            padding: 0;
            border: none;
            border-radius: 50%;
            background: #e53935;
            cursor: pointer;
            z-index: 2;
        }

        .notification-dot:hover {
            background: #b71c1c;
        }

        .notification-dot:focus-visible {
            outline: 2px solid #b71c1c;
            outline-offset: 3px;
        }

        .widget-header .notification-dot {
            position: static;
            flex: 0 0 9px;
            width: 9px;
            height: 9px;
            margin-left: 6px;
        }

        .widget {
            min-width: 0;
            padding: 18px;
            background: #ffffff;
            border: 2px solid #aab8ac;
            border-radius: 10px;
            box-shadow: none;
            transition: border-color 0.2s ease;
        }

        .widget:hover {
            border-color: #174d2b;
            box-shadow: none;
        }

        .widget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .widget-header h5 {
            display: flex;
            align-items: center;
            color: #174d2b;
            font-size: 15px;
            font-weight: 700;
            margin: 0;
        }

        .widget-header a {
            color: #174d2b;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
        }

        .widget-header a:hover {
            text-decoration: underline;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-top: 20px;
        }

        .announcement-item,
        .schedule-item {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 11px 0;
            border-bottom: 1px solid #dce2dc;
        }

        .announcement-item:first-child,
        .schedule-item:first-child {
            padding-top: 3px;
        }

        .announcement-item:last-child,
        .schedule-item:last-child {
            border-bottom: none;
            padding-bottom: 2px;
        }

        .announcement-content {
            flex: 1;
            min-width: 130px;
        }

        .announcement-item strong,
        .schedule-item strong {
            display: block;
            color: #303830;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 5px;
            overflow-wrap: anywhere;
        }

        .announcement-item small,
        .schedule-item small {
            color: #626b63;
            font-size: 11px;
        }

        .mark-read-button {
            padding: 6px 10px;
            background: #ffffff;
            color: #174d2b;
            border: 1px solid #aab8ac;
            border-radius: 6px;
            box-shadow: none;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
        }

        .mark-read-button:hover {
            background: #174d2b;
            color: #ffffff;
            border-color: #174d2b;
            box-shadow: none;
        }

        .read-label {
            color: #626b63;
            font-size: 11px;
            font-weight: 600;
        }

        .empty-message {
            color: #626b63;
            font-size: 13px;
            margin: 0;
            padding: 8px 0;
        }

        .graph-widget {
            margin-top: 20px;
        }

        .graph-widget p {
            color: #626b63;
            font-size: 13px;
            line-height: 1.5;
            margin: 7px 0 14px;
        }

        .graph-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 9px 14px;
            background: #174d2b;
            color: #ffffff;
            border: 1px solid #174d2b;
            border-radius: 7px;
            box-shadow: none;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }

        .graph-link:hover {
            background: #103b20;
            color: #ffffff;
            border-color: #103b20;
            box-shadow: none;
        }

        .quick-actions-section {
            margin-top: 24px;
        }

        .quick-actions-title {
            color: #174d2b;
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 14px;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .quick-action {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 0;
            min-height: 64px;
            padding: 12px;
            background: #ffffff;
            color: #174d2b;
            border: 2px solid #c0cbc1;
            border-radius: 9px;
            box-shadow: none;
            text-decoration: none;
            transition: border-color 0.2s ease,
                        background 0.2s ease;
        }

        .quick-action:hover {
            background: #f5f8f5;
            color: #103b20;
            border-color: #174d2b;
            box-shadow: none;
            transform: none;
        }

        .quick-action:focus-visible,
        .graph-link:focus-visible,
        .mark-read-button:focus-visible {
            outline: 2px solid #174d2b;
            outline-offset: 3px;
        }

        .quick-action-icon {
            display: flex;
            flex: 0 0 34px;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            background: #174d2b;
            border-radius: 7px;
            color: #ffffff;
            font-size: 19px;
            font-weight: 500;
        }

        .quick-action-text {
            min-width: 0;
        }

        .quick-action-text strong {
            display: block;
            color: inherit;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.4;
        }

        .quick-action-text small {
            display: block;
            margin-top: 3px;
            color: #626b63;
            font-size: 10px;
            line-height: 1.3;
        }

        @media (max-width: 1100px) {
            .quick-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .page-header h2 {
                font-size: 22px;
            }

            .stat-card {
                min-height: 102px;
                padding: 14px;
            }

            .stat-card h3 {
                font-size: 23px;
            }

            .widget {
                padding: 14px;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .quick-action {
                min-height: 58px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                transition: none !important;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">

    <div class="page-header">
        <h2>Dashboard</h2>
        <p>Overview of the Home for the Aged Management System</p>
    </div>

    <div class="row g-3">

        <div class="col-6 col-xl">
            <div class="stat-card">
                <?= notificationDot($newResidents, 'residents') ?>

                <a href="residents.php" class="stat-link">
                    <h6>Total Residents</h6>
                    <h3><?= $totalResidents ?></h3>
                </a>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <?= notificationDot($newHealthRecords, 'health_records') ?>

                <a href="health_records.php" class="stat-link">
                    <h6>Health Records</h6>
                    <h3><?= $totalHealthRecords ?></h3>
                </a>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <?= notificationDot($newCaretakers, 'caretakers') ?>

                <a href="caretakers_staff.php" class="stat-link">
                    <h6>Total Caretakers</h6>
                    <h3><?= $totalCaretakers ?></h3>
                </a>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <?= notificationDot($newRooms, 'rooms') ?>

                <a href="rooms.php" class="stat-link">
                    <h6>Available Rooms</h6>
                    <h3><?= $availableRooms ?></h3>
                </a>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <?= notificationDot($newStaff, 'staff') ?>

                <a href="caretakers_staff.php" class="stat-link">
                    <h6>Active Staff</h6>
                    <h3><?= $totalStaff ?></h3>
                </a>
            </div>
        </div>

    </div>

    <section class="widget graph-widget">
        <div class="widget-header">
            <h5>Patient Admission Trends</h5>
            <a href="admission_tracker.php">View Graph &rarr;</a>
        </div>

        <p>
            Track resident admissions with weekly, monthly, and yearly statistics.
        </p>

        <a href="admission_tracker.php" class="graph-link">
            Open Admission Tracker
        </a>
    </section>

    <div class="dashboard-grid">

        <section class="widget">
            <div class="widget-header">
                <h5>
                    Announcements
                    <?= notificationDot($newAnnouncements, 'announcements') ?>
                </h5>

                <a href="announcements.php">View All &rarr;</a>
            </div>

            <?php if (!empty($dashboardAnnouncements)): ?>

                <?php foreach ($dashboardAnnouncements as $announcement): ?>

                    <?php
                    $announcementKey = 'announcement_' . md5(
                        $announcement['title'] . '|' .
                        ($announcement['created_at'] ?? '')
                    );
                    ?>

                    <div
                        class="announcement-item"
                        data-announcement-key="<?= h($announcementKey) ?>"
                    >
                        <div class="announcement-content">
                            <strong><?= h($announcement['title']) ?></strong>

                            <small>
                                <?= !empty($announcement['created_at'])
                                    ? date(
                                        'M d, Y',
                                        strtotime($announcement['created_at'])
                                    )
                                    : '' ?>
                            </small>
                        </div>

                        <button
                            type="button"
                            class="mark-read-button"
                            data-announcement-key="<?= h($announcementKey) ?>"
                        >
                            Mark as Read
                        </button>

                        <span class="read-label" hidden>Read</span>
                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <p class="empty-message">
                    No published announcements yet.
                </p>

            <?php endif; ?>
        </section>

        <section class="widget">
            <div class="widget-header">
                <h5>
                    Scheduled Tasks
                    <?= notificationDot($newSchedules, 'schedules') ?>
                </h5>

                <a href="schedules.php">View All &rarr;</a>
            </div>

            <?php if (!empty($dashboardSchedules)): ?>

                <?php foreach ($dashboardSchedules as $schedule): ?>

                    <div class="schedule-item">
                        <div>
                            <strong><?= h($schedule['task_name']) ?></strong>

                            <small>
                                Date:
                                <?= !empty($schedule['scheduled_date'])
                                    ? date(
                                        'M d, Y',
                                        strtotime($schedule['scheduled_date'])
                                    )
                                    : 'Not set' ?>
                                |
                                Status:
                                <?= h($schedule['status'] ?? 'Pending') ?>
                            </small>
                        </div>
                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <p class="empty-message">
                    No scheduled tasks available.
                </p>

            <?php endif; ?>
        </section>

    </div>

    <section class="quick-actions-section">
        <h5 class="quick-actions-title">Quick Actions</h5>

        <div class="quick-actions">

            <a href="residents.php" class="quick-action">
                <span class="quick-action-icon">+</span>
                <span class="quick-action-text">
                    <strong>Add / Manage Residents</strong>
                    <small>Resident information</small>
                </span>
            </a>

            <a href="caretakers_staff.php" class="quick-action">
                <span class="quick-action-icon">+</span>
                <span class="quick-action-text">
                    <strong>Manage Caretakers &amp; Staff</strong>
                    <small>Staff accounts and roles</small>
                </span>
            </a>

            <a href="rooms.php" class="quick-action">
                <span class="quick-action-icon">+</span>
                <span class="quick-action-text">
                    <strong>Manage Rooms</strong>
                    <small>Room availability</small>
                </span>
            </a>

            <a href="health_records.php" class="quick-action">
                <span class="quick-action-icon">+</span>
                <span class="quick-action-text">
                    <strong>Health Records</strong>
                    <small>Resident health information</small>
                </span>
            </a>

            <a href="announcements.php" class="quick-action">
                <span class="quick-action-icon">+</span>
                <span class="quick-action-text">
                    <strong>Create Announcement</strong>
                    <small>Publish an announcement</small>
                </span>
            </a>

            <a href="schedules.php" class="quick-action">
                <span class="quick-action-icon">+</span>
                <span class="quick-action-text">
                    <strong>Create Schedule</strong>
                    <small>Organize scheduled tasks</small>
                </span>
            </a>

        </div>
    </section>

</div>

</main>

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
            '<img src="../assets/images/logo.png" ' +
            'alt="Home for the Aged - Care, Comfort, Dignity">' +
            '</a>';

        sidebar.insertBefore(logo, sidebar.firstChild);
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll(
        '.notification-dot[data-notification-key]'
    ).forEach(function (dot) {

        const category = dot.dataset.notificationKey;
        const version = dot.dataset.notificationVersion;
        const storageKey = 'homeAgedRead_' + category;
        const dismissedVersion = category + ':' + version;

        try {
            if (localStorage.getItem(storageKey) === dismissedVersion) {
                dot.remove();
            }
        } catch (error) {
        }

        function dismissNotification() {
            try {
                localStorage.setItem(storageKey, dismissedVersion);
            } catch (error) {
            }

            dot.remove();
        }

        const widget = dot.closest('.stat-card, .widget');

        if (widget) {
            widget.addEventListener('click', function (event) {
                if (event.target.closest('.mark-read-button')) {
                    return;
                }

                dismissNotification();
            });
        }
    });

    document.querySelectorAll('.mark-read-button').forEach(function (button) {

        const key = 'homeAged_' + button.dataset.announcementKey;
        const item = button.closest('.announcement-item');
        const readLabel = item.querySelector('.read-label');

        function showAsRead() {
            button.hidden = true;
            readLabel.hidden = false;
            item.classList.add('announcement-read');
        }

        try {
            if (localStorage.getItem(key) === 'read') {
                showAsRead();
            }
        } catch (error) {
        }

        button.addEventListener('click', function () {
            try {
                localStorage.setItem(key, 'read');
            } catch (error) {
            }

            showAsRead();
        });
    });

});
</script>

</body>
</html>