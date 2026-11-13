<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    strtolower((string)$_SESSION['role']) !== 'caretaker'
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/archive_helper.php';
require_once __DIR__ . '/../includes/avatar.php';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$caretakerId = (int)$_SESSION['user_id'];

/* Caretaker profile (same source as profile.php) */
$user = [];
try {
    $stmt = $pdo->prepare(
        "SELECT
            u.full_name AS user_full_name,
            u.profile_picture,
            c.shift
         FROM users u
         INNER JOIN caretakers c ON c.username = u.username
         WHERE u.id = ?
           AND LOWER(u.role) = 'caretaker'
         LIMIT 1"
    );
    $stmt->execute([$caretakerId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('My Schedule profile error: ' . $e->getMessage());
}

$currentUserName = $user['user_full_name'] ?? ($_SESSION['full_name'] ?? 'Caretaker');
$profilePicture  = $user['profile_picture'] ?? '';

/* Assigned residents (same rules as myresidents.php) */
$assignedResidentsCount = 0;
try {
    archiveEnsureCaretakerTable($pdo);
    $residentVisible = archiveResidentVisibleSql($pdo, '');

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM residents
        WHERE assigned_caretaker = :caretaker
          AND $residentVisible
          AND NOT EXISTS (
              SELECT 1 FROM caretaker_resident_archive ca
              WHERE ca.resident_id = residents.id
                AND ca.caretaker_user_id = :caretaker_archive
          )
    ");
    $stmt->execute([
        'caretaker' => $caretakerId,
        'caretaker_archive' => $caretakerId
    ]);
    $assignedResidentsCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    error_log('My Schedule residents error: ' . $e->getMessage());
}

/*
 * Static sample data (same content as the design mockup).
 * Replace this array with a database query when the schedule table is ready.
 */
$weekStart = new DateTime('2025-10-13');
$weekEnd   = new DateTime('2025-10-17');

$schedule = [
    ['date' => '2025-10-13', 'shift' => 'Morning',   'tasks' => 'Assist residents with daily activities, check vital signs', 'status' => 'Completed'],
    ['date' => '2025-10-14', 'shift' => 'Morning',   'tasks' => 'Medication reminder, room check',                         'status' => 'Completed'],
    ['date' => '2025-10-15', 'shift' => 'Night',     'tasks' => 'Ensure residents are settled, monitor safety',            'status' => 'Upcoming'],
    ['date' => '2025-10-16', 'shift' => 'Afternoon', 'tasks' => 'Meal assistance, health monitoring',                      'status' => 'Upcoming'],
    ['date' => '2025-10-17', 'shift' => 'Morning',   'tasks' => 'Companionship, activity session',                         'status' => 'Upcoming'],
];

$shiftMeta = [
    'Morning'   => ['class' => 'shift-morning'],
    'Afternoon' => ['class' => 'shift-afternoon'],
    'Night'     => ['class' => 'shift-night'],
];

$statusMeta = [
    'Completed' => ['class' => 'status-completed'],
    'Upcoming'  => ['class' => 'status-upcoming'],
];

$totalShifts = count($schedule);

/* Monthly view helpers */
$shiftByDate = [];
foreach ($schedule as $row) {
    $shiftByDate[$row['date']] = $row;
}
$monthStart   = new DateTime($weekStart->format('Y-m-01'));
$daysInMonth  = (int)$monthStart->format('t');
$leadingBlank = (int)$monthStart->format('w'); // 0 = Sunday
$view = (($_GET['view'] ?? 'weekly') === 'monthly') ? 'monthly' : 'weekly';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule - Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
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

        .sidebar-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.4);
            z-index: 998;
            display: none;
        }
        .sidebar-overlay.active { display: block; }

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
        @media (max-width: 992px) {
            .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar {
                position: fixed;
                top: 0; left: 0;
                height: 100%;
                transform: translateX(-100%);
                width: 260px !important;
            }
            body.sidebar-open .sidebar, body.sidebar-open #sidebar, body.sidebar-open .sidebar-container, body.sidebar-open aside, body.sidebar-open .app-sidebar {
                transform: translateX(0);
            }
        }

        /* Page header */
        .residents-header {
            background: #eaf6ee;
            border: 1px solid #d8eadc;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 22px;
        }
        .residents-header h1 {
            color: #17683a;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .residents-header p { color: #61796a; margin-bottom: 0; }

        .welcome-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            border: 1px solid #d8eadc;
            border-radius: 12px;
            padding: 8px 14px;
            color: #17683a;
            font-size: 13px;
            text-decoration: none;
        }
        .welcome-chip small { display: block; color: #66786c; line-height: 1.1; }
        .welcome-chip strong { display: block; font-size: 14px; line-height: 1.3; }

        /* View toggle */
        .view-toggle {
            display: inline-flex;
            background: #f5faf6;
            border: 1px solid #d8eadc;
            border-radius: 10px;
            padding: 4px;
            gap: 4px;
        }
        .view-toggle a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            color: #17683a;
            text-decoration: none;
        }
        .view-toggle a.active { background: #198754; color: #fff; }
        .view-toggle a:not(.active):hover { background: #e7f5ec; }

        /* Main card */
        .single-resident-card {
            background: #fff;
            border: 1px solid #d8eadc;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.06);
        }

        .week-banner {
            border: 2px solid #417541;
            border-radius: 12px;
            padding: 14px 18px;
            background: #faf7fc;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
            color: #17683a;
        }
        .week-banner i { font-size: 22px; }
        .week-banner h2 { font-size: 18px; font-weight: 700; margin: 0; }

        /* Table */
        .report-table {
            width: 100%;
            margin-bottom: 0;
            border-collapse: collapse;
        }
        .report-table th {
            background-color: #e7f5ec;
            color: #17683a;
            font-weight: 700;
            font-size: 15px;
            padding: 12px 16px;
            text-align: center;
            border-bottom: 1px solid #d5eadc;
        }
        .report-table td {
            padding: 12px 16px;
            color: #303830;
            font-size: 14px;
            border-bottom: 1px solid #e5ede7;
            vertical-align: middle;
        }
        .report-table tbody tr:last-child td { border-bottom: none; }
        .report-table td.col-date  { font-weight: 700; width: 15%; text-align: center; }
        .report-table td.col-day   { width: 14%; text-align: center; }
        .report-table td.col-shift { width: 17%; text-align: center; }
        .report-table td.col-tasks { width: 34%; text-align: left; }
        .report-table td.col-status{ width: 20%; text-align: center; }

        /* Pills */
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border-radius: 12px;
            padding: 3px 14px;
            font-weight: 700;
            font-size: 13px;
            border: 1px solid transparent;
        }
        .shift-morning   { background: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .shift-afternoon { background: #fff3cd; border-color: #ffe69c; color: #8a5a00; }
        .shift-night     { background: #e0e7ff; border-color: #c7d2fe; color: #2f3f9e; }
        .status-completed { background: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .status-upcoming  { background: #e0ecff; border-color: #c5dbff; color: #1f4e9e; }

        /* Summary cards */
        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1.4fr;
            gap: 18px;
        }
        .summary-card {
            background: #f5faf6;
            border: 1px solid #e3eee6;
            border-radius: 14px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.05);
        }
        .summary-card .label { color: #294b35; font-weight: 700; font-size: 15px; }
        .summary-card .value { color: #17683a; font-weight: 700; font-size: 30px; line-height: 1.1; }
        .summary-card.note { align-items: flex-start; }
        .summary-link { text-decoration: none; color: inherit; }
        .summary-link:hover { border-color: #c9dccd; background: #eef7f0; }
        .summary-card.note p { margin: 0; font-size: 13px; color: #303830; line-height: 1.5; }

        /* Monthly calendar */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 6px;
        }
        .cal-head {
            background: #e7f5ec;
            color: #17683a;
            font-weight: 700;
            font-size: 14px;
            text-align: center;
            padding: 10px 4px;
            border-radius: 8px;
        }
        .cal-cell {
            min-height: 92px;
            border: 1px solid #e5ede7;
            border-radius: 10px;
            padding: 8px;
            background: #fff;
            font-size: 13px;
        }
        .cal-cell.empty { background: transparent; border-color: transparent; }
        .cal-cell .num { font-weight: 700; color: #294b35; margin-bottom: 6px; }
        .cal-cell.has-shift { background: #faf7fc; border-color: #c9dccd; }
        .cal-cell .pill { font-size: 11px; padding: 2px 8px; gap: 5px; }

        @media (max-width: 992px) {
            .summary-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .report-table th, .report-table td { padding: 10px 10px; }
            .cal-cell { min-height: 70px; padding: 5px; }
            .cal-cell .pill { font-size: 10px; padding: 2px 6px; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">

    <!-- Header -->
    <div class="residents-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1>My Schedule</h1>
            <p>View your assigned work schedule and upcoming tasks.</p>
        </div>
        <div class="d-flex align-items-center flex-wrap gap-3">
            <a href="profile.php" class="welcome-chip">
                <?= avatar_html($currentUserName, $profilePicture, 40) ?>
                <div>
                    <small>Welcome back,</small>
                    <strong><?= h($currentUserName) ?></strong>
                </div>
            </a>
            <div class="view-toggle">
                <a href="?view=weekly" class="<?= $view === 'weekly' ? 'active' : '' ?>">Weekly</a>
                <a href="?view=monthly" class="<?= $view === 'monthly' ? 'active' : '' ?>">Monthly</a>
            </div>
        </div>
    </div>

    <?php if ($view === 'weekly'): ?>
        <!-- Weekly view -->
        <div class="single-resident-card">
            <div class="week-banner">
                <h2>This Week &ndash; <?= h($weekStart->format('F j')) ?> to <?= h($weekEnd->format('F j, Y')) ?></h2>
            </div>

            <div class="table-responsive">
                <table class="table report-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Shift</th>
                            <th>Assigned Tasks</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedule as $row): ?>
                            <?php
                                $d  = new DateTime($row['date']);
                                $sm = $shiftMeta[$row['shift']];
                                $st = $statusMeta[$row['status']];
                            ?>
                            <tr>
                                <td class="col-date"><?= h($d->format('M d, Y')) ?></td>
                                <td class="col-day"><?= h($d->format('l')) ?></td>
                                <td class="col-shift">
                                    <span class="pill <?= h($sm['class']) ?>"><?= h($row['shift']) ?></span>
                                </td>
                                <td class="col-tasks"><?= h($row['tasks']) ?></td>
                                <td class="col-status">
                                    <span class="pill <?= h($st['class']) ?>"><?= h($row['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <!-- Monthly view -->
        <div class="single-resident-card">
            <div class="week-banner">
                <h2><?= h($monthStart->format('F Y')) ?></h2>
            </div>

            <div class="calendar-grid">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
                    <div class="cal-head"><?= h($dow) ?></div>
                <?php endforeach; ?>

                <?php for ($i = 0; $i < $leadingBlank; $i++): ?>
                    <div class="cal-cell empty"></div>
                <?php endfor; ?>

                <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                    <?php
                        $key  = $monthStart->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
                        $item = $shiftByDate[$key] ?? null;
                    ?>
                    <div class="cal-cell <?= $item ? 'has-shift' : '' ?>">
                        <div class="num"><?= $day ?></div>
                        <?php if ($item): ?>
                            <?php $sm = $shiftMeta[$item['shift']]; ?>
                            <span class="pill <?= h($sm['class']) ?>"><span><?= h($item['shift']) ?></span></span>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="summary-grid">
        <div class="summary-card">
            <div>
                <div class="label">Total Shifts This Week</div>
                <div class="value"><?= $totalShifts ?></div>
            </div>
        </div>

        <a href="myresidents.php" class="summary-card summary-link">
            <div>
                <div class="label">Assigned Residents</div>
                <div class="value"><?= (int)$assignedResidentsCount ?></div>
            </div>
        </a>

        <div class="summary-card note">
            <div>
                <div class="label">Note:</div>
                <p>Please notify the admin if you are unable to attend your scheduled shift.</p>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>