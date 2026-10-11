
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

function h($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$error = '';
$residents = [];

if (!in_array($status, ['Active', 'Inactive'], true)) {
    $status = '';
}

try {
    // deleted_at will be added in our soft-delete step.
    // Until then, this query matches your current table.
    $sql = "SELECT
                id, resident_id, first_name, middle_name, last_name,
                date_of_birth, gender, contact_number, address,
                room_number, admission_date, care_level,
                assigned_caretaker, assigned_staff, status
            FROM residents
            WHERE 1 = 1";

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
    $residents = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Residents page error: ' . $e->getMessage());
    $error = 'Unable to load residents. Please check the database table.';
}

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.residents-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}

.residents-heading h1 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 6px;
}

.residents-heading p {
    color: #6c757d;
    margin-bottom: 0;
}

.resident-card {
    background: #fff;
    padding: 22px;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, .05);
    margin-bottom: 22px;
}

.table th {
    background: #f1f8f3;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
}

@media (max-width: 768px) {
    .resident-card {
        padding: 14px;
    }
}
</style>

<div class="residents-heading">
    <div>
        <h1>Resident Management</h1>
        <p>View and manage resident information.</p>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="dashboard.php" class="btn btn-outline-success">
            &larr; Back to Dashboard
        </a>

        <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="add_resident.php" class="btn btn-success">
                + Add Resident
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (($_GET['added'] ?? '') === '1'): ?>
    <div class="alert alert-success">
        Resident added successfully.
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php else: ?>

    <div class="resident-card">
        <form method="GET" action="residents.php" class="row g-3">
            <div class="col-md-6">
                <label for="search" class="form-label">
                    Search by resident ID or name
                </label>

                <input
                    type="text"
                    id="search"
                    name="search"
                    class="form-control"
                    placeholder="Enter resident ID or name"
                    value="<?= h($search) ?>"
                >
            </div>

            <div class="col-md-3">
                <label for="status" class="form-label">
                    Filter by status
                </label>

                <select id="status" name="status" class="form-select">
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
                <button type="submit" class="btn btn-success">
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
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Resident ID</th>
                        <th>Full Name</th>
                        <th>Room</th>
                        <th>Care Level</th>
                        <th>Admission Date</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$residents): ?>
                    <tr>
                        <td colspan="6"
                            class="text-center text-muted py-4">
                            No residents found.
                        </td>
                    </tr>
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
                                <?php if (($resident['status'] ?? '') === 'Active'): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">
                                        <?= h($resident['status'] ?: 'Unknown') ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</main>
</body>
</html>
