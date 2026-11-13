<?php
/**
 * incident_reports.php - Incident Reports page (standalone).
 * Put this file in the admin/ folder. It does not need any other report file.
 */
$entity = 'incident';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

$allowedRoles = ['admin', 'staff', 'caretaker'];
if (!in_array((string)$_SESSION['role'], $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied. Only authorized users can access reports.');
}

require_once __DIR__ . '/../config/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection error. Check config/db.php and make sure it defines $pdo.');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (empty($_SESSION['care_csrf'])) {
    $_SESSION['care_csrf'] = bin2hex(random_bytes(32));
}

/* ---------------------------------------------------------------
 * Option lists
 * ------------------------------------------------------------- */
const CARE_ACTIVITIES   = ['Bathing', 'Dressing', 'Feeding', 'Medication', 'Vital Signs', 'Mobility Assistance', 'Toileting', 'Wound Care', 'Physical Therapy', 'Social Activity', 'Other'];
const CARE_CONDITIONS   = ['Stable', 'Improving', 'Needs Monitoring', 'Declining', 'Critical'];
const CARE_COMPLETION   = ['Completed', 'Partially Completed', 'Not Completed', 'Refused by Resident'];
const INCIDENT_TYPES    = ['Fall', 'Injury', 'Medication Error', 'Behavioral Incident', 'Wandering', 'Medical Emergency', 'Property Damage', 'Other'];
const INCIDENT_PLACES   = ['Resident Room', 'Common Area', 'Dining Hall', 'Bathroom', 'Hallway', 'Garden', 'Clinic', 'Other'];
const REPORT_STATUSES   = ['Submitted', 'Reviewed', 'Closed'];

/* ---------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------- */
function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function validDate(string $v): bool {
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return $d !== false && $d->format('Y-m-d') === $v;
}
function validTime(string $v): bool {
    $d = DateTime::createFromFormat('!H:i', $v);
    return $d !== false && $d->format('H:i') === $v;
}
function fullName(array $r): string {
    return trim(preg_replace('/\s+/', ' ', ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? '')));
}
function submitterName(): string {
    foreach (['full_name', 'name', 'username', 'user_name', 'email'] as $k) {
        if (!empty($_SESSION[$k])) return mb_substr(trim((string)$_SESSION[$k]), 0, 150);
    }
    return 'User #' . (string)$_SESSION['user_id'];
}
function reportCode(string $prefix, $id): string {
    return $prefix . '-' . str_pad((string)(int)$id, 3, '0', STR_PAD_LEFT);
}
function prettyDateTime($date, $time): string {
    $d = strtotime((string)$date);
    $t = strtotime((string)$time);
    $out = $d ? date('M d, Y', $d) : (string)$date;
    if ($t) $out .= ' ' . date('g:i A', $t);
    return $out;
}
function pill(string $text): string {
    $map = [
        'Completed' => 'p-green', 'Partially Completed' => 'p-yellow', 'Not Completed' => 'p-red', 'Refused by Resident' => 'p-red',
        'Submitted' => 'p-red', 'Reviewed' => 'p-blue', 'Closed' => 'p-green',
    ];
    return '<span class="pill ' . ($map[$text] ?? '') . '">' . h($text) . '</span>';
}
function residentsHaveArchive(PDO $pdo): bool {
    return (bool)$pdo->query("SHOW COLUMNS FROM residents LIKE 'archived_at'")->fetch();
}
function fetchResidentOptions(PDO $pdo): array {
    $sql = 'SELECT id, resident_id, first_name, middle_name, last_name FROM residents';
    if (residentsHaveArchive($pdo)) $sql .= ' WHERE archived_at IS NULL';
    $sql .= ' ORDER BY last_name, first_name';
    return $pdo->query($sql)->fetchAll();
}
function fetchReports(PDO $pdo, array $e, bool $archived, array $f, bool $isCare): array {
    $typeCol = $e['type_col'];
    $where = [$archived ? 't.archived_at IS NOT NULL' : 't.archived_at IS NULL'];
    $p = [];

    if ($f['q'] !== '') {
        $where[] = "(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name) LIKE :q1
                  OR CONCAT('{$e['prefix']}-', LPAD(t.id, 3, '0')) LIKE :q2
                  OR t.$typeCol LIKE :q3
                  OR t.submitted_by_name LIKE :q4
                  OR r.resident_id LIKE :q5)";
        $like = '%' . $f['q'] . '%';
        foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) $p[$k] = $like;
    }
    if ($f['from'] !== '') { $where[] = 't.report_date >= :dfrom'; $p['dfrom'] = $f['from']; }
    if ($f['to'] !== '')   { $where[] = 't.report_date <= :dto';   $p['dto'] = $f['to']; }
    if ($f['type'] !== '') { $where[] = "t.$typeCol = :ftype";      $p['ftype'] = $f['type']; }
    if ($isCare && $f['status'] !== '') { $where[] = 't.completion_status = :fstatus'; $p['fstatus'] = $f['status']; }
    if ($f['rstatus'] !== '') { $where[] = 't.report_status = :frstatus'; $p['frstatus'] = $f['rstatus']; }

    $sql = "SELECT t.*, r.first_name, r.middle_name, r.last_name, r.resident_id AS resident_code
            FROM {$e['table']} t
            LEFT JOIN residents r ON r.id = t.resident_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.report_date DESC, t.report_time DESC, t.id DESC
            LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    return $stmt->fetchAll();
}

