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
    "SELECT MAX(created_at) FROM schedules
     WHERE archived_at IS NULL
     AND created_at >= NOW() - INTERVAL 1 DAY"
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

/* Same table as Schedules / Add Schedule, so new schedules show up here. */
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

if (empty($_SESSION['schedules_csrf_token'])) {
    $_SESSION['schedules_csrf_token'] = bin2hex(random_bytes(32));
}

$scheduleCsrf = $_SESSION['schedules_csrf_token'];

try {
    $scheduleStmt = $pdo->query(
        "SELECT s.id, s.title, s.schedule_type, s.schedule_date,
                s.start_time, s.status,
                r.first_name, r.last_name
         FROM schedules s
         LEFT JOIN residents r ON r.id = s.resident_id
         WHERE s.archived_at IS NULL
           AND s.status = 'Scheduled'
         ORDER BY s.schedule_date ASC, s.start_time ASC, s.id ASC
         LIMIT 5"
    );

    $dashboardSchedules =
        $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Dashboard schedules error: ' . $e->getMessage());
    $dashboardSchedules = [];
}

$scheduleNotice = [
    'done'     => ['success', 'Schedule marked as done.'],
    'archived' => ['success', 'Schedule deleted. You can restore it from Archive & Restore.'],
    'notfound' => ['warning', 'That schedule was not found or was already updated.'],
    'invalid'  => ['warning', 'Please select a valid schedule.'],
][$_GET['schedule'] ?? ''] ?? null;
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
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            padding: 20px;
            flex-grow: 1;
            transition: margin-left 0.3s ease;
        }

        /* SIDEBAR TOGGLE BUTTON STYLES (Aligned Left) */
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
        .sidebar, #sidebar, .sidebar-container, aside {
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
            body.sidebar-folded aside {
                width: 70px;
                min-width: 70px;
            }

            /* Hide everything except the toggle button wrapper when folded */
            body.sidebar-folded .sidebar > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded #sidebar > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded aside > *:not(.sidebar-toggle-wrapper) {
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
            .sidebar, #sidebar, .sidebar-container, aside {
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
            body.sidebar-open aside {
                transform: translateX(0);
            }
        }

        /* SIDEBAR LOGO */
        .sidebar-logo {
            display: block;
            padding: 10px 4px 6px;
            text-align: center;
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
            transition: transform 0.3s ease, opacity 0.3s ease;
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
            transition: border-color 0.2s ease, background 0.2s ease;
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

        .schedule-info {
            flex: 1;
            min-width: 140px;
        }

        .schedule-item-actions {
            display: flex;
            gap: 6px;
        }

        .schedule-item-actions form {
            margin: 0;
        }

        .schedule-btn {
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }

        .schedule-btn-done {
            background: #17683a;
            color: #ffffff;
            border: 1px solid #17683a;
        }

        .schedule-btn-done:hover {
            background: #0f4f2a;
        }

        .schedule-btn-delete {
            background: #ffffff;
            color: #b02a37;
            border: 1px solid #e0a4aa;
        }

        .schedule-btn-delete:hover {
            background: #b02a37;
            color: #ffffff;
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
            transition: border-color 0.2s ease, background 0.2s ease;
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

<div class="sidebar-overlay" id="sidebarOverlay"></div>

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
            <a href="admission_tracker.php">View Graph →</a>
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

                <a href="announcements.php">View All →</a>
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

                <a href="schedules.php">View All →</a>
            </div>

            <?php if ($scheduleNotice): ?>
                <div class="alert alert-<?= h($scheduleNotice[0]) ?> py-2 px-3 mb-2" style="font-size:12px;">
                    <?= h($scheduleNotice[1]) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($dashboardSchedules)): ?>

                <?php foreach ($dashboardSchedules as $schedule): ?>

                    <?php
                    $dashResident = trim(
                        ($schedule['first_name'] ?? '') . ' ' .
                        ($schedule['last_name'] ?? '')
                    );
                    $dashTime = !empty($schedule['start_time'])
                        ? date('g:i A', strtotime($schedule['start_time']))
                        : '';
                    ?>

                    <div class="schedule-item">
                        <div class="schedule-info">
                            <strong><?= h($schedule['title']) ?></strong>

                            <small>
                                <?= !empty($schedule['schedule_date'])
                                    ? h(date('M d, Y', strtotime($schedule['schedule_date'])))
                                    : 'No date' ?>
                                <?= $dashTime !== '' ? '· ' . h($dashTime) : '' ?>
                                <?= $dashResident !== '' ? '· ' . h($dashResident) : '' ?>
                                · <?= h($schedule['schedule_type'] ?? '') ?>
                            </small>
                        </div>

                        <div class="schedule-item-actions">
                            <form method="POST" action="schedules.php">
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="return" value="dashboard">
                                <input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= h($scheduleCsrf) ?>">
                                <button type="submit" class="schedule-btn schedule-btn-done">
                                    Mark as Done
                                </button>
                            </form>

                            <?php if ($isAdmin): ?>
                                <form method="POST" action="schedules.php"
                                      onsubmit="return confirm('Delete this schedule? It will be moved to Archive &amp; Restore and can be restored later.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="return" value="dashboard">
                                    <input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= h($scheduleCsrf) ?>">
                                    <button type="submit" class="schedule-btn schedule-btn-delete">
                                        Delete
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <p class="empty-message">
                    No pending schedules. New schedules you add will appear here.
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
                    <strong>Manage Caretakers & Staff</strong>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Hamburger Toggle & Sidebar Behavior -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('sidebarOverlay');
    var sidebar = document.querySelector(
        '.sidebar, #sidebar, .sidebar-container, aside, nav'
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

    // Ensure the logo sits at the top of the sidebar
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