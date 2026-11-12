<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array(strtolower($_SESSION['role']), ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$recordId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$record = null;
$error = '';

if (!$recordId || $recordId < 1) {
    $error = 'Invalid health record ID.';
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT
                hr.id,
                hr.resident_id,
                hr.record_date,
                hr.blood_pressure,
                hr.temperature,
                hr.weight,
                hr.allergies,
                hr.medical_conditions,
                hr.medications,
                hr.notes,
                r.resident_id AS resident_code,
                r.first_name,
                r.middle_name,
                r.last_name,
                r.room_number,
                r.status,
                r.profile_picture
            FROM health_records hr
            INNER JOIN residents r ON r.id = hr.resident_id
            WHERE hr.id = :id
              AND hr.archived_at IS NULL
            LIMIT 1
        ");

        $stmt->execute(['id' => $recordId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            $error = 'Health record not found. It may have been archived.';
        }

    } catch (PDOException $ex) {
        error_log('View Health Record error: ' . $ex->getMessage());
        $error = 'Unable to retrieve health record information.';
    }
}

$picture = '../images/default-profile.png';

if ($record && !empty($record['profile_picture'])) {
    $filename = basename($record['profile_picture']);
    $physicalPath = __DIR__ . '/uploads/residentspic/' . $filename;

    if (is_file($physicalPath)) {
        $picture = 'uploads/residentspic/' . rawurlencode($filename);
    }
}

$fullName = '';
$checkupDate = '';

if ($record) {
    $fullName = trim(implode(' ', array_filter([
        $record['first_name'],
        $record['middle_name'],
        $record['last_name']
    ])));

    $checkupDate = $record['record_date'] ?: 'Not provided';
    if (!empty($record['record_date']) && strtotime($record['record_date'])) {
        $checkupDate = date('F j, Y', strtotime($record['record_date']));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Health Record | Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f3f7f4;
            font-family: Arial, sans-serif;
        }

        .profile-page {
            max-width: 1500px;
            margin: auto;
        }

        .page-header {
            background: #eaf6ee;
            border: 1px solid #d8eadc;
            border-radius: 14px;
            padding: 25px 28px;
            margin-bottom: 24px;
        }

        .page-header h1 {
            color: #17683a;
            font-size: 28px;
            font-weight: 700;
        }

        .page-header p {
            color: #61796a;
            margin: 0;
        }

        .profile-panel {
            background: #fff;
            border: 1px solid #e1ebe4;
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 4px 15px rgba(24, 75, 42, .04);
        }

        .profile-heading {
            display: flex;
            align-items: center;
            gap: 20px;
            padding-bottom: 25px;
            border-bottom: 1px solid #e8eee9;
        }

        .profile-picture {
            width: 115px;
            height: 115px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #e1ebe4;
            background: #e6f0e8;
        }

        .profile-heading h2 {
            color: #17683a;
            font-size: 25px;
            font-weight: 700;
        }

        .section-title {
            color: #17683a;
            font-size: 17px;
            font-weight: 700;
            margin: 28px 0 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e8eee9;
        }

        .detail-box {
            background: #f7faf8;
            border: 1px solid #e8eee9;
            border-radius: 9px;
            padding: 16px;
            min-height: 80px;
            height: 100%;
        }

        .detail-box small {
            display: block;
            color: #718078;
            margin-bottom: 8px;
        }

        .detail-box strong {
            color: #26352b;
            overflow-wrap: anywhere;
            white-space: pre-line;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            background: #e5f4e9;
            color: #17632c;
            font-size: 12px;
            font-weight: 700;
        }

        .btn-edit {
            color: #725800;
            background: #ffdf70;
            border: 1px solid #e2b422;
        }

        .btn-edit:hover {
            color: #5d4600;
            background: #ffcf40;
            border-color: #d6a000;
        }

        @media (max-width: 576px) {
            .profile-panel {
                padding: 18px;
            }

            .profile-picture {
                width: 80px;
                height: 80px;
            }

            .profile-heading h2 {
                font-size: 20px;
            }
        }

        @media print {
            .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar, .no-print {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .profile-panel {
                box-shadow: none;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="profile-page">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">

        <div>
            <h1>Health Record</h1>
            <p>View the complete health information of the selected resident.</p>
        </div>

        <a href="health_records.php" class="btn btn-outline-success no-print">
            Back to Health Records
        </a>

    </div>

    <?php if ($error): ?>

        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>

    <?php elseif ($record): ?>

        <div class="profile-panel">

            <div class="profile-heading">

                <img
                    src="<?= e($picture) ?>"
                    alt="Resident profile picture"
                    class="profile-picture"
                >

                <div class="flex-grow-1">
                    <h2><?= e($fullName) ?></h2>

                    <p class="text-secondary mb-2">
                        Resident ID: <?= e($record['resident_code']) ?>
                        | Room: <?= e($record['room_number'] ?: 'Not assigned') ?>
                    </p>

                    <span class="status-badge">
                        Check-up: <?= e($checkupDate) ?>
                    </span>
                </div>

                <div class="d-flex flex-wrap gap-2 no-print">
                    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                    <a href="add_health_record.php?edit=<?= (int)$record['id'] ?>" class="btn btn-edit">Edit</a>
                </div>

            </div>

            <h5 class="section-title">Vital Signs</h5>

            <div class="row g-3">

                <?php
                $vitalDetails = [
                    'Blood Pressure' => $record['blood_pressure'] ?: 'Not recorded',
                    'Temperature' => ($record['temperature'] !== null && $record['temperature'] !== '')
                        ? $record['temperature'] . ' °C'
                        : 'Not recorded',
                    'Weight' => ($record['weight'] !== null && $record['weight'] !== '')
                        ? $record['weight'] . ' kg'
                        : 'Not recorded'
                ];
                ?>

                <?php foreach ($vitalDetails as $label => $value): ?>

                    <div class="col-md-6 col-lg-4">
                        <div class="detail-box">
                            <small><?= e($label) ?></small>
                            <strong><?= e($value) ?></strong>
                        </div>
                    </div>

                <?php endforeach; ?>

            </div>

            <h5 class="section-title">Medical Information</h5>

            <div class="row g-3">

                <?php
                $medicalDetails = [
                    'Medical Conditions' => trim((string)$record['medical_conditions']) !== '' ? $record['medical_conditions'] : 'None recorded',
                    'Allergies' => trim((string)$record['allergies']) !== '' ? $record['allergies'] : 'None recorded',
                    'Medications' => trim((string)$record['medications']) !== '' ? $record['medications'] : 'None recorded'
                ];
                ?>

                <?php foreach ($medicalDetails as $label => $value): ?>

                    <div class="col-md-6 col-lg-4">
                        <div class="detail-box">
                            <small><?= e($label) ?></small>
                            <strong><?= e($value) ?></strong>
                        </div>
                    </div>

                <?php endforeach; ?>

            </div>

            <h5 class="section-title">Additional Notes</h5>

            <div class="row g-3">

                <div class="col-12">
                    <div class="detail-box">
                        <small>Notes</small>
                        <strong><?= e(trim((string)$record['notes']) !== '' ? $record['notes'] : 'No notes') ?></strong>
                    </div>
                </div>

            </div>

        </div>

    <?php endif; ?>

</div>

</main>

</body>
</html>