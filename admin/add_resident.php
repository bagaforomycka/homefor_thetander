
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

const RESIDENT_CODE_LOCK = 'homeforthe_aged_resident_code';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function nextResidentCode(PDO $pdo): string
{
    $query = $pdo->query("
        SELECT COALESCE(
            MAX(CAST(SUBSTRING(resident_id, 5) AS UNSIGNED)),
            0
        ) + 1
        FROM residents
        WHERE resident_id REGEXP '^RES-[0-9]+$'
    ");

    $nextId = (int)$query->fetchColumn();

    return 'RES-' . str_pad((string)$nextId, 4, '0', STR_PAD_LEFT);
}

$errors = [];
$pictureFile = null;
$uploadedPicturePath = null;
$residentCodeLockAcquired = false;
$existingResident = null;

$editId = 0;

if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $editId = (int)$_GET['edit'];
} elseif (
    isset($_POST['edit_id']) &&
    ctype_digit((string)$_POST['edit_id'])
) {
    $editId = (int)$_POST['edit_id'];
}

$isEdit = $editId > 0;

$releaseLock = function () use ($pdo, &$residentCodeLockAcquired): void {
    if ($residentCodeLockAcquired) {
        try {
            $pdo->query(
                "SELECT RELEASE_LOCK('" . RESIDENT_CODE_LOCK . "')"
            );
        } catch (Throwable $e) {
            error_log('Unable to release resident lock: ' . $e->getMessage());
        }

        $residentCodeLockAcquired = false;
    }
};

