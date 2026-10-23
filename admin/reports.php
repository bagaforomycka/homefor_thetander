<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

$currentRole = (string) $_SESSION['role'];
if (!in_array($currentRole, ['admin', 'staff'], true)) {
    http_response_code(403);
    exit('Access denied. Only administrators and authorized staff can access reports.');
}

require_once __DIR__ . '/../config/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection error. Check config/db.php and make sure it defines $pdo.');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (empty($_SESSION['reports_csrf'])) {
    $_SESSION['reports_csrf'] = bin2hex(random_bytes(32));
}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function reportDateIsValid(string $value): bool {
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}
function residentFullName(array $r): string {
    return trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
}
function reportUrl(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
    }
    return 'reports.php' . ($query ? '?' . http_build_query($query) : '');
}

$error = '';
$success = '';
$reportToDisplay = null;
$rows = [];
$history = [];
$showArchived = (($_GET['show_archived'] ?? '') === '1');
$viewId = filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT);
$exportId = filter_input(INPUT_GET, 'export', FILTER_VALIDATE_INT);

$search = trim((string)($_GET['search'] ?? ''));
$type = (string)($_GET['type'] ?? 'all');
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));

if (!in_array($type, ['all', 'active', 'archived'], true)) $type = 'all';
if ($startDate !== '' && !reportDateIsValid($startDate)) $error = 'Please enter a valid start date.';
if ($endDate !== '' && !reportDateIsValid($endDate)) $error = 'Please enter a valid end date.';
if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    $error = 'The start date cannot be later than the end date.';
}

