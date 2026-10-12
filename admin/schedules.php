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

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$success = $_GET['success'] ?? '';
$error = '';

/* Delete schedule: administrators only */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {
    if ($_SESSION['role'] !== 'admin') {
        http_response_code(403);
        exit('Only an administrator can delete schedules.');
    }

    $deleteId = filter_input(INPUT_POST, 'schedule_id', FILTER_VALIDATE_INT);

    if ($deleteId) {
        try {
            $stmt = $pdo->prepare('DELETE FROM schedules WHERE id = :id');
            $stmt->execute(['id' => $deleteId]);

            header('Location: schedules.php?success=deleted');
            exit;
        } catch (PDOException $e) {
            error_log('Schedule deletion error: ' . $e->getMessage());
            $error = 'Unable to delete this schedule.';
        }
    } else {
        $error = 'Invalid schedule selected.';
    }
}

/* Retrieve schedules and optional resident names */
$sql = "
    SELECT
        s.*,
        r.resident_id AS resident_code,
        r.first_name,
        r.middle_name,
        r.last_name
    FROM schedules s
    LEFT JOIN residents r ON r.id = s.resident_id
    WHERE 1 = 1
";

$params = [];

if ($search !== '') {
    $sql .= " AND (
        s.title LIKE :title
        OR s.assigned_staff LIKE :staff
        OR s.location LIKE :location
        OR r.resident_id LIKE :resident_code
        OR r.first_name LIKE :first_name
        OR r.last_name LIKE :last_name
    )";

    $term = '%' . $search . '%';

    $params = [
        'title' => $term,
        'staff' => $term,
        'location' => $term,
        'resident_code' => $term,
        'first_name' => $term,
        'last_name' => $term
    ];
}

$allowedStatuses = ['Scheduled', 'Completed', 'Cancelled'];

if (in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= ' AND s.status = :status';
    $params['status'] = $statusFilter;
}

$sql .= ' ORDER BY s.schedule_date DESC, s.start_time DESC, s.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$schedules = $stmt->fetchAll();

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.schedule-header {
    background: #eaf6ee;
    border: 1px solid #d8eadc;
    border-radius: 14px;
    padding: 22px 26px;
    margin-bottom: 22px;
}

.schedule-header h1 {
    color: #17683a;
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 6px;
}

.schedule-header p {
    color: #61796a;
    margin-bottom: 0;
}

.schedule-panel {
    background: #fff;
    border: 1px solid #e3eee6;
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 4px 16px rgba(24,75,42,.05);
}

.schedule-search {
    background: #f5faf6;
}

.schedule-card {
    background: #fff;
    border: 1px solid #e0ebe3;
    border-radius: 12px;
    padding: 18px;
    margin-bottom: 14px;
}

.schedule-card h3 {
    color: #17683a;
    font-size: 19px;
    font-weight: 700;
}

.schedule-meta {
    color: #66786c;
    font-size: 13px;
    line-height: 1.8;
}

.schedule-description {
    background: #f7faf8;
    border: 1px solid #e5ede7;
    border-radius: 9px;
    padding: 12px;
    overflow-wrap: anywhere;
}

