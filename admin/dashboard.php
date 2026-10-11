
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only administrators and staff may access this dashboard.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

$totalResidents = 0;
$totalCaretakers = 0;
$totalStaff = 0;
$availableRooms = 0;
$totalHealthRecords = 0;
$dashboardError = '';

function dashboardCount(PDO $pdo, string $sql): int
{
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (PDOException $e) {
        error_log('Dashboard count error: ' . $e->getMessage());
        return 0;
    }
}

// Count residents without relying on a deleted_at column.
// We will add proper soft-delete support in a later step.
$totalResidents = dashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM residents"
);

$totalCaretakers = dashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM users
     WHERE role = 'caretaker' AND status = 'active'"
);

$totalStaff = dashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM users
     WHERE role = 'staff' AND status = 'active'"
);

$availableRooms = dashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM rooms WHERE status = 'Available'"
);

$totalHealthRecords = dashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM health_records"
);

function h($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.dashboard-heading {
    margin-bottom: 26px;
}

.dashboard-heading h1 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 8px;
}

.dashboard-heading p {
    color: #6c757d;
    margin-bottom: 0;
}

.dashboard-card {
    display: block;
    height: 100%;
    padding: 22px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    color: #212529;
    text-decoration: none;
    box-shadow: 0 3px 10px rgba(0, 0, 0, .04);
    transition: transform .2s, box-shadow .2s;
}

.dashboard-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, .10);
    color: #198754;
}

.dashboard-card:focus-visible {
    outline: 3px solid #198754;
    outline-offset: 3px;
}

.dashboard-card .card-label {
    color: #6c757d;
    margin-bottom: 12px;
}

.dashboard-card .card-number {
    color: #198754;
    font-size: 34px;
    font-weight: 700;
    margin-bottom: 14px;
}

.dashboard-card .card-link {
    color: #198754;
    font-weight: 600;
}

.quick-actions {
    margin-top: 30px;
}

.quick-action {
    display: block;
    padding: 14px 16px;
    border-radius: 9px;
    background: #198754;
    color: #fff;
    text-align: center;
    text-decoration: none;
    font-weight: 600;
}

.quick-action:hover {
    background: #146c43;
    color: #fff;
}

@media (max-width: 768px) {
    .dashboard-heading h1 {
        font-size: 24px;
    }
}
</style>

<div class="dashboard-heading">
    <h1>Admin Dashboard</h1>
    <p>Welcome to the Home for the Tender Care Management System.</p>
</div>

<div class="row g-4">

    <div class="col-sm-6 col-xl-4">
        <a class="dashboard-card" href="residents.php">
            <div class="card-label">Total Residents</div>
            <div class="card-number"><?= h($totalResidents) ?></div>
            <span class="card-link">Manage Residents &rarr;</span>
        </a>
    </div>

    <div class="col-sm-6 col-xl-4">
        <a class="dashboard-card" href="rooms/index.php">
            <div class="card-label">Available Rooms</div>
            <div class="card-number"><?= h($availableRooms) ?></div>
            <span class="card-link">Manage Rooms &rarr;</span>
        </a>
    </div>

    <div class="col-sm-6 col-xl-4">
        <a class="dashboard-card" href="health_records.php">
            <div class="card-label">Health Records</div>
            <div class="card-number"><?= h($totalHealthRecords) ?></div>
            <span class="card-link">Manage Health Records &rarr;</span>
        </a>
    </div>

    <div class="col-sm-6 col-xl-4">
        <a class="dashboard-card" href="caretakers.php">
            <div class="card-label">Active Caretakers</div>
            <div class="card-number"><?= h($totalCaretakers) ?></div>
            <span class="card-link">Manage Caretakers &rarr;</span>
        </a>
    </div>

    <div class="col-sm-6 col-xl-4">
        <a class="dashboard-card" href="caretakers.php">
            <div class="card-label">Active Staff</div>
            <div class="card-number"><?= h($totalStaff) ?></div>
            <span class="card-link">Manage Staff &rarr;</span>
        </a>
    </div>

</div>

<div class="quick-actions">
    <h2 class="h5 fw-bold mb-3">Quick Actions</h2>

    <div class="row g-3">
        <div class="col-sm-6 col-xl-4">
            <a href="residents.php" class="quick-action">
                Manage Residents
            </a>
        </div>

        <div class="col-sm-6 col-xl-4">
            <a href="rooms/index.php" class="quick-action">
                Manage Rooms
            </a>
        </div>

        <div class="col-sm-6 col-xl-4">
            <a href="health_records.php" class="quick-action">
                Manage Health Records
            </a>
        </div>
    </div>
</div>

</main>
</body>
</html>
