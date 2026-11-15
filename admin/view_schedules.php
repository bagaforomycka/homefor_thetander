
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

$scheduleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$scheduleId || $scheduleId < 1) {
    header('Location: schedules.php');
    exit;
}

$error = '';
$schedule = null;

try {
    $stmt = $pdo->prepare("
        SELECT
            s.*,
            r.resident_id AS resident_code,
            r.first_name,
            r.middle_name,
            r.last_name
        FROM schedules s
        LEFT JOIN residents r ON r.id = s.resident_id
        WHERE s.id = :id
          AND s.archived_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(['id' => $scheduleId]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$schedule) {
        $error = 'Schedule not found or has already been archived.';
    }

} catch (PDOException $e) {
    error_log('View schedule error: ' . $e->getMessage());
    $error = 'Unable to retrieve schedule details. Please check your database.';
}

$residentName = '';

if ($schedule) {
    $residentName = trim(
        ($schedule['first_name'] ?? '') . ' ' .
        ($schedule['middle_name'] ?? '') . ' ' .
        ($schedule['last_name'] ?? '')
    );

    $residentName = preg_replace('/\s+/', ' ', $residentName);
}

$startTime = !empty($schedule['start_time'])
    ? strtotime($schedule['start_time'])
    : false;

$endTime = !empty($schedule['end_time'])
    ? strtotime($schedule['end_time'])
    : false;

$status = $schedule['status'] ?? 'Scheduled';

$statusClass = match ($status) {
    'Completed' => 'completed',
    'Cancelled' => 'cancelled',
    default => 'scheduled'
};
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>View Schedule - Home for the Aged</title>

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
            min-width: 0;
            padding: 25px;
            flex-grow: 1;
        }

        .page-header {
            background: #eaf6ee;
            border: 1px solid #d8eadc;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 22px;
        }

        .page-header h1 {
            color: #17683a;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .page-header p {
            color: #61796a;
            margin-bottom: 0;
        }

        .details-panel {
            background: #ffffff;
            border: 1px solid #e3eee6;
            border-radius: 14px;
            padding: 25px;
            box-shadow: 0 4px 16px rgba(24, 75, 42, .05);
        }

        .details-title {
            color: #17683a;
            font-size: 22px;
            font-weight: 700;
        }

        .detail-item {
            background: #f7faf8;
            border: 1px solid #e5ede7;
            border-radius: 9px;
            padding: 16px;
            height: 100%;
            overflow-wrap: anywhere;
        }

        .detail-label {
            display: block;
            color: #66786c;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .detail-value {
            color: #303830;
            font-size: 15px;
            font-weight: 600;
        }

        .description-box {
            background: #f7faf8;
            border: 1px solid #e5ede7;
            border-radius: 9px;
            padding: 18px;
            min-height: 110px;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .schedule-status {
            display: inline-block;
            padding: 6px 13px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .scheduled {
            color: #17683a;
            background: #e5f5e9;
        }

        .completed {
            color: #1553a0;
            background: #e8f1ff;
        }

        .cancelled {
            color: #b02a37;
            background: #fbe9eb;
        }

        .btn-back {
            border: 1px solid #198754;
            color: #17683a;
            background: #ffffff;
        }

        .btn-back:hover {
            background: #198754;
            color: #ffffff;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .page-header {
                padding: 17px;
            }

            .details-panel {
                padding: 17px;
            }

            .details-title {
                font-size: 19px;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">

        <div>
            <h1>Schedule Details</h1>
            <p>View complete information about this scheduled activity.</p>
        </div>

        <a href="schedules.php" class="btn btn-back">
            Back to Schedules
        </a>

    </div>

    <?php if ($error !== ''): ?>

        <div class="alert alert-danger">
            <?= h($error) ?>
        </div>

        <a href="schedules.php" class="btn btn-success">
            Return to Schedules
        </a>

    <?php elseif ($schedule): ?>

        <div class="details-panel">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

                <h2 class="details-title mb-0">
                    <?= h($schedule['title']) ?>
                </h2>

                <span class="schedule-status <?= h($statusClass) ?>">
                    <?= h($status) ?>
                </span>

            </div>

            <div class="row g-3">

                <div class="col-md-6">
                    <div class="detail-item">
                        <span class="detail-label">Schedule ID</span>
                        <div class="detail-value">
                            <?= (int)$schedule['id'] ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item">
                        <span class="detail-label">Schedule Type</span>
                        <div class="detail-value">
                            <?= h($schedule['schedule_type'] ?: 'Not specified') ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item">
                        <span class="detail-label">Schedule Date</span>
                        <div class="detail-value">
                            <?= h($schedule['schedule_date'] ?: 'Not specified') ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item">
                        <span class="detail-label">Schedule Time</span>
                        <div class="detail-value">

                            <?php if ($startTime !== false): ?>

                                <?= h(date('g:i A', $startTime)) ?>

                                <?php if ($endTime !== false): ?>
                                    - <?= h(date('g:i A', $endTime)) ?>
                                <?php endif; ?>

                            <?php else: ?>

                                Not specified

                            <?php endif; ?>

                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item">
                        <span class="detail-label">Resident</span>

                        <div class="detail-value">

                            <?php if ($residentName !== ''): ?>

                                <?= h($residentName) ?>

                            <?php elseif (!empty($schedule['resident_id'])): ?>

                                Resident not found

                            <?php else: ?>

                                Not assigned

                            <?php endif; ?>

                            <?php if (!empty($schedule['resident_code'])): ?>

                                <br>

                                <small class="text-secondary">
                                    Resident ID: <?= h($schedule['resident_code']) ?>
                                </small>

                            <?php endif; ?>

                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item">
                        <span class="detail-label">Assigned Staff</span>
                        <div class="detail-value">
                            <?= h($schedule['assigned_staff'] ?: 'Not assigned') ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item">
                        <span class="detail-label">Location</span>
                        <div class="detail-value">
                            <?= h($schedule['location'] ?: 'Not specified') ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <div class="detail-value">
                            <?= h($status) ?>
                        </div>
                    </div>
                </div>

                <div class="col-12">

                    <span class="detail-label">Description</span>

                    <div class="description-box">
                        <?= h($schedule['description'] ?: 'No description provided.') ?>
                    </div>

                </div>

            </div>

            <div class="d-flex justify-content-end gap-2 mt-4 flex-wrap">

                <a href="schedules.php" class="btn btn-outline-secondary">
                    Back
                </a>

                <a href="add_schedule.php?edit=<?= (int)$schedule['id'] ?>"
                   class="btn btn-success">
                    Edit Schedule
                </a>

            </div>

        </div>

    <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
