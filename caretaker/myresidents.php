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

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$caretakerName = $_SESSION['full_name'] ?? '';
$residents = [];
$error = '';
$profile = null;

$profileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

try {
    if ($profileId) {
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
                status
            FROM residents
            WHERE id = :id
              AND assigned_caretaker = :caretaker
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $profileId,
            'caretaker' => $caretakerName
        ]);

        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            http_response_code(404);
            $error = 'Resident not found or not assigned to your account.';
        }
    } else {
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
                status
            FROM residents
            WHERE assigned_caretaker = :caretaker
            ORDER BY first_name ASC, last_name ASC
        ");

        $stmt->execute([
            'caretaker' => $caretakerName
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

function residentName(array $resident): string
{
    return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
        $resident['first_name'] ?? '',
        $resident['middle_name'] ?? '',
        $resident['last_name'] ?? ''
    ]))));
}

require_once __DIR__ . '/../includes/sidebar.php';
?>

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
        background: #ffffff;
        border: 1px solid #e1ebe4;
        border-radius: 14px;
        padding: 25px;
        height: 100%;
        box-shadow: 0 4px 15px rgba(24, 75, 42, 0.04);
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
        background: #ffffff;
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
        background: #ffffff;
        border: 1px solid #e1ebe4;
        border-radius: 13px;
        padding: 22px;
        height: 100%;
        box-shadow: 0 4px 15px rgba(24, 75, 42, 0.04);
        transition: 0.2s ease;
    }

    .resident-card:hover {
        border-color: #8eaf96;
        box-shadow: 0 7px 20px rgba(24, 75, 42, 0.09);
        transform: translateY(-2px);
    }

    .resident-top {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
    }

    .resident-avatar {
        width: 55px;
        height: 55px;
        border-radius: 50%;
        background: #e6f0e8;
        color: #17683a;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 700;
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
        background: #eeeeee;
        color: #666666;
    }

    .status-other {
        background: #fff1d6;
        color: #8a5b00;
    }

    .view-profile-button {
        display: block;
        width: 100%;
        background: #17683a;
        color: #ffffff;
        border: none;
        border-radius: 7px;
        padding: 11px;
        margin-top: 17px;
        text-align: center;
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
        transition: 0.2s;
    }

    .view-profile-button:hover {
        background: #0b5425;
        color: #ffffff;
    }

    .empty-state {
        background: #ffffff;
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
        background: #ffffff;
        border: 1px solid #e1ebe4;
        border-radius: 14px;
        padding: 28px;
        box-shadow: 0 4px 15px rgba(24, 75, 42, 0.04);
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
        width: 72px;
        height: 72px;
        font-size: 23px;
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
    }
</style>

<div class="my-residents-page">

    <?php if ($profile): ?>

        <?php
            $fullName = residentName($profile);

            $initials = strtoupper(
                substr($profile['first_name'] ?? 'R', 0, 1) .
                substr($profile['last_name'] ?? '', 0, 1)
            );

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
                ← Back to My Residents
            </a>
        </div>

        <div class="profile-panel">

            <div class="profile-heading">
                <div class="resident-avatar">
                    <?= e($initials) ?>
                </div>

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
                <div class="col-md-6 col-lg-4">
                    <div class="profile-detail">
                        <small>First Name</small>
                        <strong><?= e($profile['first_name']) ?></strong>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="profile-detail">
                        <small>Middle Name</small>
                        <strong><?= e($profile['middle_name'] ?: 'Not provided') ?></strong>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="profile-detail">
                        <small>Last Name</small>
                        <strong><?= e($profile['last_name']) ?></strong>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="profile-detail">
                        <small>Date of Birth</small>
                        <strong><?= e($profile['date_of_birth'] ?: 'Not provided') ?></strong>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="profile-detail">
                        <small>Gender</small>
                        <strong><?= e($profile['gender'] ?: 'Not provided') ?></strong>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="profile-detail">
                        <small>Contact Number</small>
                        <strong><?= e($profile['contact_number'] ?: 'Not provided') ?></strong>
                    </div>
                </div>

                <div class="col-12">
                    <div class="profile-detail">
                        <small>Address</small>
                        <strong><?= e($profile['address'] ?: 'Not provided') ?></strong>
                    </div>
                </div>
            </div>

            <h5 class="profile-section-title">Care Information</h5>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="profile-detail">
                        <small>Room Number</small>
                        <strong><?= e($profile['room_number'] ?: 'Not assigned') ?></strong>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="profile-detail">
                        <small>Admission Date</small>
                        <strong><?= e($profile['admission_date'] ?: 'Not provided') ?></strong>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="profile-detail">
                        <small>Care Level</small>
                        <strong><?= e($profile['care_level'] ?: 'Not specified') ?></strong>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="profile-detail">
                        <small>Current Status</small>
                        <strong><?= e($status) ?></strong>
                    </div>
                </div>
            </div>

        </div>

    <?php else: ?>

        <div class="page-header">
            <h1>My Residents</h1>
            <p>View the residents assigned to your care and access their profiles.</p>
        </div>

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

                            $initials = strtoupper(
                                substr($resident['first_name'] ?? 'R', 0, 1) .
                                substr($resident['last_name'] ?? '', 0, 1)
                            );

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
                                    <div class="resident-avatar">
                                        <?= e($initials) ?>
                                    </div>

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

                            </div>
                        </div>

                    <?php endforeach; ?>

                </div>

                <div id="noSearchResults" class="empty-state mt-3" style="display:none;">
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