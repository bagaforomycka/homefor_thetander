<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['caretakers_csrf'])) {
    $_SESSION['caretakers_csrf'] = bin2hex(random_bytes(32));
}

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$errorMsg = '';

/* Archive caretaker using POST, not a GET delete link. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
    $token = $_POST['csrf_token'] ?? '';
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if (!is_string($token) || !hash_equals($_SESSION['caretakers_csrf'], $token)) {
        $errorMsg = 'Security check failed. Please refresh the page and try again.';
    } elseif (!$id || $id < 1) {
        $errorMsg = 'Invalid caretaker record.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'UPDATE caretakers
                 SET archived_at = NOW()
                 WHERE id = :id
                   AND archived_at IS NULL'
            );
            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() > 0) {
                header('Location: caretakers_staff.php?msg=archived');
                exit;
            }

            $errorMsg = 'Caretaker not found or already archived.';
        } catch (PDOException $e) {
            error_log('Caretaker archive error: ' . $e->getMessage());
            $errorMsg = 'Could not archive this caretaker. Check the database table and archived_at column.';
        }
    }
}

/* Show active caretakers only. */
$records = [];

try {
    $sql = 'SELECT *
            FROM caretakers
            WHERE archived_at IS NULL';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (employee_id LIKE :search_id OR full_name LIKE :search_name)';
        $params['search_id'] = '%' . $search . '%';
        $params['search_name'] = '%' . $search . '%';
    }

    if ($status !== '') {
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }

    $sql .= ' ORDER BY id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Caretaker list error: ' . $e->getMessage());
    $errorMsg = 'Could not load caretakers. Check that the caretakers table has an archived_at column.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Caretaker Management | Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --green: #17683a;
            --light-green: #33b960;
            --pale-green: #edf7ef;
            --text: #304b3b;
        }

        body {
            background: #f8fbf8;
            color: var(--text);
            font-family: Arial, sans-serif;
            overflow-x: hidden;
        }

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

        .main-content {
            margin-left: 10px;
            padding: 24px 32px 32px 24px;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .04);
        }

        .btn-success {
            background: var(--green);
            border-color: var(--green);
        }

        .btn-success:hover {
            background: #0f4b2a;
            border-color: #0f4b2a;
        }

        .table thead th {
            background: var(--pale-green);
            color: var(--text);
            white-space: nowrap;
        }

        .table th, .table td {
            padding: 12px 14px;
            vertical-align: middle;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 18px 12px;
            }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1">Caretaker Management</h2>
            <p class="text-muted mb-0">View and manage caretaker profiles and status.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="dashboard.php" class="btn btn-outline-success">← Back to Dashboard</a>
            <a href="add_caretaker.php" class="btn btn-success">+ Add Caretaker</a>
        </div>
    </div>

    <?php if (($_GET['msg'] ?? '') === 'archived'): ?>
        <div class="alert alert-success">
            Caretaker archived successfully. You can restore the record from Archive &amp; Restore.
        </div>
    <?php endif; ?>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger"><?= e($errorMsg) ?></div>
    <?php endif; ?>

    <div class="card p-3 mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="search" class="form-label">Search by employee ID or name</label>
                <input
                    id="search"
                    type="search"
                    name="search"
                    class="form-control"
                    value="<?= e($search) ?>"
                    placeholder="Enter ID or name"
                >
            </div>

            <div class="col-md-4">
                <label for="status" class="form-label">Filter by status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All statuses</option>
                    <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="On Leave" <?= $status === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                    <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-success flex-fill">Search</button>
                <a href="caretakers_staff.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="fw-bold mb-0">Caretaker List</h5>
            <span class="text-muted small">Total: <?= count($records) ?></span>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Full Name</th>
                        <th>Contact</th>
                        <th>Position</th>
                        <th>Shift</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$records): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No active caretakers found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $row): ?>
                        <tr>
                            <td><?= e($row['employee_id'] ?? '') ?></td>
                            <td><?= e($row['full_name'] ?? '') ?></td>
                            <td><?= e($row['contact'] ?? '') ?></td>
                            <td><?= e($row['position'] ?? 'N/A') ?></td>
                            <td><?= e($row['shift'] ?? '') ?></td>
                            <td>
                                <?php
                                $badge = ($row['status'] ?? '') === 'Active'
                                    ? 'success'
                                    : ((($row['status'] ?? '') === 'On Leave') ? 'warning text-dark' : 'secondary');
                                ?>
                                <span class="badge bg-<?= e($badge) ?>">
                                    <?= e($row['status'] ?? '') ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <a
                                        href="view_profile_cs.php?tab=caretakers&amp;id=<?= (int)$row['id'] ?>"
                                        class="btn btn-sm btn-outline-info"
                                    >View</a>

                                    <a
                                        href="edit_profile_cs.php?tab=caretakers&amp;id=<?= (int)$row['id'] ?>"
                                        class="btn btn-sm btn-outline-primary"
                                    >Edit</a>

                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Archive this caretaker? The record will be moved to Archive & Restore and will no longer appear in this list.');">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['caretakers_csrf']) ?>">
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

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

</body>
</html>