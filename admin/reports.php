
<?php
/**
 * HOME FOR THE AGED
 * ADMIN REPORTS MODULE
 * File: admin/reports.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

if (strtolower(trim($_SESSION['role'])) !== 'admin') {
    http_response_code(403);
    exit('Access denied. Administrators only.');
}

require_once __DIR__ . '/../config/db.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (empty($_SESSION['reports_csrf'])) {
    $_SESSION['reports_csrf'] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function reportDate($date): string
{
    $time = strtotime((string)$date);
    return $time ? date('M d, Y', $time) : '';
}

$types = [
    'Fall',
    'Injury',
    'Medical Emergency',
    'Medication Issue',
    'Behavioral',
    'Wandering',
    'Safety Hazard',
    'Complaint',
    'Other'
];

$statuses = ['Open', 'In Progress', 'Resolved'];

$tab = $_POST['tab'] ?? $_GET['tab'] ?? 'active';

if (!in_array($tab, ['active', 'archive', 'add'], true)) {
    $tab = 'active';
}

$error = '';
$success = '';

/* CREATE REPORTS TABLE IF IT DOES NOT EXIST */

$pdo->exec("
    CREATE TABLE IF NOT EXISTS incident_reports (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        report_type VARCHAR(40) NOT NULL,
        related_to ENUM('Resident','Caretaker','General')
            NOT NULL DEFAULT 'General',
        resident_id INT NULL,
        caretaker_name VARCHAR(150) NULL,
        location VARCHAR(100) NULL,
        report_date DATE NOT NULL,
        details TEXT NOT NULL,
        status ENUM('Open','In Progress','Resolved')
            NOT NULL DEFAULT 'Open',
        created_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        archived_at DATETIME NULL DEFAULT NULL,
        INDEX idx_status (status),
        INDEX idx_archived (archived_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* CHECK RESIDENT TABLE COLUMNS */

$residentColumns = $pdo->query(
    "SHOW COLUMNS FROM residents"
)->fetchAll(PDO::FETCH_COLUMN);

$hasResidentArchive = in_array('archived_at', $residentColumns, true);

$residentArchiveCondition = $hasResidentArchive
    ? "AND archived_at IS NULL"
    : "AND COALESCE(status, '') <> 'archived'";

/* PROCESS FORM ACTIONS */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        if (!hash_equals(
            $_SESSION['reports_csrf'],
            (string)($_POST['csrf_token'] ?? '')
        )) {
            throw new RuntimeException(
                'Invalid security token. Please refresh the page.'
            );
        }

        $action = $_POST['action'] ?? '';

        /* ADD REPORT */

        if ($action === 'add') {

            $reportType = trim($_POST['report_type'] ?? '');
            $relatedTo = trim($_POST['related_to'] ?? 'General');
            $residentId = $_POST['resident_id'] ?? null;
            $caretakerName = trim($_POST['caretaker_name'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $reportDateValue = trim($_POST['report_date'] ?? '');
            $details = trim($_POST['details'] ?? '');

            if (!in_array($reportType, $types, true)) {
                throw new RuntimeException('Please select a valid report type.');
            }

            if (!in_array($relatedTo, ['Resident', 'Caretaker', 'General'], true)) {
                throw new RuntimeException('Please select a valid related person.');
            }

            $dateObject = DateTime::createFromFormat(
                '!Y-m-d',
                $reportDateValue
            );

            if (
                !$dateObject ||
                $dateObject->format('Y-m-d') !== $reportDateValue
            ) {
                throw new RuntimeException('Please enter a valid report date.');
            }

            if ($reportDateValue > date('Y-m-d', strtotime('+1 day'))) {
                throw new RuntimeException('Report date cannot be in the future.');
            }

            if ($details === '') {
                throw new RuntimeException('Please enter the report details.');
            }

            if (mb_strlen($details) > 5000 || mb_strlen($location) > 100) {
                throw new RuntimeException('Please check the length of your information.');
            }

            if ($relatedTo === 'Resident') {

                if (!filter_var($residentId, FILTER_VALIDATE_INT)) {
                    throw new RuntimeException('Please select a resident.');
                }

                $check = $pdo->prepare("
                    SELECT id
                    FROM residents
                    WHERE id = :id
                    $residentArchiveCondition
                    LIMIT 1
                ");

                $check->execute(['id' => $residentId]);

                if (!$check->fetch()) {
                    throw new RuntimeException('Resident not found or archived.');
                }

            } elseif ($relatedTo === 'Caretaker') {

                if ($caretakerName === '') {
                    throw new RuntimeException('Please select a caretaker.');
                }

                $check = $pdo->prepare("
                    SELECT id
                    FROM caretakers
                    WHERE full_name = :name
                    AND archived_at IS NULL
                    LIMIT 1
                ");

                $check->execute(['name' => $caretakerName]);

                if (!$check->fetch()) {
                    throw new RuntimeException('Caretaker not found or archived.');
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO incident_reports (
                    report_type,
                    related_to,
                    resident_id,
                    caretaker_name,
                    location,
                    report_date,
                    details,
                    status,
                    created_by
                )
                VALUES (
                    :type,
                    :related,
                    :resident,
                    :caretaker,
                    :location,
                    :report_date,
                    :details,
                    'Open',
                    :creator
                )
            ");

            $stmt->execute([
                'type' => $reportType,
                'related' => $relatedTo,
                'resident' => $relatedTo === 'Resident' ? $residentId : null,
                'caretaker' => $relatedTo === 'Caretaker' ? $caretakerName : null,
                'location' => $location ?: null,
                'report_date' => $reportDateValue,
                'details' => $details,
                'creator' => (string)$_SESSION['user_id']
            ]);

            header('Location: reports.php?tab=active&success=added');
            exit;
        }

        /* UPDATE REPORT STATUS */

        if ($action === 'update_status') {

            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $status = trim($_POST['status'] ?? '');

            if (!$id) {
                throw new RuntimeException('Invalid report ID.');
            }

            if (!in_array($status, $statuses, true)) {
                throw new RuntimeException('Please select a valid report status.');
            }

            // Make sure the report exists and is not archived.
            $check = $pdo->prepare("
                SELECT id
                FROM incident_reports
                WHERE id = :id
                AND archived_at IS NULL
                LIMIT 1
            ");

            $check->execute(['id' => $id]);

            if (!$check->fetch()) {
                throw new RuntimeException('Report not found or already archived.');
            }

            // Save the new status.
            $update = $pdo->prepare("
                UPDATE incident_reports
                SET status = :status
                WHERE id = :id
                AND archived_at IS NULL
            ");

            $update->execute([
                'status' => $status,
                'id' => $id
            ]);

            header('Location: reports.php?tab=active&success=updated');
            exit;
        }

        /* ARCHIVE REPORT */

        if ($action === 'archive') {

            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$id) {
                throw new RuntimeException('Invalid report ID.');
            }

            $stmt = $pdo->prepare("
                UPDATE incident_reports
                SET archived_at = NOW()
                WHERE id = :id
                AND archived_at IS NULL
            ");

            $stmt->execute(['id' => $id]);

            if (!$stmt->rowCount()) {
                throw new RuntimeException('Report not found.');
            }

            header('Location: reports.php?tab=active&success=archived');
            exit;
        }

        /* RESTORE REPORT */

        if ($action === 'restore') {

            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$id) {
                throw new RuntimeException('Invalid report ID.');
            }

            $stmt = $pdo->prepare("
                UPDATE incident_reports
                SET archived_at = NULL
                WHERE id = :id
                AND archived_at IS NOT NULL
            ");

            $stmt->execute(['id' => $id]);

            if (!$stmt->rowCount()) {
                throw new RuntimeException('Archived report not found.');
            }

            header('Location: reports.php?tab=archive&success=restored');
            exit;
        }

        /* PERMANENT DELETE */

        if ($action === 'delete') {

            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$id) {
                throw new RuntimeException('Invalid report ID.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM incident_reports
                WHERE id = :id
                AND archived_at IS NOT NULL
            ");

            $stmt->execute(['id' => $id]);

            if (!$stmt->rowCount()) {
                throw new RuntimeException(
                    'Only archived reports can be permanently deleted.'
                );
            }

            header('Location: reports.php?tab=archive&success=deleted');
            exit;
        }

        throw new RuntimeException('Invalid action.');

    } catch (Throwable $e) {

        error_log('Reports error: ' . $e->getMessage());

        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to process the report. Please check your database.';
    }
}

/* SUCCESS MESSAGES */

$messages = [
    'added' => 'Report submitted successfully.',
    'updated' => 'Report status updated successfully.',
    'archived' => 'Report moved to archive.',
    'restored' => 'Report restored successfully.',
    'deleted' => 'Report permanently deleted.'
];

$success = $messages[$_GET['success'] ?? ''] ?? '';

/* LOAD REPORTS */

$archived = $tab === 'archive';

$stmt = $pdo->prepare("
    SELECT
        i.*,
        r.first_name,
        r.middle_name,
        r.last_name
    FROM incident_reports i
    LEFT JOIN residents r ON r.id = i.resident_id
    WHERE i.archived_at " . ($archived ? "IS NOT NULL" : "IS NULL") . "
    ORDER BY i.report_date DESC, i.id DESC
");

$stmt->execute();
$reports = $stmt->fetchAll();

/* LOAD RESIDENTS */

$residents = $pdo->query("
    SELECT id, resident_id, first_name, middle_name, last_name
    FROM residents
    WHERE " . ($hasResidentArchive
        ? "archived_at IS NULL AND COALESCE(status, '') <> 'archived'"
        : "COALESCE(status, '') <> 'archived'") . "
    ORDER BY last_name, first_name
")->fetchAll();

/* LOAD CARETAKERS */

$caretakers = $pdo->query("
    SELECT full_name
    FROM caretakers
    WHERE archived_at IS NULL
    AND full_name <> ''
    ORDER BY full_name
")->fetchAll(PDO::FETCH_COLUMN);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Reports | Home for the Aged</title>

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

.app-main {
    margin-left: 250px !important;
    width: calc(100% - 250px) !important;
    min-height: 100vh;
    padding: 20px 24px !important;
    overflow-x: hidden;
}

.page-header {
    background: #edf8ef;
    border: 1px solid #dcecdf;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 18px;
}

.page-header h1 {
    color: #17683a;
    font-size: 23px;
    font-weight: 700;
    margin-bottom: 5px;
}

.page-header p {
    color: #325a42;
    font-size: 13px;
    margin: 0;
}

.panel {
    background: #ffffff;
    border: 1px solid #e3eee6;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 18px;
    box-shadow: 0 2px 10px rgba(216, 241, 225, 0.25);
}

.report-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 18px;
}

.report-tabs a {
    padding: 10px 17px;
    border-radius: 7px;
    border: 1px solid #cedbd1;
    background: #ffffff;
    color: #304b3b;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.report-tabs a.active,
.report-tabs a:hover {
    background: #17683a;
    color: #ffffff;
    border-color: #17683a;
}

.form-label {
    color: #17683a;
    font-size: 13px;
    font-weight: 600;
}

.form-control,
.form-select {
    font-size: 13px;
    min-height: 40px;
    border-color: #cedbd1;
}

.form-control:focus,
.form-select:focus {
    border-color: #33b960;
    box-shadow: 0 0 0 .15rem rgba(51, 185, 96, 0.15);
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

.table {
    font-size: 13px;
    vertical-align: middle;
}

.table th {
    background: #edf8ef;
    color: #17683a;
    white-space: nowrap;
    padding: 12px;
}

.table td {
    padding: 12px;
}

.status {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.status-open {
    background: #fde2e2;
    color: #c0262d;
}

.status-progress {
    background: #fff1c9;
    color: #b26a00;
}

.status-resolved {
    background: #d9f5e3;
    color: #17683a;
}

.btn-success {
    background: #17683a;
    border-color: #17683a;
}

.btn-success:hover {
    background: #0b5425;
    border-color: #0b5425;
}

.btn-sm {
    font-size: 12px;
}

.section-title {
    color: #17683a;
    font-size: 17px;
    font-weight: 700;
    margin-bottom: 15px;
}

.details-text {
    max-width: 250px;
    white-space: normal;
    overflow-wrap: anywhere;
}

.edit-status-btn {
    background: #ffffff;
    color: #17683a;
    border: 1px solid #c2c500;
    border-radius: 5px;
    padding: 5px 10px;
    font-size: 12px;
    font-weight: 600;
}

.edit-status-btn:hover {
    background: #04692be5;
    color: #ffffff;
}

.modal-content {
    border: 1px solid #dcecdf;
    border-radius: 10px;
}

.modal-header {
    background: #edf8ef;
}

.modal-title {
    color: #17683a;
}

@media(max-width: 760px) {

    .app-main {
        margin-left: 0 !important;
        width: 100% !important;
        padding: 12px !important;
    }

    .panel {
        padding: 14px;
    }

    .page-header {
        padding: 15px;
    }
}

@media print {

    .app-sidebar,
    .report-tabs,
    .no-print {
        display: none !important;
    }

    .app-main {
        margin: 0 !important;
        width: 100% !important;
        padding: 0 !important;
    }

    body {
        background: #ffffff !important;
    }
}

</style>

</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<!-- PAGE HEADER -->

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">

    <div>
        <h1>Reports</h1>
        <p>Manage and monitor resident and caretaker incident reports.</p>
    </div>

    <div class="d-flex flex-wrap gap-2 no-print">


        <button onclick="window.print()" class="btn btn-outline-success btn-sm">
            Print
        </button>

    </div>

</div>

<!-- NAVIGATION -->

<nav class="report-tabs no-print">

    <a href="reports.php?tab=active"
       class="<?= $tab === 'active' ? 'active' : '' ?>">
        Active Reports
    </a>

    <a href="reports.php?tab=archive"
       class="<?= $tab === 'archive' ? 'active' : '' ?>">
        Archived Reports
    </a>

    <a href="reports.php?tab=add"
       class="<?= $tab === 'add' ? 'active' : '' ?>">
        Add Report
    </a>

</nav>

<!-- MESSAGES -->

<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>


<!-- ADD REPORT -->

<?php if ($tab === 'add'): ?>

<section class="panel">

    <h2 class="section-title">Add New Report</h2>

    <form method="POST" action="reports.php?tab=add">

        <input type="hidden" name="csrf_token"
               value="<?= h($_SESSION['reports_csrf']) ?>">

        <input type="hidden" name="action" value="add">
        <input type="hidden" name="tab" value="add">

        <div class="row g-3">

            <div class="col-md-6">

                <label class="form-label">Report Type *</label>

                <select name="report_type" class="form-select" required>

                    <option value="">Select report type</option>

                    <?php foreach ($types as $type): ?>
                        <option value="<?= h($type) ?>">
                            <?= h($type) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

            </div>

            <div class="col-md-6">

                <label class="form-label">Related To *</label>

                <select name="related_to" id="related_to"
                        class="form-select" required>

                    <option value="Resident">Resident</option>
                    <option value="Caretaker">Caretaker</option>
                    <option value="General">General</option>

                </select>

            </div>

            <div class="col-md-6" id="residentGroup">

                <label class="form-label">Select Resident *</label>

                <select name="resident_id" id="resident_id"
                        class="form-select">

                    <option value="">Select resident</option>

                    <?php foreach ($residents as $r): ?>

                        <option value="<?= (int)$r['id'] ?>">
                            <?= h(trim(
                                $r['first_name'] . ' ' .
                                $r['middle_name'] . ' ' .
                                $r['last_name']
                            )) ?>
                            (<?= h($r['resident_id']) ?>)
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="col-md-6" id="caretakerGroup"
                 style="display:none;">

                <label class="form-label">Select Caretaker *</label>

                <select name="caretaker_name" id="caretaker_name"
                        class="form-select">

                    <option value="">Select caretaker</option>

                    <?php foreach ($caretakers as $c): ?>
                        <option value="<?= h($c) ?>">
                            <?= h($c) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

            </div>

            <div class="col-md-6">

                <label class="form-label">Report Date *</label>

                <input type="date" name="report_date"
                       class="form-control"
                       value="<?= date('Y-m-d') ?>" required>

            </div>

            <div class="col-md-6">

                <label class="form-label">Location</label>

                <input type="text" name="location"
                       class="form-control"
                       maxlength="100"
                       placeholder="e.g. Room 101">

            </div>

            <div class="col-12">

                <label class="form-label">Report Details *</label>

                <textarea name="details" class="form-control"
                          rows="5" maxlength="5000"
                          placeholder="Describe what happened..."
                          required></textarea>

            </div>

        </div>

        <div class="d-flex justify-content-end gap-2 mt-4">

            <a href="reports.php?tab=active"
               class="btn btn-outline-secondary">
                Cancel
            </a>

            <button type="submit" class="btn btn-success">
                Submit Report
            </button>

        </div>

    </form>

</section>

<?php endif; ?>


<!-- ACTIVE AND ARCHIVED REPORTS -->

<?php if ($tab === 'active' || $tab === 'archive'): ?>

<section class="panel">

    <div class="d-flex flex-wrap justify-content-between
                align-items-center gap-2 mb-3">

        <div>

            <h2 class="section-title mb-1">
                <?= $tab === 'archive' ? 'Archived Reports' : 'Active Reports' ?>
            </h2>

            <small class="text-secondary">
                <?= count($reports) ?> report(s) found.
            </small>

        </div>

        <?php if ($tab === 'active'): ?>

          

        <?php endif; ?>

    </div>

    <div class="table-wrap">

        <table class="table table-hover mb-0">

            <thead>

                <tr>
                    <th>Report ID</th>
                    <th>Date</th>
                    <th>Report Type</th>
                    <th>Related To</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>

            </thead>

            <tbody>

            <?php if (!$reports): ?>

                <tr>
                    <td colspan="7" class="text-center text-secondary py-4">
                        No reports found.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($reports as $r): ?>

                    <?php

                    $related = $r['related_to'];

                    if ($related === 'Resident') {

                        $name = trim(
                            ($r['first_name'] ?? '') . ' ' .
                            ($r['middle_name'] ?? '') . ' ' .
                            ($r['last_name'] ?? '')
                        );

                        $related .= ': ' . ($name ?: 'Resident');

                    } elseif ($related === 'Caretaker') {

                        $related .= ': ' . ($r['caretaker_name'] ?? '');

                    }

                    $statusClass = match ($r['status']) {
                        'Open' => 'status-open',
                        'In Progress' => 'status-progress',
                        'Resolved' => 'status-resolved',
                        default => 'status-open'
                    };

                    ?>

                    <tr>

                        <td>
                            INC-<?= str_pad((string)$r['id'], 3, '0', STR_PAD_LEFT) ?>
                        </td>

                        <td><?= h(reportDate($r['report_date'])) ?></td>

                        <td><?= h($r['report_type']) ?></td>

                        <td><?= h($related) ?></td>

                        <td><?= h($r['location'] ?: '—') ?></td>

                        <td>

                            <span class="status <?= $statusClass ?>">
                                <?= h($r['status']) ?>
                            </span>

                        </td>

                        <td>

                            <div class="d-flex flex-wrap gap-1">

                                <!-- VIEW BUTTON -->

                                <button type="button"
                                        class="btn btn-outline-success btn-sm viewReport"
                                        data-bs-toggle="modal"
                                        data-bs-target="#viewModal"
                                        data-id="<?= (int)$r['id'] ?>"
                                        data-type="<?= h($r['report_type']) ?>"
                                        data-related="<?= h($related) ?>"
                                        data-date="<?= h(reportDate($r['report_date'])) ?>"
                                        data-location="<?= h($r['location']) ?>"
                                        data-status="<?= h($r['status']) ?>"
                                        data-details="<?= h($r['details']) ?>">

                                    View

                                </button>

                                <?php if ($tab === 'active'): ?>

                                    <!-- EDIT STATUS BUTTON -->

                                    <button type="button"
                                            class="edit-status-btn editStatus"
                                            data-id="<?= (int)$r['id'] ?>"
                                            data-status="<?= h($r['status']) ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#statusModal">

                                        Edit Status

                                    </button>

                                    <!-- ARCHIVE BUTTON -->

                                    <form method="POST"
                                          action="reports.php?tab=active"
                                          onsubmit="return confirm('Move this report to archive?');">

                                        <input type="hidden" name="csrf_token"
                                               value="<?= h($_SESSION['reports_csrf']) ?>">

                                        <input type="hidden" name="id"
                                               value="<?= (int)$r['id'] ?>">

                                        <input type="hidden" name="tab"
                                               value="active">

                                        <button type="submit"
                                                name="action"
                                                value="archive"
                                                class="btn btn-outline-danger btn-sm">

                                            Archive

                                        </button>

                                    </form>

                                <?php else: ?>

                                    <!-- RESTORE AND DELETE BUTTONS -->

                                    <form method="POST"
                                          action="reports.php?tab=archive"
                                          class="d-flex flex-wrap gap-1">

                                        <input type="hidden" name="csrf_token"
                                               value="<?= h($_SESSION['reports_csrf']) ?>">

                                        <input type="hidden" name="id"
                                               value="<?= (int)$r['id'] ?>">

                                        <input type="hidden" name="tab"
                                               value="archive">

                                        <button type="submit"
                                                name="action"
                                                value="restore"
                                                class="btn btn-success btn-sm">

                                            Restore

                                        </button>

                                        <button type="submit"
                                                name="action"
                                                value="delete"
                                                class="btn btn-danger btn-sm"
                                                onclick="return confirm('Permanently delete this report? This cannot be undone.');">

                                            Delete

                                        </button>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>

<?php endif; ?>


<!-- VIEW REPORT MODAL -->

<div class="modal fade" id="viewModal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title fw-bold">
                    Report Details
                </h5>

                <button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body">

                <p><strong>Report ID:</strong> <span id="vId"></span></p>

                <p><strong>Report Type:</strong> <span id="vType"></span></p>

                <p><strong>Related To:</strong> <span id="vRelated"></span></p>

                <p><strong>Date:</strong> <span id="vDate"></span></p>

                <p><strong>Location:</strong> <span id="vLocation"></span></p>

                <p><strong>Status:</strong> <span id="vStatus"></span></p>

                <hr>

                <strong>Report Details</strong>

                <div class="border rounded p-3 mt-2"
                     style="white-space:pre-wrap; overflow-wrap:anywhere;"
                     id="vDetails"></div>

            </div>

            <div class="modal-footer">

                <button class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">
                    Close
                </button>

            </div>

        </div>

    </div>

</div>


<!-- EDIT STATUS MODAL -->

<div class="modal fade" id="statusModal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered modal-sm">

        <form method="POST"
              action="reports.php?tab=active"
              class="modal-content"
              id="editStatusForm">

            <input type="hidden" name="csrf_token"
                   value="<?= h($_SESSION['reports_csrf']) ?>">

            <input type="hidden" name="action"
                   value="update_status">

            <input type="hidden" name="tab"
                   value="active">

            <input type="hidden" name="id"
                   id="statusId">

            <div class="modal-header">

                <h5 class="modal-title fw-bold">
                    Edit Report Status
                </h5>

                <button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body">

                <p class="small text-secondary mb-3">
                    Select the new status for this report.
                </p>

                <label class="form-label">
                    Report Status
                </label>

                <select name="status"
                        id="statusSelect"
                        class="form-select"
                        required>

                    <option value="Open">Open</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Resolved">Resolved</option>

                </select>

            </div>

            <div class="modal-footer">

                <button type="button"
                        class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">
                    Cancel
                </button>

                <button type="submit"
                        class="btn btn-success btn-sm">
                    Save Changes
                </button>

            </div>

        </form>

    </div>

</div>


</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/* SHOW RESIDENT OR CARETAKER FIELDS */

const relatedSelect = document.getElementById('related_to');

if (relatedSelect) {

    const residentGroup = document.getElementById('residentGroup');
    const caretakerGroup = document.getElementById('caretakerGroup');

    const residentSelect = document.getElementById('resident_id');
    const caretakerSelect = document.getElementById('caretaker_name');

    function updateRelatedFields() {

        const value = relatedSelect.value;

        residentGroup.style.display =
            value === 'Resident' ? '' : 'none';

        caretakerGroup.style.display =
            value === 'Caretaker' ? '' : 'none';

        residentSelect.required = value === 'Resident';
        caretakerSelect.required = value === 'Caretaker';

        residentSelect.disabled = value !== 'Resident';
        caretakerSelect.disabled = value !== 'Caretaker';
    }

    relatedSelect.addEventListener('change', updateRelatedFields);

    updateRelatedFields();
}


/* VIEW REPORT */

document.querySelectorAll('.viewReport').forEach(button => {

    button.addEventListener('click', function () {

        const d = this.dataset;

        document.getElementById('vId').textContent =
            'INC-' + String(d.id).padStart(3, '0');

        document.getElementById('vType').textContent = d.type;
        document.getElementById('vRelated').textContent = d.related;
        document.getElementById('vDate').textContent = d.date;
        document.getElementById('vLocation').textContent = d.location || '—';
        document.getElementById('vStatus').textContent = d.status;
        document.getElementById('vDetails').textContent = d.details;

    });

});


/* EDIT REPORT STATUS */

document.querySelectorAll('.editStatus').forEach(button => {

    button.addEventListener('click', function () {

        const reportId = this.getAttribute('data-id');
        const currentStatus = this.getAttribute('data-status');

        document.getElementById('statusId').value = reportId;

        document.getElementById('statusSelect').value = currentStatus;

    });

});


/* PREVENT EMPTY REPORT ID */

document.getElementById('editStatusForm')?.addEventListener('submit', function (event) {

    const reportId = document.getElementById('statusId').value;
    const status = document.getElementById('statusSelect').value;

    if (!reportId || !status) {
        event.preventDefault();
        alert('Please select a valid report and status.');
    }

});

</script>

</body>
</html>
