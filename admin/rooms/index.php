
<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';

$error = '';
$success = '';

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$roomTypes = ['Standard', 'Private', 'Shared', 'Deluxe'];
$statuses = ['Available', 'Occupied', 'Maintenance'];

$editRoom = null;
$rooms = [];
$totalRooms = 0;
$availableRooms = 0;
$occupiedRooms = 0;
$maintenanceRooms = 0;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $roomNumber = trim($_POST['room_number'] ?? '');
            $roomType = $_POST['room_type'] ?? 'Standard';
            $capacity = (int) ($_POST['capacity'] ?? 1);
            $status = $_POST['status'] ?? 'Available';

            if ($roomNumber === '') {
                throw new RuntimeException('Please enter a room number.');
            }

            if (!in_array($roomType, $roomTypes, true)) {
                throw new RuntimeException('Please select a valid room type.');
            }

            if (!in_array($status, $statuses, true)) {
                throw new RuntimeException('Please select a valid room status.');
            }

            if ($capacity < 1) {
                throw new RuntimeException('Capacity must be at least 1.');
            }

            if ($action === 'add') {
                $stmt = $pdo->prepare(
                    'INSERT INTO rooms (room_number, room_type, capacity, status)
                     VALUES (?, ?, ?, ?)'
                );

                $stmt->execute([
                    $roomNumber,
                    $roomType,
                    $capacity,
                    $status
                ]);

                header('Location: index.php?success=added');
                exit;
            }

            if ($id < 1) {
                throw new RuntimeException('Invalid room ID.');
            }

            $stmt = $pdo->prepare(
                'UPDATE rooms
                 SET room_number = ?, room_type = ?, capacity = ?, status = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $roomNumber,
                $roomType,
                $capacity,
                $status,
                $id
            ]);

            header('Location: index.php?success=updated');
            exit;
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id < 1) {
                throw new RuntimeException('Invalid room ID.');
            }

            $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
            $stmt->execute([$id]);

            header('Location: index.php?success=deleted');
            exit;
        }
    }

    if (isset($_GET['edit'])) {
        $id = (int) $_GET['edit'];

        $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
        $stmt->execute([$id]);
        $editRoom = $stmt->fetch();

        if (!$editRoom) {
            $error = 'Room not found.';
        }
    }

    $search = trim($_GET['search'] ?? '');
    $filterStatus = $_GET['status'] ?? '';

    $sql = 'SELECT * FROM rooms WHERE 1=1';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (room_number LIKE ? OR room_type LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    if (
        $filterStatus !== '' &&
        in_array($filterStatus, $statuses, true)
    ) {
        $sql .= ' AND status = ?';
        $params[] = $filterStatus;
    }

    $sql .= ' ORDER BY room_number ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll();

    $totalRooms = (int) $pdo->query(
        'SELECT COUNT(*) FROM rooms'
    )->fetchColumn();

    $availableRooms = (int) $pdo->query(
        "SELECT COUNT(*) FROM rooms WHERE status = 'Available'"
    )->fetchColumn();

    $occupiedRooms = (int) $pdo->query(
        "SELECT COUNT(*) FROM rooms WHERE status = 'Occupied'"
    )->fetchColumn();

    $maintenanceRooms = (int) $pdo->query(
        "SELECT COUNT(*) FROM rooms WHERE status = 'Maintenance'"
    )->fetchColumn();

} catch (PDOException $ex) {
    if ($ex->getCode() === '23000') {
        $error = 'That room number already exists. Please use another one.';
    } else {
        error_log($ex->getMessage());
        $error = 'A database error occurred. Check your database and rooms table.';
    }
} catch (RuntimeException $ex) {
    $error = $ex->getMessage();
}

