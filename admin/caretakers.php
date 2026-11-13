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

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Generate CSRF token if not set
if (empty($_SESSION['caretakers_csrf'])) {
    $_SESSION['caretakers_csrf'] = bin2hex(random_bytes(32));
}

$errorMsg = '';
$successMsg = '';

// Handle Archive / Restore actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['caretakers_csrf'], $csrf)) {
        $errorMsg = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'archive' && $id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE caretakers SET archived_at = NOW(), status = 'Inactive' WHERE id = ?");
                $stmt->execute([$id]);
                $successMsg = 'Caretaker archived successfully.';
            } catch (PDOException $e) {
                $errorMsg = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Search and Filter Handling (Name-only search)
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$sql = "SELECT * FROM caretakers WHERE (archived_at IS NULL OR archived_at = '')";
$params = [];

if ($search !== '') {
    $sql .= " AND full_name LIKE ?";
    $params[] = "%$search%";
}

if ($status_filter !== '' && $status_filter !== 'All statuses') {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$caretakers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Caretaker Management | Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --green: #17683a;
            --green-dark: #13522e;
            --green-hover: #0f4b2a;
            --section-bg: #e8f5eb;
        }

        body {
            font-family: Arial, sans-serif;
            overflow-x: hidden;
            background-color: #f8f9fa;
        }

        .main-content {
            padding: 20px 28px 32px;
            min-height: 100vh;
        }

        .page-banner {
            background-color: var(--section-bg);
            border: 1px solid #d1e7dd;
            color: var(--green);
            border-radius: 12px;
            padding: 20px 28px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.03);
        }

        .page-banner h2 {
            font-size: 1.45rem;
            font-weight: 700;
            margin: 0 0 2px 0;
            color: var(--green);
        }

        .page-banner p {
            font-size: 0.85rem;
            color: #4b5563;
            margin: 0;
        }

        .content-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e3eee6;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.05);
            padding: 24px 28px;
            margin-bottom: 20px;
        }

        .form-label {
            font-weight: 700;
            color: var(--green);
            font-size: 0.85rem;
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #d1d5db;
            padding: 9px 12px;
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 0.2rem rgba(23, 104, 58, 0.18);
        }

        .table thead th {
            background-color: var(--section-bg) !important;
            color: var(--green);
            font-weight: 700;
            border-bottom: 2px solid #d1e7dd;
        }

        @media (max-width: 768px) {
            .main-content { padding: 14px; }
            .content-card { padding: 16px; }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <!-- Top Banner -->
    <div class="page-banner">
        <div>
            <h2>Caretaker Management</h2>
            <p>View and manage caretaker profiles and status.</p>
        </div>
        <div>
            <a href="add_caretaker.php" class="btn btn-success">
                + Add Caretaker
            </a>
        </div>
    </div>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <?= e($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <?= e($successMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search & Filter Card -->
    <div class="content-card">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label">Search by name</label>
                <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Enter name">
            </div>
            <div class="col-md-4">
                <label class="form-label">Filter by status</label>
                <select name="status" class="form-select">
                    <option value="">All statuses</option>
                    <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $status_filter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="On Leave" <?= $status_filter === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-success w-100">Search</button>
                <a href="caretakers.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <!-- Caretaker List Table Card -->
    <div class="content-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold m-0" style="color: var(--green);">Caretaker List</h5>
            <span class="text-muted small">Total: <?= count($caretakers) ?></span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>User Name</th>
                        <th>Contact</th>
                        <th>Position</th>
                        <th>Shift</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($caretakers)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No caretakers found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($caretakers as $row): ?>
                            <tr>
                                <td class="fw-bold"><?= e($row['full_name']) ?></td>
                                <td><?= e($row['username']) ?></td>
                                <td><?= e($row['contact'] ?: 'N/A') ?></td>
                                <td><?= e($row['position']) ?></td>
                                <td><?= e($row['shift']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $row['status'] === 'Active' ? 'success' : 'secondary' ?>">
                                        <?= e($row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <a href="view_profile_cs.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-success">View</a>
                                        <a href="edit_profile_cs.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-warning">Edit</a>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Archive this caretaker? You can restore them later from Archive & Restore.');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['caretakers_csrf']) ?>">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Archive</button>
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
</body>
</html>