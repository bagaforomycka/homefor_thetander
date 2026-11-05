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
<<<<<<< Updated upstream
            background: #f3f7f4;
            font-family: Arial, sans-serif;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
            margin: 0;
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

        .add-resident-page {
            width: 100% !important;
            max-width: none !important;
            min-width: 0;
            min-height: 100vh;
            flex-grow: 1;
            box-shadow: none !important;
            overflow-x: hidden;
            transition: margin-left 0.3s ease;
=======
            background: #f5f8f6;
            color: #24352b;
            font-family: Arial, Helvetica, sans-serif;
            overflow-x: hidden;
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
>>>>>>> Stashed changes
        }

        .app-main {
            margin-left: 250px !important;
            width: calc(100% - 250px) !important;
            max-width: none !important;
            min-width: 0;
            min-height: 100vh;
            padding: 28px !important;
            box-shadow: none !important;
            overflow-x: hidden;
        }

        .page-heading {
            background: #304b3b;
            color: white;
            border-radius: 14px;
            padding: 22px;
        }

<<<<<<< Updated upstream
        .page-header p {
            margin: 0;
        }

        .form-container {
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            margin: 0 24px 30px 24px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, .04);
        }

        .section-title {
            font-size: 21px;
            color: #183f29;
            font-weight: 700;
            margin-bottom: 20px;
=======
        .form-card {
            background: white;
            padding: 26px;
            border-radius: 14px;
            box-shadow: 0 3px 14px rgba(0, 0, 0, .06);
>>>>>>> Stashed changes
        }

        .form-label {
            font-weight: 600;
        }

        .sidebar-link.active {
            box-shadow: none !important;
            outline: none !important;
        }

<<<<<<< Updated upstream
        .form-control:focus,
        .form-select:focus {
            border-color: #43845b;
            box-shadow: 0 0 0 .2rem rgba(67, 132, 91, .12);
        }

        textarea.form-control {
            min-height: 100px;
        }

        .picture-section {
            background: #f7faf8;
            border: 1px solid #e1ebe4;
            border-radius: 13px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .picture-preview {
            width: 140px;
            height: 140px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #dce9df;
            background: #e6f0e8;
            display: block;
            margin-bottom: 20px;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid #e8eee9;
            padding-top: 25px;
            margin-top: 30px;
        }

        .btn-save {
            background: #17683a;
            color: #fff;
            border: none;
            padding: 11px 25px;
            border-radius: 7px;
        }

        .btn-save:hover {
            background: #0b5425;
            color: #fff;
        }

        .btn-cancel {
            padding: 11px 25px;
            border-radius: 7px;
        }

        @media (max-width: 576px) {
            .form-container {
                padding: 20px;
                margin: 0 12px 20px 12px;
            }

            .page-header {
                padding: 22px;
=======
        @media (max-width: 760px) {
            .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 18px 12px !important;
>>>>>>> Stashed changes
            }
        }
    </style>
</head>
<body>

<<<<<<< Updated upstream
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="add-resident-page">

    <div class="page-header">
        <h1>Add Resident</h1>
        <p>Enter the resident's information and upload a profile picture[cite: 15].</p>
    </div>

    <div class="form-container">

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <h4 class="section-title">Resident Profile Picture</h4>

            <div class="picture-section">

                <img
                    src="../images/default-profile.png"
                    alt="Profile picture preview"
                    class="picture-preview"
                    id="picturePreview"
                >

                <label for="profile_picture" class="form-label">
                    Upload Profile Picture *
                </label>

                <input
                    type="file"
                    name="profile_picture"
                    id="profile_picture"
                    class="form-control"
                    accept="image/jpeg,image/png,image/webp"
                    required
                >

                <small class="text-secondary">
                    JPG, PNG, or WEBP. Maximum file size: 5 MB[cite: 15].
                </small>

            </div>

            <h4 class="section-title">Personal Information</h4>

            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label">Resident ID *</label>
                    <input
                        type="text"
                        name="resident_id"
                        class="form-control"
                        value="<?= e($_POST['resident_id'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">First Name *</label>
                    <input
                        type="text"
                        name="first_name"
                        class="form-control"
                        value="<?= e($_POST['first_name'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Middle Name</label>
                    <input
                        type="text"
                        name="middle_name"
                        class="form-control"
                        value="<?= e($_POST['middle_name'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Last Name *</label>
                    <input
                        type="text"
                        name="last_name"
                        class="form-control"
                        value="<?= e($_POST['last_name'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Date of Birth</label>
                    <input
                        type="date"
                        name="date_of_birth"
                        class="form-control"
                        value="<?= e($_POST['date_of_birth'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">Select Gender</option>
                        <option value="Male" <?= ($_POST['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= ($_POST['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                        <option value="Other" <?= ($_POST['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Contact Number</label>
                    <input
                        type="text"
                        name="contact_number"
                        class="form-control"
                        value="<?= e($_POST['contact_number'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Room Number</label>
                    <input
                        type="text"
                        name="room_number"
                        class="form-control"
                        value="<?= e($_POST['room_number'] ?? '') ?>"
                    >
                </div>

                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control"><?= e($_POST['address'] ?? '') ?></textarea>
                </div>

            </div>

            <h4 class="section-title mt-4">Care Information</h4>

            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label">Admission Date</label>
                    <input
                        type="date"
                        name="admission_date"
                        class="form-control"
                        value="<?= e($_POST['admission_date'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Care Level</label>
                    <select name="care_level" class="form-select">
                        <option value="">Select Care Level</option>
                        <option value="Low" <?= ($_POST['care_level'] ?? '') === 'Low' ? 'selected' : '' ?>>Low</option>
                        <option value="Moderate" <?= ($_POST['care_level'] ?? '') === 'Moderate' ? 'selected' : '' ?>>Moderate</option>
                        <option value="High" <?= ($_POST['care_level'] ?? '') === 'High' ? 'selected' : '' ?>>High</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Assigned Caretaker</label>
                    <select name="assigned_caretaker" class="form-select">
                        <option value="">Not Assigned</option>

                        <?php foreach ($caretakers as $caretaker): ?>
                            <option
                                value="<?= (int)$caretaker['id'] ?>"
                                <?= (string)($_POST['assigned_caretaker'] ?? '') === (string)$caretaker['id'] ? 'selected' : '' ?>
                            >
                                <?= e($caretaker['full_name']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Resident Status</label>
                    <select name="status" class="form-select" required>
                        <?php
                        $statuses = [
                            'new' => 'New',
                            'active' => 'Active',
                            'needs_attention' => 'Needs Attention',
                            'discharged' => 'Discharged',
                            'archived' => 'Archived'
                        ];

                        $selectedStatus = $_POST['status'] ?? 'new';
                        ?>

                        <?php foreach ($statuses as $value => $label): ?>
                            <option
                                value="<?= e($value) ?>"
                                <?= $selectedStatus === $value ? 'selected' : '' ?>
                            >
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

            </div>

            <div class="form-actions">

                <a href="residents.php" class="btn btn-outline-secondary btn-cancel">
                    Cancel
                </a>

                <button type="submit" class="btn btn-save">
                    Save Resident
                </button>

            </div>

        </form>

    </div>
=======
<?php require __DIR__ . '/../includes/sidebar.php'; ?>
>>>>>>> Stashed changes

<div class="page-heading mb-4">
    <h1 class="h3 mb-1">Add Resident</h1>
    <p class="mb-0">Enter the resident's details below.</p>
</div>

<div class="form-card">

<<<<<<< Updated upstream
<!-- Hamburger Toggle & Sidebar Behavior Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('sidebarOverlay');
    var sidebar = document.querySelector(
        '.app-sidebar, .sidebar, #sidebar, .sidebar-container, aside, nav'
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
            '<a href="/homefor_thetander/admin/dashboard.php">' +
            '<img src="/homefor_thetander/images/white1.png" ' +
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

=======
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

<!-- Puts the logo at the top of the sidebar and hides the old text title -->
>>>>>>> Stashed changes
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector(
        '.app-sidebar, .sidebar, #sidebar, .sidebar-container, aside, nav'
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
            '<a href="/homefor_thetander/admin/dashboard.php">' +
            '<img src="/homefor_thetander/images/white1.png" ' +
            'alt="Home for the Aged - Care, Comfort, Dignity">' +
            '</a>';

        sidebar.insertBefore(logo, sidebar.firstChild);
    }
});
</script>

</main>
</body>
</html>