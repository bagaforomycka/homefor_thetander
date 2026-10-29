
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'caretaker'
) {
    header('Location: ../index.php');
    exit;
}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function countRecords(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Caretaker dashboard count: ' . $e->getMessage());
        return 0;
    }
}

function latestRecord(PDO $pdo, string $sql): string {
    try {
        $value = $pdo->query($sql)->fetchColumn();
        return $value ? (string)$value : '';
    } catch (Throwable $e) {
        return '';
    }
}

/* Verify caretaker account. */
$userId = (int)$_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Caretaker';

try {
    $stmt = $pdo->prepare(
        "SELECT full_name, status
         FROM users
         WHERE id = ? AND role = 'caretaker'
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$account ||
        strtolower((string)$account['status']) !== 'active'
    ) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../index.php?error=account_inactive');
        exit;
    }

    $fullName = $account['full_name'] ?: $fullName;

} catch (Throwable $e) {
    error_log('Caretaker dashboard account: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to verify caretaker account. Check your database connection.');
}

/* Summary counts. */
$totalResidents = 0;

try {
    $residentColumns = $pdo->query("SHOW COLUMNS FROM residents")
        ->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('assigned_caretaker', $residentColumns, true)) {
        $totalResidents = countRecords(
            $pdo,
            "SELECT COUNT(*)
             FROM residents
             WHERE (
                 assigned_caretaker = ?
                 OR assigned_caretaker = ?
             )
             AND (
                 status IS NULL
                 OR LOWER(status) NOT IN ('archived', 'deleted')
             )",
            [$userId, $fullName]
        );
    }
} catch (Throwable $e) {
    error_log('Caretaker residents: ' . $e->getMessage());
}

/* Count tasks from the same table used by the admin dashboard. */
$todayTasks = countRecords(
    $pdo,
    "SELECT COUNT(*)
     FROM scheduled_tasks
     WHERE DATE(scheduled_date) = CURDATE()"
);

$completedTasks = countRecords(
    $pdo,
    "SELECT COUNT(*)
     FROM scheduled_tasks
     WHERE DATE(scheduled_date) = CURDATE()
       AND LOWER(status) = 'completed'"
);

$pendingTasks = countRecords(
    $pdo,
    "SELECT COUNT(*)
     FROM scheduled_tasks
     WHERE DATE(scheduled_date) = CURDATE()
       AND LOWER(status) <> 'completed'"
);

/* Load announcements published by the admin. */
try {
    $stmt = $pdo->query(
        "SELECT title, message, created_at
         FROM announcements
         WHERE status = 'Published'
         ORDER BY created_at DESC
         LIMIT 5"
    );
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Caretaker announcements: ' . $e->getMessage());
    $announcements = [];
}

/* Load upcoming/latest schedules. */
try {
    $stmt = $pdo->query(
        "SELECT task_name, scheduled_date, status
         FROM scheduled_tasks
         ORDER BY scheduled_date DESC
         LIMIT 5"
    );
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Caretaker schedules: ' . $e->getMessage());
    $schedules = [];
}

$newAnnouncements = latestRecord(
    $pdo,
    "SELECT MAX(created_at)
     FROM announcements
     WHERE status = 'Published'
       AND created_at >= NOW() - INTERVAL 1 DAY"
);

$newSchedules = latestRecord(
    $pdo,
    "SELECT MAX(created_at)
     FROM scheduled_tasks
     WHERE created_at >= NOW() - INTERVAL 1 DAY"
);