function fetchResidentRows(PDO $pdo, string $search, string $type, string $startDate, string $endDate): array {
    $where = [];
    $params = [];

    if ($type === 'active') {
        $where[] = 'archived_at IS NULL';
    } elseif ($type === 'archived') {
        $where[] = 'archived_at IS NOT NULL';
    }

    if ($search !== '') {
        $where[] = "(
            CAST(id AS CHAR) LIKE :q_id
            OR CAST(resident_id AS CHAR) LIKE :q_resident_id
            OR first_name LIKE :q_first
            OR middle_name LIKE :q_middle
            OR last_name LIKE :q_last
            OR contact_number LIKE :q_contact
            OR room_number LIKE :q_room
            OR assigned_caretaker LIKE :q_caretaker
            OR status LIKE :q_status
            OR care_level LIKE :q_care
        )";
        $like = '%' . $search . '%';
        foreach (['id','resident_id','first','middle','last','contact','room','caretaker','status','care'] as $key) {
            $params['q_' . $key] = $like;
        }
    }
    if ($startDate !== '') {
        $where[] = 'admission_date >= :start_date';
        $params['start_date'] = $startDate;
    }
    if ($endDate !== '') {
        $where[] = 'admission_date <= :end_date';
        $params['end_date'] = $endDate;
    }

    $sql = "SELECT id, resident_id, first_name, middle_name, last_name,
                   date_of_birth, gender, contact_number, address, room_number,
                   admission_date, care_level, assigned_caretaker, status, archived_at
            FROM residents";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY admission_date DESC, id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['reports_csrf'], $token)) {
            throw new RuntimeException('Security token expired. Refresh the page and try again.');
        }

        $action = (string)($_POST['action'] ?? '');
        $id = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);

        if ($action === 'generate') {
            $title = trim((string)($_POST['title'] ?? 'Residents Report'));
            $postSearch = trim((string)($_POST['search'] ?? ''));
            $postType = (string)($_POST['type'] ?? 'all');
            $postStart = trim((string)($_POST['start_date'] ?? ''));
            $postEnd = trim((string)($_POST['end_date'] ?? ''));

            if ($title === '') $title = 'Residents Report';
            if (mb_strlen($title) > 150) throw new RuntimeException('Report title must be 150 characters or fewer.');
            if (mb_strlen($postSearch) > 150) throw new RuntimeException('Search must be 150 characters or fewer.');
            if (!in_array($postType, ['all', 'active', 'archived'], true)) throw new RuntimeException('Invalid report type.');
            if (($postStart !== '' && !reportDateIsValid($postStart)) ||
                ($postEnd !== '' && !reportDateIsValid($postEnd))) {
                throw new RuntimeException('Please enter valid admission dates.');
            }
            if ($postStart !== '' && $postEnd !== '' && $postStart > $postEnd) {
                throw new RuntimeException('The start date cannot be later than the end date.');
            }

            $snapshot = fetchResidentRows($pdo, $postSearch, $postType, $postStart, $postEnd);
            $creator = (string)($_SESSION['user_id']);
            $insert = $pdo->prepare(
                'INSERT INTO reports
                (title, report_type, search_term, start_date, end_date, total_records, report_data, created_by)
                VALUES (:title, :type, :search_term, :start_date, :end_date, :total, :data, :creator)'
            );
            $insert->execute([
                'title' => $title,
                'type' => $postType,
                'search_term' => $postSearch !== '' ? $postSearch : null,
                'start_date' => $postStart !== '' ? $postStart : null,
                'end_date' => $postEnd !== '' ? $postEnd : null,
                'total' => count($snapshot),
                'data' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'creator' => $creator
            ]);
            header('Location: reports.php?view=' . (int)$pdo->lastInsertId() . '&saved=1');
            exit;
        }

        if (!$id || $id < 1) throw new RuntimeException('Invalid report ID.');

        if ($action === 'archive') {
            $stmt = $pdo->prepare('UPDATE reports SET archived_at = NOW() WHERE id = :id AND archived_at IS NULL');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('Report was not found or is already archived.');
            header('Location: reports.php?show_archived=0&notice=archived');
            exit;
        }

        if ($action === 'restore') {
            $stmt = $pdo->prepare('UPDATE reports SET archived_at = NULL WHERE id = :id AND archived_at IS NOT NULL');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('Archived report was not found or is already restored.');
            header('Location: reports.php?show_archived=1&notice=restored');
            exit;
        }

        if ($action === 'delete_permanently') {
            $stmt = $pdo->prepare('DELETE FROM reports WHERE id = :id AND archived_at IS NOT NULL');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('Only archived reports can be permanently deleted.');
            header('Location: reports.php?show_archived=1&notice=deleted');
            exit;
        }

        throw new RuntimeException('Unknown report action.');
    }

    if (isset($_GET['saved'])) $success = 'Report generated and saved in the database.';
    if (($_GET['notice'] ?? '') === 'archived') $success = 'Report moved to the archive. You can restore it later.';
    if (($_GET['notice'] ?? '') === 'restored') $success = 'Report restored successfully.';
    if (($_GET['notice'] ?? '') === 'deleted') $success = 'Archived report permanently deleted.';

    if ($exportId && $exportId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = :id');
        $stmt->execute(['id' => $exportId]);
        $exportReport = $stmt->fetch();
        if (!$exportReport) {
            http_response_code(404);
            exit('Report not found.');
        }
        $exportRows = json_decode($exportReport['report_data'], true);
        if (!is_array($exportRows)) throw new RuntimeException('Saved report data could not be read.');

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="report_' . (int)$exportReport['id'] . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Database ID','Resident ID','First Name','Middle Name','Last Name','Date of Birth','Gender','Contact Number','Address','Room Number','Admission Date','Care Level','Assigned Caretaker','Resident Status','Resident Archived At']);
        foreach ($exportRows as $r) {
            fputcsv($out, [
                $r['id'] ?? '', $r['resident_id'] ?? '', $r['first_name'] ?? '',
                $r['middle_name'] ?? '', $r['last_name'] ?? '', $r['date_of_birth'] ?? '',
                $r['gender'] ?? '', $r['contact_number'] ?? '', $r['address'] ?? '',
                $r['room_number'] ?? '', $r['admission_date'] ?? '', $r['care_level'] ?? '',
                $r['assigned_caretaker'] ?? '', $r['status'] ?? '', $r['archived_at'] ?? ''
            ]);
        }
        fclose($out);
        exit;
    }

    if ($viewId && $viewId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = :id');
        $stmt->execute(['id' => $viewId]);
        $reportToDisplay = $stmt->fetch();
        if (!$reportToDisplay) {
            $error = 'Saved report was not found.';
        } else {
            $decoded = json_decode($reportToDisplay['report_data'], true);
            $rows = is_array($decoded) ? $decoded : [];
        }
    } else {
        if ($error === '') $rows = fetchResidentRows($pdo, $search, $type, $startDate, $endDate);
    }

    $countStmt = $pdo->query(
        'SELECT
            COALESCE(SUM(archived_at IS NULL), 0) AS active_count,
            COALESCE(SUM(archived_at IS NOT NULL), 0) AS archived_count
         FROM residents'
    );
    $counts = $countStmt->fetch() ?: [];
    $summary = [
        'total' => (int)$pdo->query('SELECT COUNT(*) FROM residents')->fetchColumn(),
        'active' => (int)($counts['active_count'] ?? 0),
        'archived' => (int)($counts['archived_count'] ?? 0)
    ];

    $hist = $pdo->prepare(
        'SELECT id, title, report_type, total_records, created_by, created_at, archived_at
         FROM reports WHERE archived_at ' . ($showArchived ? 'IS NOT NULL' : 'IS NULL') . '
         ORDER BY created_at DESC, id DESC LIMIT 100'
    );
    $hist->execute();
    $history = $hist->fetchAll();

} catch (Throwable $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $error === '') {
        $error = $e instanceof PDOException
            ? 'A database operation failed. Confirm the reports table exists and check your PHP error log.'
            : $e->getMessage();
    }
}
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

    /* SIDEBAR LOGO */
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

    /* app-main opened in sidebar.php */
    .app-main {
        margin-left: 250px !important;
        width: calc(100% - 250px) !important;
        max-width: none !important;
        min-width: 0;
        min-height: 100vh;
        padding: 16px 24px !important;
        margin-top: 0 !important;
        box-shadow: none !important;
        overflow-x: hidden;
    }

    .report-header {
        background: #edf8ef;
        border: 1px solid #dcecdf;
        border-radius: 10px;
        padding: 16px 20px;
        margin-bottom: 16px;
    }

    .report-header h1 {
        color: #17683a;
        font-size: 22px;
        font-weight: 700;
        margin: 0 0 4px;
    }

    .report-header p {
        color: #61796a;
        margin: 0;
        font-size: 13px;
    }

    .panel {
        background: #fff;
        border: 1px solid #e3eee6;
        border-radius: 10px;
        padding: 18px;
        margin-bottom: 16px;
        box-shadow: 0 2px 10px rgba(24, 75, 42, .04);
    }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 16px;
    }

    .stat-grid .panel {
        margin-bottom: 0;
    }

    .stat-label {
        color: #61796a;
        font-size: 13px;
    }

    .stat-value {
        font-size: 24px;
        font-weight: 700;
        color: #17683a;
        margin-top: 4px;
    }

    .form-label {
        font-weight: 600;
        color: #17683a;
        font-size: 13px;
        margin-bottom: 4px;
    }

    .form-control,
    .form-select {
        min-height: 38px;
        font-size: 13px;
        border-color: #cedbd1;
        border-radius: 6px;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #33b960;
        box-shadow: 0 0 0 0.15rem rgba(51, 185, 96, 0.15);
    }

    .table-wrap {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    th,
    td {
        padding: 10px 14px;
        border-bottom: 1px solid #dcecdf;
        text-align: left;
        vertical-align: middle;
    }

    th {
        background: #edf8ef;
        color: #17683a;
        font-weight: 700;
        white-space: nowrap;
    }

    .pill {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 20px;
        background: #edf8ef;
        color: #19582a;
        font-size: 11px;
        font-weight: 600;
    }

    .pill-archived {
        background: #fff3e0;
        color: #b78103;
    }

    .small-muted {
        font-size: 12px;
        color: #61796a;
    }

    .btn-success {
        background-color: #198754;
        border-color: #198754;
        font-size: 13px;
        padding: 6px 16px;
        border-radius: 6px;
        font-weight: 600;
    }

    .btn-success:hover {
        background-color: #157347;
        border-color: #146c43;
    }

    .btn-outline-success,
    .btn-outline-secondary,
    .btn-outline-primary {
        font-size: 13px;
        padding: 6px 14px;
        border-radius: 6px;
    }

    .sidebar-link.active {
        box-shadow: none !important;
        outline: none !important;
    }

    @media (max-width: 760px) {
        .app-main {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 10px !important;
        }

        .stat-grid {
            grid-template-columns: 1fr;
        }

        .report-header,
        .panel {
            padding: 14px;
        }
    }

    @media print {
        .app-sidebar,
        .no-print {
            display: none !important;
        }

        .app-main {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }

        body {
            background: #fff !important;
        }

        .panel {
            border: 0 !important;
            padding: 8px !important;
            box-shadow: none !important;
        }

        table {
            font-size: 11px !important;
        }

        th,
        td {
            padding: 6px !important;
        }
    }
</style>
</head>
<body>

<?php 
require_once __DIR__ . '/../includes/sidebar.php'; 
?>

<div class="report-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <h1>Reports</h1>
        <p>Generate, save, retrieve, archive, and restore report snapshots.</p>
    </div>
    <div class="no-print d-flex gap-2">
        <a class="btn btn-outline-success btn-sm" href="reports.php?show_archived=<?= $showArchived ? '1' : '0' ?>">Refresh</a>
        <button class="btn btn-success btn-sm" onclick="window.print()" type="button">Print current view</button>
    </div>
</div>

<?php if ($error !== ''): ?><div class="alert alert-danger py-2 px-3 mb-3 fs-6"><?= h($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success py-2 px-3 mb-3 fs-6"><?= h($success) ?></div><?php endif; ?>

<div class="stat-grid">
    <div class="panel"><div class="stat-label">All Residents</div><div class="stat-value"><?= (int)$summary['total'] ?></div></div>
    <div class="panel"><div class="stat-label">Active Resident Records</div><div class="stat-value"><?= (int)$summary['active'] ?></div></div>
    <div class="panel"><div class="stat-label">Archived Resident Records</div><div class="stat-value"><?= (int)$summary['archived'] ?></div></div>
</div>

<?php if (!$reportToDisplay): ?>
<section class="panel no-print">
    <h2 class="h6 mb-3 text-success fw-bold">Generate a resident report</h2>
    <form method="post" action="reports.php">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['reports_csrf']) ?>">
        <input type="hidden" name="action" value="generate">
        <div class="row g-2">
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="title">Report title</label>
                <input class="form-control" id="title" name="title" maxlength="150" value="Residents Report" required>
            </div>
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="search">Search</label>
                <input class="form-control" id="search" name="search" maxlength="150" value="<?= h($search) ?>" placeholder="Resident name, ID, room, caretaker">
            </div>
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="type">Resident record type</label>
                <select class="form-select" id="type" name="type">
                    <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All records</option>
                    <option value="active" <?= $type === 'active' ? 'selected' : '' ?>>Active records only</option>
                    <option value="archived" <?= $type === 'archived' ? 'selected' : '' ?>>Archived records only</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="start_date">Admission date from</label>
                <input class="form-control" type="date" id="start_date" name="start_date" value="<?= h($startDate) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="end_date">Admission date to</label>
                <input class="form-control" type="date" id="end_date" name="end_date" value="<?= h($endDate) ?>">
            </div>
            <div class="col-md-6 d-flex align-items-end gap-2">
                <button class="btn btn-success btn-sm" type="submit">Generate &amp; Save Report</button>
                <a class="btn btn-outline-secondary btn-sm" href="reports.php">Clear filters</a>
            </div>
        </div>
    </form>
    <p class="small-muted mt-2 mb-0">The report snapshot is saved in SQL only when you click Generate &amp; Save Report.</p>
</section>
<?php endif; ?>

<?php if ($reportToDisplay): ?>
<section class="panel">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <h2 class="h6 mb-1 text-success fw-bold"><?= h($reportToDisplay['title']) ?></h2>
            <div class="small-muted">
                Saved report #<?= (int)$reportToDisplay['id'] ?> ·
                Generated <?= h($reportToDisplay['created_at']) ?> ·
                Created by user <?= h($reportToDisplay['created_by']) ?>
            </div>
            <?php if ($reportToDisplay['archived_at'] !== null): ?>
                <span class="pill pill-archived mt-2">Archived report</span>
            <?php endif; ?>
        </div>
        <div class="no-print d-flex flex-wrap gap-2">
            <a class="btn btn-outline-success btn-sm" href="<?= h(reportUrl(['export' => (int)$reportToDisplay['id']])) ?>">Export saved CSV</a>
            <button class="btn btn-success btn-sm" onclick="window.print()" type="button">Print saved report</button>
            <a class="btn btn-outline-secondary btn-sm" href="reports.php">Back to reports</a>
        </div>
    </div>
    <p class="mt-2 mb-0 small">Saved rows: <strong><?= (int)$reportToDisplay['total_records'] ?></strong> · Filter type: <?= h($reportToDisplay['report_type']) ?></p>
</section>
<?php else: ?>
<section class="panel">
    <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center mb-2">
        <div>
            <h2 class="h6 mb-1 text-success fw-bold">Resident report preview</h2>
            <div class="small-muted"><?= count($rows) ?> resident record(s) match the current filters.</div>
        </div>
    </div>
<?php endif; ?>

    <div class="table-wrap">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>ID</th><th>Resident ID</th><th>Name</th><th>Birth Date</th>
                <th>Gender</th><th>Contact</th><th>Room</th><th>Admission</th>
                <th>Care Level</th><th>Caretaker</th><th>Status</th><th>Resident Record</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="12" class="text-center text-secondary py-4">No records found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= h($r['id'] ?? '') ?></td>
                    <td><?= h($r['resident_id'] ?? '') ?></td>
                    <td><?= h(residentFullName($r)) ?></td>
                    <td><?= h($r['date_of_birth'] ?? '') ?></td>
                    <td><?= h($r['gender'] ?? '') ?></td>
                    <td><?= h($r['contact_number'] ?? '') ?></td>
                    <td><?= h($r['room_number'] ?? '') ?></td>
                    <td><?= h($r['admission_date'] ?? '') ?></td>
                    <td><?= h($r['care_level'] ?? '') ?></td>
                    <td><?= h($r['assigned_caretaker'] ?? '') ?></td>
                    <td><?= h($r['status'] ?? '') ?></td>
                    <td>
                        <?php if (($r['archived_at'] ?? null) === null): ?>
                            <span class="pill">Active record</span>
                        <?php else: ?>
                            <span class="pill pill-archived">Archived</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 class="h6 mb-1 text-success fw-bold"><?= $showArchived ? 'Archived report history' : 'Saved report history' ?></h2>
            <div class="small-muted">Reports are stored in SQL. Archived reports can be restored; permanent deletion is only available after archiving.</div>
        </div>
        <div class="no-print d-flex gap-2">
            <a class="btn <?= !$showArchived ? 'btn-success' : 'btn-outline-success' ?> btn-sm" href="reports.php?show_archived=0">Active history</a>
            <a class="btn <?= $showArchived ? 'btn-success' : 'btn-outline-success' ?> btn-sm" href="reports.php?show_archived=1">Archive</a>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table table-hover mb-0">
            <thead><tr><th>Report #</th><th>Title</th><th>Type</th><th>Rows</th><th>Created By</th><th>Generated</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$history): ?>
                <tr><td colspan="7" class="text-center text-secondary py-4">No reports in this list.</td></tr>
            <?php else: foreach ($history as $item): ?>
                <tr>
                    <td><?= (int)$item['id'] ?></td>
                    <td><?= h($item['title']) ?></td>
                    <td><?= h(ucfirst($item['report_type'])) ?></td>
                    <td><?= (int)$item['total_records'] ?></td>
                    <td><?= h($item['created_by']) ?></td>
                    <td><?= h($item['created_at']) ?></td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <a class="btn btn-sm btn-outline-primary" href="reports.php?view=<?= (int)$item['id'] ?>">View</a>
                            <a class="btn btn-sm btn-outline-success" href="reports.php?export=<?= (int)$item['id'] ?>">CSV</a>
                            <form method="post" action="reports.php" onsubmit="return confirm('Continue with this report action?');" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['reports_csrf']) ?>">
                                <input type="hidden" name="report_id" value="<?= (int)$item['id'] ?>">
                                <?php if ($showArchived): ?>
                                    <button class="btn btn-sm btn-success" name="action" value="restore" type="submit">Restore</button>
                                    <button class="btn btn-sm btn-danger" name="action" value="delete_permanently" type="submit" onclick="return confirm('Permanently delete this archived report? This cannot be undone.');">Delete permanently</button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-danger" name="action" value="archive" type="submit">Archive</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Puts the logo at the top of the sidebar and hides the old text title -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector(
        '.app-sidebar, .sidebar, #sidebar, .sidebar-container, aside, nav'
    );

    if (!sidebar) {
        return;
    }

    var oldTexts = ['home for the aged', 'care management system'];

    sidebar.querySelectorAll('*').forEach(function (el) {
        if (
            el.children.length === 0 &&
            oldTexts.indexOf(el.textContent.trim().toLowerCase()) !== -1
        ) {
            el.style.display = 'none';
        }
    });

    if (!sidebar.querySelector('.sidebar-logo')) {
        var logo = document.createElement('div');
        logo.className = 'sidebar-logo';
        logo.innerHTML =
            '<a href="/homefor_thetander/admin/dashboard.php">' +
            '<img src="/homefor_thetander/images/white1.png" ' +
            'alt="Home for the Aged - Care, Comfort, Dignity">' +
            '</a>';

        sidebar.insertBefore(logo, sidebar.firstChild);
    }
});
</script>

<!-- sidebar.php already opened <main class="app-main">, close it here -->
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>