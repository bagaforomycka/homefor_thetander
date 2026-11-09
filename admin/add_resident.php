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
require_once __DIR__ . '/../config/room_helper.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$errors = [];

$values = [
    'resident_id' => '',
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'date_of_birth' => '',
    'gender' => '',
    'contact_number' => '',
    'address' => '',
    'room_number' => '',
    'admission_date' => date('Y-m-d'),
    'care_level' => '',
    'assigned_caretaker' => '',
    'status' => 'new'
];

$caretakers = [];

try {
    // Load caretaker accounts.
    // This assumes users has an id, full_name, and role column.
    $caretakerQuery = $pdo->prepare(
        "SELECT id, full_name
         FROM users
         WHERE role = 'caretaker'
         ORDER BY full_name"
    );
    $caretakerQuery->execute();
    $caretakers = $caretakerQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log($e->getMessage());
    $errors[] = 'Unable to load caretakers. Check your users table columns.';
}

// Rooms from Room Management that are Available and still have a free bed.
$availableRooms = [];

try {
    $availableRooms = fetchAvailableRooms($pdo);
} catch (Throwable $e) {
    error_log('Unable to load rooms: ' . $e->getMessage());
    $errors[] = 'Unable to load rooms. Check the rooms table.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $values[$key] = trim($_POST[$key]);
        }
    }

    $allowedGenders = ['Male', 'Female', 'Other'];
    $allowedCareLevels = ['Low', 'Moderate', 'High'];
    $allowedStatuses = [
        'active',
        'needs_attention',
        'new',
        'discharged',
        'archived'
    ];

    if ($values['resident_id'] === '') {
        $errors[] = 'Resident ID is required.';
    }

    if ($values['first_name'] === '' || $values['last_name'] === '') {
        $errors[] = 'First name and last name are required.';
    }

    if (
        $values['gender'] !== '' &&
        !in_array($values['gender'], $allowedGenders, true)
    ) {
        $errors[] = 'Please select a valid gender.';
    }

    if (
        $values['care_level'] !== '' &&
        !in_array($values['care_level'], $allowedCareLevels, true)
    ) {
        $errors[] = 'Please select a valid care level.';
    }

    if (!in_array($values['status'], $allowedStatuses, true)) {
        $errors[] = 'Please select a valid status.';
    }

    foreach (['date_of_birth', 'admission_date'] as $dateField) {
        if ($values[$dateField] !== '') {
            $date = DateTime::createFromFormat(
                '!Y-m-d',
                $values[$dateField]
            );

            if (
                !$date ||
                $date->format('Y-m-d') !== $values[$dateField]
            ) {
                $errors[] = 'Please enter valid dates.';
                break;
            }
        }
    }

    if (
        $values['date_of_birth'] !== '' &&
        $values['date_of_birth'] > date('Y-m-d')
    ) {
        $errors[] = 'Date of birth cannot be in the future.';
    }

    if (
        $values['admission_date'] !== '' &&
        $values['admission_date'] > date('Y-m-d')
    ) {
        $errors[] = 'Admission date cannot be in the future.';
    }

    if (strlen($values['resident_id']) > 50) {
        $errors[] = 'Resident ID cannot exceed 50 characters.';
    }

    if (
        $values['room_number'] !== '' &&
        !in_array(
            $values['room_number'],
            array_map('strval', array_column($availableRooms, 'room_number')),
            true
        )
    ) {
        $errors[] = 'Please select a room that is available.';
    }

    if (!$errors) {
        try {
            $check = $pdo->prepare(
                'SELECT id FROM residents
                 WHERE resident_id = :resident_id
                 LIMIT 1'
            );

            $check->execute([
                'resident_id' => $values['resident_id']
            ]);

            if ($check->fetch()) {
                $errors[] = 'That Resident ID already exists.';
            } else {
                // Validate the selected caretaker ID.
                $caretakerId = null;

                if ($values['assigned_caretaker'] !== '') {
                    if (
                        !ctype_digit($values['assigned_caretaker']) ||
                        !in_array(
                            (int)$values['assigned_caretaker'],
                            array_map(
                                'intval',
                                array_column($caretakers, 'id')
                            ),
                            true
                        )
                    ) {
                        $errors[] = 'Please select a valid caretaker.';
                    } else {
                        $caretakerId = (int)$values['assigned_caretaker'];
                    }
                }

                if (!$errors) {
                    $pdo->beginTransaction();

                    if ($values['room_number'] !== '') {
                        // Lock the room so two people cannot take its last bed at once.
                        $lockSql = 'SELECT id, status
                                    FROM rooms
                                    WHERE room_number = :room_number';

                        if (in_array('archived_at', roomHelperColumns($pdo, 'rooms'), true)) {
                            $lockSql .= ' AND archived_at IS NULL';
                        }

                        $lock = $pdo->prepare($lockSql . ' FOR UPDATE');
                        $lock->execute(['room_number' => $values['room_number']]);
                        $room = $lock->fetch(PDO::FETCH_ASSOC);

                        if (
                            !$room ||
                            $room['status'] !== 'Available' ||
                            countRoomOccupants($pdo, $values['room_number']) > 0
                        ) {
                            $pdo->rollBack();
                            $errors[] = 'That room is no longer available. Please choose another room.';
                        }
                    }
                }

                if (!$errors) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO residents (
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
                            status
                        ) VALUES (
                            :resident_id,
                            :first_name,
                            :middle_name,
                            :last_name,
                            :date_of_birth,
                            :gender,
                            :contact_number,
                            :address,
                            :room_number,
                            :admission_date,
                            :care_level,
                            :assigned_caretaker,
                            :status
                        )'
                    );

                    $stmt->execute([
                        'resident_id' => $values['resident_id'],
                        'first_name' => $values['first_name'],
                        'middle_name' => $values['middle_name'] ?: null,
                        'last_name' => $values['last_name'],
                        'date_of_birth' => $values['date_of_birth'] ?: null,
                        'gender' => $values['gender'] ?: null,
                        'contact_number' => $values['contact_number'] ?: null,
                        'address' => $values['address'] ?: null,
                        'room_number' => $values['room_number'] ?: null,
                        'admission_date' => $values['admission_date'] ?: null,
                        'care_level' => $values['care_level'] ?: null,
                        'assigned_caretaker' => $caretakerId,
                        'status' => $values['status']
                    ]);

                    // The room now shows as Occupied in Room Management.
                    syncRoomStatus($pdo, $values['room_number']);

                    $pdo->commit();

                    header('Location: residents.php?added=1');
                    exit;
                }

                // A room problem was found: refresh the list shown below.
                $availableRooms = fetchAvailableRooms($pdo);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $errors[] = 'The resident could not be saved. Check the server error log for the database error.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Resident | Home for the Aged</title>

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
            color: #24352b;
            font-family: Arial, Helvetica, sans-serif;
        }

        /*
         * The shared includes/sidebar.php provides the sidebar, the hamburger
         * button and opens <main class="app-main">. Its layout (open or hidden)
         * is controlled there, so .app-main is not overridden on this page.
         */
        .app-main {
            min-width: 0;
            overflow-x: hidden;
        }

        .page-heading {
            background: #304b3b;
            color: white;
            border-radius: 14px;
            padding: 22px;
        }

        .form-card {
            background: white;
            padding: 26px;
            border-radius: 14px;
            box-shadow: 0 3px 14px rgba(0, 0, 0, .06);
        }

        .form-label {
            font-weight: 600;
        }

        .sidebar-link.active {
            box-shadow: none !important;
            outline: none !important;
        }

        @media (max-width: 760px) {
            .form-card {
                padding: 18px;
            }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="page-heading mb-4">
    <h1 class="h3 mb-1">Add Resident</h1>
    <p class="mb-0">Enter the resident's details below.</p>
</div>

<div class="form-card">


    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="row g-3">

            <div class="col-md-4">
                <label class="form-label" for="resident_id">Resident ID *</label>
                <input
                    class="form-control"
                    id="resident_id"
                    name="resident_id"
                    maxlength="50"
                    required
                    value="<?= e($values['resident_id']) ?>"
                >
            </div>

            <div class="col-md-4">
                <label class="form-label" for="first_name">First Name *</label>
                <input
                    class="form-control"
                    id="first_name"
                    name="first_name"
                    maxlength="100"
                    required
                    value="<?= e($values['first_name']) ?>"
                >
            </div>

            <div class="col-md-4">
                <label class="form-label" for="middle_name">Middle Name</label>
                <input
                    class="form-control"
                    id="middle_name"
                    name="middle_name"
                    maxlength="100"
                    value="<?= e($values['middle_name']) ?>"
                >
            </div>

            <div class="col-md-4">
                <label class="form-label" for="last_name">Last Name *</label>
                <input
                    class="form-control"
                    id="last_name"
                    name="last_name"
                    maxlength="100"
                    required
                    value="<?= e($values['last_name']) ?>"
                >
            </div>

            <div class="col-md-4">
                <label class="form-label" for="date_of_birth">Date of Birth</label>
                <input
                    type="date"
                    class="form-control"
                    id="date_of_birth"
                    name="date_of_birth"
                    max="<?= date('Y-m-d') ?>"
                    value="<?= e($values['date_of_birth']) ?>"
                >
            </div>

            <div class="col-md-4">
                <label class="form-label" for="gender">Gender</label>
                <select class="form-select" id="gender" name="gender">
                    <option value="">Select gender</option>
                    <?php foreach (['Male', 'Female', 'Other'] as $option): ?>
                        <option
                            value="<?= e($option) ?>"
                            <?= $values['gender'] === $option ? 'selected' : '' ?>
                        >
                            <?= e($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="contact_number">Contact Number</label>
                <input
                    class="form-control"
                    id="contact_number"
                    name="contact_number"
                    maxlength="30"
                    value="<?= e($values['contact_number']) ?>"
                >
            </div>

            <div class="col-md-6">
                <label class="form-label" for="room_number">Room</label>
                <select class="form-select" id="room_number" name="room_number">
                    <option value="">Not assigned yet</option>

                    <?php foreach ($availableRooms as $room): ?>
                        <?php $roomNo = (string)$room['room_number']; ?>
                        <option
                            value="<?= e($roomNo) ?>"
                            <?= $values['room_number'] === $roomNo ? 'selected' : '' ?>
                        >
                            Room <?= e($roomNo) ?>
                            &mdash; <?= e($room['room_type'] ?: 'Standard') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if (!$availableRooms): ?>
                    <div class="form-text text-danger">
                        No rooms are available right now.
                        <a href="rooms.php">Add or free up a room</a>
                        in Room Management.
                    </div>
                <?php else: ?>
                    <div class="form-text">
                        Only available rooms are listed. The room shows as Occupied
                        in Room Management once it is assigned.
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-12">
                <label class="form-label" for="address">Address</label>
                <textarea
                    class="form-control"
                    id="address"
                    name="address"
                    rows="2"
                ><?= e($values['address']) ?></textarea>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="admission_date">Admission Date</label>
                <input
                    type="date"
                    class="form-control"
                    id="admission_date"
                    name="admission_date"
                    max="<?= date('Y-m-d') ?>"
                    value="<?= e($values['admission_date']) ?>"
                >
            </div>

            <div class="col-md-6">
                <label class="form-label" for="care_level">Care Level</label>
                <select class="form-select" id="care_level" name="care_level">
                    <option value="">Select care level</option>
                    <?php foreach (['Low', 'Moderate', 'High'] as $option): ?>
                        <option
                            value="<?= e($option) ?>"
                            <?= $values['care_level'] === $option ? 'selected' : '' ?>
                        >
                            <?= e($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="assigned_caretaker">
                    Assigned Caretaker
                </label>
                <select
                    class="form-select"
                    id="assigned_caretaker"
                    name="assigned_caretaker"
                >
                    <option value="">No caretaker assigned</option>

                    <?php foreach ($caretakers as $caretaker): ?>
                        <option
                            value="<?= e($caretaker['id']) ?>"
                            <?= (string)$values['assigned_caretaker'] ===
                                (string)$caretaker['id'] ? 'selected' : '' ?>
                        >
                            <?= e($caretaker['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status" required>
                    <?php
                    $statuses = [
                        'new' => 'New',
                        'active' => 'Active',
                        'needs_attention' => 'Needs Attention',
                        'discharged' => 'Discharged',
                        'archived' => 'Archived'
                    ];
                    ?>

                    <?php foreach ($statuses as $value => $label): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $values['status'] === $value ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-success">
                Save Resident
            </button>

            <a href="residents.php" class="btn btn-outline-secondary">
                Cancel
            </a>
        </div>
    </form>
</div>

<!-- sidebar.php already opened <main class="app-main">, close it here -->
</main>
</body>
</html>