if (isset($_GET['success'])) {
    $messages = [
        'added' => 'Room added successfully.',
        'updated' => 'Room updated successfully.',
        'deleted' => 'Room deleted successfully.'
    ];

    $success = $messages[$_GET['success']] ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rooms</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f4f6f9;
            color: #263238;
            font-family: Arial, sans-serif;
        }

        .page-header {
            margin-bottom: 25px;
        }

        .page-header h2 {
            font-weight: 700;
            color: #263238;
        }

        .stat-card {
            background: #fff;
            border: 1px solid #e1e5e9;
            border-radius: 10px;
            padding: 20px;
            height: 100%;
        }

        .stat-card h3 {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-card p {
            color: #59636e;
            margin: 0;
        }

        .content-card {
            background: #fff;
            border: 1px solid #e1e5e9;
            border-radius: 10px;
            padding: 22px;
            margin-top: 22px;
        }

        .table th {
            background: #e9f0eb;
            color: #26382b;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .quick-actions {
            background: #198754;
            color: white;
            border: none;
        }

        .quick-actions:hover {
            background: #146c43;
            color: white;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-available {
            background: #d1e7dd;
            color: #0f5132;
        }

        .status-occupied {
            background: #fff3cd;
            color: #664d03;
        }

        .status-maintenance {
            background: #f8d7da;
            color: #842029;
        }
    </style>
</head>

<body>
<div class="container-fluid p-4">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h2>Manage Rooms</h2>
            <p class="text-secondary mb-0">
                Add, update, and monitor facility rooms.
            </p>
        </div>

        <!-- Back to Dashboard and Add Room buttons -->
        <div class="d-flex flex-wrap gap-2">
            <a href="../dashboard.php" class="btn quick-actions">
                &larr; Back to Dashboard
            </a>

            <a href="#room-form" class="btn quick-actions">
                + Add Room
            </a>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
                <p>Total Rooms</p>
                <h3 class="text-success"><?= $totalRooms ?></h3>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
                <p>Available</p>
                <h3 class="text-success"><?= $availableRooms ?></h3>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
                <p>Occupied</p>
                <h3 class="text-success"><?= $occupiedRooms ?></h3>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
                <p>Maintenance</p>
                <h3 class="text-success"><?= $maintenanceRooms ?></h3>
            </div>
        </div>
    </div>

    <div class="content-card" id="room-form">
        <h5 class="mb-3">
            <?= $editRoom ? 'Edit Room' : 'Add New Room' ?>
        </h5>

        <form method="POST" action="index.php">
            <input
                type="hidden"
                name="action"
                value="<?= $editRoom ? 'update' : 'add' ?>"
            >

            <?php if ($editRoom): ?>
                <input
                    type="hidden"
                    name="id"
                    value="<?= (int) $editRoom['id'] ?>"
                >
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label">Room Number</label>
                    <input
                        type="text"
                        name="room_number"
                        class="form-control"
                        maxlength="20"
                        required
                        value="<?= e($editRoom['room_number'] ?? '') ?>"
                        placeholder="e.g. Room 101"
                    >
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label">Room Type</label>
                    <select name="room_type" class="form-select" required>
                        <?php foreach ($roomTypes as $type): ?>
                            <option
                                value="<?= e($type) ?>"
                                <?= ($editRoom['room_type'] ?? 'Standard') === $type ? 'selected' : '' ?>
                            >
                                <?= e($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label">Capacity</label>
                    <input
                        type="number"
                        name="capacity"
                        class="form-control"
                        min="1"
                        required
                        value="<?= (int) ($editRoom['capacity'] ?? 1) ?>"
                    >
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" required>
                        <?php foreach ($statuses as $status): ?>
                            <option
                                value="<?= e($status) ?>"
                                <?= ($editRoom['status'] ?? 'Available') === $status ? 'selected' : '' ?>
                            >
                                <?= e($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn quick-actions w-100">
                        <?= $editRoom ? 'Update' : 'Add Room' ?>
                    </button>

                    <?php if ($editRoom): ?>
                        <a href="index.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <div class="content-card">
        <h5 class="mb-3">Room Records</h5>

        <form method="GET" action="index.php" class="row g-2 mb-3">
            <div class="col-12 col-md-6">
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Search room number or type..."
                    value="<?= e($_GET['search'] ?? '') ?>"
                >
            </div>

            <div class="col-12 col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <?php foreach ($statuses as $status): ?>
                        <option
                            value="<?= e($status) ?>"
                            <?= ($_GET['status'] ?? '') === $status ? 'selected' : '' ?>
                        >
                            <?= e($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-1">
                <button type="submit" class="btn btn-success w-100">Search</button>
            </div>

            <div class="col-6 col-md-2">
                <a href="index.php" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>Room Number</th>
                        <th>Room Type</th>
                        <th>Capacity</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($rooms)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4">
                            No rooms found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rooms as $room): ?>
                        <tr>
                            <td><?= e($room['room_number']) ?></td>
                            <td><?= e($room['room_type']) ?></td>
                            <td><?= (int) $room['capacity'] ?></td>
                            <td>
                                <span class="status status-<?= strtolower(e($room['status'])) ?>">
                                    <?= e($room['status']) ?>
                                </span>
                            </td>
                            <td><?= e($room['created_at']) ?></td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a
                                        href="index.php?edit=<?= (int) $room['id'] ?>#room-form"
                                        class="btn btn-sm btn-primary"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="index.php"
                                        onsubmit="return confirm('Are you sure you want to delete this room?');"
                                    >
                                        <input type="hidden" name="action" value="delete">
                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $room['id'] ?>"
                                        >

                                        <button type="submit" class="btn btn-sm btn-danger">
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
</body>
</html>