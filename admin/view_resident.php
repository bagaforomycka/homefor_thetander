
<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$residentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$resident = null;
$error = '';

if (!$residentId) {
    $error = 'Invalid resident ID.';
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT
                id,
                resident_id,
                first_name,
                middle_name,
                last_name,
                date_of_birth,
                gender,
                contact_number,
                address,
                room_number,
                admission_date,
                care_level,
                assigned_caretaker,
                status,
                profile_picture
            FROM residents
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute(['id' => $residentId]);
        $resident = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$resident) {
            $error = 'Resident not found.';
        }

    } catch (PDOException $ex) {
        error_log('View Resident error: ' . $ex->getMessage());
        $error = 'Unable to retrieve resident information.';
    }
}

$picture = '../images/default-profile.png';

if ($resident && !empty($resident['profile_picture'])) {
    $filename = basename($resident['profile_picture']);
    $physicalPath = __DIR__ . '/uploads/residentspic/' . $filename;

    if (is_file($physicalPath)) {
        $picture = 'uploads/residentspic/' . rawurlencode($filename);
    }
}

$fullName = '';

if ($resident) {
    $fullName = trim(implode(' ', array_filter([
        $resident['first_name'],
        $resident['middle_name'],
        $resident['last_name']
    ])));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Resident Profile | Home for the Aged</title>

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
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="profile-page">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">

        <div>
            <h1>Resident Profile</h1>
            <p>View the complete information of the selected resident.</p>
        </div>

        <a href="residents.php" class="btn btn-outline-success">
            Back to Residents
        </a>

    </div>

    <?php if ($error): ?>

        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>

    <?php elseif ($resident): ?>

        <div class="profile-panel">

            <div class="profile-heading">

                <img
                    src="<?= e($picture) ?>"
                    alt="Resident profile picture"
                    class="profile-picture"
                >

                <div>
                    <h2><?= e($fullName) ?></h2>

                    <p class="text-secondary mb-2">
                        Resident ID: <?= e($resident['resident_id']) ?>
                    </p>

                    <span class="status-badge">
                        <?= e($resident['status']) ?>
                    </span>
                </div>

            </div>

            <h5 class="section-title">Personal Information</h5>

            <div class="row g-3">

                <?php
                $personalDetails = [
                    'First Name' => $resident['first_name'],
                    'Middle Name' => $resident['middle_name'] ?: 'Not provided',
                    'Last Name' => $resident['last_name'],
                    'Date of Birth' => $resident['date_of_birth'] ?: 'Not provided',
                    'Gender' => $resident['gender'] ?: 'Not provided',
                    'Contact Number' => $resident['contact_number'] ?: 'Not provided',
                    'Address' => $resident['address'] ?: 'Not provided'
                ];
                ?>

                <?php foreach ($personalDetails as $label => $value): ?>

                    <div class="<?= $label === 'Address' ? 'col-12' : 'col-md-6 col-lg-4' ?>">
                        <div class="detail-box">
                            <small><?= e($label) ?></small>
                            <strong><?= e($value) ?></strong>
                        </div>
                    </div>

                <?php endforeach; ?>

            </div>

            <h5 class="section-title">Care Information</h5>

            <div class="row g-3">

                <?php
                $careDetails = [
                    'Room Number' => $resident['room_number'] ?: 'Not assigned',
                    'Admission Date' => $resident['admission_date'] ?: 'Not provided',
                    'Care Level' => $resident['care_level'] ?: 'Not specified',
                    'Assigned Caretaker ID' => $resident['assigned_caretaker'] ?: 'Not assigned',
                    'Current Status' => $resident['status'] ?: 'Not specified'
                ];
                ?>

                <?php foreach ($careDetails as $label => $value): ?>

                    <div class="col-md-6 col-lg-4">
                        <div class="detail-box">
                            <small><?= e($label) ?></small>
                            <strong><?= e($value) ?></strong>
                        </div>
                    </div>

                <?php endforeach; ?>

            </div>

        </div>

    <?php endif; ?>

</div>

</main>

</body>
</html>