$hour = (int)date('G');
$greeting = $hour < 12
    ? 'Good Morning'
    : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Caretaker Dashboard - Home for the Aged</title>

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
            padding: 28px;
        }

        .page-header {
            margin-bottom: 22px;
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

        .welcome-panel {
            background: #fff;
            border: 2px solid #aab8ac;
            border-radius: 10px;
            padding: 22px;
            margin-bottom: 20px;
        }

        .welcome-panel h3 {
            color: #174d2b;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .welcome-panel p {
            color: #626b63;
            margin: 0;
        }

        .stat-card {
            position: relative;
            height: 100%;
            min-height: 112px;
            padding: 17px;
            background: #fff;
            border: 2px solid #aab8ac;
            border-radius: 10px;
            transition: border-color .2s ease;
        }

        .stat-card:hover {
            border-color: #174d2b;
        }

        .stat-link {
            display: block;
            height: 100%;
            color: inherit;
            text-decoration: none;
        }

        .stat-card h6 {
            color: #454d46;
            font-size: 12px;
            font-weight: 600;
            margin: 0 0 12px;
        }

        .stat-card h3 {
            color: #174d2b;
            font-size: 29px;
            font-weight: 700;
            margin: 0;
        }

        .stat-symbol {
            float: right;
            color: #23733e;
            font-size: 26px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-top: 20px;
        }

        .widget {
            min-width: 0;
            padding: 18px;
            background: #fff;
            border: 2px solid #aab8ac;
            border-radius: 10px;
            transition: border-color .2s ease;
        }

        .widget:hover {
            border-color: #174d2b;
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
            gap: 7px;
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

        .announcement-item,
        .schedule-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #dce2dc;
        }

        .announcement-item:last-child,
        .schedule-item:last-child {
            border-bottom: none;
        }

        .announcement-content,
        .schedule-content {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .announcement-content strong,
        .schedule-content strong {
            display: block;
            color: #303830;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .announcement-content p {
            font-size: 13px;
            color: #515b52;
            white-space: pre-wrap;
            margin: 0 0 5px;
        }

        .announcement-content small,
        .schedule-content small {
            display: block;
            color: #788078;
            font-size: 11px;
            margin-top: 3px;
        }

        .notification-dot {
            flex: 0 0 9px;
            width: 9px;
            height: 9px;
            border: 0;
            border-radius: 50%;
            background: #e53935;
            cursor: pointer;
        }

        .mark-read-button {
            flex-shrink: 0;
            padding: 5px 9px;
            background: #edf8ef;
            color: #174d2b;
            border: 1px solid #b7cbb9;
            border-radius: 5px;
            font-size: 11px;
        }

        .mark-read-button:hover {
            background: #dcefe0;
        }

        .read-label {
            flex-shrink: 0;
            color: #23733e;
            font-size: 11px;
        }

        .announcement-read .announcement-content {
            opacity: .65;
        }

        .empty-message {
            color: #788078;
            font-size: 13px;
            margin: 0;
            padding: 12px 0;
        }

        .quick-actions-section {
            margin-top: 22px;
        }

        .quick-actions-title {
            color: #174d2b;
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 13px;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .quick-action {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            padding: 15px;
            background: #fff;
            color: #303830;
            text-decoration: none;
            border: 2px solid #aab8ac;
            border-radius: 10px;
            transition: border-color .2s ease, background .2s ease;
        }

        .quick-action:hover {
            border-color: #174d2b;
            background: #f7faf7;
            color: #174d2b;
        }

        .quick-action-icon {
            display: grid;
            place-items: center;
            flex: 0 0 38px;
            width: 38px;
            height: 38px;
            background: #edf8ef;
            color: #174d2b;
            border-radius: 8px;
            font-size: 21px;
            font-weight: 700;
        }

        .quick-action-text {
            min-width: 0;
        }

        .quick-action-text strong {
            display: block;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .quick-action-text small {
            display: block;
            color: #788078;
            font-size: 11px;
            margin-top: 4px;
        }

        @media (max-width: 1200px) {
            .quick-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 991.98px) {
            .main-content {
                padding: 20px;
            }
        }

        @media (max-width: 700px) {
            .main-content {
                padding: 15px 12px;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .welcome-panel {
                padding: 17px;
            }

            .welcome-panel h3 {
                font-size: 21px;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">

    <div class="page-header">
        <h2>Caretaker Dashboard</h2>
        <p>Welcome to your Home for the Aged care workspace.</p>
    </div>

    <section class="welcome-panel">
        <h3><?= h($greeting) ?>, <?= h($fullName) ?>!</h3>
        <p>
            Thank you for providing compassionate care.
            Review your residents, daily tasks, schedules, and the latest
            announcements from the administrator.
        </p>
    </section>

    <!-- CARE SUMMARY -->

    <div class="row g-3">

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-symbol" aria-hidden="true">&#9823;</span>
                <a href="my_residents.php" class="stat-link">
                    <h6>My Residents</h6>
                    <h3><?= number_format($totalResidents) ?></h3>
                </a>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-symbol" aria-hidden="true">&#9638;</span>
                <a href="care_tasks.php" class="stat-link">
                    <h6>Today's Tasks</h6>
                    <h3><?= number_format($todayTasks) ?></h3>
                </a>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-symbol" aria-hidden="true">&#10003;</span>
                <a href="care_tasks.php" class="stat-link">
                    <h6>Completed Today</h6>
                    <h3><?= number_format($completedTasks) ?></h3>
                </a>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-symbol" aria-hidden="true">&#9711;</span>
                <a href="care_tasks.php" class="stat-link">
                    <h6>Pending Today</h6>
                    <h3><?= number_format($pendingTasks) ?></h3>
                </a>
            </div>
        </div>

    </div>

    <!-- ANNOUNCEMENTS AND SCHEDULES -->

    <div class="dashboard-grid">

        <section class="widget">

            <div class="widget-header">
                <h5>
                    Announcements
                    <?php if ($newAnnouncements !== ''): ?>
                        <button
                            type="button"
                            class="notification-dot"
                            data-notification-key="announcements"
                            data-notification-version="<?= h($newAnnouncements) ?>"
                            aria-label="Dismiss announcement notification"
                        ></button>
                    <?php endif; ?>
                </h5>

                <a href="announcements.php">View All &rarr;</a>
            </div>

            <?php if (!empty($announcements)): ?>

                <?php foreach ($announcements as $announcement): ?>

                    <?php
                    $announcementKey = 'announcement_' . md5(
                        ($announcement['title'] ?? '') . '|' .
                        ($announcement['created_at'] ?? '')
                    );
                    ?>

                    <div
                        class="announcement-item"
                        data-announcement-key="<?= h($announcementKey) ?>"
                    >
                        <div class="announcement-content">
                            <strong><?= h($announcement['title']) ?></strong>

                            <p><?= h($announcement['message'] ?? '') ?></p>

                            <small>
                                Published:
                                <?= !empty($announcement['created_at'])
                                    ? h(date(
                                        'M d, Y g:i A',
                                        strtotime($announcement['created_at'])
                                    ))
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
                    <?php if ($newSchedules !== ''): ?>
                        <button
                            type="button"
                            class="notification-dot"
                            data-notification-key="schedules"
                            data-notification-version="<?= h($newSchedules) ?>"
                            aria-label="Dismiss schedule notification"
                        ></button>
                    <?php endif; ?>
                </h5>

                <a href="my_schedule.php">View All &rarr;</a>
            </div>

            <?php if (!empty($schedules)): ?>

                <?php foreach ($schedules as $schedule): ?>

                    <div class="schedule-item">
                        <div class="schedule-content">

                            <strong>
                                <?= h($schedule['task_name'] ?? 'Scheduled Task') ?>
                            </strong>

                            <small>
                                Date:
                                <?= !empty($schedule['scheduled_date'])
                                    ? h(date(
                                        'M d, Y',
                                        strtotime($schedule['scheduled_date'])
                                    ))
                                    : 'Not set' ?>
                            </small>

                            <small>
                                Status: <?= h($schedule['status'] ?? 'Pending') ?>
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

    <!-- QUICK ACTIONS -->

    <section class="quick-actions-section">

        <h5 class="quick-actions-title">Quick Actions</h5>

        <div class="quick-actions">

            <a href="my_residents.php" class="quick-action">
                <span class="quick-action-icon">&#9823;</span>
                <span class="quick-action-text">
                    <strong>My Residents</strong>
                    <small>View assigned residents</small>
                </span>
            </a>

            <a href="care_tasks.php" class="quick-action">
                <span class="quick-action-icon">&#10003;</span>
                <span class="quick-action-text">
                    <strong>Care Tasks</strong>
                    <small>Review daily care activities</small>
                </span>
            </a>

            <a href="my_schedule.php" class="quick-action">
                <span class="quick-action-icon">&#9638;</span>
                <span class="quick-action-text">
                    <strong>My Schedule</strong>
                    <small>Review your schedule</small>
                </span>
            </a>

            <a href="health_information.php" class="quick-action">
                <span class="quick-action-icon">&#9829;</span>
                <span class="quick-action-text">
                    <strong>Health Information</strong>
                    <small>Review resident health details</small>
                </span>
            </a>

            <a href="messages.php" class="quick-action">
                <span class="quick-action-icon">&#9993;</span>
                <span class="quick-action-text">
                    <strong>Messages</strong>
                    <small>Open your messages</small>
                </span>
            </a>

            <a href="care_reports.php" class="quick-action">
                <span class="quick-action-icon">&#9776;</span>
                <span class="quick-action-text">
                    <strong>Care Reports</strong>
                    <small>Open care documentation</small>
                </span>
            </a>

            <a href="incident_reports.php" class="quick-action">
                <span class="quick-action-icon">&#9888;</span>
                <span class="quick-action-text">
                    <strong>Incident Reports</strong>
                    <small>View or record incidents</small>
                </span>
            </a>

            <a href="profile.php" class="quick-action">
                <span class="quick-action-icon">&#9881;</span>
                <span class="quick-action-text">
                    <strong>My Profile</strong>
                    <small>View your account information</small>
                </span>
            </a>

        </div>

    </section>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /*
     * Keep notification dismissal consistent with the admin dashboard.
     */
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
            // Dashboard remains usable when browser storage is unavailable.
        }

        dot.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            try {
                localStorage.setItem(storageKey, dismissedVersion);
            } catch (error) {
            }

            dot.remove();
        });
    });

    /*
     * Mark announcements as read in this browser.
     */
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
