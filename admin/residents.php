
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

$isAdmin = $_SESSION['role'] === 'admin';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function residentPicture($picture): string
{
    $picture = trim((string)($picture ?? ''));

    if ($picture === '') {
        return '../images/default-profile.png';
    }

    $filename = basename($picture);
    $physicalPath = __DIR__ . '/uploads/residentspic/' . $filename;

    if (!is_file($physicalPath)) {
        return '../images/default-profile.png';
    }

    return 'uploads/residentspic/' . rawurlencode($filename);
}

if (empty($_SESSION['residents_csrf'])) {
    $_SESSION['residents_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['residents_csrf'];
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$success = $_GET['success'] ?? '';
$error = '';
$residents = [];
$hasArchivedAt = false;

try {
    $columnCheck = $pdo->query("SHOW COLUMNS FROM residents");
    $columns = $columnCheck->fetchAll(PDO::FETCH_COLUMN);
    $hasArchivedAt = in_array('archived_at', $columns, true);
} catch (PDOException $e) {
    error_log('Resident column check error: ' . $e->getMessage());
    $error = 'Unable to check the residents database table.';
}

/* HANDLE PROFILE PICTURE UPLOAD */

if (
    !$error &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'upload_picture'
) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only an administrator can upload resident pictures.');
    }

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) || !hash_equals($csrf, $postedToken)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }

    $residentId = filter_input(INPUT_POST, 'resident_id', FILTER_VALIDATE_INT);

    if (!$residentId || $residentId < 1) {
        $error = 'Invalid resident selected.';
    } elseif (
        !isset($_FILES['profile_picture']) ||
        $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK
    ) {
        $error = 'Please select a picture to upload.';
    } else {
        $file = $_FILES['profile_picture'];

        if ($file['size'] > 5 * 1024 * 1024) {
            $error = 'The picture must not exceed 5 MB.';
        } else {
            $imageInfo = @getimagesize($file['tmp_name']);

            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            $mimeType = $imageInfo['mime'] ?? '';

            if (!$imageInfo || !isset($allowedTypes[$mimeType])) {
                $error = 'Only JPG, PNG, and WEBP images are allowed.';
            } else {
                $uploadDirectory = __DIR__ . '/uploads/residentspic/';

                if (
                    !is_dir($uploadDirectory) &&
                    !mkdir($uploadDirectory, 0755, true)
                ) {
                    $error = 'Unable to create the picture upload folder.';
                } else {
                    $extension = $allowedTypes[$mimeType];
                    $newFilename = 'resident_' . bin2hex(random_bytes(16)) . '.' . $extension;
                    $destination = $uploadDirectory . $newFilename;

                    try {
                        $checkResident = $pdo->prepare("
                            SELECT profile_picture
                            FROM residents
                            WHERE id = :id
                            LIMIT 1
                        ");

                        $checkResident->execute(['id' => $residentId]);
                        $existingResident = $checkResident->fetch(PDO::FETCH_ASSOC);

                        if (!$existingResident) {
                            $error = 'Resident not found.';
                        } elseif (!move_uploaded_file($file['tmp_name'], $destination)) {
                            $error = 'Unable to save the uploaded picture.';
                        } else {
                            try {
                                $updatePicture = $pdo->prepare("
                                    UPDATE residents
                                    SET profile_picture = :picture
                                    WHERE id = :id
                                ");

                                $updatePicture->execute([
                                    'picture' => $newFilename,
                                    'id' => $residentId
                                ]);

                                $oldPicture = basename(
                                    (string)($existingResident['profile_picture'] ?? '')
                                );

                                if (
                                    $oldPicture !== '' &&
                                    $oldPicture !== $newFilename
                                ) {
                                    $oldPath = $uploadDirectory . $oldPicture;

                                    if (is_file($oldPath)) {
                                        @unlink($oldPath);
                                    }
                                }

                                header('Location: residents.php?success=picture');
                                exit;

                            } catch (PDOException $e) {
                                @unlink($destination);
                                throw $e;
                            }
                        }

                    } catch (PDOException $e) {
                        error_log('Profile picture update error: ' . $e->getMessage());
                        $error = 'Unable to update the resident picture. Please try again.';
                    }
                }
            }
        }
    }
}

/* ARCHIVE RESIDENT */

if (
    !$error &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only an administrator can archive residents.');
    }

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) || !hash_equals($csrf, $postedToken)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }

    $residentId = filter_input(INPUT_POST, 'resident_id', FILTER_VALIDATE_INT);

    if (!$residentId || $residentId < 1) {
        $error = 'Invalid resident selected.';
    } else {
        try {
            if ($hasArchivedAt) {
                $stmt = $pdo->prepare("
                    UPDATE residents
                    SET archived_at = NOW(),
                        status = 'archived'
                    WHERE id = :id
                      AND archived_at IS NULL
                ");
            } else {
                $stmt = $pdo->prepare("
                    UPDATE residents
                    SET status = 'archived'
                    WHERE id = :id
                      AND (status IS NULL OR status <> 'archived')
                ");
            }

            $stmt->execute(['id' => $residentId]);

            if ($stmt->rowCount() > 0) {
                header('Location: residents.php?success=deleted');
                exit;
            }

            $error = 'Resident not found or already archived.';

        } catch (PDOException $e) {
            error_log('Resident archive error: ' . $e->getMessage());
            $error = 'Unable to archive this resident. Please try again.';
        }
    }
}

/* LOAD RESIDENTS */

if (!$error) {
    $sql = "
        SELECT
            r.id,
            r.resident_id,
            r.first_name,
            r.middle_name,
            r.last_name,
            r.date_of_birth,
            r.gender,
            r.contact_number,
            r.address,
            r.room_number,
            r.admission_date,
            r.care_level,
            r.assigned_caretaker,
            r.profile_picture,
            r.status,
            u.full_name AS caretaker_name
        FROM residents r
        LEFT JOIN users u
            ON u.id = r.assigned_caretaker
        WHERE 1 = 1
    ";

    $params = [];

    if ($hasArchivedAt) {
        $sql .= " AND r.archived_at IS NULL";
    } elseif ($statusFilter !== 'archived') {
        $sql .= " AND (r.status IS NULL OR r.status <> 'archived')";
    }

    if ($search !== '') {
        $sql .= " AND (
            r.resident_id LIKE :resident_id
            OR r.first_name LIKE :first_name
            OR r.middle_name LIKE :middle_name
            OR r.last_name LIKE :last_name
            OR r.room_number LIKE :room_number
            OR u.full_name LIKE :caretaker
        )";

        $term = '%' . $search . '%';

        $params = [
            'resident_id' => $term,
            'first_name' => $term,
            'middle_name' => $term,
            'last_name' => $term,
            'room_number' => $term,
            'caretaker' => $term
        ];
    }

    if ($statusFilter !== 'all' && $statusFilter !== '') {
        $sql .= " AND LOWER(r.status) = :status";
        $params['status'] = strtolower($statusFilter);
    }

    $sql .= " ORDER BY r.id DESC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log('Resident loading error: ' . $e->getMessage());
        $error = 'Unable to load residents. Please check the database structure.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Resident Management - Home for the Aged</title>

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

        @media (min-width: 993px) {
            body.sidebar-folded .sidebar,
            body.sidebar-folded #sidebar,
            body.sidebar-folded .sidebar-container,
            body.sidebar-folded aside,
            body.sidebar-folded .app-sidebar {
                width: 70px;
                min-width: 70px;
            }

            body.sidebar-folded .sidebar > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded #sidebar > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded .sidebar-container > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded aside > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded .app-sidebar > *:not(.sidebar-toggle-wrapper) {
                display: none !important;
            }

            body.sidebar-folded .sidebar-toggle-wrapper {
                display: flex !important;
                justify-content: center !important;
                padding-left: 0 !important;
                width: 100% !important;
                margin-top: 12px !important;
            }
        }

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
        }

        .residents-header p {
            color: #61796a;
            margin-bottom: 0;
        }

        .residents-panel {
            background: #fff;
            border: 1px solid #e3eee6;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 16px rgba(24,75,42,.05);
        }

        .residents-search-panel {
            background: #f5faf6;
        }

        .resident-card {
            background: #fff;
            border: 1px solid #e0ebe3;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 14px;
        }

        .resident-profile {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 0;
        }

        .resident-profile-picture {
            width: 85px;
            height: 85px;
            min-width: 85px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e0eee4;
            background: #f1f5f2;
        }

        .resident-profile-info {
            min-width: 0;
        }

        .resident-card h3 {
            color: #17683a;
            font-size: 19px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .resident-meta {
            color: #66786c;
            font-size: 13px;
            line-height: 1.8;
        }

        .resident-detail {
            background: #f7faf8;
            border: 1px solid #e5ede7;
            border-radius: 9px;
            padding: 10px;
            min-height: 42px;
            overflow-wrap: anywhere;
        }

        .resident-status {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            background: #e5f5e9;
            color: #17683a;
        }

        .resident-status.needs_attention {
            background: #fff1d6;
            color: #916000;
        }

        .resident-status.discharged,
        .resident-status.archived {
            background: #edf0f2;
            color: #58636b;
        }

        .resident-actions {
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 14px;
        }

        /* VIEW BUTTON - GREEN BORDER */
        .resident-actions a.btn-resident-view {
            color: #17683a !important;
            background-color: #ffffff !important;
            border: 2px solid #198754 !important;
            font-size: 13px;
            padding: 5px 12px;
            border-radius: 6px;
            box-shadow: none !important;
        }

        .resident-actions a.btn-resident-view:hover,
        .resident-actions a.btn-resident-view:focus {
            color: #ffffff !important;
            background-color: #198754 !important;
            border: 2px solid #198754 !important;
        }

        /* EDIT BUTTON - YELLOW BORDER */
        .resident-actions a.btn-resident-edit {
            color: #8a6d00 !important;
            background-color: #ffffff !important;
            border: 2px solid #e0b400 !important;
            font-size: 13px;
            padding: 5px 12px;
            border-radius: 6px;
            box-shadow: none !important;
        }

        .resident-actions a.btn-resident-edit:hover,
        .resident-actions a.btn-resident-edit:focus {
            color: #303030 !important;
            background-color: #ffdf70 !important;
            border: 2px solid #d6a900 !important;
        }

        /* ARCHIVE BUTTON - RED BORDER */
        .resident-actions button.btn-resident-delete {
            color: #b02a37 !important;
            background-color: #ffffff !important;
            border: 2px solid #dc3545 !important;
            font-size: 13px;
            padding: 5px 12px;
            border-radius: 6px;
            box-shadow: none !important;
        }

        .resident-actions button.btn-resident-delete:hover,
        .resident-actions button.btn-resident-delete:focus {
            color: #ffffff !important;
            background-color: #dc3545 !important;
            border: 2px solid #dc3545 !important;
        }

        .btn-picture {
            color: #17683a;
            background: #eaf6ee;
            border: 1px solid #b8d9c2;
        }

        .btn-picture:hover {
            color: #fff;
            background: #17683a;
        }

        .picture-upload-form {
            margin-top: 12px;
            padding: 12px;
            background: #f5faf6;
            border: 1px solid #e0ebe3;
            border-radius: 9px;
        }

        .picture-upload-form input[type="file"] {
            max-width: 100%;
            font-size: 13px;
        }

        @media (max-width: 576px) {
            .residents-header,
            .residents-panel {
                padding: 15px;
            }

            .resident-profile-picture {
                width: 68px;
                height: 68px;
                min-width: 68px;
            }
        }
    </style>
</head>

<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">

    <div class="residents-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1>Resident Management</h1>
            <p>View and manage resident information and room assignments.</p>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <a href="add_resident.php" class="btn btn-success">
                + Add Resident
            </a>
        </div>
    </div>

    <?php if ($success === 'added'): ?>
        <div class="alert alert-success">Resident added successfully.</div>
    <?php elseif ($success === 'updated'): ?>
        <div class="alert alert-success">Resident updated successfully.</div>
    <?php elseif ($success === 'deleted'): ?>
        <div class="alert alert-success">Resident archived successfully.</div>
    <?php elseif ($success === 'picture'): ?>
        <div class="alert alert-success">Resident profile picture uploaded successfully.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="residents-panel residents-search-panel">
        <form method="GET" action="residents.php"
              class="d-flex flex-wrap gap-2 align-items-center">

            <input
                type="text"
                name="search"
                class="form-control flex-grow-1"
                style="min-width:220px"
                placeholder="Search resident, ID, room, or caretaker..."
                value="<?= h($search) ?>"
            >

            <select name="status" class="form-select"
                    style="width:auto;min-width:170px">

                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                    All statuses
                </option>

                <option value="new" <?= $statusFilter === 'new' ? 'selected' : '' ?>>
                    New
                </option>

                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                    Active
                </option>

                <option value="needs_attention" <?= $statusFilter === 'needs_attention' ? 'selected' : '' ?>>
                    Needs Attention
                </option>

                <option value="discharged" <?= $statusFilter === 'discharged' ? 'selected' : '' ?>>
                    Discharged
                </option>

                <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>
                    Archived
                </option>
            </select>

            <button type="submit" class="btn btn-success">
                Search / Filter
            </button>

            <a href="residents.php" class="btn btn-outline-secondary">
                Reset
            </a>
        </form>
    </div>

    <div class="residents-panel">

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h2 class="h5 mb-0">Resident List</h2>
            <span class="text-secondary">
                <?= count($residents) ?> resident(s)
            </span>
        </div>

        <?php if ($error !== ''): ?>

            <div class="alert alert-warning mb-0">
                Residents could not be displayed because of a database error.
            </div>

        <?php elseif (empty($residents)): ?>

            <div class="alert alert-info mb-0">
                No residents found. Add a resident or change your search.
            </div>

        <?php else: ?>

            <?php foreach ($residents as $resident): ?>

                <?php
                $fullName = trim(
                    ($resident['first_name'] ?? '') . ' ' .
                    ($resident['middle_name'] ?? '') . ' ' .
                    ($resident['last_name'] ?? '')
                );

                $fullName = preg_replace('/\s+/', ' ', $fullName);

                $status = strtolower($resident['status'] ?? '');
                $statusLabel = ucwords(str_replace('_', ' ', $status));

                $profilePicture = residentPicture(
                    $resident['profile_picture'] ?? ''
                );

                $hasPicture = !empty($resident['profile_picture']);
                ?>

                <div class="resident-card">

                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">

                        <div class="resident-profile">

                            <img
                                src="<?= h($profilePicture) ?>"
                                alt="<?= h($fullName) ?> profile picture"
                                class="resident-profile-picture"
                                onerror="this.onerror=null;this.src='../images/default-profile.png';"
                            >

                            <div class="resident-profile-info">
                                <h3><?= h($fullName) ?></h3>

                                <div class="resident-meta">
                                    Resident ID: <?= h($resident['resident_id']) ?>
                                    &nbsp;|&nbsp;
                                    Room: <?= h($resident['room_number'] ?: 'Not assigned') ?>
                                    &nbsp;|&nbsp;
                                    Gender: <?= h($resident['gender'] ?: 'Not specified') ?>
                                </div>
                            </div>

                        </div>

                        <span class="resident-status <?= h($status) ?>">
                            <?= h($statusLabel ?: 'Unspecified') ?>
                        </span>

                    </div>

                    <?php if ($isAdmin): ?>
                        <form
                            method="POST"
                            action="residents.php"
                            enctype="multipart/form-data"
                            class="picture-upload-form"
                        >
                            <input type="hidden" name="action" value="upload_picture">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= h($csrf) ?>"
                            >

                            <input
                                type="hidden"
                                name="resident_id"
                                value="<?= (int)$resident['id'] ?>"
                            >

                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <input
                                    type="file"
                                    name="profile_picture"
                                    class="form-control form-control-sm"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                    required
                                >

                                <button type="submit" class="btn btn-picture btn-sm">
                                    <?= $hasPicture ? 'Replace Picture' : 'Upload Picture' ?>
                                </button>
                            </div>

                            <small class="text-secondary">
                                JPG, PNG, or WEBP. Maximum file size: 5 MB.
                            </small>
                        </form>
                    <?php endif; ?>

                    <div class="row g-2 mt-2">

                        <div class="col-md-6">
                            <div class="resident-detail">
                                <strong>Date of Birth:</strong>
                                <?= h($resident['date_of_birth'] ?: 'Not recorded') ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="resident-detail">
                                <strong>Contact Number:</strong>
                                <?= h($resident['contact_number'] ?: 'Not provided') ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="resident-detail">
                                <strong>Admission Date:</strong>
                                <?= h($resident['admission_date'] ?: 'Not recorded') ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="resident-detail">
                                <strong>Care Level:</strong>
                                <?= h($resident['care_level'] ?: 'Not specified') ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="resident-detail">
                                <strong>Assigned Caretaker:</strong>
                                <?= h($resident['caretaker_name'] ?: 'Not assigned') ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="resident-detail">
                                <strong>Address:</strong>
                                <?= h($resident['address'] ?: 'Not provided') ?>
                            </div>
                        </div>

                    </div>

                    <div class="resident-actions">

                        <a
                            href="view_resident.php?id=<?= (int)$resident['id'] ?>"
                            class="btn btn-resident-view"
                        >View</a>

                        <a
                            href="add_resident.php?edit=<?= (int)$resident['id'] ?>"
                            class="btn btn-resident-edit"
                        >Edit</a>

                        <?php if ($isAdmin): ?>

                            <form
                                method="POST"
                                action="residents.php"
                                class="d-inline"
                                onsubmit="return confirm('Archive this resident? You can restore them later from Archive & Restore.');"
                            >
                                <input type="hidden" name="action" value="delete">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= h($csrf) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="resident_id"
                                    value="<?= (int)$resident['id'] ?>"
                                >

                                <button type="submit" class="btn btn-resident-delete">
                                    Archive
                                </button>
                            </form>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- SIDEBAR TOGGLE -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    var overlay = document.getElementById('sidebarOverlay');

    var sidebar = document.querySelector(
        '.sidebar, #sidebar, .sidebar-container, aside, .app-sidebar'
    );

    if (!sidebar) {
        return;
    }

    var oldTexts = [
        'home for the aged',
        'care management system'
    ];

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
            '<a href="dashboard.php">' +
            '<img src="../images/white1.png" ' +
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

                if (overlay) {
                    overlay.classList.toggle('active');
                }

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

</body>
</html>
