
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

/* Delete means archive; never permanently delete a resident here. */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only an administrator can delete residents.');
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
            $stmt = $pdo->prepare("
                UPDATE residents
                SET archived_at = NOW()
                WHERE id = :id
                  AND archived_at IS NULL
            ");

            $stmt->execute(['id' => $residentId]);

            if ($stmt->rowCount() > 0) {
                header('Location: residents.php?success=deleted');
                exit;
            }

            $error = 'Resident not found or already archived.';
        } catch (PDOException $e) {
            error_log('Resident archive error: ' . $e->getMessage());
            $error = 'Unable to delete this resident. Please try again.';
        }
    }
}

/* Only display active residents. */
$sql = "
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
        created_at
    FROM residents
    WHERE archived_at IS NULL
";

$params = [];

if ($search !== '') {
    $sql .= " AND (
        resident_id LIKE :resident_id
        OR first_name LIKE :first_name
        OR middle_name LIKE :middle_name
        OR last_name LIKE :last_name
        OR room_number LIKE :room_number
        OR assigned_caretaker LIKE :caretaker
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
    $sql .= ' AND status = :status';
    $params['status'] = $statusFilter;
}

$sql .= ' ORDER BY id DESC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Resident loading error: ' . $e->getMessage());
    $residents = [];
    $error = 'Unable to load residents. Please check your database tables.';
}

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
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

        <a href="add_residents.php" class="btn btn-success">
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
        Resident deleted successfully and moved to Archive &amp; Restore.
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
                style="width:auto;min-width:160px">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                All statuses
            </option>
            <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>
                Active
            </option>
            <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>
                Inactive
            </option>
            <option value="Discharged" <?= $statusFilter === 'Discharged' ? 'selected' : '' ?>>
                Discharged
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
        <span class="text-secondary"><?= count($residents) ?> resident(s)</span>
    </div>

    <?php if (empty($residents)): ?>
        <div class="alert alert-info mb-0">
            No active residents found. Add a resident or change your search.
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

                    <span class="resident-status">
                        <?= h($resident['status'] ?: 'Unspecified') ?>
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
                            <?= h($resident['assigned_caretaker'] ?: 'Not assigned') ?>
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
                        href="add_residents.php?edit=<?= (int)$resident['id'] ?>"
                        class="btn btn-resident-edit"
                    >Edit</a>

                    <?php if ($isAdmin): ?>
                        <form
                            method="POST"
                            action="residents.php"
                            class="d-inline"
                            onsubmit="return confirm('Delete this resident? The resident will be moved to Archive & Restore, where you can restore the record later.');"
                        >
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
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

</main>
</body>
</html>
