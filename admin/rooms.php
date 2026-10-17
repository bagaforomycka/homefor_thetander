
<?php
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header("Location: /homefor_thetander/index.php");
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'staff'], true)) {
    http_response_code(403);
    exit("Access denied.");
}

require_once __DIR__ . '/../config/db.php';

$pdo = $pdo ?? $conn ?? null;

if (!$pdo instanceof PDO) {
    exit("Check your database connection in config/db.php.");
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['rooms_token'])) {
    $_SESSION['rooms_token'] = bin2hex(random_bytes(32));
}

$token = $_SESSION['rooms_token'];
$message = '';
$error = '';

$isAdmin = $_SESSION['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($token, $_POST['token'] ?? '')) {
            throw new RuntimeException("Session expired. Refresh the page.");
        }

        $action = $_POST['action'] ?? '';
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($action === 'save') {
            if (!$isAdmin) {
                throw new RuntimeException("Only administrators can add or edit rooms.");
            }

            $number = trim($_POST['room_number'] ?? '');
            $type = trim($_POST['room_type'] ?? '');
            $capacity = filter_input(INPUT_POST, 'capacity', FILTER_VALIDATE_INT);
            $status = trim($_POST['status'] ?? '');

            if ($number === '' || strlen($number) > 20) {
                throw new RuntimeException("Enter a room number up to 20 characters.");
            }

            if (!in_array($type, ['Standard', 'Private', 'Shared', 'Ward'], true)) {
                throw new RuntimeException("Invalid room type.");
            }

            if ($capacity === false || $capacity === null || $capacity < 1) {
                throw new RuntimeException("Capacity must be at least 1.");
            }

            if (!in_array($status, ['Available', 'Occupied', 'Maintenance'], true)) {
                throw new RuntimeException("Invalid room status.");
            }

            if ($id) {
                $stmt = $pdo->prepare(
                    "UPDATE rooms
                     SET room_number = ?, room_type = ?, capacity = ?, status = ?
                     WHERE id = ? AND archived_at IS NULL"
                );
                $stmt->execute([$number, $type, $capacity, $status, $id]);
                $message = $stmt->rowCount() > 0
                    ? "Room updated successfully."
                    : "No room was changed. Check that the room still exists.";
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO rooms (room_number, room_type, capacity, status)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->execute([$number, $type, $capacity, $status]);
                $message = "Room added successfully.";
            }

        } elseif ($action === 'archive' && $id) {
            if (!$isAdmin) {
                throw new RuntimeException("Only administrators can delete or archive rooms.");
            }

            $stmt = $pdo->prepare(
                "UPDATE rooms
                 SET archived_at = NOW()
                 WHERE id = ? AND archived_at IS NULL"
            );
            $stmt->execute([$id]);

            if ($stmt->rowCount() > 0) {
                $message = "Room archived successfully. You can restore it from the Archive & Restore module.";
            } else {
                $error = "The room was not found or has already been archived.";
            }

        } elseif ($action === 'view' && $id) {
            header("Location: rooms.php?view=" . $id);
            exit;

        } else {
            throw new RuntimeException("Invalid action.");
        }

    } catch (PDOException $ex) {
        error_log("Rooms module error: " . $ex->getMessage());

        $error = $ex->getCode() === '23000'
            ? "That room number already exists or conflicts with related records."
            : "A database error occurred. Check the rooms table and database connection.";

    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$viewId = filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT);
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

$rooms = [];
$counts = [
    'total' => 0,
    'Available' => 0,
    'Occupied' => 0,
    'Maintenance' => 0
];

$selectedRoom = null;

