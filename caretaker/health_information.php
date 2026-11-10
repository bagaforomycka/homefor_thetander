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

function e($value): string
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
    $path = __DIR__ . '/../admin/uploads/residentspic/' . $filename;
    if (!is_file($path)) {
        return '../images/default-profile.png';
    }
    return '../admin/uploads/residentspic/' . rawurlencode($filename);
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
$csrfToken = $_SESSION['caretaker_report_csrf'];

$success = $_GET['success'] ?? '';
$error = '';

/* Handle Adding Additional Notes via POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_note') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page.');
    }

    $additionalNotes = trim($_POST['additional_notes'] ?? '');
    $residentId = filter_input(INPUT_POST, 'resident_id', FILTER_VALIDATE_INT);

    if ($residentId && $additionalNotes !== '') {
        try {
            $stmt = $pdo->prepare("
                UPDATE health_records 
                SET notes = CONCAT(IFNULL(notes, ''), '\n', :new_notes)
                WHERE resident_id = :resident_id 
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([
                'new_notes' => $additionalNotes,
                'resident_id' => $residentId
            ]);
            header('Location: caretaker_report.php?resident_id=' . $residentId . '&success=note_added');
            exit;
        } catch (Throwable $e) {
            error_log('Error updating notes: ' . $e->getMessage());
            $error = 'Failed to update additional notes.';
        }
    }
}

try {
    archiveEnsureCaretakerTable($pdo);
    $residentVisible = archiveResidentVisibleSql($pdo, '');
} catch (Throwable $e) {
    $residentVisible = '1=1';
}

/* Fetch All Assigned Residents */
$assignedResidents = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, resident_id, first_name, middle_name, last_name, date_of_birth, room_number, status, profile_picture, care_level
        FROM residents
        WHERE assigned_caretaker = :caretaker
          AND $residentVisible
          AND NOT EXISTS (
              SELECT 1 FROM caretaker_resident_archive ca
              WHERE ca.resident_id = residents.id
                AND ca.caretaker_user_id = :caretaker_archive
          )
        ORDER BY first_name ASC, last_name ASC
    ");
    $stmt->execute(['caretaker' => $caretakerId, 'caretaker_archive' => $caretakerId]);
    $assignedResidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Caretaker report residents fetch error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CareTaker Report - Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link 
        rel="stylesheet" 
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #eef1ee;
            font-family: Arial, sans-serif;
            color: #303830;
            display: flex;
            min-height: 100vh;
        }

        .main-content, .app-main {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            padding: 20px;
            flex-grow: 1;
            transition: margin-left 0.3s ease;
        }

        .sidebar-toggle-btn {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 6px;
            padding: 6px;
            cursor: pointer;
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 4px;
            width: 36px;
            height: 36px;
            margin: 12px 0;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .sidebar-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
        }

        .sidebar-toggle-btn .hamburger-line {
            display: block;
            width: 18px;
            height: 2px;
            background-color: #ffffff;
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        .sidebar-toggle-wrapper {
            padding-left: 15px;
            width: 100%;
            display: flex;
            justify-content: flex-start;
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
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
            display: flex;
            flex-direction: column;
        }

        @media (min-width: 993px) {
            body.sidebar-folded .sidebar,
            body.sidebar-folded #sidebar,
            body.sidebar-folded .sidebar-container,
            body.sidebar-folded aside,
            body.sidebar-folded .app-sidebar {
                width: 70px;
                min-width: 70px;
            }

            body.sidebar-folded .sidebar > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded #sidebar > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded .sidebar-container > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded aside > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded .app-sidebar > *:not(.sidebar-toggle-wrapper) {
                display: none !important;
            }

            body.sidebar-folded .sidebar-toggle-wrapper {
                display: flex !important;
                justify-content: center !important;
                padding-left: 0 !important;
                width: 100% !important;
                margin-top: 12px !important;
            }
        }

        @media (max-width: 992px) {
            .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100%;
                transform: translateX(-100%);
                width: 260px !important;
            }

            body.sidebar-open .sidebar,
            body.sidebar-open #sidebar,
            body.sidebar-open .sidebar-container,
            body.sidebar-open aside,
            body.sidebar-open .app-sidebar {
                transform: translateX(0);
            }
        }

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

        .search-panel {
            background: #f3faf5;
            border: 1px solid #e3eee6;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 22px;
            box-shadow: 0 4px 16px rgba(24, 75, 42, .05);
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .search-panel form {
            position: relative;
            flex: 1;
            min-width: 240px;
        }

        .search-panel input {
            border-radius: 20px;
            padding-left: 42px;
            padding-right: 42px;
            border: 1px solid #d5eadc;
            background-color: #fff;
            height: 42px;
            font-size: 15px;
            width: 100%;
        }

        .search-panel .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #61796a;
            font-size: 16px;
        }

        .search-panel .mic-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #61796a;
            font-size: 16px;
            cursor: pointer;
        }

        .page-header {
            background: #e7f5ec;
            border: 1px solid #d5eadc;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-header i {
            font-size: 32px;
            color: #17683a;
        }

        .page-header h1 {
            color: #17683a;
            font-size: 28px;
            margin: 0;
            font-weight: 700;
        }

        .health-panel {
            background: #fff;
            border: 1px solid #e3eee6;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 16px rgba(24, 75, 42, .05);
        }

        .quick-info-box {
            border: 2px solid #a855f7;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .quick-info-box:last-child {
            margin-bottom: 0;
        }

        .info-left {
            flex: 1;
            min-width: 260px;
            padding-right: 20px;
            border-right: 1px solid #d5eadc;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .resident-avatar {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            object-fit: cover;
            background-color: #198754;
            border: 2px solid #e1ebe4;
            flex-shrink: 0;
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
            margin-bottom: 12px;
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
        }

        .notes-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notes-title-wrap i {
            font-size: 26px;
            color: #17683a;
        }

        .notes-title-wrap h2 {
            color: #17683a;
            font-size: 22px;
            margin: 0;
            font-weight: 700;
        }

        .btn-add-notes {
            color: #fff;
            background: #198754;
            border: 1px solid #198754;
            font-weight: 700;
            padding: 8px 18px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-add-notes:hover {
            background: #157347;
            color: #fff;
        }

        @media (max-width: 768px) {
            .info-left {
                border-right: none;
                border-bottom: 1px solid #d5eadc;
                padding-right: 0;
                padding-bottom: 16px;
                margin-bottom: 16px;
            }
            .info-right {
                padding-left: 0;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
 
    <!-- Search Panel -->
    <div class="search-panel">
        <form method="GET" action="caretaker_report.php">
            <i class="bi bi-search search-icon"></i>
            <input 
                type="text" 
                name="search" 
                placeholder="Search resident, name, or ID......" 
                value="<?= e($_GET['search'] ?? '') ?>"
            >
            <i class="bi bi-mic mic-icon"></i>
        </form>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <i class="bi bi-clipboard2-check"></i>
        <h1>CareTaker Report</h1>
    </div>
 
    <?php if ($success === 'note_added'): ?>
        <div class="alert alert-success">
            Additional note added successfully.
        </div>
    <?php endif; ?>
 
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (empty($assignedResidents)): ?>
        <div class="alert alert-info">
            No residents assigned to your caretaker account.
        </div>
    <?php else: ?>
        <?php foreach ($assignedResidents as $res): ?>
            <?php
                $residentId = (int)$res['id'];
                $fullName = residentName($res);
                $age = calculateAge($res['date_of_birth'] ?? null);
                $room = $res['room_number'] ?: 'Not assigned';
                $status = $res['status'] ?: 'Stable';

                // Fetch latest health record for this specific resident
                $hrStmt = $pdo->prepare("
                    SELECT * FROM health_records
                    WHERE resident_id = :resident_id AND archived_at IS NULL
                    ORDER BY record_date DESC, id DESC
                    LIMIT 1
                ");
                $hrStmt->execute(['resident_id' => $residentId]);
                $healthRecord = $hrStmt->fetch(PDO::FETCH_ASSOC);

                $allergies = $healthRecord['allergies'] ?? 'None recorded';
                $diet = $healthRecord['diet'] ?? 'Low Sodium / Regular';
                $medications = $healthRecord['medications'] ?? 'None recorded';
                $mobility = $res['care_level'] ?? 'Requires assistance when walking';
                $vitalSigns = $healthRecord ? ('BP: ' . ($healthRecord['blood_pressure'] ?: 'N/A') . ', Temp: ' . ($healthRecord['temperature'] ? $healthRecord['temperature'].'°C' : 'N/A')) : 'Heart: 76bpm';
                $specialInstructions = $healthRecord['notes'] ?? 'Observe for daily comfort and care.';
            ?>

            <!-- Quick Info Panel for Resident -->
            <div class="health-panel">
                <div class="quick-info-box">
                    <div class="info-left">
                        <img 
                            src="<?= e(residentPicture($res['profile_picture'] ?? '')) ?>" 
                            alt="<?= e($fullName) ?>" 
                            class="resident-avatar"
                        >
                        <div class="patient-details">
                            <h3><?= e($fullName) ?></h3>
                            <div class="resident-meta">
                                Age: <?= e($age) ?>  |  Room: <?= e($room) ?>  |  ID: <?= e($res['resident_id']) ?>
                            </div>
                            <div class="status-badge">
                                <span class="status-dot"></span>
                                <?= e($status) ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="info-right">
                        <div class="info-right-title">Quick Info</div>
                        <div class="info-list">
                            <div><strong>Allergies:</strong> <?= e($allergies) ?></div>
                            <div><strong>Diet:</strong> <?= e($diet) ?></div>
                            <div><strong>Medication Reminders:</strong> <?= e($medications) ?></div>
                            <div><strong>Mobility:</strong> <?= e($mobility) ?></div>
                            <div><strong>Vital Signs:</strong> <?= e($vitalSigns) ?></div>
                            <div><strong>Special Instructions:</strong> <?= e($specialInstructions) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Activity Report Table for Resident -->
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
                                    <td class="col-time"><?= e(date('h:i A', strtotime($healthRecord['record_date']))) ?></td>
                                    <td class="col-activity">Health Check & Vital Signs</td>
                                    <td class="col-notes"><?= e($healthRecord['notes'] ?: 'Record logged successfully.') ?></td>
                                    <td class="col-status">Completed</td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td class="col-time">8:00 AM</td>
                                    <td class="col-activity">Morning Routine & Medication</td>
                                    <td class="col-notes">Administered as prescribed.</td>
                                    <td class="col-status">Completed</td>
                                </tr>
                                <tr>
                                    <td class="col-time">1:00 PM</td>
                                    <td class="col-activity">Lunch & Hydration</td>
                                    <td class="col-notes">Ate meal completely.</td>
                                    <td class="col-status">Completed</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Additional Notes Header & Trigger Button -->
                <div class="notes-card-header">
                    <div class="notes-title-wrap">
                        <i class="bi bi-clipboard2-check"></i>
                        <h2>Additional Notes</h2>
                    </div>
                    <button type="button" class="btn btn-success btn-add-notes" data-bs-toggle="modal" data-bs-target="#addNotesModal<?= $residentId ?>">
                        <i class="bi bi-pencil-fill"></i> Add Notes
                    </button>
                </div>
            </div>

            <!-- Modal for Adding Notes for this Resident -->
            <div class="modal fade" id="addNotesModal<?= $residentId ?>" tabindex="-1" aria-labelledby="addNotesModalLabel<?= $residentId ?>" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="caretaker_report.php">
                            <div class="modal-header">
                                <h5 class="modal-title" id="addNotesModalLabel<?= $residentId ?>">Add Additional Notes - <?= e($fullName) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="add_note">
                                <input type="hidden" name="resident_id" value="<?= $residentId ?>">
                                
                                <div class="mb-3">
                                    <label for="additional_notes_<?= $residentId ?>" class="form-label">Note Content</label>
                                    <textarea 
                                        class="form-control" 
                                        id="additional_notes_<?= $residentId ?>" 
                                        name="additional_notes" 
                                        rows="4" 
                                        required
                                        placeholder="Enter any additional observations or notes..."
                                    ></textarea>
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

<!-- Hamburger Toggle & Sidebar Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('sidebarOverlay');
    var sidebar = document.querySelector(
        '.sidebar, #sidebar, .sidebar-container, aside, .app-sidebar'
    );
 
    if (!sidebar) {
        return;
    }
 
    if (!sidebar.querySelector('.sidebar-logo')) {
        var logo = document.createElement('div');
        logo.className = 'sidebar-logo';
        logo.innerHTML =
            '<a href="dashboard.php">' +
            '<img src="../images/white1.png" ' +
            'alt="Home for the Aged - Care, Comfort, Dignity">' +
            '</a>';
        sidebar.insertBefore(logo, sidebar.firstChild);
    }

    if (!sidebar.querySelector('#sidebarToggle')) {
        var toggleWrapper = document.createElement('div');
        toggleWrapper.className = 'sidebar-toggle-wrapper';
        toggleWrapper.innerHTML = `
            <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Toggle Navigation Sidebar">
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
            </button>
        `;
        sidebar.insertBefore(toggleWrapper, sidebar.firstChild);
    }

    var toggleBtn = document.getElementById('sidebarToggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            if (window.innerWidth <= 992) {
                document.body.classList.toggle('sidebar-open');
                if (overlay) overlay.classList.toggle('active');
            } else {
                document.body.classList.toggle('sidebar-folded');
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            document.body.classList.remove('sidebar-open');
            overlay.classList.remove('active');
        });
    }
});
</script>
 
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>