$values = [
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

$existingPicture = null;
$existingRoom = '';

if ($isEdit) {
    try {
        $residentQuery = $pdo->prepare("
            SELECT *
            FROM residents
            WHERE id = :id
            LIMIT 1
        ");

        $residentQuery->execute(['id' => $editId]);

        $existingResident = $residentQuery->fetch(PDO::FETCH_ASSOC);

        if (!$existingResident) {
            $errors[] = 'The resident you are trying to edit could not be found.';
        } else {
            foreach ($values as $key => $default) {
                if (array_key_exists($key, $existingResident)) {
                    $values[$key] = (string)($existingResident[$key] ?? '');
                }
            }

            $existingPicture = $existingResident['profile_picture'] ?? null;
            $existingRoom = (string)($existingResident['room_number'] ?? '');
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $errors[] = 'Unable to load the resident information.';
    }
}

$caretakers = [];

try {
    $caretakerQuery = $pdo->prepare("
        SELECT id, full_name
        FROM users
        WHERE role = 'caretaker'
        ORDER BY full_name
    ");

    $caretakerQuery->execute();
    $caretakers = $caretakerQuery->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
    $errors[] = 'Unable to load caretakers. Check your users table columns.';
}

$availableRooms = [];

try {
    $availableRooms = fetchAvailableRooms($pdo);

} catch (Throwable $e) {
    error_log('Unable to load rooms: ' . $e->getMessage());
    $errors[] = 'Unable to load rooms. Check the rooms table.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($isEdit && !$existingResident) {
        $errors[] = 'The resident could not be loaded for editing.';
    }

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
        $values['date_of_birth'] !== '' &&
        $values['date_of_birth'] < '1900-01-01'
    ) {
        $errors[] = 'Please enter a realistic date of birth.';
    }

    if (
        $values['admission_date'] !== '' &&
        $values['admission_date'] > date('Y-m-d')
    ) {
        $errors[] = 'Admission date cannot be in the future.';
    }

    $availableRoomNumbers = array_map(
        'strval',
        array_column($availableRooms, 'room_number')
    );

    $isKeepingCurrentRoom = (
        $isEdit &&
        $values['room_number'] !== '' &&
        $values['room_number'] === $existingRoom
    );

    if (
        $values['room_number'] !== '' &&
        !$isKeepingCurrentRoom &&
        !in_array($values['room_number'], $availableRoomNumbers, true)
    ) {
        $errors[] = 'Please select a room that is available.';
    }

    $caretakerId = null;

    if ($values['assigned_caretaker'] !== '') {
        $validCaretakerIds = array_map(
            'intval',
            array_column($caretakers, 'id')
        );

        if (
            !ctype_digit($values['assigned_caretaker']) ||
            !in_array(
                (int)$values['assigned_caretaker'],
                $validCaretakerIds,
                true
            )
        ) {
            $errors[] = 'Please select a valid caretaker.';
        } else {
            $caretakerId = (int)$values['assigned_caretaker'];
        }
    }

    if (
        isset($_FILES['profile_picture']) &&
        $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        $file = $_FILES['profile_picture'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'There was a problem uploading the profile picture.';

        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Profile picture must not exceed 5 MB.';

        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'Invalid profile picture upload.';

        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);

            $allowedImageTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'image/bmp' => 'bmp',
                'image/x-ms-bmp' => 'bmp'
            ];

            $imageInfo = @getimagesize($file['tmp_name']);

            if (
                !isset($allowedImageTypes[$mimeType]) ||
                $imageInfo === false
            ) {
                $errors[] = 'Invalid image. Please upload JPG, JPEG, PNG, WEBP, GIF, or BMP.';

            } else {
                $pictureFile = [
                    'tmp_name' => $file['tmp_name'],
                    'extension' => $allowedImageTypes[$mimeType]
                ];
            }
        }
    }

    if (!$errors) {

        try {
            if (!$isEdit) {
                $lockResult = (int)$pdo->query(
                    "SELECT GET_LOCK('" . RESIDENT_CODE_LOCK . "', 10)"
                )->fetchColumn();

                if ($lockResult !== 1) {
                    throw new RuntimeException(
                        'Unable to generate a Resident ID. Please try again.'
                    );
                }

                $residentCodeLockAcquired = true;
            }

            $pdo->beginTransaction();

            if (
                $values['room_number'] !== '' &&
                !$isKeepingCurrentRoom
            ) {
                $lockSql = "
                    SELECT id, status
                    FROM rooms
                    WHERE room_number = :room_number
                ";

                if (
                    in_array(
                        'archived_at',
                        roomHelperColumns($pdo, 'rooms'),
                        true
                    )
                ) {
                    $lockSql .= " AND archived_at IS NULL";
                }

                $lock = $pdo->prepare($lockSql . " FOR UPDATE");

                $lock->execute([
                    'room_number' => $values['room_number']
                ]);

                $room = $lock->fetch(PDO::FETCH_ASSOC);

                if (
                    !$room ||
                    $room['status'] !== 'Available' ||
                    countRoomOccupants($pdo, $values['room_number']) > 0
                ) {
                    throw new DomainException(
                        'That room is no longer available. Please choose another room.'
                    );
                }
            }

            $pictureName = $existingPicture;

            if ($pictureFile !== null) {
                $uploadDirectory = __DIR__ . '/uploads/residentspic/';

                if (
                    !is_dir($uploadDirectory) &&
                    !mkdir($uploadDirectory, 0755, true) &&
                    !is_dir($uploadDirectory)
                ) {
                    throw new RuntimeException(
                        'Unable to create the resident picture folder.'
                    );
                }

                $pictureName = bin2hex(random_bytes(16))
                    . '.'
                    . $pictureFile['extension'];

                $uploadedPicturePath = $uploadDirectory . $pictureName;

                if (
                    !move_uploaded_file(
                        $pictureFile['tmp_name'],
                        $uploadedPicturePath
                    )
                ) {
                    $uploadedPicturePath = null;

                    throw new RuntimeException(
                        'Unable to save the resident profile picture.'
                    );
                }
            }

            $residentData = [
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
                'status' => $values['status'],
                'profile_picture' => $pictureName
            ];

            if ($isEdit) {

                $oldRoom = $existingRoom;

                $stmt = $pdo->prepare("
                    UPDATE residents SET
                        first_name = :first_name,
                        middle_name = :middle_name,
                        last_name = :last_name,
                        date_of_birth = :date_of_birth,
                        gender = :gender,
                        contact_number = :contact_number,
                        address = :address,
                        room_number = :room_number,
                        admission_date = :admission_date,
                        care_level = :care_level,
                        assigned_caretaker = :assigned_caretaker,
                        status = :status,
                        profile_picture = :profile_picture
                    WHERE id = :id
                ");

                $stmt->execute(array_merge(
                    $residentData,
                    ['id' => $editId]
                ));

                if (
                    $oldRoom !== '' &&
                    $oldRoom !== $values['room_number']
                ) {
                    syncRoomStatus($pdo, $oldRoom);
                }

                if ($values['room_number'] !== '') {
                    syncRoomStatus($pdo, $values['room_number']);
                }

                $pdo->commit();

                $releaseLock();

                if (
                    $pictureFile !== null &&
                    !empty($existingPicture)
                ) {
                    $oldPicturePath = __DIR__
                        . '/uploads/residentspic/'
                        . basename($existingPicture);

                    if (
                        is_file($oldPicturePath) &&
                        realpath($oldPicturePath) !==
                            realpath($uploadedPicturePath)
                    ) {
                        unlink($oldPicturePath);
                    }
                }

                header('Location: residents.php?updated=1');
                exit;

            } else {

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

                $stmt->execute(array_merge(
                    [
                        'resident_id' => nextResidentCode($pdo)
                    ],
                    $residentData
                ));

                if ($values['room_number'] !== '') {
                    syncRoomStatus($pdo, $values['room_number']);
                }

                $pdo->commit();

                $releaseLock();

                header('Location: residents.php?added=1');
                exit;
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $releaseLock();

            if (
                $uploadedPicturePath !== null &&
                is_file($uploadedPicturePath)
            ) {
                unlink($uploadedPicturePath);
            }

            if ($e instanceof DomainException) {
                $errors[] = $e->getMessage();
            } else {
                error_log($e->getMessage());

                $errors[] = 'The resident could not be saved. Check the server error log for the database error.';
            }
        }
    }
}

try {
    if ($isEdit && $existingResident) {
        $displayResidentId = $existingResident['resident_id'];
    } else {
        $displayResidentId = nextResidentCode($pdo);
    }

} catch (Throwable $e) {
    error_log($e->getMessage());
    $displayResidentId = 'RES-----';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        <?= $isEdit ? 'Edit Resident' : 'Add Resident' ?> | Home for the Aged
    </title>

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

        .image-preview {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid #dee2e6;
            display: none;
            margin-top: 12px;
        }

        .current-picture {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid #dee2e6;
            margin-top: 12px;
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
    <h1 class="h3 mb-1">
        <?= $isEdit ? 'Edit Resident' : 'Add Resident' ?>
    </h1>

    <p class="mb-0">
        <?= $isEdit
            ? 'Update the resident information below.'
            : "Enter the resident's details below." ?>
    </p>
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

    <?php if (!$isEdit || $existingResident): ?>

    <form
        method="POST"
        action="<?= $isEdit ? 'add_resident.php?edit=' . $editId : 'add_resident.php' ?>"
        enctype="multipart/form-data"
    >

        <?php if ($isEdit): ?>
            <input
                type="hidden"
                name="edit_id"
                value="<?= (int)$editId ?>"
            >
        <?php endif; ?>

        <div class="row g-3">

            <div class="col-12">

                <label class="form-label" for="profile_picture">
                    Resident Profile Picture
                </label>

                <input
                    type="file"
                    class="form-control"
                    id="profile_picture"
                    name="profile_picture"
                    accept=".jpg,.jpeg,.png,.webp,.gif,.bmp,image/*"
                >

                <div class="form-text">
                    Accepted formats: JPG, JPEG, PNG, WEBP, GIF, and BMP.
                    Maximum file size: 5 MB.
                    <?= $isEdit ? 'Leave this empty to keep the current picture.' : '' ?>
                </div>

                <?php if ($isEdit && !empty($existingPicture)): ?>
                    <?php
                    $currentPicturePath = 'uploads/residentspic/'
                        . basename($existingPicture);
                    ?>

                    <div>
                        <p class="form-text mb-1">Current profile picture:</p>

                        <img
                            src="<?= e($currentPicturePath) ?>"
                            class="current-picture"
                            alt="Current resident profile picture"
                        >
                    </div>
                <?php endif; ?>

                <img
                    id="imagePreview"
                    class="image-preview"
                    alt="New resident profile picture preview"
                >

            </div>

            <div class="col-md-4">

                <label class="form-label" for="resident_id">
                    Resident ID
                </label>

                <input
                    type="text"
                    class="form-control fw-bold text-success"
                    id="resident_id"
                    value="<?= e($displayResidentId) ?>"
                    readonly
                >

                <div class="form-text">
                    <?= $isEdit
                        ? 'Resident ID cannot be changed.'
                        : 'Generated automatically when you save.' ?>
                </div>

            </div>

            <div class="col-md-4">

                <label class="form-label" for="first_name">
                    First Name *
                </label>

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

                <label class="form-label" for="middle_name">
                    Middle Name
                </label>

                <input
                    class="form-control"
                    id="middle_name"
                    name="middle_name"
                    maxlength="100"
                    value="<?= e($values['middle_name']) ?>"
                >

            </div>

            <div class="col-md-4">

                <label class="form-label" for="last_name">
                    Last Name *
                </label>

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

                <label class="form-label" for="date_of_birth">
                    Date of Birth
                </label>

                <input
                    type="date"
                    class="form-control"
                    id="date_of_birth"
                    name="date_of_birth"
                    min="1900-01-01"
                    max="<?= date('Y-m-d') ?>"
                    value="<?= e($values['date_of_birth']) ?>"
                >

            </div>

            <div class="col-md-4">

                <label class="form-label" for="gender">
                    Gender
                </label>

                <select
                    class="form-select"
                    id="gender"
                    name="gender"
                >

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

                <label class="form-label" for="contact_number">
                    Contact Number
                </label>

                <input
                    class="form-control"
                    id="contact_number"
                    name="contact_number"
                    maxlength="30"
                    value="<?= e($values['contact_number']) ?>"
                >

            </div>

            <div class="col-md-6">

                <label class="form-label" for="room_number">
                    Room
                </label>

                <select
                    class="form-select"
                    id="room_number"
                    name="room_number"
                >

                    <option value="">Not assigned yet</option>

                    <?php
                    $currentRoomIsListed = false;

                    foreach ($availableRooms as $room):
                        $roomNo = (string)$room['room_number'];

                        if ($roomNo === $existingRoom) {
                            $currentRoomIsListed = true;
                        }

                        $roomLabel = stripos($roomNo, 'room') === 0
                            ? $roomNo
                            : 'Room ' . $roomNo;
                    ?>

                        <option
                            value="<?= e($roomNo) ?>"
                            <?= $values['room_number'] === $roomNo ? 'selected' : '' ?>
                        >
                            <?= e($roomLabel) ?>
                            &mdash; <?= e($room['room_type'] ?: 'Standard') ?>
                        </option>

                    <?php endforeach; ?>

                    <?php if (
                        $isEdit &&
                        $existingRoom !== '' &&
                        !$currentRoomIsListed
                    ): ?>

                        <option
                            value="<?= e($existingRoom) ?>"
                            <?= $values['room_number'] === $existingRoom ? 'selected' : '' ?>
                        >
                            <?= e(
                                stripos($existingRoom, 'room') === 0
                                    ? $existingRoom
                                    : 'Room ' . $existingRoom
                            ) ?>
                            — Currently Assigned
                        </option>

                    <?php endif; ?>

                </select>

                <?php if (!$availableRooms && !$isEdit): ?>

                    <div class="form-text text-danger">
                        No rooms are available right now.
                        <a href="rooms.php">Add or free up a room</a>
                        in Room Management.
                    </div>

                <?php else: ?>

                    <div class="form-text">
                        Your resident's currently assigned room remains selectable
                        when editing.
                    </div>

                <?php endif; ?>

            </div>

            <div class="col-12">

                <label class="form-label" for="address">
                    Address
                </label>

                <textarea
                    class="form-control"
                    id="address"
                    name="address"
                    rows="2"
                ><?= e($values['address']) ?></textarea>

            </div>

            <div class="col-md-6">

                <label class="form-label" for="admission_date">
                    Admission Date
                </label>

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

                <label class="form-label" for="care_level">
                    Care Level
                </label>

                <select
                    class="form-select"
                    id="care_level"
                    name="care_level"
                >

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

                <label class="form-label" for="status">
                    Status
                </label>

                <?php
                $statuses = [
                    'new' => 'New',
                    'active' => 'Active',
                    'needs_attention' => 'Needs Attention',
                    'discharged' => 'Discharged',
                    'archived' => 'Archived'
                ];
                ?>

                <select
                    class="form-select"
                    id="status"
                    name="status"
                    required
                >

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
                <?= $isEdit ? 'Update Resident' : 'Save Resident' ?>
            </button>

            <a href="residents.php" class="btn btn-outline-secondary">
                Cancel
            </a>

        </div>

    </form>

    <?php endif; ?>

</div>

<script>
document.getElementById('profile_picture').addEventListener('change', function () {
    const preview = document.getElementById('imagePreview');
    const file = this.files[0];

    if (file) {
        preview.src = URL.createObjectURL(file);
        preview.style.display = 'block';
    } else {
        preview.removeAttribute('src');
        preview.style.display = 'none';
    }
});
</script>

</main>
</body>
</html>