try {
    foreach (['Available', 'Occupied', 'Maintenance'] as $status) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM rooms
             WHERE archived_at IS NULL AND status = ?"
        );
        $stmt->execute([$status]);
        $counts[$status] = (int)$stmt->fetchColumn();
    }

    $counts['total'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM rooms WHERE archived_at IS NULL"
    )->fetchColumn();

    $sql = "SELECT *
            FROM rooms
            WHERE archived_at IS NULL";
    $params = [];

    if ($search !== '') {
        $sql .= " AND room_number LIKE ?";
        $params[] = '%' . $search . '%';
    }

    if (in_array($statusFilter, ['Available', 'Occupied', 'Maintenance'], true)) {
        $sql .= " AND status = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY room_number ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($viewId || $editId) {
        $selectedId = $viewId ?: $editId;

        $stmt = $pdo->prepare(
            "SELECT *
             FROM rooms
             WHERE id = ? AND archived_at IS NULL"
        );
        $stmt->execute([$selectedId]);
        $selectedRoom = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$selectedRoom) {
            $error = "The selected room could not be found.";
        }
    }

} catch (PDOException $ex) {
    error_log("Rooms loading error: " . $ex->getMessage());
    $error = "Unable to load rooms. Check the rooms table and its archived_at column.";
}

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.rooms-page {
    padding: 28px;
    max-width: 1400px;
    margin: auto;
    color: #24352e;
}
.rooms-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 24px;
}
.rooms-header h1 {
    color: #17472f;
    font-size: 28px;
    font-weight: 700;
}
.rooms-header p {
    color: #78867d;
    margin: 5px 0;
}
.room-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 22px;
}
.room-card {
    background: white;
    border: 1px solid #e0e9e3;
    border-radius: 12px;
    padding: 18px;
}
.room-card span {
    color: #718078;
    font-size: 13px;
}
.room-card h2 {
    color: #176b45;
    margin: 8px 0 0;
    font-size: 27px;
}
.room-btn {
    display: inline-block;
    padding: 9px 12px;
    border: 0;
    border-radius: 8px;
    text-decoration: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
}
.primary {
    background: #176b45;
    color: white;
}
.light {
    background: #edf3ef;
    color: #28513d;
}
.danger {
    background: #fff0ed;
    color: #a43b2d;
}
.success {
    background: #e5f5eb;
    color: #176b45;
}
.room-toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 16px;
    background: white;
    padding: 15px;
    border: 1px solid #e0e9e3;
    border-radius: 12px;
}
.room-toolbar input {
    flex: 1;
    min-width: 180px;
}
.room-toolbar input,
.room-toolbar select,
.room-form input,
.room-form select {
    padding: 10px;
    border: 1px solid #d5e0d8;
    border-radius: 7px;
}
.room-table-wrap {
    background: white;
    overflow-x: auto;
    border: 1px solid #e0e9e3;
    border-radius: 12px;
}
.room-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}
.room-table th {
    background: #f3f7f4;
    color: #63736a;
    text-align: left;
    padding: 14px;
}
.room-table td {
    padding: 14px;
    border-top: 1px solid #edf1ee;
}
.room-table td form {
    display: inline;
}
.room-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}
.room-status {
    display: inline-block;
    border-radius: 20px;
    padding: 5px 10px;
    font-size: 12px;
    font-weight: 700;
}
.Available {
    background: #e2f5e8;
    color: #197044;
}
.Occupied {
    background: #fff1d7;
    color: #8a5a00;
}
.Maintenance {
    background: #ffe8e5;
    color: #a43b2d;
}
.room-alert {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
}
.room-ok {
    background: #e5f5eb;
    color: #176b45;
}
.room-error {
    background: #fff0ed;
    color: #a43b2d;
}
.room-panel {
    background: white;
    border: 1px solid #e0e9e3;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 20px;
}
.room-form h2 {
    color: #17472f;
    margin-top: 0;
}
.room-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.room-fields label {
    display: block;
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: 600;
}
.room-fields input,
.room-fields select {
    width: 100%;
    box-sizing: border-box;
}
.room-form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 20px;
}
.room-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.room-detail {
    padding: 12px;
    background: #f6f9f7;
    border-radius: 8px;
}
.room-detail small {
    display: block;
    color: #718078;
    margin-bottom: 5px;
}
@media(max-width: 800px) {
    .room-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .rooms-page {
        padding: 15px;
    }
}
@media(max-width: 480px) {
    .room-fields,
    .room-detail-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="rooms-page">
    <div class="rooms-header">
        <div>
            <h1>Room Management</h1>
            <p>Manage room capacity, availability, and records.</p>
        </div>

        <?php if ($isAdmin && !$viewId && !$editId): ?>
            <button class="room-btn primary" type="button"
                    onclick="document.getElementById('roomFormPanel').scrollIntoView({behavior:'smooth'})">
                + Add Room
            </button>
        <?php endif; ?>
    </div>

    <?php if ($message !== ''): ?>
        <div class="room-alert room-ok"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="room-alert room-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($viewId && $selectedRoom): ?>
        <section class="room-panel">
            <h2>View Room Details</h2>

            <div class="room-detail-grid">
                <div class="room-detail">
                    <small>Room Number</small>
                    <strong><?= e($selectedRoom['room_number']) ?></strong>
                </div>
                <div class="room-detail">
                    <small>Room Type</small>
                    <strong><?= e($selectedRoom['room_type']) ?></strong>
                </div>
                <div class="room-detail">
                    <small>Capacity</small>
                    <strong><?= (int)$selectedRoom['capacity'] ?></strong>
                </div>
                <div class="room-detail">
                    <small>Status</small>
                    <strong><?= e($selectedRoom['status']) ?></strong>
                </div>
                <div class="room-detail">
                    <small>Created At</small>
                    <strong><?= e($selectedRoom['created_at'] ?? '—') ?></strong>
                </div>
            </div>

            <div class="room-form-actions">
                <a class="room-btn light" href="rooms.php">Back to Rooms</a>
                <?php if ($isAdmin): ?>
                    <a class="room-btn primary"
                       href="rooms.php?edit=<?= (int)$selectedRoom['id'] ?>">
                        Edit Room
                    </a>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($isAdmin && $editId && $selectedRoom): ?>
        <section class="room-panel room-form" id="roomFormPanel">
            <h2>Edit Room</h2>

            <form method="post">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)$selectedRoom['id'] ?>">

                <div class="room-fields">
                    <div>
                        <label for="editRoomNumber">Room Number</label>
                        <input id="editRoomNumber" name="room_number" maxlength="20"
                               value="<?= e($selectedRoom['room_number']) ?>" required>
                    </div>
                    <div>
                        <label for="editRoomType">Room Type</label>
                        <select id="editRoomType" name="room_type" required>
                            <?php foreach (['Standard', 'Private', 'Shared', 'Ward'] as $type): ?>
                                <option value="<?= e($type) ?>"
                                    <?= $selectedRoom['room_type'] === $type ? 'selected' : '' ?>>
                                    <?= e($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="editRoomCapacity">Capacity</label>
                        <input id="editRoomCapacity" type="number" name="capacity"
                               min="1" value="<?= (int)$selectedRoom['capacity'] ?>" required>
                    </div>
                    <div>
                        <label for="editRoomStatus">Status</label>
                        <select id="editRoomStatus" name="status" required>
                            <?php foreach (['Available', 'Occupied', 'Maintenance'] as $status): ?>
                                <option value="<?= e($status) ?>"
                                    <?= $selectedRoom['status'] === $status ? 'selected' : '' ?>>
                                    <?= e($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="room-form-actions">
                    <a class="room-btn light" href="rooms.php">Cancel</a>
                    <button class="room-btn primary" type="submit">Save Changes</button>
                </div>
            </form>
        </section>
    <?php elseif ($isAdmin && !$viewId): ?>
        <section class="room-panel room-form" id="roomFormPanel">
            <h2>Add Room</h2>

            <form method="post">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="save">

                <div class="room-fields">
                    <div>
                        <label for="roomNumber">Room Number</label>
                        <input id="roomNumber" name="room_number" maxlength="20" required>
                    </div>
                    <div>
                        <label for="roomType">Room Type</label>
                        <select id="roomType" name="room_type" required>
                            <option>Standard</option>
                            <option>Private</option>
                            <option>Shared</option>
                            <option>Ward</option>
                        </select>
                    </div>
                    <div>
                        <label for="roomCapacity">Capacity</label>
                        <input id="roomCapacity" type="number" name="capacity"
                               min="1" value="1" required>
                    </div>
                    <div>
                        <label for="roomStatus">Status</label>
                        <select id="roomStatus" name="status" required>
                            <option>Available</option>
                            <option>Occupied</option>
                            <option>Maintenance</option>
                        </select>
                    </div>
                </div>

                <div class="room-form-actions">
                    <button class="room-btn primary" type="submit">Save Room</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <div class="room-stats">
        <div class="room-card">
            <span>Active Rooms</span>
            <h2><?= $counts['total'] ?></h2>
        </div>
        <div class="room-card">
            <span>Available</span>
            <h2><?= $counts['Available'] ?></h2>
        </div>
        <div class="room-card">
            <span>Occupied</span>
            <h2><?= $counts['Occupied'] ?></h2>
        </div>
        <div class="room-card">
            <span>Maintenance</span>
            <h2><?= $counts['Maintenance'] ?></h2>
        </div>
    </div>

    <form class="room-toolbar" method="get">
        <input name="search" value="<?= e($search) ?>"
               placeholder="Search room number...">

        <select name="status">
            <option value="">All statuses</option>
            <?php foreach (['Available', 'Occupied', 'Maintenance'] as $status): ?>
                <option value="<?= e($status) ?>"
                    <?= $statusFilter === $status ? 'selected' : '' ?>>
                    <?= e($status) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="room-btn primary" type="submit">Search</button>
        <a class="room-btn light" href="rooms.php">Reset</a>
    </form>

    <div class="room-table-wrap">
        <table class="room-table">
            <thead>
                <tr>
                    <th>Room Number</th>
                    <th>Type</th>
                    <th>Capacity</th>
                    <th>Status</th>
                    <th>Date Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rooms): ?>
                    <tr>
                        <td colspan="6">No active rooms found.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rooms as $room): ?>
                    <tr>
                        <td><strong><?= e($room['room_number']) ?></strong></td>
                        <td><?= e($room['room_type']) ?></td>
                        <td><?= (int)$room['capacity'] ?></td>
                        <td>
                            <span class="room-status <?= e($room['status']) ?>">
                                <?= e($room['status']) ?>
                            </span>
                        </td>
                        <td><?= e($room['created_at'] ?? '—') ?></td>
                        <td>
                            <div class="room-actions">
                                <a class="room-btn light"
                                   href="rooms.php?view=<?= (int)$room['id'] ?>">
                                    View
                                </a>

                                <?php if ($isAdmin): ?>
                                    <a class="room-btn success"
                                       href="rooms.php?edit=<?= (int)$room['id'] ?>">
                                        Edit
                                    </a>

                                    <form method="post"
                                          onsubmit="return confirm('Delete this room? It will be moved to Archive & Restore and will not be permanently deleted.');">
                                        <input type="hidden" name="token"
                                               value="<?= e($token) ?>">
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="id"
                                               value="<?= (int)$room['id'] ?>">
                                        <button class="room-btn danger" type="submit">
                                            Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</main>
</body>
</html>