/* ---------------------------------------------------------------
 * Per-page config
 * ------------------------------------------------------------- */
$isCare = ($entity === 'care');
$info = $isCare
    ? ['table' => 'care_reports',          'prefix' => 'CR', 'type_col' => 'care_activity', 'label' => 'Report']
    : ['table' => 'care_incident_reports', 'prefix' => 'INC', 'type_col' => 'incident_type', 'label' => 'Incident Report'];
$pageFile = $isCare ? 'care_reports.php' : 'incident_reports.php';
$typeOptions = $isCare ? CARE_ACTIVITIES : INCIDENT_TYPES;

$titles = $isCare
    ? ['list' => ['Reports', 'Manage and monitor resident care reports.'],
       'new'  => ['Add Report', 'Create a new care report for a resident.']]
    : ['list' => ['Incident Reports', 'Manage and monitor resident and caretaker incident reports.'],
       'new'  => ['Add Incident Report', 'Create a new incident report for a resident.']];

/* ---------------------------------------------------------------
 * State
 * ------------------------------------------------------------- */
$error = '';
$success = '';
$rows = [];
$residentOptions = [];

$view = (string)($_POST['view'] ?? $_GET['view'] ?? 'list');
// Old links (new_care / new_incident) still work
$view = in_array($view, ['new', 'new_care', 'new_incident'], true) ? 'new' : 'list';

$showArchived = (($_GET['show_archived'] ?? '') === '1');
$filters = [
    'q'       => trim((string)($_GET['q'] ?? '')),
    'from'    => array_key_exists('from', $_GET) ? trim((string)$_GET['from']) : date('Y-m-01'),
    'to'      => array_key_exists('to', $_GET)   ? trim((string)$_GET['to'])   : date('Y-m-t'),
    'type'    => trim((string)($_GET['f_type'] ?? '')),
    'status'  => trim((string)($_GET['f_status'] ?? '')),
    'rstatus' => trim((string)($_GET['f_rstatus'] ?? '')),
];
if ($filters['from'] !== '' && !validDate($filters['from'])) { $filters['from'] = ''; $error = 'Please enter a valid start date.'; }
if ($filters['to'] !== '' && !validDate($filters['to']))     { $filters['to'] = '';   $error = 'Please enter a valid end date.'; }
if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
    $error = 'The start date cannot be later than the end date.';
    $filters['from'] = $filters['to'] = '';
}
$activeFilterCount = count(array_filter([$filters['type'], $filters['status'], $filters['rstatus']], fn($v) => $v !== ''));