.schedule-status {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.status-scheduled {
    color: #17683a;
    background: #e5f5e9;
}

.status-completed {
    color: #1553a0;
    background: #e8f1ff;
}

.status-cancelled {
    color: #b02a37;
    background: #fbe9eb;
}

.btn-schedule-view {
    color: #198754;
    background: #fff;
    border: 1px solid #198754;
}

.btn-schedule-view:hover {
    color: #fff;
    background: #198754;
}

.btn-schedule-edit {
    color: #0d6efd;
    background: #fff;
    border: 1px solid #0d6efd;
}

.btn-schedule-edit:hover {
    color: #fff;
    background: #0d6efd;
}

.btn-schedule-delete {
    color: #dc3545;
    background: #fff;
    border: 1px solid #dc3545;
}

.btn-schedule-delete:hover {
    color: #fff;
    background: #dc3545;
}

.schedule-actions {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 14px;
}

.schedule-actions .btn {
    font-size: 13px;
    padding: 5px 10px;
}

@media (max-width: 576px) {
    .schedule-header,
    .schedule-panel {
        padding: 15px;
    }
}
</style>

<div class="schedule-header d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <h1>Schedules</h1>
        <p>Manage resident activities, appointments, and daily schedules.</p>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="dashboard.php" class="btn btn-outline-success">
            ← Back to Dashboard
        </a>

        <a href="add_schedule.php" class="btn btn-success">
            + Add Schedule
        </a>
    </div>
</div>

<?php if ($success === 'added'): ?>
    <div class="alert alert-success">Schedule added successfully.</div>
<?php elseif ($success === 'updated'): ?>
    <div class="alert alert-success">Schedule updated successfully.</div>
<?php elseif ($success === 'deleted'): ?>
    <div class="alert alert-success">Schedule deleted successfully.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="schedule-panel schedule-search">
    <form method="GET" action="schedules.php"
          class="d-flex flex-wrap gap-2 align-items-center">

        <input
            type="text"
            name="search"
            class="form-control flex-grow-1"
            style="min-width:220px"
            placeholder="Search title, resident, staff, or location..."
            value="<?= h($search) ?>"
        >

        <select name="status" class="form-select"
                style="width:auto;min-width:170px">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                All statuses
            </option>
            <?php foreach ($allowedStatuses as $status): ?>
                <option value="<?= h($status) ?>"
                    <?= $statusFilter === $status ? 'selected' : '' ?>>
                    <?= h($status) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-success">
            Search / Filter
        </button>

        <a href="schedules.php" class="btn btn-outline-secondary">
            Reset
        </a>
    </form>
</div>

<div class="schedule-panel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Schedule List</h2>
        <span class="text-secondary">
            <?= count($schedules) ?> schedule(s)
        </span>
    </div>

    <?php if (empty($schedules)): ?>
        <div class="alert alert-info mb-0">
            No schedules found. Click Add Schedule to create one.
        </div>
    <?php else: ?>

        <?php foreach ($schedules as $item): ?>
            <?php
            $residentName = trim(
                ($item['first_name'] ?? '') . ' ' .
                ($item['middle_name'] ?? '') . ' ' .
                ($item['last_name'] ?? '')
            );
            $residentName = preg_replace('/\s+/', ' ', $residentName);

            $statusClass = match ($item['status']) {
                'Completed' => 'status-completed',
                'Cancelled' => 'status-cancelled',
                default => 'status-scheduled'
            };
            ?>

            <div class="schedule-card">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h3 class="mb-1"><?= h($item['title']) ?></h3>
                        <div class="schedule-meta">
                            Type: <?= h($item['schedule_type']) ?>
                            &nbsp;|&nbsp;
                            Date: <?= h($item['schedule_date']) ?>
                            &nbsp;|&nbsp;
                            Time: <?= h(date('g:i A', strtotime($item['start_time']))) ?>
                            <?php if (!empty($item['end_time'])): ?>
                                – <?= h(date('g:i A', strtotime($item['end_time']))) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <span class="schedule-status <?= h($statusClass) ?>">
                        <?= h($item['status']) ?>
                    </span>
                </div>

                <div class="row g-2 mt-2">
                    <div class="col-md-6">
                        <div class="schedule-description">
                            <strong>Resident:</strong>
                            <?= h($residentName !== ''
                                ? $residentName
                                : ($item['resident_id'] ? 'Resident not found' : 'Not assigned')) ?>
                            <?php if (!empty($item['resident_code'])): ?>
                                (<?= h($item['resident_code']) ?>)
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="schedule-description">
                            <strong>Assigned staff:</strong>
                            <?= h($item['assigned_staff'] ?: 'Not assigned') ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="schedule-description">
                            <strong>Location:</strong>
                            <?= h($item['location'] ?: 'Not specified') ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="schedule-description">
                            <strong>Description:</strong>
                            <?= h($item['description'] ?: 'No description') ?>
                        </div>
                    </div>
                </div>

                <div class="schedule-actions">
                    <a href="view_schedule.php?id=<?= (int)$item['id'] ?>"
                       class="btn btn-schedule-view">View</a>

                    <a href="add_schedule.php?edit=<?= (int)$item['id'] ?>"
                       class="btn btn-schedule-edit">Edit</a>

                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <form method="POST" action="schedules.php"
                              class="d-inline"
                              onsubmit="return confirm('Permanently delete this schedule?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="schedule_id"
                                   value="<?= (int)$item['id'] ?>">
                            <button type="submit"
                                    class="btn btn-schedule-delete">
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
