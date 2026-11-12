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
 
$isAdmin = $_SESSION['role'] === 'admin';
 
if (empty($_SESSION['health_records_csrf'])) {
    $_SESSION['health_records_csrf'] = bin2hex(random_bytes(32));
}
 
$csrf = $_SESSION['health_records_csrf'];
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$success = $_GET['success'] ?? '';
$error = '';
 
function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
 
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'archive'
) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only an administrator can delete health records.');
    }
 
    $postedToken = $_POST['csrf_token'] ?? '';
 
    if (
        !is_string($postedToken) ||
        !hash_equals($csrf, $postedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }
 
    $recordId = filter_input(
        INPUT_POST,
        'record_id',
        FILTER_VALIDATE_INT
    );
 
    if (!$recordId || $recordId < 1) {
        $error = 'Invalid health record selected. (Received ID: '
            . h($_POST['record_id'] ?? 'none')
            . '. If this is 0, the health_records.id column is not AUTO_INCREMENT.)';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE health_records
                SET archived_at = NOW()
                WHERE id = :id
                  AND archived_at IS NULL
            ");
 
            $stmt->execute(['id' => $recordId]);
 
            if ($stmt->rowCount() > 0) {
                header('Location: health_records.php?success=deleted');
                exit;
            }
 
            $error = 'Health record not found or already archived.';
        } catch (PDOException $e) {
            error_log('Health record archive error: ' . $e->getMessage());
            $error = 'Unable to delete this health record. Please try again.';
        }
    }
}
 
$sql = "
    SELECT
        hr.id,
        hr.resident_id,
        hr.record_date,
        hr.blood_pressure,
        hr.temperature,
        hr.weight,
        hr.allergies,
        hr.medical_conditions,
        hr.medications,
        hr.notes,
        r.resident_id AS resident_code,
        r.first_name,
        r.middle_name,
        r.last_name,
        r.room_number
    FROM health_records hr
    INNER JOIN residents r ON r.id = hr.resident_id
    WHERE hr.archived_at IS NULL
";
 
$params = [];
 
if ($search !== '') {
    $sql .= " AND (
        r.resident_id LIKE :search_code
        OR r.first_name LIKE :search_first
        OR r.middle_name LIKE :search_middle
        OR r.last_name LIKE :search_last
        OR hr.medical_conditions LIKE :search_condition
        OR hr.allergies LIKE :search_allergies
    )";
 
    $term = '%' . $search . '%';
 
    $params = [
        'search_code' => $term,
        'search_first' => $term,
        'search_middle' => $term,
        'search_last' => $term,
        'search_condition' => $term,
        'search_allergies' => $term
    ];
}
 
if ($filter === 'allergies') {
    $sql .= " AND hr.allergies IS NOT NULL
              AND TRIM(hr.allergies) <> ''";
} elseif ($filter === 'conditions') {
    $sql .= " AND hr.medical_conditions IS NOT NULL
              AND TRIM(hr.medical_conditions) <> ''";
}
 
