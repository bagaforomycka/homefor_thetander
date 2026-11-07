
<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'caretaker'
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/archive_helper.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function residentName(array $resident): string
{
    return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
        $resident['first_name'] ?? '',
        $resident['middle_name'] ?? '',
        $resident['last_name'] ?? ''
    ]))));
}

function residentPicture($picture): string
{
    $picture = trim((string)($picture ?? ''));

    if ($picture === '') {
        return '../images/default-profile.png';
    }

    $filename = basename($picture);

    // Actual location of uploaded resident pictures.
    $path = __DIR__ . '/../admin/uploads/residentspic/' . $filename;

    if (!is_file($path)) {
        return '../images/default-profile.png';
    }

    // Browser URL of uploaded resident pictures.
    return '../admin/uploads/residentspic/' . rawurlencode($filename);
}

$caretakerId = (int)$_SESSION['user_id'];

/* Private archive table (shared with archive.php). */
try {
    archiveEnsureCaretakerTable($pdo);
    $residentVisible = archiveResidentVisibleSql($pdo, '');
} catch (Throwable $e) {
    error_log('My Residents setup error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to prepare your residents list. Please contact the administrator.');
}

if (empty($_SESSION['caretaker_archive_csrf'])) {
    $_SESSION['caretaker_archive_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['caretaker_archive_csrf'];

/* Archive a resident (hides from My Residents only; restorable). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page.');
    }

    $archiveResidentId = filter_var($_POST['resident_id'] ?? null, FILTER_VALIDATE_INT);

    if ($archiveResidentId && $archiveResidentId > 0) {
        try {
            // Only residents assigned to this caretaker can be archived.
            $check = $pdo->prepare("
                SELECT id FROM residents
                WHERE id = :id AND assigned_caretaker = :caretaker
                  AND $residentVisible
                LIMIT 1
            ");
            $check->execute(['id' => $archiveResidentId, 'caretaker' => $caretakerId]);

            if ($check->fetchColumn()) {
                $ins = $pdo->prepare("
                    INSERT IGNORE INTO caretaker_resident_archive
                        (caretaker_user_id, resident_id)
                    VALUES (:caretaker, :resident)
                ");
                $ins->execute([
                    'caretaker' => $caretakerId,
                    'resident' => $archiveResidentId
                ]);
                header('Location: myresidents.php?archived=1');
                exit;
            }
        } catch (Throwable $e) {
            error_log('Caretaker archive error: ' . $e->getMessage());
        }
    }

    header('Location: myresidents.php?archive_error=1');
    exit;
}

$residents = [];
$error = '';
$profile = null;

$profileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

try {

    if ($profileId) {

        // Load one resident assigned to this caretaker.
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
                status,
                profile_picture
            FROM residents
            WHERE id = :id
              AND assigned_caretaker = :caretaker
              AND $residentVisible
              AND NOT EXISTS (
                  SELECT 1 FROM caretaker_resident_archive ca
                  WHERE ca.resident_id = residents.id
                    AND ca.caretaker_user_id = :caretaker_archive
              )
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $profileId,
            'caretaker' => $caretakerId,
            'caretaker_archive' => $caretakerId
        ]);

        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            http_response_code(404);
            $error = 'Resident not found or not assigned to your account.';
        }

    } else {

        // Load all residents assigned to this caretaker.
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
                status,
                profile_picture
            FROM residents
            WHERE assigned_caretaker = :caretaker
              AND $residentVisible
              AND NOT EXISTS (
                  SELECT 1 FROM caretaker_resident_archive ca
                  WHERE ca.resident_id = residents.id
                    AND ca.caretaker_user_id = :caretaker_archive
              )
            ORDER BY first_name ASC, last_name ASC
        ");

        $stmt->execute([
            'caretaker' => $caretakerId,
            'caretaker_archive' => $caretakerId
        ]);

        $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $ex) {

    error_log('My Residents error: ' . $ex->getMessage());

    $error = 'Unable to load resident information. Please check the database connection and table structure.';
}

$totalResidents = count($residents);
$activeResidents = 0;
$otherResidents = 0;

foreach ($residents as $resident) {

    if (strtolower($resident['status'] ?? '') === 'active') {
        $activeResidents++;
    } else {
        $otherResidents++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>My Residents | Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        .my-residents-page {
            width: 100%;
            max-width: 1600px;
            margin: 0 auto;
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
            font-size: 29px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .page-header p {
            color: #61796a;
            margin: 0;
        }

        .summary-card {
            background: #fff;
            border: 1px solid #e1ebe4;
            border-radius: 14px;
            padding: 25px;
            height: 100%;
            box-shadow: 0 4px 15px rgba(24, 75, 42, .04);
        }

        .summary-card small {
            display: block;
            color: #718078;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .summary-card h2 {
            color: #063b18;
            font-size: 34px;
            font-weight: 700;
            margin: 0;
        }

        .section-heading {
            color: #063b18;
            font-size: 21px;
            font-weight: 700;
            margin: 30px 0 5px;
        }

        .section-description {
            color: #718078;
            font-size: 14px;
            margin-bottom: 18px;
        }

        .resident-toolbar {
            background: #fff;
            border: 1px solid #e1ebe4;
            border-radius: 13px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .resident-toolbar .form-control,
        .resident-toolbar .form-select {
            min-height: 45px;
            border-color: #dce6df;
        }

        .resident-card {
            background: #fff;
            border: 1px solid #e1ebe4;
            border-radius: 13px;
            padding: 22px;
            height: 100%;
            box-shadow: 0 4px 15px rgba(24, 75, 42, .04);
            transition: .2s ease;
        }

        .resident-card:hover {
            border-color: #8eaf96;
            box-shadow: 0 7px 20px rgba(24, 75, 42, .09);
            transform: translateY(-2px);
        }

        .resident-top {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            min-width: 0;
        }

        .resident-avatar {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            object-fit: cover;
            background: #e6f0e8;
            border: 2px solid #e1ebe4;
            flex-shrink: 0;
        }

        .resident-name {
            color: #17683a;
            font-size: 17px;
            font-weight: 700;
            text-decoration: none;
            overflow-wrap: anywhere;
        }

        .resident-name:hover {
            color: #0b5425;
            text-decoration: underline;
        }

        .resident-id {
            color: #7b8780;
            font-size: 13px;
            margin-top: 4px;
        }

        .resident-info {
            border-top: 1px solid #edf1ed;
            padding-top: 16px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 13px;
            font-size: 14px;
        }

        .info-label {
            color: #718078;
        }

        .info-value {
            color: #303a33;
            font-weight: 600;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-active {
            background: #e5f4e9;
            color: #17632c;
        }

        .status-inactive {
            background: #eee;
            color: #666;
        }

        .status-other {
            background: #fff1d6;
            color: #8a5b00;
        }

        .view-profile-button {
            display: block;
            width: 100%;
            background: #17683a;
            color: #fff;
            border: none;
            border-radius: 7px;
            padding: 11px;
            margin-top: 17px;
            text-align: center;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: .2s;
        }

        .view-profile-button:hover {
            background: #0b5425;
            color: #fff;
        }

        .archive-button {
            display: block;
            width: 100%;
            background: #fff;
            color: #8a5b00;
            border: 1px solid #e0b25a;
            border-radius: 7px;
            padding: 9px;
            margin-top: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: .2s;
        }

        .archive-button:hover {
            background: #fff1d6;
        }

        .empty-state {
            background: #fff;
            border: 1px solid #e1ebe4;
            border-radius: 13px;
            padding: 55px 20px;
            text-align: center;
            color: #718078;
        }

        .empty-state h5 {
            color: #26352b;
            font-weight: 700;
            margin-bottom: 9px;
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
            gap: 18px;
            padding-bottom: 24px;
            border-bottom: 1px solid #e8eee9;
            margin-bottom: 25px;
        }

        .profile-heading .resident-avatar {
            width: 100px;
            height: 100px;
        }

        .profile-heading h2 {
            color: #17683a;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .profile-section-title {
            color: #17683a;
            font-size: 16px;
            font-weight: 700;
            margin: 25px 0 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e8eee9;
        }

        .profile-detail {
            background: #f7faf8;
            border: 1px solid #e8eee9;
            border-radius: 9px;
            padding: 15px;
            height: 100%;
            min-height: 78px;
        }

        .profile-detail small {
            display: block;
            color: #718078;
            font-size: 12px;
            margin-bottom: 8px;
        }

        .profile-detail strong {
            display: block;
            color: #26352b;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        @media (max-width: 576px) {
            .page-header,
            .profile-panel {
                padding: 18px;
            }

            .page-header h1 {
                font-size: 24px;
            }

            .summary-card {
                padding: 18px;
            }

            .profile-heading h2 {
                font-size: 19px;
            }

            .profile-heading .resident-avatar {
                width: 75px;
                height: 75px;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="my-residents-page">

    <?php if ($profile): ?>

        <?php
            $fullName = residentName($profile);
            $status = $profile['status'] ?: 'Unspecified';

            $statusClass = strtolower($status) === 'active'
                ? 'status-active'
                : (strtolower($status) === 'inactive'
                    ? 'status-inactive'
                    : 'status-other');
        ?>

        <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">

            <div>
                <h1>Resident Profile</h1>
                <p>View the complete information of your assigned resident.</p>
            </div>

            <a href="myresidents.php" class="btn btn-outline-success">
                Back to My Residents
            </a>

        </div>

        <div class="profile-panel">

            <div class="profile-heading">

                <img
                    src="<?= e(residentPicture($profile['profile_picture'])) ?>"
                    alt="Resident profile picture"
                    class="resident-avatar"
                >

                <div>
                    <h2><?= e($fullName) ?></h2>

                    <div class="text-secondary mb-2">
                        Resident ID: <?= e($profile['resident_id']) ?>
                    </div>

                    <span class="status-badge <?= $statusClass ?>">
                        <?= e($status) ?>
                    </span>
                </div>

            </div>

            <h5 class="profile-section-title">Personal Information</h5>

            <div class="row g-3">

                <?php
                $personalDetails = [
                    'First Name' => $profile['first_name'],
                    'Middle Name' => $profile['middle_name'] ?: 'Not provided',
                    'Last Name' => $profile['last_name'],
                    'Date of Birth' => $profile['date_of_birth'] ?: 'Not provided',
                    'Gender' => $profile['gender'] ?: 'Not provided',
                    'Contact Number' => $profile['contact_number'] ?: 'Not provided',
                    'Address' => $profile['address'] ?: 'Not provided'
                ];
                ?>

                <?php foreach ($personalDetails as $label => $value): ?>

                    <div class="<?= $label === 'Address' ? 'col-12' : 'col-md-6 col-lg-4' ?>">

                        <div class="profile-detail">
                            <small><?= e($label) ?></small>
                            <strong><?= e($value) ?></strong>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

            <h5 class="profile-section-title">Care Information</h5>

            <div class="row g-3">

                <?php
                $careDetails = [
                    'Room Number' => $profile['room_number'] ?: 'Not assigned',
                    'Admission Date' => $profile['admission_date'] ?: 'Not provided',
                    'Care Level' => $profile['care_level'] ?: 'Not specified',
                    'Current Status' => $status
                ];
                ?>

                <?php foreach ($careDetails as $label => $value): ?>

                    <div class="col-md-6 col-lg-4">

                        <div class="profile-detail">
                            <small><?= e($label) ?></small>
                            <strong><?= e($value) ?></strong>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

    <?php elseif ($profileId && $error): ?>

        <div class="page-header">
            <h1>Resident Profile</h1>
            <p>Resident information could not be displayed.</p>
        </div>

        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>

        <a href="myresidents.php" class="btn btn-success">
            Back to My Residents
        </a>

    <?php else: ?>

        <div class="page-header">
            <h1>My Residents</h1>
            <p>View the residents assigned to your care and access their profiles.</p>
        </div>

        <?php if (isset($_GET['archived'])): ?>
            <div class="alert alert-success">
                Resident archived. You can bring them back anytime from
                <a href="archive.php">Archive &amp; Restore</a>.
            </div>
        <?php elseif (isset($_GET['archive_error'])): ?>
            <div class="alert alert-danger">Unable to archive that resident.</div>
        <?php endif; ?>

        <?php if ($error): ?>

            <div class="alert alert-danger">
                <?= e($error) ?>
            </div>

        <?php else: ?>

            <div class="row g-3">

                <div class="col-md-4">
                    <div class="summary-card">
                        <small>Total Assigned Residents</small>
                        <h2><?= $totalResidents ?></h2>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="summary-card">
                        <small>Active Residents</small>
                        <h2><?= $activeResidents ?></h2>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="summary-card">
                        <small>Other Status</small>
                        <h2><?= $otherResidents ?></h2>
                    </div>
                </div>

            </div>

            <div class="d-flex justify-content-between align-items-end flex-wrap gap-2">

                <div>
                    <h5 class="section-heading">Assigned Residents</h5>

                    <p class="section-description">
                        Select a resident's name or profile button to view their information.
                    </p>
                </div>

                <span class="text-secondary mb-3">
                    <?= $totalResidents ?> resident(s) assigned
                </span>

            </div>

            <div class="resident-toolbar">

                <div class="row g-3">

                    <div class="col-md-8">
                        <input
                            type="text"
                            id="residentSearch"
                            class="form-control"
                            placeholder="Search by resident name, ID, or room number..."
                        >
                    </div>

                    <div class="col-md-4">
                        <select id="statusFilter" class="form-select">
                            <option value="all">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                </div>

            </div>

            <?php if (empty($residents)): ?>

                <div class="empty-state">

                    <h5>No Assigned Residents</h5>

                    <p class="mb-0">
                        Residents assigned to your caretaker account will appear here.
                    </p>

                </div>

            <?php else: ?>

                <div class="row g-3" id="residentList">

                    <?php foreach ($residents as $resident): ?>

                        <?php
                            $fullName = residentName($resident);
                            $status = $resident['status'] ?: 'Unspecified';

                            $statusClass = strtolower($status) === 'active'
                                ? 'status-active'
                                : (strtolower($status) === 'inactive'
                                    ? 'status-inactive'
                                    : 'status-other');

                            $searchText = strtolower(
                                $fullName . ' ' .
                                ($resident['resident_id'] ?? '') . ' ' .
                                ($resident['room_number'] ?? '')
                            );
                        ?>

                        <div
                            class="col-xl-4 col-md-6 resident-item"
                            data-search="<?= e($searchText) ?>"
                            data-status="<?= e(strtolower($status)) ?>"
                        >

                            <div class="resident-card">

                                <div class="resident-top">

                                    <img
                                        src="<?= e(residentPicture($resident['profile_picture'])) ?>"
                                        alt="Resident profile picture"
                                        class="resident-avatar"
                                    >

                                    <div class="flex-grow-1">

                                        <a
                                            class="resident-name"
                                            href="myresidents.php?id=<?= (int)$resident['id'] ?>"
                                        >
                                            <?= e($fullName) ?>
                                        </a>

                                        <div class="resident-id">
                                            Resident ID: <?= e($resident['resident_id']) ?>
                                        </div>

                                    </div>

                                </div>

                                <div class="resident-info">

                                    <div class="info-row">

                                        <span class="info-label">Room Number</span>

                                        <span class="info-value">
                                            <?= e($resident['room_number'] ?: 'Not assigned') ?>
                                        </span>

                                    </div>

                                    <div class="info-row">

                                        <span class="info-label">Care Level</span>

                                        <span class="info-value">
                                            <?= e($resident['care_level'] ?: 'Not specified') ?>
                                        </span>

                                    </div>

                                    <div class="info-row">

                                        <span class="info-label">Status</span>

                                        <span class="info-value">
                                            <span class="status-badge <?= $statusClass ?>">
                                                <?= e($status) ?>
                                            </span>
                                        </span>

                                    </div>

                                </div>

                                <a
                                    href="myresidents.php?id=<?= (int)$resident['id'] ?>"
                                    class="view-profile-button"
                                >
                                    View Resident Profile
                                </a>

                                <form method="post" action="myresidents.php"
                                      onsubmit="return confirm('Archive this resident? You can restore them later from Archive & Restore.');">
                                    <input type="hidden" name="action" value="archive">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="resident_id" value="<?= (int)$resident['id'] ?>">
                                    <button type="submit" class="archive-button">Archive</button>
                                </form>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

                <div
                    id="noSearchResults"
                    class="empty-state mt-3"
                    style="display:none;"
                >

                    <h5>No Matching Residents</h5>

                    <p class="mb-0">
                        Try another resident name, ID, room number, or status.
                    </p>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>

</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const searchInput = document.getElementById('residentSearch');
const statusFilter = document.getElementById('statusFilter');
const residentItems = document.querySelectorAll('.resident-item');
const noResults = document.getElementById('noSearchResults');

function filterResidents() {

    if (!searchInput || !statusFilter) {
        return;
    }

    const searchValue = searchInput.value.toLowerCase().trim();
    const selectedStatus = statusFilter.value;

    let visibleCount = 0;

    residentItems.forEach(function(item) {

        const searchText = item.dataset.search || '';
        const residentStatus = item.dataset.status || '';

        const matchesSearch = searchText.includes(searchValue);

        const matchesStatus =
            selectedStatus === 'all' ||
            residentStatus === selectedStatus;

        const shouldShow = matchesSearch && matchesStatus;

        item.style.display = shouldShow ? '' : 'none';

        if (shouldShow) {
            visibleCount++;
        }

    });

    if (noResults) {
        noResults.style.display =
            visibleCount === 0 ? 'block' : 'none';
    }
}

if (searchInput) {
    searchInput.addEventListener('input', filterResidents);
}

if (statusFilter) {
    statusFilter.addEventListener('change', filterResidents);
}
</script>

</body>
</html>
