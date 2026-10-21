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

$isAdmin = $_SESSION['role'] === 'admin';

$allowedStatuses = ['Scheduled', 'Completed', 'Cancelled'];

/* Create a CSRF token for schedule actions. */
if (empty($_SESSION['schedules_csrf_token'])) {
    $_SESSION['schedules_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['schedules_csrf_token'];

/* Delete means archive. It does not permanently delete the schedule. */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only an administrator can archive schedules.');
    }

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token. Please return to Schedules and try again.');
    }

    $deleteId = filter_input(INPUT_POST, 'schedule_id', FILTER_VALIDATE_INT);

    if (!$deleteId || $deleteId < 1) {
        header('Location: schedules.php?success=invalid');
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE schedules
             SET archived_at = NOW()
             WHERE id = :id
               AND archived_at IS NULL'
        );

        $stmt->execute(['id' => $deleteId]);

        if ($stmt->rowCount() > 0) {
            header('Location: schedules.php?success=archived');
            exit;
        }

        header('Location: schedules.php?success=notfound');
        exit;
    } catch (PDOException $e) {
        error_log('Schedule archive error: ' . $e->getMessage());
        $error = 'Unable to archive this schedule. Please check the database and try again.';
    }
}

/* Retrieve active schedules and their optional resident names. */
$sql = "
    SELECT
        s.*,
        r.resident_id AS resident_code,
        r.first_name,
        r.middle_name,
        r.last_name
    FROM schedules s
    LEFT JOIN residents r ON r.id = s.resident_id
    WHERE s.archived_at IS NULL
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

if (in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= ' AND s.status = :status';
    $params['status'] = $statusFilter;
}

$sql .= ' ORDER BY s.schedule_date DESC, s.start_time DESC, s.id DESC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Schedule retrieval error: ' . $e->getMessage());
    $schedules = [];
    $error = 'Unable to load schedules. Please check the schedules and residents table structure.';
}

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* SIDEBAR LOGO (injected at top of sidebar) */
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
<?php elseif ($success === 'archived'): ?>
    <div class="alert alert-success">
        Schedule archived successfully. You can restore it from Archive &amp; Restore in the sidebar.
    </div>
<?php elseif ($success === 'notfound'): ?>
    <div class="alert alert-warning">
        This schedule was not found or has already been archived.
    </div>
<?php elseif ($success === 'invalid'): ?>
    <div class="alert alert-warning">Please select a valid schedule.</div>
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
            No active schedules found. Click Add Schedule to create one.
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

            $itemStatus = $item['status'] ?? 'Scheduled';

            $statusClass = match ($itemStatus) {
                'Completed' => 'status-completed',
                'Cancelled' => 'status-cancelled',
                default => 'status-scheduled'
            };

            $startTime = !empty($item['start_time'])
                ? strtotime($item['start_time'])
                : false;

            $endTime = !empty($item['end_time'])
                ? strtotime($item['end_time'])
                : false;
            ?>

            <div class="schedule-card">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h3 class="mb-1"><?= h($item['title'] ?? '') ?></h3>

                        <div class="schedule-meta">
                            Type: <?= h($item['schedule_type'] ?? '') ?>
                            &nbsp;|&nbsp;
                            Date: <?= h($item['schedule_date'] ?? '') ?>
                            &nbsp;|&nbsp;
                            Time:
                            <?= $startTime !== false ? h(date('g:i A', $startTime)) : 'Not specified' ?>

                            <?php if ($endTime !== false): ?>
                                – <?= h(date('g:i A', $endTime)) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <span class="schedule-status <?= h($statusClass) ?>">
                        <?= h($itemStatus) ?>
                    </span>
                </div>

                <div class="row g-2 mt-2">
                    <div class="col-md-6">
                        <div class="schedule-description">
                            <strong>Resident:</strong>

                            <?= h(
                                $residentName !== ''
                                    ? $residentName
                                    : (!empty($item['resident_id'])
                                        ? 'Resident not found'
                                        : 'Not assigned')
                            ) ?>

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
                       class="btn btn-schedule-view">
                        View
                    </a>

                    <a href="add_schedule.php?edit=<?= (int)$item['id'] ?>"
                       class="btn btn-schedule-edit">
                        Edit
                    </a>

                    <?php if ($isAdmin): ?>
                        <form method="POST"
                              action="schedules.php"
                              class="d-inline"
                              onsubmit="return confirm('Delete this schedule from the active list? It will be moved to Archive & Restore and can be restored later.');">

                            <input type="hidden" name="action" value="delete">

                            <input type="hidden"
                                   name="schedule_id"
                                   value="<?= (int)$item['id'] ?>">

                            <input type="hidden"
                                   name="csrf_token"
                                   value="<?= h($csrfToken) ?>">

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

<!-- Puts the logo at the top of the sidebar and hides the old text title -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector(
        '.sidebar, #sidebar, .sidebar-container, aside, nav'
    );

    if (!sidebar) {
        return;
    }

    // Hide the old text title and subtitle
    var oldTexts = ['home for the aged', 'care management system'];

    sidebar.querySelectorAll('*').forEach(function (el) {
        if (
            el.children.length === 0 &&
            oldTexts.indexOf(el.textContent.trim().toLowerCase()) !== -1
        ) {
            el.style.display = 'none';
        }
    });

    // Add the logo
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
});
</script>

</main>
</body>
</html>