$sql .= ' ORDER BY hr.record_date DESC, hr.id DESC';
 
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Health records loading error: ' . $e->getMessage());
    $records = [];
    $error = 'Database Error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Records - Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef1ee; font-family: Arial, sans-serif; color: #303830; display: flex; min-height: 100vh; }
        .main-content, .app-main { width: 100%; max-width: 100%; min-width: 0; padding: 20px; flex-grow: 1; transition: margin-left 0.3s ease; }
        .sidebar-toggle-btn { background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3); border-radius: 6px; padding: 6px; cursor: pointer; display: inline-flex; flex-direction: column; justify-content: center; align-items: center; gap: 4px; width: 36px; height: 36px; margin: 12px 0; transition: background 0.2s ease, border-color 0.2s ease; }
        .sidebar-toggle-btn:hover { background: rgba(255, 255, 255, 0.25); border-color: rgba(255, 255, 255, 0.5); }
        .sidebar-toggle-btn .hamburger-line { display: block; width: 18px; height: 2px; background-color: #ffffff; border-radius: 2px; transition: all 0.3s ease; }
        .sidebar-toggle-wrapper { padding-left: 15px; width: 100%; display: flex; justify-content: flex-start; }
        .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.4); z-index: 998; display: none; }
        .sidebar-overlay.active { display: block; }
        .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar { width: 260px; min-width: 260px; transition: width 0.3s ease, transform 0.3s ease; z-index: 999; flex-shrink: 0; overflow: hidden; display: flex; flex-direction: column; }
        @media (min-width: 993px) {
            body.sidebar-folded .sidebar, body.sidebar-folded #sidebar, body.sidebar-folded .sidebar-container, body.sidebar-folded aside, body.sidebar-folded .app-sidebar { width: 70px; min-width: 70px; }
            body.sidebar-folded .sidebar > *:not(.sidebar-toggle-wrapper), body.sidebar-folded #sidebar > *:not(.sidebar-toggle-wrapper), body.sidebar-folded .sidebar-container > *:not(.sidebar-toggle-wrapper), body.sidebar-folded aside > *:not(.sidebar-toggle-wrapper), body.sidebar-folded .app-sidebar > *:not(.sidebar-toggle-wrapper) { display: none !important; }
            body.sidebar-folded .sidebar-toggle-wrapper { display: flex !important; justify-content: center !important; padding-left: 0 !important; width: 100% !important; margin-top: 12px !important; }
        }
        @media (max-width: 992px) {
            .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar { position: fixed; top: 0; left: 0; height: 100%; transform: translateX(-100%); width: 260px !important; }
            body.sidebar-open .sidebar, body.sidebar-open #sidebar, body.sidebar-open .sidebar-container, body.sidebar-open aside, body.sidebar-open .app-sidebar { transform: translateX(0); }
        }
        .sidebar-logo { display: block; padding: 10px 4px 6px; }
        .sidebar-logo a { display: block; }
        .sidebar-logo img { display: block; width: 100%; max-width: 300px; height: auto; margin: 0 auto; transform: scale(1.1); }
        .page-header { background: #e7f5ec; border: 1px solid #d5eadc; border-radius: 14px; padding: 22px 26px; margin-bottom: 22px; }
        .page-header h1 { color: #17683a; font-size: 28px; margin: 0 0 6px; font-weight: 700; }
        .page-header p { color: #61796a; margin: 0; }
        .health-panel { background: #fff; border: 1px solid #e3eee6; border-radius: 14px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(24, 75, 42, .05); }
        .health-search-panel { background: #f3faf5; }
        .health-card { background: #fff; border: 1px solid #e0ebe3; border-radius: 12px; padding: 18px; margin-bottom: 14px; transition: box-shadow .2s; }
        .health-card:hover { box-shadow: 0 5px 16px rgba(24, 75, 42, .07); }
        .health-card h3 { color: #17683a; font-size: 19px; font-weight: 700; margin-bottom: 5px; }
        .resident-meta { color: #66786c; font-size: 13px; margin-bottom: 14px; }
        .health-detail { background: #f7faf8; border: 1px solid #e5ede7; border-radius: 9px; padding: 10px; min-height: 42px; overflow-wrap: anywhere; }
        .health-detail strong { color: #294b35; }
        .btn-view { color: #198754; background: #fff; border: 1px solid #198754; }
        .btn-view:hover { color: #fff; background: #198754; }
        .btn-edit { color: #725800; background: #ffdf70; border: 1px solid #e2b422; }
        .btn-edit:hover { color: #5d4600; background: #ffcf40; border-color: #d6a000; }
        .btn-delete { color: #b42318; background: #fff; border: 1px solid #dc3545; }
        .btn-delete:hover { color: #fff; background: #dc3545; }
        .record-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 7px; margin-top: 12px; }
        .record-actions .btn { font-size: 13px; padding: 5px 10px; }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <h1>Health Records</h1>
        <p>View and manage residents' health information.</p>
    </div>
    <a href="add_health_record.php" class="btn btn-success">+ Add Health Record</a>
</div>
 
<?php if ($success === 'added'): ?>
    <div class="alert alert-success">Health record added successfully.</div>
<?php elseif ($success === 'updated'): ?>
    <div class="alert alert-success">Health record updated successfully.</div>
<?php elseif ($success === 'deleted'): ?>
    <div class="alert alert-success">Health record deleted successfully and moved to Archive & Restore.</div>
<?php endif; ?>
 
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>
 
<div class="health-panel health-search-panel">
    <form method="GET" action="health_records.php" class="d-flex flex-wrap gap-2 align-items-center">
        <input type="text" name="search" class="form-control flex-grow-1" style="min-width:220px" placeholder="Search resident, name, or ID..." value="<?= h($search) ?>">
        <select name="filter" class="form-select" style="width:auto; min-width:170px">
            <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All records</option>
            <option value="allergies" <?= $filter === 'allergies' ? 'selected' : '' ?>>With allergies</option>
            <option value="conditions" <?= $filter === 'conditions' ? 'selected' : '' ?>>With medical conditions</option>
        </select>
        <button type="submit" class="btn btn-success">Search / Filter</button>
        <a href="health_records.php" class="btn btn-outline-secondary">Reset</a>
    </form>
</div>
 
<div class="health-panel">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="h5 mb-0">List of Patients</h2>
        <span class="text-secondary"><?= count($records) ?> record(s)</span>
    </div>
 
    <?php if (empty($records)): ?>
        <div class="alert alert-info mb-0">No health records found. Add a health record or change your search.</div>
    <?php else: ?>
        <?php foreach ($records as $record): ?>
            <?php
            $fullName = trim(($record['first_name'] ?? '') . ' ' . ($record['middle_name'] ?? '') . ' ' . ($record['last_name'] ?? ''));
            $fullName = preg_replace('/\s+/', ' ', $fullName);
            ?>
            <div class="health-card">
                <h3><?= h($fullName) ?></h3>
                <div class="resident-meta">
                    Resident ID: <?= h($record['resident_code']) ?> | Room: <?= h($record['room_number'] ?: 'Not assigned') ?> | Check-up: <?= h($record['record_date']) ?>
                </div>
                <div class="row g-2">
                    <div class="col-md-6"><div class="health-detail"><strong>Blood Pressure:</strong> <?= h($record['blood_pressure'] ?: 'Not recorded') ?></div></div>
                    <div class="col-md-6"><div class="health-detail"><strong>Allergies:</strong> <?= h($record['allergies'] ?: 'None recorded') ?></div></div>
                    <div class="col-md-6"><div class="health-detail"><strong>Temperature:</strong> <?= h($record['temperature'] !== null ? $record['temperature'] . ' °C' : 'Not recorded') ?></div></div>
                    <div class="col-md-6"><div class="health-detail"><strong>Medication:</strong> <?= h($record['medications'] ?: 'None recorded') ?></div></div>
                    <div class="col-md-6"><div class="health-detail"><strong>Weight:</strong> <?= h($record['weight'] !== null ? $record['weight'] . ' kg' : 'Not recorded') ?></div></div>
                    <div class="col-md-6"><div class="health-detail"><strong>Medical Condition:</strong> <?= h($record['medical_conditions'] ?: 'None recorded') ?></div></div>
                    <div class="col-md-6"><div class="health-detail"><strong>Notes:</strong> <?= h($record['notes'] ?: 'No notes') ?></div></div>
                </div>
                <div class="record-actions">
                    <a href="view_health_record.php?id=<?= (int)$record['id'] ?>" class="btn btn-view">View</a>
                    <a href="add_health_record.php?edit=<?= (int)$record['id'] ?>" class="btn btn-edit">Edit</a>
                    <?php if ($isAdmin): ?>
                        <form method="POST" action="health_records.php" onsubmit="return confirm('Delete this health record? It will be moved to Archive & Restore.');" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="archive">
                            <input type="hidden" name="record_id" value="<?= (int)$record['id'] ?>">
                            <button type="submit" class="btn btn-delete">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>