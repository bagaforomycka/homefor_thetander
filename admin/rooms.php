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

/* Check and auto-add archived_at column if missing */
try {
    $columns = $pdo->query("SHOW COLUMNS FROM rooms")->fetchAll(PDO::FETCH_COLUMN);
    $hasArchivedAt = in_array('archived_at', $columns, true);
    
    if (!$hasArchivedAt) {
        $pdo->exec("ALTER TABLE rooms ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL");
        $hasArchivedAt = true;
    }
} catch (PDOException $ex) {
    $hasArchivedAt = false;
}

$error = '';
$success = '';

/* Add Room Request */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_room'])) {
    $roomNumber = trim($_POST['room_number'] ?? '');
    $roomType   = trim($_POST['room_type'] ?? 'Standard');
    $capacity   = filter_input(INPUT_POST, 'capacity', FILTER_VALIDATE_INT);
    $status     = trim($_POST['status'] ?? 'Available');

    if ($roomNumber === '' || !$capacity || $capacity < 1) {
        $error = 'Please enter a valid room number and capacity.';
    } else {
        try {
            /* Check if room already exists */
            $checkStmt = $pdo->prepare("SELECT id FROM rooms WHERE room_number = ?");
            $checkStmt->execute([$roomNumber]);
            if ($checkStmt->fetch()) {
                $error = 'Room number already exists.';
            } else {
                $insertSql = "INSERT INTO rooms (room_number, room_type, capacity, status) VALUES (?, ?, ?, ?)";
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute([$roomNumber, $roomType, $capacity, $status]);

                header("Location: rooms.php?success=added");
                exit;
            }
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $error = 'Failed to add room. Please check database configuration.';
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] === 'added') {
    $success = 'Room added successfully!';
}

/* Search and Filters */
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

