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
$recordId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
 
if (!$recordId || $recordId < 1) {
    header('Location: health_records.php');
    exit;
}
 
if (empty($_SESSION['health_records_csrf'])) {
    $_SESSION['health_records_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['health_records_csrf'];
 
function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
 
try {
    $stmt = $pdo->prepare("
        SELECT
            hr.*,
            r.resident_id AS resident_code,
            r.first_name,
            r.middle_name,
            r.last_name,
            r.room_number,
            r.gender,
            r.date_of_birth
        FROM health_records hr
        INNER JOIN residents r ON r.id = hr.resident_id
        WHERE hr.id = :id AND hr.archived_at IS NULL
    ");
    $stmt->execute(['id' => $recordId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
 
    if (!$record) {
        header('Location: health_records.php');
        exit;
    }
} catch (PDOException $e) {
    error_log('Health record view error: ' . $e->getMessage());
    header('Location: health_records.php');
    exit;
}
 
$fullName = trim(($record['first_name'] ?? '') . ' ' . ($record['middle_name'] ?? '') . ' ' . ($record['last_name'] ?? ''));
$fullName = preg_replace('/\s+/', ' ', $fullName);

$initials = '';
if (!empty($record['first_name'])) $initials .= mb_substr($record['first_name'], 0, 1);
if (!empty($record['last_name'])) $initials .= mb_substr($record['last_name'], 0, 1);
if ($initials === '') $initials = 'R';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Health Record - Home for the Aged[cite: 1]</title>
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
        
        /* Profile card layout matching resident profile */
        .health-card { background: #fff; border: 1px solid #e3eee6; border-radius: 14px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(24, 75, 42, .05); }
        .health-card-header { display: flex; align-items: center; gap: 16px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #edf2ee; }
        .health-avatar { width: 64px; height: 64px; border-radius: 50%; background: #d8e8dc; color: #17683a; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 700; flex-shrink: 0; }
        .health-card h3 { color: #17683a; font-size: 22px; font-weight: 700; margin: 0 0 4px; }
        .resident-meta { color: #61796a; font-size: 14px; margin: 0; }
        
        .section-title { color: #17683a; font-size: 16px; font-weight: 700; margin-top: 20px; margin-bottom: 12px; }
        
        /* Boxed field styling matching resident profile */
        .health-detail { background: #fbfdfc; border: 1px solid #e5ede7; border-radius: 10px; padding: 12px 16px; height: 100%; }
        .health-detail .field-label { display: block; font-size: 12px; color: #61796a; margin-bottom: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .health-detail .field-value { display: block; font-size: 15px; color: #294b35; font-weight: 600; overflow-wrap: anywhere; }
        
        .record-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 8px; margin-top: 25px; padding-top: 15px; border-top: 1px solid #edf2ee; }
        .record-actions .btn { font-size: 13px; padding: 6px 14px; border-radius: 6px; font-weight: 600; }
        .btn-edit { color: #725800; background: #ffdf70; border: 1px solid #e2b422; }
        .btn-edit:hover { color: #5d4600; background: #ffcf40; border-color: #d6a000; }
        .btn-delete { color: #b42318; background: #fff; border: 1px solid #dc3545; }
        .btn-delete:hover { color: #fff; background: #dc3545; }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <h1>Health Record Details</h1>
        <p>View complete health record and vital signs for the resident.</p>
    </div>
    <a href="health_records.php" class="btn btn-outline-secondary">← Back to Health Records</a>
</div>
 
<div class="health-card">
    <div class="health-card-header">
        <div class="health-avatar"><?= h(strtoupper($initials)) ?></div>
        <div>
            <h3><?= h($fullName) ?></h3>
            <p class="resident-meta">
                Resident ID: <strong><?= h($record['resident_code']) ?></strong> | Room: <strong><?= h($record['room_number'] ?: 'Not assigned') ?></strong> | Check-up Date: <strong><?= h($record['record_date']) ?></strong>
            </p>
        </div>
    </div>

    <div class="section-title">Vitals & Measurements</div>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="health-detail">
                <span class="field-label">Blood Pressure</span>
                <span class="field-value"><?= h($record['blood_pressure'] ?: 'Not recorded') ?></span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="health-detail">
                <span class="field-label">Temperature</span>
                <span class="field-value"><?= h($record['temperature'] !== null ? $record['temperature'] . ' °C' : 'Not recorded') ?></span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="health-detail">
                <span class="field-label">Weight</span>
                <span class="field-value"><?= h($record['weight'] !== null ? $record['weight'] . ' kg' : 'Not recorded') ?></span>
            </div>
        </div>
    </div>

    <div class="section-title">Medical Information</div>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="health-detail">
                <span class="field-label">Allergies</span>
                <span class="field-value"><?= h($record['allergies'] ?: 'None recorded') ?></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="health-detail">
                <span class="field-label">Medications</span>
                <span class="field-value"><?= h($record['medications'] ?: 'None recorded') ?></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="health-detail">
                <span class="field-label">Medical Conditions</span>
                <span class="field-value"><?= h($record['medical_conditions'] ?: 'None recorded') ?></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="health-detail">
                <span class="field-label">Notes</span>
                <span class="field-value"><?= h($record['notes'] ?: 'No notes') ?></span>
            </div>
        </div>
    </div>

    <div class="record-actions">
        <a href="add_health_record.php?edit=<?= (int)$record['id'] ?>" class="btn btn-edit">Edit Record</a>
        <?php if ($isAdmin): ?>
            <form method="POST" action="health_records.php" onsubmit="return confirm('Delete this health record? It will be moved to Archive & Restore.');" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="record_id" value="<?= (int)$record['id'] ?>">
                <button type="submit" class="btn btn-delete">Delete Record</button>
            </form>
        <?php endif; ?>
    </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>