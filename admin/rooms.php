
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /homefor_thetander/index.php");
    exit;
}

if (!in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    http_response_code(403);
    exit("Access denied.");
}

require_once __DIR__ . '/../config/db.php';

$pdo = $pdo ?? $conn ?? null;

if (!$pdo instanceof PDO) {
    exit("Check your database connection in config/db.php.");
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['rooms_token'])) {
    $_SESSION['rooms_token'] = bin2hex(random_bytes(32));
}

$token = $_SESSION['rooms_token'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($token, $_POST['token'] ?? '')) {
            throw new RuntimeException("Session expired. Refresh the page.");
        }

        $action = $_POST['action'] ?? '';
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($action === 'save') {
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

            if (!$capacity || $capacity < 1) {
                throw new RuntimeException("Capacity must be at least 1.");
            }

            if (!in_array($status, ['Available', 'Occupied', 'Maintenance'], true)) {
                throw new RuntimeException("Invalid room status.");
            }

            if ($id) {
                $stmt = $pdo->prepare(
                    "UPDATE rooms
                     SET room_number=?, room_type=?, capacity=?, status=?
                     WHERE id=? AND archived_at IS NULL"
                );
                $stmt->execute([$number, $type, $capacity, $status, $id]);
                $message = "Room updated successfully.";
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO rooms (room_number, room_type, capacity, status)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->execute([$number, $type, $capacity, $status]);
                $message = "Room added successfully.";
            }
        } elseif ($action === 'archive' && $id) {
            $stmt = $pdo->prepare(
                "UPDATE rooms SET archived_at=NOW()
                 WHERE id=? AND archived_at IS NULL"
            );
            $stmt->execute([$id]);
            $message = "Room archived successfully.";
        } elseif ($action === 'restore' && $id) {
            $stmt = $pdo->prepare(
                "UPDATE rooms SET archived_at=NULL
                 WHERE id=? AND archived_at IS NOT NULL"
            );
            $stmt->execute([$id]);
            $message = "Room restored successfully.";
        }
    } catch (PDOException $ex) {
        $error = $ex->getCode() === '23000'
            ? "That room number already exists."
            : "Database error. Check your database connection.";
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$archived = ($_GET['archived'] ?? '') === '1';
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

try {
    $counts = [];
    foreach (['Available', 'Occupied', 'Maintenance'] as $s) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM rooms
             WHERE archived_at IS NULL AND status=?"
        );
        $stmt->execute([$s]);
        $counts[$s] = (int)$stmt->fetchColumn();
    }

    $counts['total'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM rooms WHERE archived_at IS NULL"
    )->fetchColumn();

    $counts['archived'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM rooms WHERE archived_at IS NOT NULL"
    )->fetchColumn();

    $sql = "SELECT * FROM rooms WHERE " .
        ($archived ? "archived_at IS NOT NULL" : "archived_at IS NULL");
    $params = [];

    if ($search !== '') {
        $sql .= " AND room_number LIKE ?";
        $params[] = "%$search%";
    }

    if (!$archived &&
        in_array($statusFilter, ['Available', 'Occupied', 'Maintenance'], true)) {
        $sql .= " AND status=?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY room_number ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    $rooms = [];
    $counts = [
        'total' => 0, 'Available' => 0,
        'Occupied' => 0, 'Maintenance' => 0, 'archived' => 0
    ];
    $error = "Unable to load rooms. Check the rooms table.";
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
.rooms-header h1 { color: #17472f; font-size: 28px; font-weight: 700; }
.rooms-header p { color: #78867d; margin: 5px 0; }
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
.room-card span { color: #718078; font-size: 13px; }
.room-card h2 { color: #176b45; margin: 8px 0 0; font-size: 27px; }
.room-btn {
    display: inline-block;
    padding: 10px 14px;
    border: 0;
    border-radius: 8px;
    text-decoration: none;
    cursor: pointer;
    font-weight: 600;
}
.primary { background: #176b45; color: white; }
.light { background: #edf3ef; color: #28513d; }
.danger { background: #fff0ed; color: #a43b2d; }
.success { background: #e5f5eb; color: #176b45; }
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
.room-toolbar input { flex: 1; min-width: 180px; }
.room-toolbar input, .room-toolbar select,
.room-form input, .room-form select {
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
.room-table { width: 100%; border-collapse: collapse; min-width: 750px; }
.room-table th {
    background: #f3f7f4;
    color: #63736a;
    text-align: left;
    padding: 14px;
}
.room-table td { padding: 14px; border-top: 1px solid #edf1ee; }
.room-table td form { display: inline; }
.room-status {
    display: inline-block;
    border-radius: 20px;
    padding: 5px 10px;
    font-size: 12px;
    font-weight: 700;
}
.Available { background: #e2f5e8; color: #197044; }
.Occupied { background: #fff1d7; color: #8a5a00; }
.Maintenance { background: #ffe8e5; color: #a43b2d; }
.room-alert { padding: 12px; border-radius: 8px; margin-bottom: 15px; }
.room-ok { background: #e5f5eb; color: #176b45; }
.room-error { background: #fff0ed; color: #a43b2d; }
.room-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 3000;
    background: #0008;
    align-items: center;
    justify-content: center;
    padding: 15px;
}
.room-modal.open { display: flex; }
.room-form {
    background: white;
    padding: 24px;
    border-radius: 14px;
    width: 100%;
    max-width: 500px;
}
.room-form h2 { color: #17472f; margin-top: 0; }
.room-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.room-fields label { display: block; margin-bottom: 6px; font-size: 13px; }
.room-fields input, .room-fields select { width: 100%; box-sizing: border-box; }
.room-form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
@media(max-width: 800px) {
    .room-stats { grid-template-columns: repeat(2, 1fr); }
    .rooms-page { padding: 15px; }
}
@media(max-width: 480px) {
    .room-fields { grid-template-columns: 1fr; }
}
</style>

<div class="rooms-page">
    <div class="rooms-header">
        <div>
            <h1>Room Management</h1>
            <p>Manage room capacity, availability, and records.</p>
        </div>
        <div>
            <?php if ($archived): ?>
                <a class="room-btn light" href="rooms.php">Active Rooms</a>
            <?php else: ?>
                <a class="room-btn light" href="rooms.php?archived=1">
                    Archived (<?= $counts['archived'] ?>)
                </a>
                <button class="room-btn primary" onclick="openRoom()">+ Add Room</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="room-alert room-ok"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="room-alert room-error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="room-stats">
        <div class="room-card"><span>Active Rooms</span><h2><?= $counts['total'] ?></h2></div>
        <div class="room-card"><span>Available</span><h2><?= $counts['Available'] ?></h2></div>
        <div class="room-card"><span>Occupied</span><h2><?= $counts['Occupied'] ?></h2></div>
        <div class="room-card"><span>Maintenance</span><h2><?= $counts['Maintenance'] ?></h2></div>
    </div>

    <form class="room-toolbar" method="get">
        <?php if ($archived): ?>
            <input type="hidden" name="archived" value="1">
        <?php endif; ?>
        <input name="search" value="<?= e($search) ?>" placeholder="Search room number...">
        <?php if (!$archived): ?>
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach (['Available', 'Occupied', 'Maintenance'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>>
                        <?= e($s) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button class="room-btn primary">Search</button>
        <a class="room-btn light" href="<?= $archived ? 'rooms.php?archived=1' : 'rooms.php' ?>">Reset</a>
    </form>

    <div class="room-table-wrap">
        <table class="room-table">
            <thead>
                <tr>
                    <th>Room Number</th><th>Type</th><th>Capacity</th>
                    <th>Status</th><th>Date</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rooms): ?>
                <tr><td colspan="6">No rooms found.</td></tr>
            <?php endif; ?>

            <?php foreach ($rooms as $room): ?>
                <tr>
                    <td><strong><?= e($room['room_number']) ?></strong></td>
                    <td><?= e($room['room_type']) ?></td>
                    <td><?= (int)$room['capacity'] ?></td>
                    <td><span class="room-status <?= e($room['status']) ?>">
                        <?= e($room['status']) ?>
                    </span></td>
                    <td><?= e($archived ? $room['archived_at'] : $room['created_at']) ?></td>
                    <td>
                    <?php if ($archived): ?>
                        <form method="post" onsubmit="return confirm('Restore this room?')">
                            <input type="hidden" name="token" value="<?= e($token) ?>">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="id" value="<?= (int)$room['id'] ?>">
                            <button class="room-btn success">Restore</button>
                        </form>
                    <?php else: ?>
                        <button class="room-btn light" onclick='editRoom(<?= json_encode([
                            'id' => (int)$room['id'],
                            'room_number' => $room['room_number'],
                            'room_type' => $room['room_type'],
                            'capacity' => (int)$room['capacity'],
                            'status' => $room['status']
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                            Edit
                        </button>
                        <form method="post" onsubmit="return confirm('Archive this room?')">
                            <input type="hidden" name="token" value="<?= e($token) ?>">
                            <input type="hidden" name="action" value="archive">
                            <input type="hidden" name="id" value="<?= (int)$room['id'] ?>">
                            <button class="room-btn danger">Archive</button>
                        </form>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="room-modal" id="roomModal">
    <form class="room-form" method="post">
        <h2 id="roomTitle">Add Room</h2>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="roomId">

        <div class="room-fields">
            <div>
                <label for="roomNumber">Room Number</label>
                <input id="roomNumber" name="room_number" maxlength="20" required>
            </div>
            <div>
                <label for="roomType">Room Type</label>
                <select id="roomType" name="room_type">
                    <option>Standard</option><option>Private</option>
                    <option>Shared</option><option>Ward</option>
                </select>
            </div>
            <div>
                <label for="roomCapacity">Capacity</label>
                <input id="roomCapacity" type="number" name="capacity" min="1" value="1" required>
            </div>
            <div>
                <label for="roomStatus">Status</label>
                <select id="roomStatus" name="status">
                    <option>Available</option><option>Occupied</option>
                    <option>Maintenance</option>
                </select>
            </div>
        </div>

        <div class="room-form-actions">
            <button type="button" class="room-btn light" onclick="closeRoom()">Cancel</button>
            <button class="room-btn primary">Save Room</button>
        </div>
    </form>
</div>

<script>
const roomModal = document.getElementById('roomModal');

function openRoom() {
    roomModal.querySelector('form').reset();
    document.getElementById('roomId').value = '';
    document.getElementById('roomCapacity').value = 1;
    document.getElementById('roomTitle').textContent = 'Add Room';
    roomModal.classList.add('open');
}

function editRoom(room) {
    document.getElementById('roomId').value = room.id;
    document.getElementById('roomNumber').value = room.room_number;
    document.getElementById('roomType').value = room.room_type;
    document.getElementById('roomCapacity').value = room.capacity;
    document.getElementById('roomStatus').value = room.status;
    document.getElementById('roomTitle').textContent = 'Edit Room';
    roomModal.classList.add('open');
}

function closeRoom() {
    roomModal.classList.remove('open');
}

roomModal.addEventListener('click', function(event) {
    if (event.target === roomModal) closeRoom();
});
</script>
</main>
</body>
</html>
