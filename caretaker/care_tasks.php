<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array(strtolower($_SESSION['role']), ['admin', 'staff', 'caretaker'], true)
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/archive_helper.php';

$caretakerId = (int)$_SESSION['user_id'];
$isAdmin = strtolower($_SESSION['role']) === 'admin';
$currentUserName = $_SESSION['username'] ?? $_SESSION['name'] ?? 'Avah Pasigue';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function residentName(array $resident): string
{
    return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
        $resident['first_name'] ?? '',
        $resident['middle_name'] ?? '',
        $resident['last_name'] ?? ''
    ]))));
}

function residentPicture($picture): string
{
    $picture = trim((string)($picture ?? ''));
    if ($picture === '') {
        return '../images/default-profile.png';
    }
    $filename = basename($picture);
    $path1 = __DIR__ . '/uploads/residentspic/' . $filename;
    $path2 = __DIR__ . '/../admin/uploads/residentspic/' . $filename;

    if (is_file($path1)) {
        return 'uploads/residentspic/' . rawurlencode($filename);
    } elseif (is_file($path2)) {
        return '../admin/uploads/residentspic/' . rawurlencode($filename);
    }
    return '../images/default-profile.png';
}

function calculateAge(?string $dob): string
{
    if (!$dob) return 'N/A';
    try {
        $birthDate = new DateTime($dob);
        $today = new DateTime('today');
        return (string)$birthDate->diff($today)->y;
    } catch (Exception $e) {
        return 'N/A';
    }
}

if (empty($_SESSION['caretaker_report_csrf'])) {
    $_SESSION['caretaker_report_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['caretaker_report_csrf'];
$search = trim($_GET['search'] ?? '');
$success = $_GET['success'] ?? '';
$error = '';

/* Handle Add Note Submission Directly */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_note') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrf, $postedToken)) {
        $error = 'Invalid security token.';
    } else {
        $residentPk = filter_input(INPUT_POST, 'resident_id', FILTER_VALIDATE_INT);
        $noteContent = trim($_POST['additional_notes'] ?? '');

        if (!$residentPk || $noteContent === '') {
            $error = 'Resident and note content are required.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO health_records (resident_id, notes, record_date) 
                    VALUES (:resident_id, :notes, NOW())
                ");
                $stmt->execute([
                    'resident_id' => $residentPk,
                    'notes' => '[Caretaker Note]: ' . $noteContent
                ]);
                header('Location: health_information.php?success=note_added');
                exit;
            } catch (PDOException $e) {
                $error = 'Failed to save note: ' . $e->getMessage();
            }
        }
    }
}

$assignedResidents = [];
$hasArchivedAt = false;

try {
    $columnCheck = $pdo->query("SHOW COLUMNS FROM residents");
    $columns = $columnCheck->fetchAll(PDO::FETCH_COLUMN);
    $hasArchivedAt = in_array('archived_at', $columns, true);
} catch (PDOException $e) {}

try {
    archiveEnsureCaretakerTable($pdo);
} catch (Throwable $e) {}

