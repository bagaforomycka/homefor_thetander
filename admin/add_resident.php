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

$error = '';

$uploadDirectory = __DIR__ . '/uploads/residentspic/';

if (!is_dir($uploadDirectory)) {
    if (!mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        $error = 'Unable to create the resident picture upload folder.';
    }
}

// Load caretaker accounts.
$caretakers = [];

if ($error === '') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, full_name
            FROM users
            WHERE LOWER(role) = 'caretaker'
            ORDER BY full_name ASC
        ");

        $stmt->execute();
        $caretakers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $ex) {
        error_log('Caretaker loading error: ' . $ex->getMessage());
        $error = 'Unable to load caretaker accounts.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $residentId = trim($_POST['resident_id'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $dateOfBirth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $roomNumber = trim($_POST['room_number'] ?? '');
    $admissionDate = trim($_POST['admission_date'] ?? '');
    $careLevel = trim($_POST['care_level'] ?? '');
    $status = trim($_POST['status'] ?? 'new');

    $caretakerInput = trim($_POST['assigned_caretaker'] ?? '');

    $caretakerId = $caretakerInput === ''
        ? null
        : filter_var($caretakerInput, FILTER_VALIDATE_INT);

    $profilePicture = null;
    $uploadedFilePath = null;

    $allowedStatuses = [
        'active',
        'needs_attention',
        'new',
        'discharged',
        'archived'
    ];

    $allowedCareLevels = [
        'Low',
        'Moderate',
        'High'
    ];

    $allowedGenders = [
        'Male',
        'Female',
        'Other'
    ];

    // Validate required information.
    if ($residentId === '' || $firstName === '' || $lastName === '') {
        $error = 'Please complete all required resident information.';
    } elseif (!in_array($status, $allowedStatuses, true)) {
        $error = 'Please select a valid resident status.';
    } elseif ($careLevel !== '' && !in_array($careLevel, $allowedCareLevels, true)) {
        $error = 'Please select a valid care level.';
    } elseif ($gender !== '' && !in_array($gender, $allowedGenders, true)) {
        $error = 'Please select a valid gender.';
    } elseif ($caretakerInput !== '' && $caretakerId === false) {
        $error = 'Please select a valid caretaker.';
    }

    // Validate selected caretaker.
    if ($error === '' && $caretakerId !== null) {

        $validCaretakerIds = array_map(
            'intval',
            array_column($caretakers, 'id')
        );

        if (!in_array((int)$caretakerId, $validCaretakerIds, true)) {
            $error = 'The selected caretaker account does not exist.';
        }
    }

    // Validate dates.
    if ($error === '' && $dateOfBirth !== '') {

        $date = DateTime::createFromFormat('Y-m-d', $dateOfBirth);

        if (!$date || $date->format('Y-m-d') !== $dateOfBirth) {
            $error = 'Please enter a valid date of birth.';
        }
    }

    if ($error === '' && $admissionDate !== '') {

        $date = DateTime::createFromFormat('Y-m-d', $admissionDate);

        if (!$date || $date->format('Y-m-d') !== $admissionDate) {
            $error = 'Please enter a valid admission date.';
        }
    }

    // Require a profile picture.
    if (
        $error === '' &&
        (
            !isset($_FILES['profile_picture']) ||
            $_FILES['profile_picture']['error'] === UPLOAD_ERR_NO_FILE
        )
    ) {
        $error = 'Please upload a resident profile picture.';
    }

    // Upload and validate profile picture.
    if ($error === '' && isset($_FILES['profile_picture'])) {

        $file = $_FILES['profile_picture'];

        if ($file['error'] !== UPLOAD_ERR_OK) {

            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'The picture exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'The picture exceeds the form upload limit.',
                UPLOAD_ERR_PARTIAL => 'The picture was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server temporary upload folder is missing.',
                UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded picture.',
                UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.'
            ];

            $error = $uploadErrors[$file['error']] ?? 'An unknown upload error occurred.';

        } elseif ($file['size'] > 5 * 1024 * 1024) {

            $error = 'The profile picture must not exceed 5 MB.';

        } elseif (!is_uploaded_file($file['tmp_name'])) {

            $error = 'The uploaded file could not be verified.';

        } else {

            $imageInfo = getimagesize($file['tmp_name']);

            if ($imageInfo === false) {

                $error = 'Please select a valid image file.';

            } else {

                $allowedMimeTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp'
                ];

                $mimeType = $imageInfo['mime'] ?? '';

                if (!isset($allowedMimeTypes[$mimeType])) {

                    $error = 'Only JPG, PNG, and WEBP images are allowed.';

                } else {

                    $extension = $allowedMimeTypes[$mimeType];

                    $profilePicture = 'resident_' .
                        bin2hex(random_bytes(12)) . '.' . $extension;

                    $uploadedFilePath = $uploadDirectory . $profilePicture;

                    if (!move_uploaded_file($file['tmp_name'], $uploadedFilePath)) {

                        $error = 'Unable to save the profile picture. Check the upload folder permissions.';

                        $profilePicture = null;
                        $uploadedFilePath = null;
                    }
                }
            }
        }
    }

    // Insert resident information into database.
    if ($error === '') {

        try {

            $stmt = $pdo->prepare("
                INSERT INTO residents (
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
                    :status,
                    :profile_picture
                )
            ");

            $stmt->execute([
                'resident_id' => $residentId,
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
                'date_of_birth' => $dateOfBirth !== '' ? $dateOfBirth : null,
                'gender' => $gender !== '' ? $gender : null,
                'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                'address' => $address !== '' ? $address : null,
                'room_number' => $roomNumber !== '' ? $roomNumber : null,
                'admission_date' => $admissionDate !== '' ? $admissionDate : null,
                'care_level' => $careLevel !== '' ? $careLevel : null,
                'assigned_caretaker' => $caretakerId !== null ? (int)$caretakerId : null,
                'status' => $status,
                'profile_picture' => $profilePicture
            ]);

            header('Location: residents.php?added=1');
            exit;

        } catch (PDOException $ex) {

            // Remove the uploaded image if the database insert fails.
            if ($uploadedFilePath && is_file($uploadedFilePath)) {
                unlink($uploadedFilePath);
            }

            error_log('Add Resident error: ' . $ex->getMessage());

            if ($ex->getCode() === '23000') {
                $error = 'This Resident ID may already exist. Please use a different ID.';
            } else {
                $error = 'Unable to save the resident. Please check the database table structure and PHP error log.';
            }
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
        }

        .page-header {
            background: #304f3e;
            color: #fff;
            padding: 28px;
            border-radius: 0 0 15px 15px;
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 30px;
            margin-bottom: 8px;
        }

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
        }

        .form-label {
            font-weight: 600;
            color: #303a33;
            margin-bottom: 8px;
        }

        .form-control,
        .form-select {
            min-height: 45px;
            border-color: #dce5de;
        }

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
            }
        }
    </style>
</head>

<body>

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

</div>

</main>

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

<script>
const pictureInput = document.getElementById('profile_picture');
const picturePreview = document.getElementById('picturePreview');

pictureInput.addEventListener('change', function () {

    const file = this.files[0];

    if (!file) {
        picturePreview.src = '../images/default-profile.png';
        return;
    }

    if (!file.type.startsWith('image/')) {
        alert('Please select an image file.');
        this.value = '';
        picturePreview.src = '../images/default-profile.png';
        return;
    }

    picturePreview.src = URL.createObjectURL(file);
});
</script>

</body>
</html>