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

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Determine active tab from URL (?tab=staff or ?tab=caretakers)
$tab = $_GET['tab'] ?? 'caretakers';
if (!in_array($tab, ['caretakers', 'staff'], true)) {
    $tab = 'caretakers';
}

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

// Handle Delete Action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $deleteId = $_GET['id'];
    $targetTable = ($tab === 'staff') ? 'staff' : 'caretakers';
    try {
        $delStmt = $pdo->prepare("DELETE FROM {$targetTable} WHERE id = ?");
        $delStmt->execute([$deleteId]);
        header("Location: caretakers_staff.php?tab={$tab}&msg=deleted");
        exit;
    } catch (PDOException $e) {
        $errorMsg = "Could not delete record.";
    }
}

$records = [];
try {
    if ($tab === 'staff') {
        $sql = "SELECT * FROM staff WHERE 1=1";
    } else {
        $sql = "SELECT * FROM caretakers WHERE 1=1";
    }

    $params = [];

    if ($search !== '') {
        $sql .= " AND (employee_id LIKE ? OR full_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($status !== '') {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $records = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $tab === 'staff' ? 'Staff' : 'Caretaker' ?> Management | Home for the Aged</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-bg: #0d3b1e;
            --green: #17683a;
            --light-green: #33b960;
            --pale-green: #edf7ef;
            --text: #304b3b;
        }

        body {
            background-color: #f8fbf8;
            color: var(--text);
            font-family: Arial, sans-serif;
            overflow-x: hidden;
        }

        .main-content {
            margin-left: 10px;
            padding: 24px 32px 32px 24px;
            width: auto;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            width: 100%;
        }

        .btn-success {
            background-color: #17683a;
            border-color: #17683a;
            color: #ffffff;
        }

        .btn-success:hover {
            background-color: #0f4b2a;
            border-color: #0f4b2a;
            color: #ffffff;
        }

        .btn-outline-success {
            color: #17683a;
            border-color: #17683a;
            background-color: transparent;
        }

        .btn-outline-success:hover, .btn-check:checked + .btn-outline-success {
            background-color: #17683a;
            border-color: #17683a;
            color: #ffffff;
        }

        .table thead th {
            background-color: #edf7ef;
            color: #304b3b;
            font-weight: 600;
            border-bottom: 1px solid #e0eee3;
            padding: 12px 16px;
        }

        .table tbody td {
            padding: 14px 16px;
        }
    </style>
</head>
<body>

    <!-- Include Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        
        <!-- Header Controls -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="fw-bold mb-1"><?= $tab === 'staff' ? 'Staff Management' : 'Caretaker Management' ?></h2>
                <p class="text-muted mb-0">
                    <?= $tab === 'staff' ? 'View and manage administrative and support staff profiles.' : 'View and manage caretaker details, assignments, and schedules.' ?>
                </p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-outline-success me-2">&larr; Back to Dashboard</a>
                <a href="<?= $tab === 'staff' ? 'add_staff.php' : 'add_caretaker.php' ?>" class="btn btn-success">
                    <?= $tab === 'staff' ? '+ Add Staff' : '+ Add Caretaker' ?>
                </a>
            </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Record successfully deleted.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Mode Toggle Switch (Caretakers vs Staff) -->
        <div class="toggle-tabs mb-4">
            <div class="btn-group" role="group">
                <a href="caretakers_staff.php?tab=caretakers" class="btn btn-outline-success <?= $tab === 'caretakers' ? 'active' : '' ?>">Caretakers</a>
                <a href="caretakers_staff.php?tab=staff" class="btn btn-outline-success <?= $tab === 'staff' ? 'active' : '' ?>">Staff</a>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card p-3 mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <div class="col-md-5">
                    <label class="form-label text-muted small mb-1">Search by <?= $tab === 'staff' ? 'staff' : 'caretaker' ?> ID or name</label>
                    <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Enter ID or name">
                </div>
                <div class="col-md-4">
                    <label class="form-label text-muted small mb-1">Filter by status</label>
                    <select name="status" class="form-select">
                        <option value="">All statuses</option>
                        <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="On Leave" <?= $status === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                        <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-success flex-fill">Search</button>
                    <a href="caretakers_staff.php?tab=<?= e($tab) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>

        <!-- Table Card -->
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0"><?= $tab === 'staff' ? 'Staff List' : 'Caretaker List' ?></h5>
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
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No <?= e($tab) ?> found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $row): ?>
                                <tr>
                                    <td><?= e($row['employee_id']) ?></td>
                                    <td><?= e($row['full_name']) ?></td>
                                    <td><?= e($row['contact']) ?></td>
                                    <td><?= e($row['position'] ?? 'N/A') ?></td>
                                    <td><?= e($row['shift']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $row['status'] === 'Active' ? 'success' : ($row['status'] === 'On Leave' ? 'warning' : 'secondary') ?>">
                                            <?= e($row['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <a href="view_profile_cs.php?tab=<?= e($tab) ?>&id=<?= e($row['id']) ?>" class="btn btn-sm btn-outline-info" title="View">View</a>
                                            <a href="edit_profile_cs.php?tab=<?= e($tab) ?>&id=<?= e($row['id']) ?>" class="btn btn-sm btn-outline-primary" title="Edit">Edit</a>
                                            <a href="caretakers_staff.php?tab=<?= e($tab) ?>&action=delete&id=<?= e($row['id']) ?>" 
                                               class="btn btn-sm btn-outline-danger" 
                                               title="Delete" 
                                               onclick="return confirm('Are you sure you want to delete this record?');">Delete</a>
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

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>