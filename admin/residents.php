<?php
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: ../index.php');
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'staff'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/../config/db.php';

$isAdmin = $_SESSION['role'] === 'admin';

if (empty($_SESSION['resident_csrf'])) {
    $_SESSION['resident_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['resident_csrf'];
$message = '';
$error = '';
$action = $_GET['action'] ?? '';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function goToResidents(string $message): never
{
    header('Location: residents.php?message=' . urlencode($message));
    exit;
}

function getResident(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM residents WHERE id = ?');
    $stmt->execute([$id]);
    $resident = $stmt->fetch();

    return $resident ?: null;
}

/* Handle edit and delete submissions. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) || !hash_equals($csrf, $postedToken)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }

    $postAction = $_POST['action'] ?? '';
    $residentId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;

    if (!$isAdmin) {
        http_response_code(403);
        exit('Only administrators can edit or delete residents.');
    }

    if ($residentId < 1) {
        $error = 'Invalid resident ID.';
        $action = '';
    } elseif ($postAction === 'delete') {
        try {
            $stmt = $pdo->prepare('DELETE FROM residents WHERE id = ?');
            $stmt->execute([$residentId]);

            if ($stmt->rowCount() > 0) {
                goToResidents('deleted');
            }

            $error = 'Resident not found.';
            $action = '';
        } catch (PDOException $e) {
            error_log('Resident deletion failed: ' . $e->getMessage());
            $error = 'This resident could not be deleted. They may have linked health records. Check the linked records before trying again.';
            $action = '';
        }
    } elseif ($postAction === 'update') {
        $residentIdValue = trim($_POST['resident_id'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $middleName = trim($_POST['middle_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $dob = trim($_POST['date_of_birth'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $room = trim($_POST['room_number'] ?? '');
        $admission = trim($_POST['admission_date'] ?? '');
        $careLevel = trim($_POST['care_level'] ?? '');
        $caretaker = trim($_POST['assigned_caretaker'] ?? '');
        $status = trim($_POST['status'] ?? '');

        if ($residentIdValue === '' || $firstName === '' || $lastName === '') {
            $error = 'Resident ID, first name, and last name are required.';
            $action = 'edit';
            $id = $residentId;
        } elseif (!in_array($status, ['active', 'inactive', 'discharged'], true)) {
            $error = 'Please select a valid resident status.';
            $action = 'edit';
            $id = $residentId;
        } elseif ($gender !== '' && !in_array($gender, ['Male', 'Female', 'Other'], true)) {
            $error = 'Please select a valid gender.';
            $action = 'edit';
            $id = $residentId;
        } elseif ($careLevel !== '' && !in_array($careLevel, ['Low', 'Medium', 'High'], true)) {
            $error = 'Please select a valid care level.';
            $action = 'edit';
            $id = $residentId;
        } else {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE residents SET
                        resident_id = :resident_id,
                        first_name = :first_name,
                        middle_name = :middle_name,
                        last_name = :last_name,
                        date_of_birth = :dob,
                        gender = :gender,
                        contact_number = :contact,
                        address = :address,
                        room_number = :room,
                        admission_date = :admission,
                        care_level = :care_level,
                        assigned_caretaker = :caretaker,
                        status = :status
                     WHERE id = :id"
                );

                $stmt->execute([
                    'resident_id' => $residentIdValue,
                    'first_name' => $firstName,
                    'middle_name' => $middleName !== '' ? $middleName : null,
                    'last_name' => $lastName,
                    'dob' => $dob !== '' ? $dob : null,
                    'gender' => $gender !== '' ? $gender : null,
                    'contact' => $contact !== '' ? $contact : null,
                    'address' => $address !== '' ? $address : null,
                    'room' => $room !== '' ? $room : null,
                    'admission' => $admission !== '' ? $admission : null,
                    'care_level' => $careLevel !== '' ? $careLevel : null,
                    'caretaker' => $caretaker !== '' ? $caretaker : null,
                    'status' => $status,
                    'id' => $residentId
                ]);

                goToResidents('updated');
            } catch (PDOException $e) {
                error_log('Resident update failed: ' . $e->getMessage());
                $error = 'Unable to save changes. Check that the Resident ID is unique and that the information is valid.';
                $action = 'edit';
                $id = $residentId;
            }
        }
    }
}

if (isset($_GET['message'])) {
    $message = match ($_GET['message']) {
        'updated' => 'Resident updated successfully.',
        'deleted' => 'Resident deleted successfully.',
        default => ''
    };
}

/* Load resident for View or Edit. */
$resident = null;

if (in_array($action, ['view', 'edit'], true)) {
    if ($id < 1 || !($resident = getResident($pdo, $id))) {
        $error = 'Resident not found.';
        $action = '';
    } elseif ($action === 'edit' && !$isAdmin) {
        http_response_code(403);
        exit('Only administrators can edit residents.');
    }
}

/* Search and status filtering for the list. */
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$residents = [];

if ($action === '') {
    $sql = 'SELECT * FROM residents WHERE 1=1';
    $params = [];

    if ($search !== '') {
        $sql .= " AND (
            resident_id LIKE :rid
            OR first_name LIKE :first
            OR middle_name LIKE :middle
            OR last_name LIKE :last
        )";

        $term = '%' . $search . '%';
        $params = [
            'rid' => $term,
            'first' => $term,
            'middle' => $term,
            'last' => $term
        ];
    }

    if (in_array($statusFilter, ['active', 'inactive', 'discharged'], true)) {
        $sql .= ' AND status = :status';
        $params['status'] = $statusFilter;
    }

    $sql .= ' ORDER BY last_name ASC, first_name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $residents = $stmt->fetchAll();
}

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.page-heading {
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:15px;
    margin-bottom:24px;
}
.page-heading h1 {font-size:28px;font-weight:700;margin-bottom:6px}
.page-subtitle {color:#64748b;margin:0}
.content-card {
    background:#fff;
    border-radius:14px;
    padding:22px;
    margin-bottom:20px;
    box-shadow:0 5px 18px rgba(0,0,0,.05);
}
.resident-table {width:100%;border-collapse:collapse}
.resident-table th,.resident-table td {
    padding:11px 8px;
    border-bottom:1px solid #dee5e8;
    text-align:left;
    vertical-align:middle;
}
.resident-table th {background:#f0f7f2;white-space:nowrap}
.table-wrapper {overflow-x:auto}
.action-buttons {display:flex;flex-wrap:wrap;gap:6px}
.btn-view,.btn-edit,.btn-delete {
    display:inline-block;
    background:#fff;
    border-radius:6px;
    padding:5px 10px;
    font-size:13px;
    text-decoration:none;
    cursor:pointer;
}
.btn-view {color:#198754;border:1px solid #198754}
.btn-view:hover {background:#eaf6ed;color:#146c43}
.btn-edit {color:#725800;border:1px solid #e6c84f}
.btn-edit:hover {background:#fff9df;color:#463900}
.btn-delete {color:#b42318;border:1px solid #dc3545}
.btn-delete:hover {background:#fff0f0;color:#8b1c13}
.status-badge {
    display:inline-block;
    background:#e5e7eb;
    color:#374151;
    border-radius:5px;
    padding:3px 8px;
    font-size:12px;
    font-weight:700;
}
.detail-grid {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:18px;
}
.detail-item {padding:12px;background:#f7faf8;border-radius:8px;overflow-wrap:anywhere}
.detail-label {font-size:12px;color:#64748b;margin-bottom:5px}
.detail-value {font-weight:600}
.form-label {font-weight:600}
@media(max-width:768px) {
    .detail-grid {grid-template-columns:1fr}
    .content-card {padding:15px}
    .page-heading h1 {font-size:23px}
}
</style>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($action === 'view' && $resident): ?>
    <div class="page-heading">
        <div>
            <h1>Resident Details</h1>
            <p class="page-subtitle">View the resident's information.</p>
        </div>
        <a href="residents.php" class="btn btn-outline-secondary">← Back to Residents</a>
    </div>

    <div class="content-card">
        <h2 class="h5 mb-4"><?= h($resident['first_name'] . ' ' . $resident['last_name']) ?></h2>
        <div class="detail-grid">
            <?php
            $fields = [
                'Resident ID' => 'resident_id',
                'First Name' => 'first_name',
                'Middle Name' => 'middle_name',
                'Last Name' => 'last_name',
                'Date of Birth' => 'date_of_birth',
                'Gender' => 'gender',
                'Contact Number' => 'contact_number',
                'Address' => 'address',
                'Room Number' => 'room_number',
                'Admission Date' => 'admission_date',
                'Care Level' => 'care_level',
                'Assigned Caretaker' => 'assigned_caretaker',
                'Status' => 'status'
            ];
            foreach ($fields as $label => $column):
            ?>
                <div class="detail-item">
                    <div class="detail-label"><?= h($label) ?></div>
                    <div class="detail-value"><?= h($resident[$column] ?: '—') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4">
            <?php if ($isAdmin): ?>
                <a class="btn-edit" href="residents.php?action=edit&id=<?= (int)$resident['id'] ?>">Edit Resident</a>
            <?php endif; ?>
            <a href="residents.php" class="btn btn-outline-secondary btn-sm">Back</a>
        </div>
    </div>

<?php elseif ($action === 'edit' && $resident): ?>
    <div class="page-heading">
        <div>
            <h1>Edit Resident</h1>
            <p class="page-subtitle">Update the resident's details.</p>
        </div>
        <a href="residents.php" class="btn btn-outline-secondary">← Cancel</a>
    </div>

    <div class="content-card">
        <form method="POST" action="residents.php?action=edit&id=<?= (int)$resident['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$resident['id'] ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Resident ID *</label>
                    <input class="form-control" name="resident_id" required value="<?= h($resident['resident_id']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">First Name *</label>
                    <input class="form-control" name="first_name" required value="<?= h($resident['first_name']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Middle Name</label>
                    <input class="form-control" name="middle_name" value="<?= h($resident['middle_name']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last Name *</label>
                    <input class="form-control" name="last_name" required value="<?= h($resident['last_name']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" class="form-control" name="date_of_birth" value="<?= h($resident['date_of_birth']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Gender</label>
                    <select class="form-select" name="gender">
                        <option value="">Select gender</option>
                        <?php foreach (['Male','Female','Other'] as $option): ?>
                            <option value="<?= h($option) ?>" <?= $resident['gender'] === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact Number</label>
                    <input class="form-control" name="contact_number" value="<?= h($resident['contact_number']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Room Number</label>
                    <input class="form-control" name="room_number" value="<?= h($resident['room_number']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="address" rows="2"><?= h($resident['address']) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Admission Date</label>
                    <input type="date" class="form-control" name="admission_date" value="<?= h($resident['admission_date']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Care Level</label>
                    <select class="form-select" name="care_level">
                        <option value="">Select care level</option>
                        <?php foreach (['Low','Medium','High'] as $option): ?>
                            <option value="<?= h($option) ?>" <?= $resident['care_level'] === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Assigned Caretaker</label>
                    <input class="form-control" name="assigned_caretaker" value="<?= h($resident['assigned_caretaker']) ?>">
                </div>
    
                <div class="col-md-6">
                    <label class="form-label">Status *</label>
                    <select class="form-select" name="status" required>
                        <?php foreach (['active','inactive','discharged'] as $option): ?>
                            <option value="<?= h($option) ?>" <?= $resident['status'] === $option ? 'selected' : '' ?>><?= h(ucfirst($option)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-success">Save Changes</button>
                <a href="residents.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>

<?php else: ?>
    <div class="page-heading">
        <div>
            <h1>Resident Management</h1>
            <p class="page-subtitle">View and manage resident information.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="dashboard.php" class="btn btn-outline-success">← Back to Dashboard</a>
            <?php if ($isAdmin): ?>
                <a href="add_resident.php" class="btn btn-success">+ Add Resident</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="content-card">
        <form method="GET" action="residents.php">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label for="search" class="form-label">Search by resident ID or name</label>
                    <input id="search" class="form-control" name="search" value="<?= h($search) ?>" placeholder="Enter resident ID or name">
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Filter by status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="">All statuses</option>
                        <?php foreach (['active','inactive','discharged'] as $option): ?>
                            <option value="<?= h($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= h(ucfirst($option)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-success">Search</button>
                    <a href="residents.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="content-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Resident List</h2>
            <span class="text-secondary">Total: <?= count($residents) ?></span>
        </div>

        <div class="table-wrapper">
            <table class="resident-table">
                <thead>
                    <tr>
                        <th>Resident ID</th>
                        <th>Full Name</th>
                        <th>Room</th>
                        <th>Care Level</th>
                        <th>Admission Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($residents): ?>
                    <?php foreach ($residents as $r): ?>
                        <tr>
                            <td><?= h($r['resident_id']) ?></td>
                            <td><?= h(trim($r['first_name'] . ' ' . ($r['middle_name'] ?? '') . ' ' . $r['last_name'])) ?></td>
                            <td><?= h($r['room_number'] ?: '—') ?></td>
                            <td><?= h($r['care_level'] ?: '—') ?></td>
                            <td><?= !empty($r['admission_date']) ? h(date('M d, Y', strtotime($r['admission_date']))) : '—' ?></td>
                            <td><span class="status-badge"><?= h(ucfirst($r['status'])) ?></span></td>
                            <td>
                                <div class="action-buttons">
                                    <a class="btn-view" href="residents.php?action=view&id=<?= (int)$r['id'] ?>">View</a>
                                    <?php if ($isAdmin): ?>
                                        <a class="btn-edit" href="residents.php?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                                        <form method="POST" action="residents.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this resident? This cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="btn-delete">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="text-center text-secondary py-4">No residents found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

</main>
</body>
</html>