/* Load assigned residents with unique search parameters to fix SQLSTATE[HY093] */
try {
    $sql = "
        SELECT
            r.id,
            r.resident_id,
            r.first_name,
            r.middle_name,
            r.last_name,
            r.date_of_birth,
            r.gender,
            r.contact_number,
            r.address,
            r.room_number,
            r.admission_date,
            r.care_level,
            r.assigned_caretaker,
            r.profile_picture,
            r.status
        FROM residents r
        WHERE 1 = 1
    ";

    $params = [];

    if (!$isAdmin) {
        $sql .= " AND (r.assigned_caretaker = :caretaker_id OR r.assigned_caretaker = :caretaker_name)";
        $params['caretaker_id'] = $caretakerId;
        $params['caretaker_name'] = $currentUserName;
    }

    if ($hasArchivedAt) {
        $sql .= " AND r.archived_at IS NULL";
    }
    
    $sql .= " AND (r.status IS NULL OR LOWER(r.status) <> 'archived')";

    $sql .= " AND NOT EXISTS (
        SELECT 1 FROM caretaker_resident_archive ca
        WHERE ca.resident_id = r.id
          AND ca.caretaker_user_id = :caretaker_archive
    )";
    $params['caretaker_archive'] = $caretakerId;

    if ($search !== '') {
        $sql .= " AND (
            r.resident_id LIKE :search_1
            OR r.room_number LIKE :search_2
            OR r.first_name LIKE :search_3
            OR r.last_name LIKE :search_4
            OR CONCAT(r.first_name, ' ', COALESCE(r.middle_name, ''), ' ', r.last_name) LIKE :search_5
        )";
        $term = '%' . $search . '%';
        $params['search_1'] = $term;
        $params['search_2'] = $term;
        $params['search_3'] = $term;
        $params['search_4'] = $term;
        $params['search_5'] = $term;
    }

    $sql .= " ORDER BY r.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assignedResidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Caretaker report resident loading error: ' . $e->getMessage());
    $error = 'Unable to load residents database records: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CareTaker Report - Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
             margin: 0; 
             background: #eef1ee; 
             font-family: Arial, sans-serif; 
             color: #303830; display: flex; 
             min-height: 100vh; 
            }

        .main-content {
             width: 100%; 
             max-width: 100%; 
             min-width: 0; padding: 20px; 
             flex-grow: 1; transition: 
             margin-left 0.3s ease; 
            }

        .sidebar-overlay { 
            position: fixed; 
            top: 0; left: 0; 
            width: 100vw; 
            height: 100vh; 
            background: rgba(0, 0, 0, 0.4); 
            z-index: 998; 
            display: none; 
        }

        .sidebar-overlay.active { 
            display: block; 
        }
        .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar { 
            width: 260px; 
            min-width: 260px; 
            transition: width 0.3s ease, transform 0.3s ease; 
            z-index: 999; 
            flex-shrink: 0; 
            overflow: hidden; 
            display: 
            flex; flex-direction: 
            column; 
        }
        @media (max-width: 992px) {
            .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar { 
                position: fixed; 
                top: 0; left: 0; 
                height: 100%; 
                transform: translateX(-100%); 
                width: 260px !important; 
            }
            body.sidebar-open .sidebar, body.sidebar-open #sidebar, body.sidebar-open .sidebar-container, body.sidebar-open aside, body.sidebar-open .app-sidebar { 
                transform: translateX(0); }
        }

        .residents-header { 
            background: #eaf6ee; 
            border: 1px solid #d8eadc; 
            border-radius: 14px; 
            padding: 22px 26px; 
            margin-bottom: 22px; 
        }
        .residents-header h1 { 
            color: #17683a; 
            font-size: 28px; 
            font-weight: 700; 
            margin-bottom: 6px; 
            display: flex; 
            align-items: center; 
            gap: 12px; 
        }
        .residents-header p { 
            color: #61796a; 
            margin-bottom: 0; 
        }
        
        /* Search Panel Container */
        .residents-search-panel { 
            background: #f5faf6; 
            border: 1px solid #e3eee6; 
            border-radius: 14px; 
            padding: 20px; 
            margin-bottom: 20px; 
            box-shadow: 0 4px 16px rgba(24,75,42,.05); 
        }

        /* COMPLETELY INDEPENDENT INDIVIDUAL RESIDENT CARD */
        .single-resident-card {
            background: #fff;
            border: 1px solid #d8eadc;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.06);
        }

        .quick-info-box {
            border: 2px solid #417541;
            border-radius: 12px;
            padding: 18px;
            background: #faf7fc;
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .info-left {
            flex: 1;
            min-width: 260px;
            padding-right: 20px;
            border-right: 1px solid #e2d2ec;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .resident-avatar {
            width: 65px;
            height: 65px;
            min-width: 65px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e0eee4;
            background: #f1f5f2;
        }

        .patient-details h3 {
            color: #17683a;
            font-size: 21px;
            font-weight: 700;
            margin: 0 0 4px 0;
            overflow-wrap: anywhere;
        }

        .resident-meta {
            color: #66786c;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background-color: #d1e7dd;
            border: 1px solid #badbcc;
            border-radius: 12px;
            padding: 2px 12px;
            font-weight: 700;
            font-size: 13px;
            color: #0f5132;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            background-color: #198754;
            border-radius: 50%;
        }

        .info-right {
            flex: 1.5;
            min-width: 300px;
            padding-left: 20px;
        }

        .info-right-title {
            text-align: center;
            font-size: 16px;
            font-weight: 700;
            color: #17683a;
            margin-bottom: 10px;
        }

        .info-list {
            font-size: 13px;
            line-height: 1.6;
            color: #303830;
        }

        .info-list strong {
            color: #294b35;
        }

        .report-table {
            width: 100%;
            margin-bottom: 0;
            border-collapse: collapse;
        }

        .report-table th {
            background-color: #e7f5ec;
            color: #17683a;
            font-weight: 700;
            font-size: 15px;
            padding: 12px 16px;
            text-align: center;
            border-bottom: 1px solid #d5eadc;
        }

        .report-table td {
            padding: 12px 16px;
            color: #303830;
            font-size: 14px;
            border-bottom: 1px solid #e5ede7;
            vertical-align: middle;
        }

        .report-table td.col-time { 
            font-weight: 700; 
            width: 16%; 
            text-align: center; 
        }
        .report-table td.col-activity { 
            font-weight: 700; 
            width: 26%; 
            text-align: center; 
        }

        .report-table td.col-notes { 
            width: 40%; 
            text-align: left; 
        }

        .report-table td.col-status { 
            font-weight: 700; 
            width: 18%; 
            text-align: center; 
        }

        .notes-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 18px;
            padding-top: 15px;
            border-top: 1px solid #e5ede7;
        }

        .notes-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notes-title-wrap i { 
            font-size: 24px; 
            color: #17683a; 
        }

        .notes-title-wrap h2 { 
            color: #17683a; 
            font-size: 19px; 
            margin: 0; 
            font-weight: 700; 
        }

        .btn-add-notes {
            color: #fff;
            background: #198754;
            border: 1px solid #198754;
            font-weight: 700;
            padding: 7px 16px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
        }

        .btn-add-notes:hover { background: #157347; color: #fff; }

        @media (max-width: 768px) {
            .info-left {
                border-right: none;
                border-bottom: 1px solid #e2d2ec;
                padding-right: 0;
                padding-bottom: 16px;
                margin-bottom: 16px;
            }
            .info-right { padding-left: 0; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
<div class="residents-header d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <h1>CareTaker Report</h1>
        <p>Real-time synchronization with resident health logs and care records.</p>
    </div>
</div>

<?php if ($success === 'note_added'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Additional note added successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<!-- Search Panel -->
<div class="residents-search-panel">
    <form method="GET" action="health_information.php" class="d-flex flex-wrap gap-2 align-items-center">
        <input type="text" name="search" class="form-control flex-grow-1" style="min-width:240px" placeholder="Search resident, full name, ID, or room..." value="<?= h($search) ?>">
        <button type="submit" class="btn btn-success">Search</button>
        <a href="health_information.php" class="btn btn-outline-secondary">Reset</a>
    </form>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 px-1">
    <h2 class="h5 mb-0 fw-bold" style="color: #17683a;">Resident Reports</h2>
    <span class="fw-semibold" style="color: #17683a;"><?= count($assignedResidents) ?> resident(s) found</span>
</div>

<?php if (empty($assignedResidents)): ?>
    <div class="residents-panel">
        <div class="alert alert-info mb-0">No assigned residents found for your account.</div>
    </div>
<?php else: ?>
    <?php foreach ($assignedResidents as $resident): ?>
        <?php
            $residentPk = (int)$resident['id'];
            $fullName = residentName($resident);
            $age = calculateAge($resident['date_of_birth'] ?? null);
            $room = $resident['room_number'] ?: 'Not assigned';
            $status = $resident['status'] ?: 'Stable';
            $picUrl = residentPicture($resident['profile_picture'] ?? '');

            try {
                $hrStmt = $pdo->prepare("
                    SELECT * FROM health_records
                    WHERE resident_id = :resident_id AND archived_at IS NULL
                    ORDER BY record_date DESC, id DESC
                    LIMIT 1
                ");
                $hrStmt->execute(['resident_id' => $residentPk]);
                $healthRecord = $hrStmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $ex) {
                $healthRecord = false;
            }

            $allergies = !empty($healthRecord['allergies']) ? $healthRecord['allergies'] : 'None recorded';
            $diet = !empty($healthRecord['medical_conditions']) ? 'Specialized Care / ' . $healthRecord['medical_conditions'] : 'Regular / Low Sodium';
            $medicationReminders = !empty($healthRecord['medications']) ? $healthRecord['medications'] : 'As prescribed';
            $mobility = !empty($resident['care_level']) ? 'Care Level: ' . $resident['care_level'] : 'Requires standard monitoring';
            
            $bp = !empty($healthRecord['blood_pressure']) ? $healthRecord['blood_pressure'] : 'N/A';
            $temp = isset($healthRecord['temperature']) ? $healthRecord['temperature'] . '°C' : 'N/A';
            $weight = isset($healthRecord['weight']) ? $healthRecord['weight'] . ' kg' : 'N/A';
            $vitalSigns = "BP: {$bp}, Temp: {$temp}, Weight: {$weight}";
            
            $specialInstructions = !empty($healthRecord['notes']) ? $healthRecord['notes'] : 'No special notes recorded.';
        ?>

        <!-- EACH RESIDENT IS ENCLOSED IN A COMPLETELY SEPARATED CARD -->
        <div class="single-resident-card">
            <div class="quick-info-box">
                <div class="info-left">
                    <img src="<?= h($picUrl) ?>" alt="<?= h($fullName) ?> profile picture" class="resident-avatar" onerror="this.onerror=null;this.src='../images/default-profile.png';">
                    <div class="patient-details">
                        <h3><?= h($fullName) ?></h3>
                        <div class="resident-meta">Age: <?= h($age) ?> &nbsp;|&nbsp; Room: <?= h($room) ?> &nbsp;|&nbsp; ID: <?= h($resident['resident_id']) ?></div>
                        <div class="status-badge"><span class="status-dot"></span><?= h(ucwords(str_replace('_', ' ', $status))) ?></div>
                    </div>
                </div>

                <div class="info-right">
                    <div class="info-right-title">Quick Info</div>
                    <div class="info-list">
                        <div><strong>Allergies:</strong> <?= h($allergies) ?></div>
                        <div><strong>Diet:</strong> <?= h($diet) ?></div>
                        <div><strong>Medication Reminders:</strong> <?= h($medicationReminders) ?></div>
                        <div><strong>Mobility:</strong> <?= h($mobility) ?></div>
                        <div><strong>Vital Signs:</strong> <?= h($vitalSigns) ?></div>
                        <div><strong>Special Instructions:</strong> <?= h($specialInstructions) ?></div>
                    </div>
                </div>
            </div>

            <div class="table-responsive mb-3">
                <table class="table report-table">
                    <thead>
                        <tr>
                            <th class="col-time">Time</th>
                            <th class="col-activity">Activity</th>
                            <th class="col-notes">Notes</th>
                            <th class="col-status">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($healthRecord): ?>
                            <tr>
                                <td class="col-time"><?= h(date('h:i A', strtotime($healthRecord['record_date']))) ?></td>
                                <td class="col-activity">Clinical Health Record Check</td>
                                <td class="col-notes"><?= h($healthRecord['notes'] ?: 'Record synced successfully.') ?></td>
                                <td class="col-status">Completed</td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td class="col-time">8:00 AM</td>
                                <td class="col-activity">Morning Routine & Medication</td>
                                <td class="col-notes">Administered as prescribed.</td>
                                <td class="col-status">Completed</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="notes-card-header">
                <div class="notes-title-wrap">
                    <h2>Additional Notes</h2>
                </div>
                <button type="button" class="btn btn-add-notes" data-bs-toggle="modal" data-bs-target="#addNotesModal<?= $residentPk ?>">
                    <i class="bi bi-pencil-fill"></i> Add Notes
                </button>
            </div>
        </div>

        <!-- Modal for Adding Notes (Unique ID per Resident) -->
        <div class="modal fade" id="addNotesModal<?= $residentPk ?>" tabindex="-1" aria-labelledby="addNotesModalLabel<?= $residentPk ?>" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="health_information.php">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addNotesModalLabel<?= $residentPk ?>">Add Note for <?= h($fullName) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="add_note">
                            <input type="hidden" name="resident_id" value="<?= $residentPk ?>">
                            <div class="mb-3">
                                <label for="additional_notes_<?= $residentPk ?>" class="form-label">Note Content</label>
                                <textarea class="form-control" id="additional_notes_<?= $residentPk ?>" name="additional_notes" rows="4" required placeholder="Enter observations or care notes..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success">Save Note</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>