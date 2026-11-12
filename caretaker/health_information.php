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

/* Which columns does health_records actually have? (keeps the edit form safe across schema variants) */
$hrColumns = [];
try {
    $hrColumns = $pdo->query("SHOW COLUMNS FROM health_records")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

/* Handle Edit Health Information Submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_health') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrf, $postedToken)) {
        $error = 'Invalid security token.';
    } else {
        $residentPk = filter_input(INPUT_POST, 'resident_id', FILTER_VALIDATE_INT);
        $heartInput = trim($_POST['heart_rate'] ?? '');

        if (!$residentPk) {
            $error = 'Resident is required.';
        } elseif ($heartInput !== '' && !ctype_digit($heartInput)) {
            $error = 'Heart rate must be a whole number (bpm).';
        } else {
            try {
                // Non-admins may only edit residents assigned to them
                $allowed = true;
                if (!$isAdmin) {
                    $chk = $pdo->prepare("
                        SELECT 1 FROM residents
                        WHERE id = :id AND (assigned_caretaker = :cid OR assigned_caretaker = :cname)
                    ");
                    $chk->execute(['id' => $residentPk, 'cid' => $caretakerId, 'cname' => $currentUserName]);
                    $allowed = (bool)$chk->fetchColumn();
                }

                if (!$allowed) {
                    $error = 'You are not allowed to edit this resident.';
                } else {
                    // form field => database column
                    $fieldMap = [
                        'allergies'      => 'allergies',
                        'diet'           => in_array('diet', $hrColumns, true) ? 'diet' : 'medical_conditions',
                        'medications'    => 'medications',
                        'mobility'       => 'mobility',
                        'blood_pressure' => 'blood_pressure',
                        'heart_rate'     => in_array('heart_rate', $hrColumns, true) ? 'heart_rate' : 'pulse',
                        'notes'          => 'notes',
                    ];

                    $values = [];
                    foreach ($fieldMap as $input => $column) {
                        if (in_array($column, $hrColumns, true)) {
                            $val = trim($_POST[$input] ?? '');
                            $values[$column] = ($val === '') ? null : $val;
                        }
                    }

                    if (empty($values)) {
                        $error = 'No editable health columns were found in the database.';
                    } else {
                        $latest = $pdo->prepare("
                            SELECT id FROM health_records
                            WHERE resident_id = :rid AND archived_at IS NULL
                            ORDER BY record_date DESC, id DESC
                            LIMIT 1
                        ");
                        $latest->execute(['rid' => $residentPk]);
                        $recordId = $latest->fetchColumn();

                        if ($recordId) {
                            $setParts = [];
                            foreach (array_keys($values) as $column) {
                                $setParts[] = "`$column` = :$column";
                            }
                            $stmt = $pdo->prepare("UPDATE health_records SET " . implode(', ', $setParts) . " WHERE id = :record_id");
                            $stmt->execute($values + ['record_id' => $recordId]);
                        } else {
                            $cols = array_keys($values);
                            $stmt = $pdo->prepare(
                                "INSERT INTO health_records (resident_id, record_date, `" . implode('`, `', $cols) . "`)
                                 VALUES (:resident_id, NOW(), :" . implode(', :', $cols) . ")"
                            );
                            $stmt->execute($values + ['resident_id' => $residentPk]);
                        }

                        header('Location: health_information.php?success=health_updated');
                        exit;
                    }
                }
            } catch (PDOException $e) {
                $error = 'Failed to update health information: ' . $e->getMessage();
            }
        }
    }
}

/* Handle Delete Concern/Observation Entry */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_entry') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $deletedResident = filter_input(INPUT_POST, 'resident_id', FILTER_VALIDATE_INT);
    $entryId = filter_input(INPUT_POST, 'entry_id', FILTER_VALIDATE_INT);

    if (!is_string($postedToken) || !hash_equals($csrf, $postedToken)) {
        $error = 'Invalid security token.';
    } elseif (!$deletedResident || !$entryId) {
        $error = 'Invalid entry.';
    } else {
        try {
            $allowed = true;
            if (!$isAdmin) {
                $chk = $pdo->prepare("
                    SELECT 1 FROM residents
                    WHERE id = :id AND (assigned_caretaker = :cid OR assigned_caretaker = :cname)
                ");
                $chk->execute(['id' => $deletedResident, 'cid' => $caretakerId, 'cname' => $currentUserName]);
                $allowed = (bool)$chk->fetchColumn();
            }

            if (!$allowed) {
                $error = 'You are not allowed to modify this resident.';
            } else {
                $find = $pdo->prepare("
                    SELECT * FROM health_records
                    WHERE id = :id AND resident_id = :rid AND archived_at IS NULL
                      AND (notes LIKE '[Concern]%' OR notes LIKE '[Observation]%')
                ");
                $find->execute(['id' => $entryId, 'rid' => $deletedResident]);
                $row = $find->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $error = 'Entry not found or already deleted.';
                } else {
                    // If this row also holds other health data (allergies, vitals, ...), only clear the note
                    // so that data isn't lost. Otherwise archive the whole row.
                    $hasOtherData = false;
                    foreach (['allergies', 'diet', 'medical_conditions', 'medications', 'mobility', 'blood_pressure', 'heart_rate', 'pulse'] as $col) {
                        if (array_key_exists($col, $row) && trim((string)$row[$col]) !== '') {
                            $hasOtherData = true;
                            break;
                        }
                    }

                    if ($hasOtherData) {
                        $upd = $pdo->prepare("UPDATE health_records SET notes = '' WHERE id = :id");
                    } else {
                        $upd = $pdo->prepare("UPDATE health_records SET archived_at = NOW() WHERE id = :id");
                    }
                    $upd->execute(['id' => $entryId]);

                    header('Location: health_information.php?success=entry_deleted&resident=' . $deletedResident);
                    exit;
                }
            }
        } catch (PDOException $e) {
            $error = 'Failed to delete entry: ' . $e->getMessage();
        }
    }
}

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
                    'notes' => ((($_POST['entry_type'] ?? '') === 'concern') ? '[Concern]: ' : '[Observation]: ') . $noteContent
                ]);
                header('Location: health_information.php?success=' . ((($_POST['entry_type'] ?? '') === 'concern') ? 'concern_reported' : 'note_added'));
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
    <title>Health Information - Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef1ee;
            font-family: Arial, sans-serif;
            color: #303830;
            display: flex;
            min-height: 100vh;
        }
        .main-content { width: 100%; max-width: 100%; min-width: 0; padding: 20px; flex-grow: 1; transition: margin-left 0.3s ease; }

        .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.4); z-index: 998; display: none; }
        .sidebar-overlay.active { display: block; }
        .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar {
            width: 260px; min-width: 260px; transition: width 0.3s ease, transform 0.3s ease;
            z-index: 999; flex-shrink: 0; overflow: hidden; display: flex; flex-direction: column;
        }
        @media (max-width: 992px) {
            .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar {
                position: fixed; top: 0; left: 0; height: 100%; transform: translateX(-100%); width: 260px !important;
            }
            body.sidebar-open .sidebar, body.sidebar-open #sidebar, body.sidebar-open .sidebar-container, body.sidebar-open aside, body.sidebar-open .app-sidebar { transform: translateX(0); }
        }

        .residents-header {
            background: #eaf6ee; border: 1px solid #d8eadc; border-radius: 14px;
            padding: 22px 26px; margin-bottom: 22px;
        }
        .residents-header h1 {
            color: #17683a; font-size: 28px; font-weight: 700; margin: 0;
            display: flex; align-items: center; gap: 12px;
        }

        .residents-search-panel {
            background: #f5faf6; border: 1px solid #e3eee6; border-radius: 14px;
            padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(24,75,42,.05);
        }

        .single-resident-card {
            background: #fff; border: 1px solid #d8eadc; border-radius: 14px;
            padding: 24px; margin-bottom: 22px; box-shadow: 0 4px 16px rgba(24, 75, 42, 0.06);
        }

        .resident-top {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px; margin-bottom: 16px;
        }
        .resident-id { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .resident-avatar {
            width: 65px; height: 65px; min-width: 65px; border-radius: 50%;
            object-fit: cover; border: 3px solid #e0eee4; background: #f1f5f2;
        }
        .resident-id h3 { color: #17683a; font-size: 21px; font-weight: 700; margin: 0; overflow-wrap: anywhere; }

        .quick-info-box {
            border: 2px solid #417541; border-radius: 12px; padding: 18px;
            background: #faf7fc; margin-bottom: 18px;
        }
        .info-list { font-size: 14px; line-height: 1.9; color: #303830; }
        .info-list strong { color: #294b35; }

        .action-row { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
        .btn-add-notes {
            color: #fff; background: #198754; border: 2px solid #0b4f2a; font-weight: 700;
            padding: 7px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; font-size: 14px;
        }
        .btn-add-notes:hover { background: #157347; color: #fff; border-color: #0b4f2a; }
        .alerts-link { margin-left: auto; color: #17683a; font-weight: 700; text-decoration: none; font-size: 14px; }
        .alerts-link:hover { text-decoration: underline; color: #17683a; }
        button.alerts-link { background: none; border: 0; padding: 0; }
        .alert-item { border-left: 4px solid #dc3545; background: #fff5f5; border-radius: 8px; padding: 10px 14px; margin-bottom: 10px; }
        .alert-item.observation { border-left-color: #198754; background: #f3faf5; }
        .alert-item .alert-date { font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">

    <div class="residents-header">
        <h1><i class="bi bi-heart-pulse"></i> Health Information</h1>
    </div>

    <?php if ($success === 'note_added'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Observation/Report recorded successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($success === 'health_updated'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Health information updated successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($success === 'entry_deleted'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Entry deleted successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($success === 'concern_reported'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Concern reported successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- Search -->
    <div class="residents-search-panel">
        <form method="GET" action="health_information.php" class="d-flex flex-wrap gap-2 align-items-center">
            <input type="text" name="search" class="form-control flex-grow-1" style="min-width:240px" placeholder="Search resident, name, or ID......" value="<?= h($search) ?>">
            <button type="submit" class="btn btn-success">Search</button>
            <a href="health_information.php" class="btn btn-outline-secondary">Reset</a>
        </form>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 px-1">
        <h2 class="h5 mb-0 text-success fw-bold">List of Residents</h2>
    </div>

    <?php if (empty($assignedResidents)): ?>
        <div class="alert alert-info mb-0">No assigned residents found for your account.</div>
    <?php else: ?>
        <?php foreach ($assignedResidents as $idx => $resident): ?>
            <?php
                $residentPk = (int)$resident['id'];
                $fullName = residentName($resident);
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
                $diet = !empty($healthRecord['diet']) ? $healthRecord['diet'] : (!empty($healthRecord['medical_conditions']) ? $healthRecord['medical_conditions'] : 'Regular');
                $medicationReminders = !empty($healthRecord['medications']) ? $healthRecord['medications'] : 'As prescribed';
                $mobility = !empty($healthRecord['mobility']) ? $healthRecord['mobility'] : (!empty($resident['care_level']) ? 'Care Level: ' . $resident['care_level'] : 'Requires standard monitoring');

                $heart = $healthRecord['heart_rate'] ?? $healthRecord['pulse'] ?? null;
                if (!empty($heart)) {
                    $vitalSigns = 'Heart: ' . $heart . 'bpm';
                } elseif (!empty($healthRecord['blood_pressure'])) {
                    $vitalSigns = 'BP: ' . $healthRecord['blood_pressure'];
                } else {
                    $vitalSigns = 'N/A';
                }

                $specialInstructions = !empty($healthRecord['notes']) ? $healthRecord['notes'] : 'No special notes recorded.';

                $entries = [];
                $concernCount = 0;
                try {
                    $alStmt = $pdo->prepare("
                        SELECT id, notes, record_date FROM health_records
                        WHERE resident_id = :resident_id AND archived_at IS NULL
                          AND (notes LIKE '[Concern]%' OR notes LIKE '[Observation]%')
                        ORDER BY record_date DESC, id DESC
                        LIMIT 50
                    ");
                    $alStmt->execute(['resident_id' => $residentPk]);
                    $entries = $alStmt->fetchAll(PDO::FETCH_ASSOC);
                    $concernCount = count(array_filter($entries, function ($e) {
                        return strpos((string)$e['notes'], '[Concern]') === 0;
                    }));
                } catch (PDOException $ex) {
                    $entries = [];
                }
            ?>

            <div class="single-resident-card" data-resident="<?= $residentPk ?>">
                <div class="resident-top">
                    <div class="resident-id">
                        <img src="<?= h($picUrl) ?>" alt="<?= h($fullName) ?> profile picture" class="resident-avatar" onerror="this.onerror=null;this.src='../images/default-profile.png';">
                        <h3><?= h($fullName) ?></h3>
                    </div>
                </div>

                <div class="quick-info-box">
                    <div class="info-list">
                        <div><strong>Allergies:</strong> <?= h($allergies) ?></div>
                        <div><strong>Diet:</strong> <?= h($diet) ?></div>
                        <div><strong>Medication Reminders:</strong> <?= h($medicationReminders) ?></div>
                        <div><strong>Mobility:</strong> <?= h($mobility) ?></div>
                        <div><strong>Vital Signs:</strong> <?= h($vitalSigns) ?></div>
                        <div><strong>Special Instructions:</strong> <?= h($specialInstructions) ?></div>
                    </div>
                </div>

                <div class="action-row">
                    <button type="button" class="btn btn-add-notes" data-bs-toggle="modal" data-bs-target="#recordModal<?= $residentPk ?>">Record Observation/Report Concern</button>
                    <button type="button" class="btn btn-outline-success fw-bold" data-bs-toggle="modal" data-bs-target="#editModal<?= $residentPk ?>"><i class="bi bi-pencil-square"></i> Edit</button>
                    <button type="button" class="alerts-link" data-bs-toggle="modal" data-bs-target="#alertsModal<?= $residentPk ?>"><i class="bi bi-check-lg"></i> View Health Alerts<?= $concernCount ? ' (' . $concernCount . ')' : '' ?></button>
                </div>
            </div>

            <div class="modal fade" id="alertsModal<?= $residentPk ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Health Alerts - <?= h($fullName) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <?php if (empty($entries)): ?>
                                <div class="text-muted"><i class="bi bi-check-circle text-success"></i> No concerns or observations recorded for this resident.</div>
                            <?php else: ?>
                                <?php foreach ($entries as $c): ?>
                                    <?php
                                        $isConcern = strpos((string)$c['notes'], '[Concern]') === 0;
                                        $entryText = trim(preg_replace('/^\\[(Concern|Observation)\\]:\\s*/', '', (string)$c['notes']));
                                    ?>
                                    <div class="alert-item <?= $isConcern ? '' : 'observation' ?>">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <div class="flex-grow-1" style="min-width:0">
                                                <span class="badge <?= $isConcern ? 'bg-danger' : 'bg-success' ?> mb-1"><?= $isConcern ? 'Concern' : 'Observation' ?></span>
                                                <div style="overflow-wrap:anywhere"><?= h($entryText) ?></div>
                                                <div class="alert-date"><i class="bi bi-clock"></i> <?= h(!empty($c['record_date']) ? date('M j, Y g:i A', strtotime($c['record_date'])) : '') ?></div>
                                            </div>
                                            <form method="POST" action="health_information.php" onsubmit="return confirm('Delete this <?= $isConcern ? 'concern' : 'observation' ?>? This cannot be undone from this page.');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="action" value="delete_entry">
                                                <input type="hidden" name="resident_id" value="<?= $residentPk ?>">
                                                <input type="hidden" name="entry_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete this entry"><i class="bi bi-trash"></i> Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="editModal<?= $residentPk ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <form method="POST" action="health_information.php">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Health Information - <?= h($fullName) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="edit_health">
                                <input type="hidden" name="resident_id" value="<?= $residentPk ?>">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Allergies</label>
                                        <textarea class="form-control" name="allergies" rows="2" placeholder="e.g. Penicillin, peanuts"><?= h($healthRecord['allergies'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Diet</label>
                                        <textarea class="form-control" name="diet" rows="2" placeholder="e.g. Low sodium, diabetic"><?= h($healthRecord['diet'] ?? $healthRecord['medical_conditions'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Medication Reminders</label>
                                        <textarea class="form-control" name="medications" rows="2" placeholder="e.g. Metformin 500mg after breakfast"><?= h($healthRecord['medications'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Mobility</label>
                                        <textarea class="form-control" name="mobility" rows="2" placeholder="e.g. Uses a walker"><?= h($healthRecord['mobility'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Blood Pressure</label>
                                        <input type="text" class="form-control" name="blood_pressure" placeholder="e.g. 120/80" value="<?= h($healthRecord['blood_pressure'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Heart Rate (bpm)</label>
                                        <input type="number" min="0" max="300" class="form-control" name="heart_rate" placeholder="e.g. 72" value="<?= h($heart ?? '') ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold">Special Instructions</label>
                                        <textarea class="form-control" name="notes" rows="3" placeholder="Special instructions or notes"><?= h($healthRecord['notes'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="recordModal<?= $residentPk ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="health_information.php">
                            <div class="modal-header">
                                <h5 class="modal-title">Record Observation/Report Concern - <?= h($fullName) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="add_note">
                                <input type="hidden" name="resident_id" value="<?= $residentPk ?>">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Type</label>
                                    <select name="entry_type" class="form-select">
                                        <option value="observation">Observation</option>
                                        <option value="concern">Concern</option>
                                    </select>
                                </div>
                                <textarea class="form-control" name="additional_notes" rows="4" required placeholder="Enter your observation or concern..."></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">Save</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    // After deleting an entry, scroll back to that resident and reopen the alerts popup
    const params = new URLSearchParams(window.location.search);
    const rid = params.get('resident');
    if (rid && /^\d+$/.test(rid) && params.get('success') === 'entry_deleted') {
        const card = document.querySelector('.single-resident-card[data-resident="' + rid + '"]');
        const modalEl = document.getElementById('alertsModal' + rid);
        if (card) card.scrollIntoView({ block: 'center' });
        if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
})();
</script>
</body>
</html>