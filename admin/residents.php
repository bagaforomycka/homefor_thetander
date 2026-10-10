
<?php
session_start();

require_once __DIR__ . '/../config/db.php';


if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

if (!in_array($status, ['Active', 'Inactive'], true)) {
    $status = '';
}


$sql = "SELECT
            id,
            resident_id,
            first_name,
            middle_name,
            last_name,
            date_of_birth,
            gender,
            contact_number,
            room_number,
            admission_date,
            care_level,
            assigned_caretaker,
            assigned_staff,
            status
        FROM residents
        WHERE deleted_at IS NULL";

$params = [];

if ($search !== '') {
    $sql .= " AND (
        resident_id LIKE :resident_id
        OR first_name LIKE :first_name
        OR middle_name LIKE :middle_name
        OR last_name LIKE :last_name
    )";

    $keyword = '%' . $search . '%';

    $params['resident_id'] = $keyword;
    $params['first_name'] = $keyword;
    $params['middle_name'] = $keyword;
    $params['last_name'] = $keyword;
}

if ($status !== '') {
    $sql .= " AND status = :status";
    $params['status'] = $status;
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<?php require __DIR__ . '/../includes/sidebar.php'; ?>

<style>
.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}

.page-heading h1 {
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 6px;
}

.page-heading p {
    color: #6b7b70;
    margin: 0;
}

.resident-card {
    background: #fff;
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, .04);
}

.table th {
    background: #f1f8f3;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
}

.filter-button {
    background: #33b960;
    border-color: #33b960;
    color: #fff;
}

.filter-button:hover {
    background: #26984b;
    border-color: #26984b;
    color: #fff;
}

.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    min-width: 180px;
}

.action-buttons form {
    margin: 0;
}

.action-buttons button {
    white-space: nowrap;
}

@media (max-width: 768px) {
    .resident-card {
        padding: 14px;
    }
}
</style>

<div class="page-heading">
    <div>
        <h1>Resident Management</h1>
        <p>View and manage resident information.</p>
    </div>

    <a href="add_resident.php" class="btn btn-success">
        + Add Resident
    </a>
</div>

<?php if (($_GET['added'] ?? '') === '1'): ?>
    <div class="alert alert-success">
        Resident added successfully.
    </div>
<?php endif; ?>

<?php if (($_GET['updated'] ?? '') === '1'): ?>
    <div class="alert alert-success">
        Resident updated successfully.
    </div>
<?php endif; ?>

<?php if (($_GET['deleted'] ?? '') === 'soft'): ?>
    <div class="alert alert-warning">
        Resident soft-deleted successfully. The record has been retained.
    </div>
<?php elseif (($_GET['deleted'] ?? '') === 'hard'): ?>
    <div class="alert alert-danger">
        Resident permanently deleted.
    </div>
<?php endif; ?>

<div class="resident-card mb-4">
    <form method="GET" action="residents.php" class="row g-3">

        <div class="col-md-6">
            <label for="search" class="form-label">
                Search residents
            </label>

            <input
                type="text"
                class="form-control"
                id="search"
                name="search"
                placeholder="Resident ID or name"
                value="<?= h($search) ?>"
            >
        </div>

        <div class="col-md-3">
            <label for="status" class="form-label">
                Filter by status
            </label>

            <select name="status" id="status" class="form-select">
                <option value="">All statuses</option>
                <option value="Active"
                    <?= $status === 'Active' ? 'selected' : '' ?>>
                    Active
                </option>
                <option value="Inactive"
                    <?= $status === 'Inactive' ? 'selected' : '' ?>>
                    Inactive
                </option>
            </select>
        </div>

        <div class="col-md-3 d-flex align-items-end gap-2">
            <button type="submit" class="btn filter-button">
                Search
            </button>

            <a href="residents.php"
               class="btn btn-outline-secondary">
                Reset
            </a>
        </div>
    </form>
</div>

<div class="resident-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Resident List</h2>

        <span class="text-muted">
            Total: <?= count($residents) ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">

            <thead>
                <tr>
                    <th>Resident ID</th>
                    <th>Full Name</th>
                    <th>Room</th>
                    <th>Care Level</th>
                    <th>Admission Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php if (empty($residents)): ?>
                    <tr>
                        <td colspan="7"
                            class="text-center text-muted py-4">
                            No residents found.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($residents as $resident): ?>

                        <?php
                        $fullName = trim(
                            $resident['first_name'] . ' ' .
                            ($resident['middle_name'] ?? '') . ' ' .
                            $resident['last_name']
                        );

                        $fullName = preg_replace('/\s+/', ' ', $fullName);
                        ?>

                        <tr>
                            <td><?= h($resident['resident_id']) ?></td>

                            <td><?= h($fullName) ?></td>

                            <td>
                                <?= h($resident['room_number'] ?: 'Not assigned') ?>
                            </td>

                            <td>
                                <?= h($resident['care_level'] ?: 'Not set') ?>
                            </td>

                            <td>
                                <?= h($resident['admission_date'] ?: '—') ?>
                            </td>

                            <td>
                                <?php if ($resident['status'] === 'Active'): ?>
                                    <span class="badge text-bg-success">
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">
                                        <?= h($resident['status'] ?: 'Unknown') ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="action-buttons">

                                    <!-- Soft Delete -->
                                    <form
                                        method="POST"
                                        action="delete_resident.php"
                                        onsubmit="return confirm('Soft-delete this resident? Their record will be retained.');"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= h($_SESSION['csrf_token']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $resident['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="soft_delete"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-warning btn-sm"
                                        >
                                            Soft Delete
                                        </button>
                                    </form>

                                    <!-- Hard Delete: Admin only -->
                                    <?php if ($_SESSION['role'] === 'admin'): ?>

                                        <form
                                            method="POST"
                                            action="delete_resident.php"
                                            onsubmit="return confirm('WARNING: Permanently delete this resident? This cannot be undone.');"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= h($_SESSION['csrf_token']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int) $resident['id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="hard_delete"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-danger btn-sm"
                                            >
                                                Hard Delete
                                            </button>
                                        </form>

                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</main>
</body>
</html>