/* Fetch Active Rooms */
$rooms = [];
try {
    $sql = "SELECT * FROM rooms WHERE 1=1";
    $params = [];

    if ($hasArchivedAt) {
        $sql .= " AND archived_at IS NULL";
    }

    if ($search !== '') {
        $sql .= " AND room_number LIKE ?";
        $params[] = '%' . $search . '%';
    }

    if ($filterStatus !== '') {
        $sql .= " AND status = ?";
        $params[] = $filterStatus;
    }

    $sql .= " ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Fetch Counts for Stats */
    $countSql = "SELECT status, COUNT(*) as cnt FROM rooms " . ($hasArchivedAt ? "WHERE archived_at IS NULL " : "") . "GROUP BY status";
    $countStmt = $pdo->query($countSql);
    $counts = [
        'Available' => 0,
        'Occupied' => 0,
        'Maintenance' => 0,
        'Total' => 0
    ];

    while ($row = $countStmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int)$row['cnt'];
        }
        $counts['Total'] += (int)$row['cnt'];
    }
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $error = 'Unable to load rooms. Please verify database structure.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Room Management | Home for the Aged</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f8f5;
            color: #304b3b;
            font-family: Arial, Helvetica, sans-serif;
            overflow-x: hidden;
        }

        /* SIDEBAR LOGO */
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

        /* app-main opened in sidebar.php */
        .app-main {
            margin-left: 250px !important;
            width: calc(100% - 250px) !important;
            max-width: none !important;
            min-width: 0;
            min-height: 100vh;
            padding: 16px 24px !important;
            margin-top: 0 !important;
            box-shadow: none !important;
            overflow-x: hidden;
        }

        .page-heading {
            margin-bottom: 16px;
        }

        .page-heading h1 {
            color: #17683a;
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 4px;
        }

        .page-heading p {
            color: #61796a;
            margin: 0;
            font-size: 13px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid #e3eee6;
            border-radius: 10px;
            padding: 14px 18px;
            box-shadow: 0 2px 10px rgba(24, 75, 42, .04);
        }

        .stat-card .stat-label {
            color: #61796a;
            font-size: 12px;
            font-weight: 600;
        }

        .stat-card .stat-value {
            color: #17683a;
            font-size: 24px;
            font-weight: 700;
            margin-top: 2px;
        }

        .panel {
            background: #fff;
            border: 1px solid #e3eee6;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 16px;
            box-shadow: 0 2px 10px rgba(24, 75, 42, .04);
        }

        .panel h2 {
            font-size: 17px;
            color: #17683a;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .form-label {
            font-weight: 600;
            color: #17683a;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .form-control,
        .form-select {
            border-radius: 6px;
            border: 1px solid #cedbd1;
            padding: 6px 12px;
            font-size: 13px;
            min-height: 38px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #33b960;
            box-shadow: 0 0 0 0.15rem rgba(51, 185, 96, 0.15);
        }

        .btn-success {
            background: #198754;
            border-color: #198754;
            color: #fff;
            font-weight: 600;
            border-radius: 6px;
            font-size: 13px;
            padding: 6px 16px;
        }

        .btn-success:hover {
            background: #157347;
            border-color: #146c43;
        }

        .table thead th {
            background: #edf8ef;
            color: #17683a;
            font-weight: 700;
            border-bottom: 1px solid #dcecdf;
            padding: 10px 14px;
            white-space: nowrap;
            font-size: 13px;
        }

        .table tbody td {
            padding: 10px 14px;
            vertical-align: middle;
            font-size: 13px;
        }

        .badge-available {
            background: #edf8ef;
            color: #19582a;
        }

        .badge-occupied {
            background: #eef2ff;
            color: #3554d1;
        }

        .badge-maintenance {
            background: #fff3e0;
            color: #b78103;
        }

        .sidebar-link.active {
            box-shadow: none !important;
            outline: none !important;
        }

        @media (max-width: 760px) {
            .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 10px !important;
            }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 page-heading">
    <div>
        <h1>Room Management</h1>
        <p>Manage room capacity, availability, and records.</p>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success py-2 px-3 mb-3 fs-6"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger py-2 px-3 mb-3 fs-6"><?= e($error) ?></div>
<?php endif; ?>

<!-- ADD ROOM FORM -->
<div class="panel">
    <h2>Add Room</h2>
    <form method="POST" action="rooms.php">
        <input type="hidden" name="add_room" value="1">
        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label for="room_number" class="form-label">Room Number *</label>
                <input type="text" id="room_number" name="room_number" class="form-control" placeholder="e.g. 101, A-1" required>
            </div>
            <div class="col-md-6">
                <label for="room_type" class="form-label">Room Type</label>
                <select id="room_type" name="room_type" class="form-select">
                    <option value="Standard">Standard</option>
                    <option value="Deluxe">Deluxe</option>
                    <option value="ICU / Medical">ICU / Medical</option>
                    <option value="Shared">Shared</option>
                </select>
            </div>
            <div class="col-md-6">
                <label for="capacity" class="form-label">Capacity *</label>
                <input type="number" id="capacity" name="capacity" class="form-control" value="1" min="1" required>
            </div>
            <div class="col-md-6">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="Available">Available</option>
                    <option value="Occupied">Occupied</option>
                    <option value="Maintenance">Maintenance</option>
                </select>
            </div>
        </div>
        <div class="text-end">
            <button type="submit" class="btn btn-success">Save Room</button>
        </div>
    </form>
</div>

<!-- STATS CARDS -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-label">Active Rooms</div>
            <div class="stat-value"><?= (int)($counts['Total'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-label">Available</div>
            <div class="stat-value"><?= (int)($counts['Available'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-label">Occupied</div>
            <div class="stat-value"><?= (int)($counts['Occupied'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-label">Maintenance</div>
            <div class="stat-value"><?= (int)($counts['Maintenance'] ?? 0) ?></div>
        </div>
    </div>
</div>

<!-- FILTER & SEARCH -->
<div class="panel">
    <form method="GET" action="rooms.php" class="row g-2 mb-3 align-items-end">
        <div class="col-md-7">
            <input type="search" name="search" class="form-control" placeholder="Search room number..." value="<?= e($search) ?>">
        </div>
        <div class="col-md-3">
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                <option value="Available" <?= $filterStatus === 'Available' ? 'selected' : '' ?>>Available</option>
                <option value="Occupied" <?= $filterStatus === 'Occupied' ? 'selected' : '' ?>>Occupied</option>
                <option value="Maintenance" <?= $filterStatus === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-success w-100">Search</button>
            <a href="rooms.php" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
    </form>

    <!-- ROOMS TABLE -->
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-hover">
            <thead>
                <tr>
                    <th>Room Number</th>
                    <th>Type</th>
                    <th>Capacity</th>
                    <th>Status</th>
                    <th>Date Added</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rooms)): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">No active rooms found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rooms as $row): ?>
                    <tr>
                        <td class="fw-bold"><?= e($row['room_number'] ?? '') ?></td>
                        <td><?= e($row['room_type'] ?? 'Standard') ?></td>
                        <td><?= e($row['capacity'] ?? '1') ?></td>
                        <td>
                            <?php 
                            $statusStr = $row['status'] ?? 'Available';
                            $badgeClass = match ($statusStr) {
                                'Occupied' => 'badge-occupied',
                                'Maintenance' => 'badge-maintenance',
                                default => 'badge-available',
                            };
                            ?>
                            <span class="badge <?= $badgeClass ?> px-2 py-1 fw-semibold">
                                <?= e($statusStr) ?>
                            </span>
                        </td>
                        <td class="small text-muted">
                            <?= !empty($row['created_at']) ? e(date('M d, Y', strtotime($row['created_at']))) : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SIDEBAR LOGO SCRIPT -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector(
        '.app-sidebar, .sidebar, #sidebar, .sidebar-container, aside, nav'
    );

    if (!sidebar) {
        return;
    }

    var oldTexts = ['home for the aged', 'care management system'];

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
            'alt="Home for the Aged - Care, Comfort, Dignity">' +
            '</a>';

        sidebar.insertBefore(logo, sidebar.firstChild);
    }
});
</script>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>