try {
    /* ---------- Create tables if they do not exist ---------- */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS care_reports (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            resident_id INT NOT NULL,
            care_activity VARCHAR(60) NOT NULL,
            resident_condition VARCHAR(40) NOT NULL,
            report_date DATE NOT NULL,
            report_time TIME NOT NULL,
            completion_status VARCHAR(30) NOT NULL,
            observation TEXT NOT NULL,
            action_taken TEXT NULL,
            additional_notes TEXT NULL,
            report_status VARCHAR(20) NOT NULL DEFAULT 'Submitted',
            submitted_by_id VARCHAR(100) NOT NULL,
            submitted_by_name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME NULL DEFAULT NULL,
            INDEX idx_cr_date (report_date),
            INDEX idx_cr_resident (resident_id),
            INDEX idx_cr_archived (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS care_incident_reports (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            resident_id INT NOT NULL,
            incident_type VARCHAR(60) NOT NULL,
            location VARCHAR(60) NOT NULL,
            report_date DATE NOT NULL,
            report_time TIME NOT NULL,
            witness VARCHAR(150) NULL,
            description TEXT NOT NULL,
            action_taken TEXT NULL,
            additional_notes TEXT NULL,
            report_status VARCHAR(20) NOT NULL DEFAULT 'Submitted',
            submitted_by_id VARCHAR(100) NOT NULL,
            submitted_by_name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME NULL DEFAULT NULL,
            INDEX idx_ir_date (report_date),
            INDEX idx_ir_resident (resident_id),
            INDEX idx_ir_archived (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    /* ---------- POST actions ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['care_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token expired. Refresh the page and try again.');
        }
        $action = (string)($_POST['action'] ?? '');
        $table = $info['table'];
        $back = $pageFile . '?view=list';

        if ($action === 'add') {
            $residentId = filter_var($_POST['resident_ref'] ?? '', FILTER_VALIDATE_INT);
            $date = trim((string)($_POST['report_date'] ?? ''));
            $time = trim((string)($_POST['report_time'] ?? ''));
            $actionTaken = trim((string)($_POST['action_taken'] ?? ''));
            $notes = trim((string)($_POST['additional_notes'] ?? ''));

            if (!$residentId) throw new RuntimeException('Please select a resident.');
            $chk = $pdo->prepare('SELECT id FROM residents WHERE id = :id');
            $chk->execute(['id' => $residentId]);
            if (!$chk->fetch()) throw new RuntimeException('The selected resident was not found.');
            if (!validDate($date)) throw new RuntimeException('Please enter a valid date.');
            if (!validTime($time)) throw new RuntimeException('Please enter a valid time.');
            if (mb_strlen($actionTaken) > 3000) throw new RuntimeException('Action taken must be 3,000 characters or fewer.');
            if (mb_strlen($notes) > 3000) throw new RuntimeException('Additional notes must be 3,000 characters or fewer.');

            $common = [
                'resident' => $residentId, 'rdate' => $date, 'rtime' => $time,
                'action' => $actionTaken !== '' ? $actionTaken : null,
                'notes' => $notes !== '' ? $notes : null,
                'by_id' => (string)$_SESSION['user_id'], 'by_name' => submitterName(),
            ];

            if ($isCare) {
                $activity = trim((string)($_POST['care_activity'] ?? ''));
                $condition = trim((string)($_POST['resident_condition'] ?? ''));
                $completion = trim((string)($_POST['completion_status'] ?? ''));
                $observation = trim((string)($_POST['observation'] ?? ''));
                if (!in_array($activity, CARE_ACTIVITIES, true)) throw new RuntimeException('Please select a care activity.');
                if (!in_array($condition, CARE_CONDITIONS, true)) throw new RuntimeException('Please select the resident condition.');
                if (!in_array($completion, CARE_COMPLETION, true)) throw new RuntimeException('Please select a completion status.');
                if ($observation === '') throw new RuntimeException('Please enter your observation.');
                if (mb_strlen($observation) > 5000) throw new RuntimeException('Observation must be 5,000 characters or fewer.');

                $stmt = $pdo->prepare(
                    'INSERT INTO care_reports
                     (resident_id, care_activity, resident_condition, report_date, report_time, completion_status,
                      observation, action_taken, additional_notes, submitted_by_id, submitted_by_name)
                     VALUES (:resident, :activity, :cond, :rdate, :rtime, :completion,
                             :obs, :action, :notes, :by_id, :by_name)'
                );
                $stmt->execute($common + ['activity' => $activity, 'cond' => $condition, 'completion' => $completion, 'obs' => $observation]);
            } else {
                $type = trim((string)($_POST['incident_type'] ?? ''));
                $location = trim((string)($_POST['location'] ?? ''));
                $witness = trim((string)($_POST['witness'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                if (!in_array($type, INCIDENT_TYPES, true)) throw new RuntimeException('Please select an incident type.');
                if (!in_array($location, INCIDENT_PLACES, true)) throw new RuntimeException('Please select a location.');
                if (mb_strlen($witness) > 150) throw new RuntimeException('Witness must be 150 characters or fewer.');
                if ($description === '') throw new RuntimeException('Please enter a description.');
                if (mb_strlen($description) > 5000) throw new RuntimeException('Description must be 5,000 characters or fewer.');

                $stmt = $pdo->prepare(
                    'INSERT INTO care_incident_reports
                     (resident_id, incident_type, location, report_date, report_time, witness,
                      description, action_taken, additional_notes, submitted_by_id, submitted_by_name)
                     VALUES (:resident, :type, :location, :rdate, :rtime, :witness,
                             :descr, :action, :notes, :by_id, :by_name)'
                );
                $stmt->execute($common + ['type' => $type, 'location' => $location, 'witness' => $witness !== '' ? $witness : null, 'descr' => $description]);
            }
            header('Location: ' . $back . '&notice=added');
            exit;
        }

        $id = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new RuntimeException('Invalid report ID.');

        if ($action === 'status') {
            $rs = (string)($_POST['report_status'] ?? '');
            if (!in_array($rs, REPORT_STATUSES, true)) throw new RuntimeException('Invalid report status.');
            if ($isCare) {
                $cs = (string)($_POST['completion_status'] ?? '');
                if (!in_array($cs, CARE_COMPLETION, true)) throw new RuntimeException('Invalid completion status.');
                $stmt = $pdo->prepare("UPDATE $table SET report_status = :rs, completion_status = :cs WHERE id = :id AND archived_at IS NULL");
                $stmt->execute(['rs' => $rs, 'cs' => $cs, 'id' => $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE $table SET report_status = :rs WHERE id = :id AND archived_at IS NULL");
                $stmt->execute(['rs' => $rs, 'id' => $id]);
            }
            header('Location: ' . $back . '&notice=status');
            exit;
        }
        if ($action === 'archive') {
            $stmt = $pdo->prepare("UPDATE $table SET archived_at = NOW() WHERE id = :id AND archived_at IS NULL");
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('Report was not found or is already archived.');
            header('Location: ' . $back . '&notice=archived');
            exit;
        }
        if ($action === 'restore') {
            $stmt = $pdo->prepare("UPDATE $table SET archived_at = NULL WHERE id = :id AND archived_at IS NOT NULL");
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('Archived report was not found or is already restored.');
            header('Location: ' . $back . '&show_archived=1&notice=restored');
            exit;
        }
        if ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM $table WHERE id = :id AND archived_at IS NOT NULL");
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('Only archived reports can be permanently deleted.');
            header('Location: ' . $back . '&show_archived=1&notice=deleted');
            exit;
        }
        throw new RuntimeException('Unknown action.');
    }

    $notices = [
        'added'    => 'Report submitted successfully.',
        'status'   => 'Report status updated.',
        'archived' => 'Report moved to the archive. Click Archived to restore it.',
        'restored' => 'Report restored successfully.',
        'deleted'  => 'Archived report permanently deleted.',
    ];
    $nk = (string)($_GET['notice'] ?? '');
    if (isset($notices[$nk])) $success = $notices[$nk];

    /* ---------- Page data ---------- */
    if ($view === 'new') {
        $residentOptions = fetchResidentOptions($pdo);
    } elseif ($error === '') {
        $rows = fetchReports($pdo, $info, $showArchived, $filters, $isCare);
    }
} catch (Throwable $e) {
    $error = $e instanceof PDOException
        ? 'A database operation failed. Check your PHP error log. (' . $e->getMessage() . ')'
        : $e->getMessage();
    try {
        if ($view === 'new' && !$residentOptions) {
            $residentOptions = fetchResidentOptions($pdo);
        }
    } catch (Throwable $ignored) {}
}

// Keep what the person typed if the form failed
$old = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : [];
$ov = function (string $key, string $default = '') use ($old): string {
    return isset($old[$key]) ? (string)$old[$key] : $default;
};

$pageTitle = $titles[$view][0];
$pageSub = $titles[$view][1];
$csrf = $_SESSION['care_csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> | Home for the Aged</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    * { box-sizing: border-box; }
    /* No body background here: the page background (hands photo) comes from your sidebar/global style, same as reports.php */
    body { margin: 0; color: #1f2a22; font-family: Arial, Helvetica, sans-serif; overflow-x: hidden; }

    .sidebar-logo { display: block; padding: 10px 4px 6px; }
    .sidebar-logo a { display: block; }
    .sidebar-logo img { display: block; width: 100%; max-width: 300px; height: auto; margin: 0 auto; transform: scale(1.1); }

    /* app-main is opened in sidebar.php */
    .app-main {
        margin-left: 250px !important; width: calc(100% - 250px) !important; max-width: none !important;
        min-width: 0; min-height: 100vh; padding: 20px 28px !important; margin-top: 0 !important;
        box-shadow: none !important; overflow-x: hidden;
    }
    .sidebar-link.active { box-shadow: none !important; outline: none !important; }

    /* Header card */
    .page-head {
        display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
        background: rgba(233, 246, 235, .96); border-radius: 12px; padding: 16px 18px; margin-bottom: 16px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
    }
    .page-title { font-size: 20px; font-weight: 700; margin: 0; color: #1b6b3a; }
    .page-sub { font-size: 12px; color: #3f5a47; margin: 3px 0 0; }
    .head-actions { display: flex; gap: 8px; }
    .btn-g, .btn-g-outline {
        border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: 600; text-decoration: none;
        cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; border: 1px solid #167a47;
    }
    .btn-g { background: #167a47; color: #fff; }
    .btn-g:hover { background: #12663b; color: #fff; }
    .btn-g-outline { background: rgba(255, 255, 255, .6); color: #167a47; }
    .btn-g-outline:hover { background: #fff; color: #167a47; }

    /* Tabs */
    .view-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
    .view-tabs > a {
        padding: 9px 14px; border-radius: 8px; background: #fff; color: #1f2a22; text-decoration: none;
        font-size: 12px; font-weight: 700; border: 1px solid #e3e8e4; box-shadow: 0 1px 4px rgba(0, 0, 0, .12);
    }
    .view-tabs > a.active { background: #1b6b3a; border-color: #1b6b3a; color: #fff; }

    /* White card */
    .list-panel { background: rgba(255, 255, 255, .97); border-radius: 12px; padding: 18px 16px; box-shadow: 0 4px 14px rgba(0, 0, 0, .12); }
    .card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 12px; }
    .card-title { font-size: 16px; font-weight: 700; color: #1b6b3a; margin: 0; }
    .card-count { font-size: 11px; color: #6b7a70; margin: 6px 0 0; }

    .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .search-box { position: relative; flex: 1 1 260px; max-width: 420px; }
    .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #667; font-size: 13px; }
    .search-box input { width: 100%; height: 34px; border: 1px solid #cfd8d3; border-radius: 8px; padding: 0 12px 0 34px; font-size: 12px; background: #fff; }
    .tool-right { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .range { display: flex; align-items: center; gap: 6px; border: 1px solid #cfd8d3; border-radius: 8px; height: 34px; padding: 0 10px; background: #fff; font-size: 11px; font-weight: 600; }
    .range input { border: 0; background: transparent; font-size: 11px; font-weight: 600; font-family: inherit; width: 108px; }
    .range input:focus { outline: none; }
    .btn-filter { height: 34px; border: 1px solid #cfd8d3; border-radius: 8px; background: #fff; font-weight: 600; font-size: 12px; padding: 0 12px; display: inline-flex; align-items: center; gap: 6px; }
    .filter-badge { background: #167a47; color: #fff; border-radius: 20px; font-size: 10px; padding: 1px 7px; }
    .filter-menu { padding: 14px; min-width: 280px; }
    .filter-menu label { font-size: 12px; font-weight: 700; margin-bottom: 3px; }
    .filter-menu .form-select { font-size: 12px; margin-bottom: 10px; }

    /* Table */
    .data-wrap { overflow-x: auto; }
    table.data { width: 100%; border-collapse: collapse; font-size: 12px; }
    table.data th { font-size: 12px; font-weight: 700; padding: 10px 8px; color: #111; white-space: nowrap; border-bottom: 1px solid #dfe5e1; text-align: left; }
    table.data td { padding: 13px 8px; border-bottom: 1px solid #e6ebe8; vertical-align: middle; color: #222; }
    .empty-row td { text-align: center; color: #777; padding: 32px 8px; }

    /* Buttons */
    .btn-main { background: #167a47; color: #fff; border: 1px solid #167a47; border-radius: 6px; padding: 7px 16px; font-weight: 600; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-family: inherit; }
    .btn-main:hover { background: #12663b; color: #fff; }
    .btn-plain { background: #fff; color: #167a47; border: 1px solid #167a47; border-radius: 6px; padding: 7px 16px; font-weight: 600; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-family: inherit; }
    .btn-plain:hover { background: #e9f5ea; color: #167a47; }
    .btn-mini { font-size: 11px !important; padding: 5px 10px !important; }

    .btn-act { background: #fff; border: 1px solid; border-radius: 4px; padding: 4px 9px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: inherit; line-height: 1.3; }
    .act-view { color: #167a47; border-color: #167a47; }
    .act-view:hover { background: #e9f5ea; }
    .act-edit { color: #3a3a1a; border-color: #d9be00; }
    .act-edit:hover { background: #fff8d6; }
    .act-del { color: #d32f3a; border-color: #e2606a; }
    .act-del:hover { background: #fdecee; }

    .pill { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; background: #eef1e6; color: #3b4a2f; white-space: nowrap; }
    .p-green { background: #d9f5e3; color: #17683a; }
    .p-yellow { background: #fff1c9; color: #9a5b00; }
    .p-red { background: #fde2e2; color: #d32f3a; }
    .p-blue { background: #dcebff; color: #1a4f9c; }

    /* Create form */
    .form-panel { background: rgba(255, 255, 255, .97); border-radius: 12px; padding: 20px 22px; max-width: 980px; box-shadow: 0 4px 14px rgba(0, 0, 0, .12); }
    .form-panel .lbl { display: block; font-size: 13px; font-weight: 700; color: #1b6b3a; margin: 0 0 4px; }
    .field { position: relative; margin-bottom: 14px; }
    .field > i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 15px; color: #1b6b3a; pointer-events: none; }
    .field .form-select, .field .form-control { height: 38px; border: 1px solid #cfd8d3; border-radius: 8px; font-size: 13px; background-color: #fff; padding-left: 36px; font-family: inherit; }
    textarea.area { border: 1px solid #cfd8d3; border-radius: 8px; font-size: 13px; background: #fff; padding: 10px 12px; width: 100%; font-family: inherit; resize: vertical; min-height: 78px; }
    .form-panel .form-select:focus, .form-panel .form-control:focus, textarea.area:focus { border-color: #167a47; box-shadow: 0 0 0 .15rem rgba(22, 122, 71, .18); outline: none; }
    .req { color: #c0262d; }
    .form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px; }

    .detail-row { margin-bottom: 10px; }
    .detail-row .k { font-size: 11px; font-weight: 700; color: #5b6b52; text-transform: uppercase; letter-spacing: .03em; }
    .detail-row .v { font-size: 13px; white-space: pre-wrap; word-break: break-word; }

    @media print {
        .view-tabs, .head-actions, .toolbar, .btn-act, .card-head .btn-g { display: none !important; }
        .app-main { margin-left: 0 !important; width: 100% !important; }
    }
    @media (max-width: 760px) {
        .app-main { margin-left: 0 !important; width: 100% !important; padding: 12px !important; }
        .range input { width: 96px; }
    }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= h($pageTitle) ?></h1>
        <p class="page-sub"><?= h($pageSub) ?></p>
    </div>
    <div class="head-actions">
        <?php if ($view === 'list'): ?><a class="btn-g" href="<?= h($pageFile) ?>?view=new">+ Add Report</a><?php endif; ?>
        <button type="button" class="btn-g-outline" onclick="window.print()">Print</button>
    </div>
</div>

<nav class="view-tabs" aria-label="Report tabs">
    <a href="<?= h($pageFile) ?>?view=list&amp;show_archived=0" class="<?= ($view === 'list' && !$showArchived) ? 'active' : '' ?>">Active Reports</a>
    <a href="<?= h($pageFile) ?>?view=list&amp;show_archived=1" class="<?= ($view === 'list' && $showArchived) ? 'active' : '' ?>">Archived Reports</a>
    <a href="<?= h($pageFile) ?>?view=new" class="<?= $view === 'new' ? 'active' : '' ?>">Add Report</a>
</nav>

<?php if ($error !== ''): ?><div class="alert alert-danger py-2 px-3 mb-3 fs-6"><?= h($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success py-2 px-3 mb-3 fs-6"><?= h($success) ?></div><?php endif; ?>

<?php /* =========================== CREATE FORM =========================== */ ?>
<?php if ($view === 'new'): ?>
<form method="post" action="<?= h($pageFile) ?>?view=new" class="form-panel">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="view" value="new">

    <div class="row g-0">
        <div class="col-md-6 pe-md-3">
            <label class="lbl" for="resident_ref">Resident <span class="req">*</span></label>
            <div class="field"><i class="bi bi-person"></i>
                <select class="form-select" id="resident_ref" name="resident_ref" required>
                    <option value="">Select resident</option>
                    <?php foreach ($residentOptions as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $ov('resident_ref') === (string)$r['id'] ? 'selected' : '' ?>><?= h(fullName($r)) ?><?= !empty($r['resident_id']) ? ' (' . h($r['resident_id']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($isCare): ?>
            <label class="lbl" for="care_activity">Care Activity <span class="req">*</span></label>
            <div class="field"><i class="bi bi-heart-pulse-fill"></i>
                <select class="form-select" id="care_activity" name="care_activity" required>
                    <option value="">Select care activity</option>
                    <?php foreach (CARE_ACTIVITIES as $o): ?><option value="<?= h($o) ?>" <?= $ov('care_activity') === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                </select>
            </div>

            <label class="lbl" for="resident_condition">Resident Condition <span class="req">*</span></label>
            <div class="field"><i class="bi bi-exclamation-triangle"></i>
                <select class="form-select" id="resident_condition" name="resident_condition" required>
                    <option value="">Select condition</option>
                    <?php foreach (CARE_CONDITIONS as $o): ?><option value="<?= h($o) ?>" <?= $ov('resident_condition') === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <label class="lbl" for="incident_type">Incident Type <span class="req">*</span></label>
            <div class="field"><i class="bi bi-exclamation-triangle"></i>
                <select class="form-select" id="incident_type" name="incident_type" required>
                    <option value="">Select incident type</option>
                    <?php foreach (INCIDENT_TYPES as $o): ?><option value="<?= h($o) ?>" <?= $ov('incident_type') === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                </select>
            </div>

            <label class="lbl" for="location">Location <span class="req">*</span></label>
            <div class="field"><i class="bi bi-geo-alt-fill"></i>
                <select class="form-select" id="location" name="location" required>
                    <option value="">Select location</option>
                    <?php foreach (INCIDENT_PLACES as $o): ?><option value="<?= h($o) ?>" <?= $ov('location') === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-6 ps-md-3">
            <label class="lbl" for="report_date">Date <span class="req">*</span></label>
            <div class="field"><i class="bi bi-calendar3"></i>
                <input class="form-control" type="date" id="report_date" name="report_date" value="<?= h($ov('report_date', date('Y-m-d'))) ?>" required>
            </div>

            <label class="lbl" for="report_time">Time <span class="req">*</span></label>
            <div class="field"><i class="bi bi-clock"></i>
                <input class="form-control" type="time" id="report_time" name="report_time" value="<?= h($ov('report_time', date('H:i'))) ?>" required>
            </div>

            <?php if ($isCare): ?>
            <label class="lbl" for="completion_status">Completion Status <span class="req">*</span></label>
            <div class="field"><i class="bi bi-check-circle"></i>
                <select class="form-select" id="completion_status" name="completion_status" required>
                    <option value="">Select status</option>
                    <?php foreach (CARE_COMPLETION as $o): ?><option value="<?= h($o) ?>" <?= $ov('completion_status') === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <label class="lbl" for="witness">Witness</label>
            <div class="field"><i class="bi bi-eye"></i>
                <input class="form-control" id="witness" name="witness" maxlength="150" value="<?= h($ov('witness')) ?>" placeholder="Type the witness">
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isCare): ?>
    <label class="lbl" for="observation">Observation <span class="req">*</span></label>
    <textarea class="area mb-3" id="observation" name="observation" rows="3" maxlength="5000" placeholder="Enter your observation here...." required><?= h($ov('observation')) ?></textarea>
    <?php else: ?>
    <label class="lbl" for="description">Description <span class="req">*</span></label>
    <textarea class="area mb-3" id="description" name="description" rows="3" maxlength="5000" placeholder="Enter your description here...." required><?= h($ov('description')) ?></textarea>
    <?php endif; ?>

    <label class="lbl" for="action_taken">Action Taken</label>
    <textarea class="area mb-3" id="action_taken" name="action_taken" rows="3" maxlength="3000" placeholder="Enter action taken here...."><?= h($ov('action_taken')) ?></textarea>

    <label class="lbl" for="additional_notes">Additional Notes</label>
    <textarea class="area mb-3" id="additional_notes" name="additional_notes" rows="3" maxlength="3000" placeholder="Enter any additional notes...."><?= h($ov('additional_notes')) ?></textarea>

    <div class="form-actions">
        <a class="btn-plain" href="<?= h($pageFile) ?>">Cancel</a>
        <button class="btn-main" type="submit"><i class="bi bi-send"></i> Submit <?= h($info['label']) ?></button>
    </div>
</form>
<?php endif; ?>

<?php /* =========================== LIST =========================== */ ?>
<?php if ($view === 'list'): ?>
<section class="list-panel">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= $showArchived ? 'Archived Reports' : 'Active Reports' ?></h2>
            <p class="card-count"><?= count($rows) ?> report(s) found.</p>
        </div>
        <a class="btn-g" href="<?= h($pageFile) ?>?view=new">+ Add Report</a>
    </div>
    <form method="get" action="<?= h($pageFile) ?>" id="filterForm">
        <input type="hidden" name="view" value="list">
        <input type="hidden" name="show_archived" value="<?= $showArchived ? '1' : '0' ?>">

        <div class="toolbar">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Search by resident name, report ID...">
            </div>

            <div class="tool-right">
                <div class="range" title="Report date range">
                    <i class="bi bi-calendar3"></i>
                    <input type="date" name="from" value="<?= h($filters['from']) ?>" aria-label="From date">
                    <span>-</span>
                    <input type="date" name="to" value="<?= h($filters['to']) ?>" aria-label="To date">
                </div>

                <div class="dropdown">
                    <button class="btn-filter" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                        <i class="bi bi-funnel"></i> Filter
                        <?php if ($activeFilterCount): ?><span class="filter-badge"><?= (int)$activeFilterCount ?></span><?php endif; ?>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end filter-menu">
                        <label for="f_type"><?= $isCare ? 'Care Activity' : 'Incident Type' ?></label>
                        <select class="form-select" id="f_type" name="f_type">
                            <option value="">All</option>
                            <?php foreach ($typeOptions as $o): ?><option value="<?= h($o) ?>" <?= $filters['type'] === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                        </select>
                        <?php if ($isCare): ?>
                            <label for="f_status">Status</label>
                            <select class="form-select" id="f_status" name="f_status">
                                <option value="">All</option>
                                <?php foreach (CARE_COMPLETION as $o): ?><option value="<?= h($o) ?>" <?= $filters['status'] === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <label for="f_rstatus">Report Status</label>
                        <select class="form-select" id="f_rstatus" name="f_rstatus">
                            <option value="">All</option>
                            <?php foreach (REPORT_STATUSES as $o): ?><option value="<?= h($o) ?>" <?= $filters['rstatus'] === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                        </select>
                        <div class="d-flex gap-2">
                            <button class="btn-main btn-mini" type="submit">Apply</button>
                            <a class="btn-plain btn-mini" href="<?= h($pageFile) ?>?view=list&show_archived=<?= $showArchived ? '1' : '0' ?>&from=&to=">Clear all</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="data-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Report ID</th>
                    <th>Date</th>
                    <th>Report Type</th>
                    <th>Related To</th>
                    <th><?= $isCare ? 'Condition' : 'Location' ?></th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="empty-row"><td colspan="7"><?= $showArchived ? 'No archived reports found.' : 'No reports found for the current search and date range.' ?></td></tr>
            <?php else: foreach ($rows as $r):
                $code = reportCode($info['prefix'], $r['id']);
                $resName = fullName($r) !== '' ? fullName($r) : 'Resident #' . (int)$r['resident_id'];
                $when = prettyDateTime($r['report_date'], $r['report_time']);
                if ($isCare) {
                    $pairs = [
                        'Report ID' => $code, 'Resident' => $resName, 'Date & Time' => $when,
                        'Care Activity' => $r['care_activity'], 'Resident Condition' => $r['resident_condition'],
                        'Completion Status' => $r['completion_status'], 'Report Status' => $r['report_status'],
                        'Submitted By' => $r['submitted_by_name'],
                        'Observation' => $r['observation'], 'Action Taken' => $r['action_taken'] ?: '-',
                        'Additional Notes' => $r['additional_notes'] ?: '-',
                    ];
                } else {
                    $pairs = [
                        'Report ID' => $code, 'Resident' => $resName, 'Date & Time' => $when,
                        'Incident Type' => $r['incident_type'], 'Location' => $r['location'],
                        'Witness' => $r['witness'] ?: '-', 'Report Status' => $r['report_status'],
                        'Submitted By' => $r['submitted_by_name'],
                        'Description' => $r['description'], 'Action Taken' => $r['action_taken'] ?: '-',
                        'Additional Notes' => $r['additional_notes'] ?: '-',
                    ];
                }
            ?>
                <tr>
                    <td><?= h($code) ?></td>
                    <td><?= h(prettyDateTime($r['report_date'], '')) ?></td>
                    <td><?= h($isCare ? $r['care_activity'] : $r['incident_type']) ?></td>
                    <td>Resident: <?= h($resName) ?></td>
                    <td><?= h($isCare ? $r['resident_condition'] : $r['location']) ?></td>
                    <td><?= pill((string)$r['report_status']) ?></td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn-act act-view" data-bs-toggle="modal" data-bs-target="#viewModal"
                                data-title="<?= h($code) ?>" data-fields="<?= h(json_encode($pairs, JSON_UNESCAPED_UNICODE)) ?>">View</button>

                            <?php if (!$showArchived): ?>
                                <button type="button" class="btn-act act-edit" data-bs-toggle="modal" data-bs-target="#statusModal"
                                    data-id="<?= (int)$r['id'] ?>" data-code="<?= h($code) ?>"
                                    data-rstatus="<?= h($r['report_status']) ?>"
                                    data-cstatus="<?= h($isCare ? $r['completion_status'] : '') ?>">Edit status</button>
                                <form method="post" action="<?= h($pageFile) ?>" class="d-inline" onsubmit="return confirm('Move this report to the archive? You can restore it later.');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn-act act-del" name="action" value="archive" type="submit">Archive</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="<?= h($pageFile) ?>" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn-act act-view" name="action" value="restore" type="submit">Restore</button>
                                    <button class="btn-act act-del" name="action" value="delete" type="submit" onclick="return confirm('Permanently delete this archived report? This cannot be undone.');">Delete permanently</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

</section>

<!-- View modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="viewTitle">Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewBody"></div>
            <div class="modal-footer"><button type="button" class="btn-plain btn-mini" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<!-- Edit status modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form class="modal-content" method="post" action="<?= h($pageFile) ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="report_id" id="statusReportId" value="">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="statusTitle">Edit status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($isCare): ?>
                    <label class="form-label fw-bold small" for="completionSelect">Status</label>
                    <select class="form-select mb-3" id="completionSelect" name="completion_status">
                        <?php foreach (CARE_COMPLETION as $o): ?><option value="<?= h($o) ?>"><?= h($o) ?></option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <label class="form-label fw-bold small" for="reportStatusSelect">Report Status</label>
                <select class="form-select" id="reportStatusSelect" name="report_status">
                    <?php foreach (REPORT_STATUSES as $o): ?><option value="<?= h($o) ?>"><?= h($o) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-plain btn-mini" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn-main btn-mini">Save</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php /* Closes .app-main opened in sidebar.php. Remove this line if your sidebar.php/footer already closes it. */ ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var viewModal = document.getElementById('viewModal');
    if (viewModal) {
        viewModal.addEventListener('show.bs.modal', function (ev) {
            var b = ev.relatedTarget;
            if (!b) return;
            document.getElementById('viewTitle').textContent = b.getAttribute('data-title') || 'Report';
            var body = document.getElementById('viewBody');
            body.innerHTML = '';
            var fields = {};
            try { fields = JSON.parse(b.getAttribute('data-fields') || '{}'); } catch (e) {}
            Object.keys(fields).forEach(function (k) {
                var row = document.createElement('div'); row.className = 'detail-row';
                var kk = document.createElement('div'); kk.className = 'k'; kk.textContent = k;
                var vv = document.createElement('div'); vv.className = 'v'; vv.textContent = fields[k];
                row.appendChild(kk); row.appendChild(vv); body.appendChild(row);
            });
        });
    }
    var statusModal = document.getElementById('statusModal');
    if (statusModal) {
        statusModal.addEventListener('show.bs.modal', function (ev) {
            var b = ev.relatedTarget;
            if (!b) return;
            document.getElementById('statusReportId').value = b.getAttribute('data-id') || '';
            document.getElementById('statusTitle').textContent = 'Edit status - ' + (b.getAttribute('data-code') || '');
            document.getElementById('reportStatusSelect').value = b.getAttribute('data-rstatus') || 'Submitted';
            var cs = document.getElementById('completionSelect');
            if (cs && b.getAttribute('data-cstatus')) cs.value = b.getAttribute('data-cstatus');
        });
    }
})();
</script>
</body>
</html>
