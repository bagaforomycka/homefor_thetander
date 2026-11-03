
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
    // Check whether archived_at exists in the residents table.
    $columnCheck = $pdo->query("SHOW COLUMNS FROM residents");
    $columns = $columnCheck->fetchAll(PDO::FETCH_COLUMN);

    $hasArchivedAt = in_array('archived_at', $columns, true);

} catch (PDOException $e) {
    error_log('Resident column check error: ' . $e->getMessage());
    $error = 'Unable to check the residents database table.';
}

/* Archive resident instead of permanently deleting. */
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

    if (
        !is_string($postedToken) ||
        !hash_equals($csrf, $postedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }

    $residentId = filter_input(
        INPUT_POST,
        'resident_id',
        FILTER_VALIDATE_INT
    );

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

/* Load residents and their assigned caretaker names. */
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

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
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

.resident-actions .btn {
    font-size: 13px;
    padding: 5px 10px;
}

.btn-resident-view {
    color: #198754;
    background: #fff;
    border: 1px solid #198754;
}

.btn-resident-view:hover {
    color: #fff;
    background: #198754;
}

.btn-resident-edit {
    color: #725800;
    background: #ffdf70;
    border: 1px solid #e2b422;
}

.btn-resident-edit:hover {
    color: #5d4600;
    background: #ffcf40;
}

.btn-resident-delete {
    color: #b42318;
    background: #fff;
    border: 1px solid #dc3545;
}

.btn-resident-delete:hover {
    color: #fff;
    background: #dc3545;
}

@media (max-width: 576px) {
    .residents-header,
    .residents-panel {
        padding: 15px;
    }
}
</style>

<div class="residents-header d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <h1>Resident Management</h1>
        <p>View and manage resident information and room assignments.</p>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="dashboard.php" class="btn btn-outline-success">
            ← Back to Dashboard
        </a>

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
    <div class="alert alert-success">
        Resident archived successfully.
    </div>
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
            ?>

            <div class="resident-card">

                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h3><?= h($fullName) ?></h3>

                        <div class="resident-meta">
                            Resident ID: <?= h($resident['resident_id']) ?>
                            &nbsp;|&nbsp;
                            Room: <?= h($resident['room_number'] ?: 'Not assigned') ?>
                            &nbsp;|&nbsp;
                            Gender: <?= h($resident['gender'] ?: 'Not specified') ?>
                        </div>
                    </div>

                    <span class="resident-status <?= h($status) ?>">
                        <?= h($statusLabel ?: 'Unspecified') ?>
                    </span>
                </div>

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
                            onsubmit="return confirm('Archive this resident? You can restore the record later if your archive module supports it.');"
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
                                Delete
                            </button>
                        </form>

                    <?php endif; ?>

                </div>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector('.app-sidebar');

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
            '<a href="/homefor_thetander/admin/dashboard.php">' +
            '<img src="/homefor_thetander/images/white1.png" ' +
            'alt="Home for the Aged">' +
            '</a>';

        sidebar.insertBefore(logo, sidebar.firstChild);
    }
});
</script>

</main>
</body>
</html>
