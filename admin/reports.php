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

/* ---------------------------------------------------------------
 * Constants
 * ------------------------------------------------------------- */
const INCIDENT_TYPES    = ['Incident Report', 'Fall', 'Medical', 'Behavioral', 'Health', 'Attendance', 'Other'];
const INCIDENT_RELATED  = ['Resident', 'Caretaker', 'General'];
const INCIDENT_STATUSES = ['Open', 'In Progress', 'Resolved'];
const REPORT_TABS       = ['incident', 'history', 'residents', 'caretakers', 'add', 'snapshots', 'caretaker_list'];

/* ---------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------- */
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
function incidentCode($id): string {
    return 'INC-' . str_pad((string)(int)$id, 3, '0', STR_PAD_LEFT);
}
function prettyDate($d): string {
    $t = strtotime((string)$d);
    return $t ? date('M d, Y', $t) : (string)$d;
}
function statusPill(string $status): string {
    $map = ['Open' => 'st-open', 'In Progress' => 'st-progress', 'Resolved' => 'st-resolved'];
    return '<span class="pill ' . ($map[$status] ?? 'st-open') . '">' . h($status) . '</span>';
}
function incidentRelatedLabel(array $i): string {
    if (($i['related_to'] ?? '') === 'Resident') {
        $n = residentFullName($i);
        return $n !== '' ? $n : 'Resident #' . (int)($i['resident_id'] ?? 0);
    }
    if (($i['related_to'] ?? '') === 'Caretaker') {
        return (string)($i['caretaker_name'] ?? 'Caretaker');
    }
    return 'General';
}

function fetchIncidents(PDO $pdo, string $mode): array {
    switch ($mode) {
        case 'incident':   $where = "i.archived_at IS NULL AND i.status IN ('Open','In Progress')"; break;
        case 'history':    $where = "i.archived_at IS NULL AND i.status = 'Resolved'"; break;
        case 'residents':  $where = "i.archived_at IS NULL AND i.related_to = 'Resident' AND i.status <> 'Resolved'"; break;
        case 'caretakers': $where = "i.archived_at IS NULL AND i.related_to = 'Caretaker' AND i.status <> 'Resolved'"; break;
        case 'archive':    $where = "i.archived_at IS NOT NULL"; break;
        default:           $where = "1 = 0";
    }
    $sql = "SELECT i.*, r.first_name, r.middle_name, r.last_name
            FROM incident_reports i
            LEFT JOIN residents r ON r.id = i.resident_id
            WHERE $where
            ORDER BY i.report_date DESC, i.id DESC
            LIMIT 200";
    return $pdo->query($sql)->fetchAll();
}

function caretakerDisplayName(array $c): string {
    foreach (['full_name', 'fullname', 'name', 'caretaker_name'] as $k) {
        if (!empty($c[$k])) return trim((string)$c[$k]);
    }
    $n = trim(($c['first_name'] ?? '') . ' ' . ($c['middle_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
    return trim(preg_replace('/\s+/', ' ', $n));
}

function fetchCaretakerNames(PDO $pdo): array {
    $names = [];
    try {
        foreach ($pdo->query('SELECT * FROM caretakers')->fetchAll() as $row) {
            $n = caretakerDisplayName($row);
            if ($n !== '') $names[$n] = true;
        }
    } catch (Throwable $e) {}
    try {
        $q = "SELECT DISTINCT assigned_caretaker FROM residents WHERE assigned_caretaker IS NOT NULL AND assigned_caretaker <> ''";
        foreach ($pdo->query($q)->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $n = trim((string)$n);
            if ($n !== '') $names[$n] = true;
        }
    } catch (Throwable $e) {}
    $list = array_map('strval', array_keys($names));
    natcasesort($list);
    return array_values($list);
}

/** One row per caretaker, with how many incident reports mention them. Password-like columns are never read out. */
function fetchCaretakers(PDO $pdo, string $search = ''): array {
    $stats = [];
    $sq = "SELECT caretaker_name, COUNT(*) AS total, COALESCE(SUM(status <> 'Resolved'), 0) AS open_count
           FROM incident_reports
           WHERE archived_at IS NULL AND related_to = 'Caretaker'
           GROUP BY caretaker_name";
    foreach ($pdo->query($sq)->fetchAll() as $st) {
        $stats[mb_strtolower(trim((string)$st['caretaker_name']))] = $st;
    }
    $out = [];
    foreach ($pdo->query('SELECT * FROM caretakers')->fetchAll() as $row) {
        $cols = [];
        foreach ($row as $k => $v) {
            if (!preg_match('/pass|pwd|token|hash|secret|salt/i', (string)$k)) $cols[$k] = $v;
        }
        $name = caretakerDisplayName($cols);
        if ($search !== '') {
            $hay = implode(' ', array_map('strval', array_filter($cols, 'is_scalar')));
            if (mb_stripos($hay, $search) === false) continue;
        }
        $st = $stats[mb_strtolower($name)] ?? null;
        $out[] = [
            'name'  => $name,
            'cols'  => $cols,
            'open'  => (int)($st['open_count'] ?? 0),
            'total' => (int)($st['total'] ?? 0),
        ];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/**
 * Prints one incident table. $mode: incident | history | residents | caretakers | archive
 */
function renderIncidentTable(array $items, string $mode, string $csrf): void {
    $heads = [
        'incident'   => ['ID', 'Date', 'Type', 'Location', 'Status', 'Action'],
        'history'    => ['ID', 'Date', 'Type', 'Location', 'Status', 'Action'],
        'residents'  => ['ID', 'Resident', 'Type', 'Date', 'Status', 'Action'],
        'caretakers' => ['ID', 'Caretaker', 'Type', 'Date', 'Status', 'Action'],
        'archive'    => ['ID', 'Date', 'Type', 'Related To', 'Status', 'Archived On', 'Action'],
    ];
    $head = $heads[$mode] ?? $heads['incident'];
    ?>
    <div class="table-wrap">
        <table class="table table-hover mb-0">
            <thead><tr><?php foreach ($head as $c): ?><th><?= h($c) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php if (!$items): ?>
                <tr><td colspan="<?= count($head) ?>" class="text-center text-secondary py-4">No reports in this list.</td></tr>
            <?php else: foreach ($items as $i):
                $code = incidentCode($i['id']);
                $location = (string)($i['location'] ?? '');
                $related = incidentRelatedLabel($i);
                ?>
                <tr>
                    <td><?= h($code) ?></td>
                    <?php if ($mode === 'incident' || $mode === 'history'): ?>
                        <td><?= h(prettyDate($i['report_date'])) ?></td>
                        <td><?= h($i['report_type']) ?></td>
                        <td><?= h($location !== '' ? $location : '—') ?></td>
                    <?php elseif ($mode === 'residents' || $mode === 'caretakers'): ?>
                        <td><?= h($related) ?></td>
                        <td><?= h($i['report_type']) ?></td>
                        <td><?= h(prettyDate($i['report_date'])) ?></td>
                    <?php else: ?>
                        <td><?= h(prettyDate($i['report_date'])) ?></td>
                        <td><?= h($i['report_type']) ?></td>
                        <td><?= h($i['related_to']) ?>: <?= h($related) ?></td>
                    <?php endif; ?>
                    <td><?= statusPill((string)$i['status']) ?></td>
                    <?php if ($mode === 'archive'): ?>
                        <td><?= h(prettyDate($i['archived_at'])) ?></td>
                    <?php endif; ?>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn btn-sm btn-outline-success btn-view-incident"
                                data-bs-toggle="modal" data-bs-target="#viewModal"
                                data-code="<?= h($code) ?>"
                                data-type="<?= h($i['report_type']) ?>"
                                data-related="<?= h($i['related_to'] . ': ' . $related) ?>"
                                data-date="<?= h(prettyDate($i['report_date'])) ?>"
                                data-location="<?= h($location !== '' ? $location : '—') ?>"
                                data-status="<?= h($i['status']) ?>"
                                data-by="<?= h($i['created_by']) ?>"
                                data-created="<?= h($i['created_at']) ?>"
                                data-details="<?= h($i['details']) ?>">View</button>

                            <?php if ($mode !== 'archive'): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary btn-edit-status"
                                    data-bs-toggle="modal" data-bs-target="#statusModal"
                                    data-id="<?= (int)$i['id'] ?>"
                                    data-code="<?= h($code) ?>"
                                    data-status="<?= h($i['status']) ?>">Edit status</button>
                                <form method="post" action="reports.php" class="d-inline"
                                      onsubmit="return confirm('Move this report to the archive? You can restore it later.');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="tab" value="<?= h($mode) ?>">
                                    <input type="hidden" name="incident_id" value="<?= (int)$i['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" name="action" value="archive_incident" type="submit">Delete</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="reports.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="tab" value="snapshots">
                                    <input type="hidden" name="incident_id" value="<?= (int)$i['id'] ?>">
                                    <button class="btn btn-sm btn-success" name="action" value="restore_incident" type="submit">Restore</button>
                                    <button class="btn btn-sm btn-danger" name="action" value="delete_incident" type="submit"
                                        onclick="return confirm('Permanently delete this archived report? This cannot be undone.');">Delete permanently</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ---------------------------------------------------------------
 * Request state
 * ------------------------------------------------------------- */
$error = '';
$success = '';
$reportToDisplay = null;
$rows = [];
$history = [];
$items = [];
$archivedIncidents = [];
$caretakerRows = [];
$cq = trim((string)($_GET['cq'] ?? ''));
$residentOptions = [];
$caretakerOptions = [];
$summary = ['total' => 0, 'active' => 0, 'archived' => 0];
$tabCounts = ['incident' => 0, 'history' => 0, 'residents' => 0, 'caretakers' => 0, 'archive' => 0];

$showArchived = (($_GET['show_archived'] ?? '') === '1');
$viewId = filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT);
$exportId = filter_input(INPUT_GET, 'export', FILTER_VALIDATE_INT);

$tab = (string)($_POST['tab'] ?? $_GET['tab'] ?? 'incident');
if (!in_array($tab, REPORT_TABS, true)) $tab = 'incident';
if ($viewId || $exportId) $tab = 'snapshots';

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
    // Table for incident / issue reports (created automatically if missing)
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS incident_reports (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            report_type VARCHAR(40) NOT NULL,
            related_to ENUM('Resident','Caretaker','General') NOT NULL DEFAULT 'Resident',
            resident_id INT NULL,
            caretaker_name VARCHAR(150) NULL,
            location VARCHAR(100) NULL,
            report_date DATE NOT NULL,
            details TEXT NOT NULL,
            status ENUM('Open','In Progress','Resolved') NOT NULL DEFAULT 'Open',
            created_by VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME NULL DEFAULT NULL,
            INDEX idx_status (status),
            INDEX idx_archived (archived_at),
            INDEX idx_resident (resident_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['reports_csrf'], $token)) {
            throw new RuntimeException('Security token expired. Refresh the page and try again.');
        }

        $action = (string)($_POST['action'] ?? '');
        $id = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);

        /* ---------- Incident report actions ---------- */
        if ($action === 'add_incident') {
            $rType     = trim((string)($_POST['report_type'] ?? ''));
            $related   = trim((string)($_POST['related_to'] ?? ''));
            $residentRef = filter_var($_POST['resident_ref'] ?? '', FILTER_VALIDATE_INT);
            $caretaker = trim((string)($_POST['caretaker_name'] ?? ''));
            $location  = trim((string)($_POST['location'] ?? ''));
            $rDate     = trim((string)($_POST['report_date'] ?? ''));
            $details   = trim((string)($_POST['details'] ?? ''));

            if (!in_array($rType, INCIDENT_TYPES, true)) throw new RuntimeException('Please choose a valid report type.');
            if (!in_array($related, INCIDENT_RELATED, true)) throw new RuntimeException('Please choose who the report is related to.');
            if (!reportDateIsValid($rDate)) throw new RuntimeException('Please enter a valid report date.');
            if ($details === '') throw new RuntimeException('Please enter the report details.');
            if (mb_strlen($details) > 5000) throw new RuntimeException('Details must be 5,000 characters or fewer.');
            if (mb_strlen($location) > 100) throw new RuntimeException('Location must be 100 characters or fewer.');

            $residentValue = null;
            $caretakerValue = null;
            if ($related === 'Resident') {
                if (!$residentRef) throw new RuntimeException('Please select a resident.');
                $chk = $pdo->prepare('SELECT id FROM residents WHERE id = :id AND archived_at IS NULL');
                $chk->execute(['id' => $residentRef]);
                if (!$chk->fetch()) throw new RuntimeException('The selected resident was not found.');
                $residentValue = $residentRef;
            } elseif ($related === 'Caretaker') {
                if ($caretaker === '') throw new RuntimeException('Please select a caretaker.');
                if (mb_strlen($caretaker) > 150) throw new RuntimeException('Caretaker name is too long.');
                $caretakerValue = $caretaker;
            }

            $ins = $pdo->prepare(
                'INSERT INTO incident_reports
                 (report_type, related_to, resident_id, caretaker_name, location, report_date, details, status, created_by)
                 VALUES (:type, :related, :resident, :caretaker, :location, :rdate, :details, \'Open\', :creator)'
            );
            $ins->execute([
                'type' => $rType,
                'related' => $related,
                'resident' => $residentValue,
                'caretaker' => $caretakerValue,
                'location' => $location !== '' ? $location : null,
                'rdate' => $rDate,
                'details' => $details,
                'creator' => (string)$_SESSION['user_id'],
            ]);
            header('Location: reports.php?tab=incident&notice=incident_added');
            exit;
        }

        if (in_array($action, ['update_incident_status', 'archive_incident', 'restore_incident', 'delete_incident'], true)) {
            $incidentId = filter_var($_POST['incident_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$incidentId || $incidentId < 1) throw new RuntimeException('Invalid report ID.');
            $backTab = in_array($tab, ['incident','history','residents','caretakers'], true) ? $tab : 'incident';

            if ($action === 'update_incident_status') {
                $newStatus = (string)($_POST['status'] ?? '');
                if (!in_array($newStatus, INCIDENT_STATUSES, true)) throw new RuntimeException('Invalid status.');
                $stmt = $pdo->prepare('UPDATE incident_reports SET status = :s WHERE id = :id AND archived_at IS NULL');
                $stmt->execute(['s' => $newStatus, 'id' => $incidentId]);
                header('Location: reports.php?tab=' . $backTab . '&notice=status_updated');
                exit;
            }
            if ($action === 'archive_incident') {
                $stmt = $pdo->prepare('UPDATE incident_reports SET archived_at = NOW() WHERE id = :id AND archived_at IS NULL');
                $stmt->execute(['id' => $incidentId]);
                if ($stmt->rowCount() < 1) throw new RuntimeException('Report was not found or is already archived.');
                header('Location: reports.php?tab=' . $backTab . '&notice=incident_archived');
                exit;
            }
            if ($action === 'restore_incident') {
                $stmt = $pdo->prepare('UPDATE incident_reports SET archived_at = NULL WHERE id = :id AND archived_at IS NOT NULL');
                $stmt->execute(['id' => $incidentId]);
                if ($stmt->rowCount() < 1) throw new RuntimeException('Archived report was not found or is already restored.');
                header('Location: reports.php?tab=snapshots&show_archived=1&notice=incident_restored');
                exit;
            }
            if ($action === 'delete_incident') {
                $stmt = $pdo->prepare('DELETE FROM incident_reports WHERE id = :id AND archived_at IS NOT NULL');
                $stmt->execute(['id' => $incidentId]);
                if ($stmt->rowCount() < 1) throw new RuntimeException('Only archived reports can be permanently deleted.');
                header('Location: reports.php?tab=snapshots&show_archived=1&notice=incident_deleted');
                exit;
            }
        }

        /* ---------- Resident snapshot report actions ---------- */
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
            header('Location: reports.php?tab=snapshots&view=' . (int)$pdo->lastInsertId() . '&saved=1');
            exit;
        }

        if (!$id || $id < 1) throw new RuntimeException('Invalid report ID.');

        if ($action === 'archive') {
            $stmt = $pdo->prepare('UPDATE reports SET archived_at = NOW() WHERE id = :id AND archived_at IS NULL');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('Report was not found or is already archived.');
            header('Location: reports.php?tab=snapshots&show_archived=0&notice=archived');
            exit;
        }

        if ($action === 'restore') {
            $stmt = $pdo->prepare('UPDATE reports SET archived_at = NULL WHERE id = :id AND archived_at IS NOT NULL');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('Archived report was not found or is already restored.');
            header('Location: reports.php?tab=snapshots&show_archived=1&notice=restored');
            exit;
        }

        if ($action === 'delete_permanently') {
            $stmt = $pdo->prepare('DELETE FROM reports WHERE id = :id AND archived_at IS NOT NULL');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('Only archived reports can be permanently deleted.');
            header('Location: reports.php?tab=snapshots&show_archived=1&notice=deleted');
            exit;
        }

        throw new RuntimeException('Unknown report action.');
    }

    $notices = [
        'archived'          => 'Report moved to the archive. You can restore it later.',
        'restored'          => 'Report restored successfully.',
        'deleted'           => 'Archived report permanently deleted.',
        'incident_added'    => 'Report submitted successfully.',
        'status_updated'    => 'Report status updated.',
        'incident_archived' => 'Report moved to the archive. To restore it, open Resident Reports and click Archive.',
        'incident_restored' => 'Report restored successfully.',
        'incident_deleted'  => 'Archived report permanently deleted.',
    ];
    if (isset($_GET['saved'])) $success = 'Report generated and saved in the database.';
    $noticeKey = (string)($_GET['notice'] ?? '');
    if (isset($notices[$noticeKey])) $success = $notices[$noticeKey];

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

    if (($_GET['export_caretakers'] ?? '') === '1') {
        $ctExport = fetchCaretakers($pdo, $cq);
        $ctExportCols = $ctExport ? array_keys($ctExport[0]['cols']) : [];
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="caretaker_report_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['Name'], $ctExportCols, ['Open Reports', 'Total Reports']));
        foreach ($ctExport as $c) {
            $line = [$c['name']];
            foreach ($ctExportCols as $k) $line[] = $c['cols'][$k] ?? '';
            $line[] = $c['open'];
            $line[] = $c['total'];
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    /* ---------- Tab badge counts ---------- */
    $tc = $pdo->query(
        "SELECT
            COALESCE(SUM(archived_at IS NULL AND status IN ('Open','In Progress')), 0) AS inc,
            COALESCE(SUM(archived_at IS NULL AND status = 'Resolved'), 0) AS hist,
            COALESCE(SUM(archived_at IS NULL AND related_to = 'Resident' AND status <> 'Resolved'), 0) AS res,
            COALESCE(SUM(archived_at IS NULL AND related_to = 'Caretaker' AND status <> 'Resolved'), 0) AS care,
            COALESCE(SUM(archived_at IS NOT NULL), 0) AS arch
         FROM incident_reports"
    )->fetch() ?: [];
    $tabCounts = [
        'incident'   => (int)($tc['inc'] ?? 0),
        'history'    => (int)($tc['hist'] ?? 0),
        'residents'  => (int)($tc['res'] ?? 0),
        'caretakers' => (int)($tc['care'] ?? 0),
        'archive'    => (int)($tc['arch'] ?? 0),
    ];

    /* ---------- Tab content ---------- */
    if ($tab === 'add') {
        $residentOptions = $pdo->query(
            'SELECT id, resident_id, first_name, middle_name, last_name
             FROM residents WHERE archived_at IS NULL
             ORDER BY last_name, first_name'
        )->fetchAll();
        $caretakerOptions = fetchCaretakerNames($pdo);
    } elseif ($tab === 'caretaker_list') {
        $caretakerRows = fetchCaretakers($pdo, $cq);
    } elseif ($tab === 'snapshots') {
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
        if ($showArchived) {
            $archivedIncidents = fetchIncidents($pdo, 'archive');
        }
    } else {
        $items = fetchIncidents($pdo, $tab);
    }

} catch (Throwable $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $error === '') {
        $error = $e instanceof PDOException
            ? 'A database operation failed. Check your PHP error log. (' . $e->getMessage() . ')'
            : $e->getMessage();
    }
    // When a POST fails, still load the data needed to redraw the page
    try {
        if ($tab === 'add' && !$residentOptions) {
            $residentOptions = $pdo->query('SELECT id, resident_id, first_name, middle_name, last_name FROM residents WHERE archived_at IS NULL ORDER BY last_name, first_name')->fetchAll();
            $caretakerOptions = fetchCaretakerNames($pdo);
        } elseif (in_array($tab, ['incident','history','residents','caretakers'], true) && !$items) {
            $items = fetchIncidents($pdo, $tab);
        }
    } catch (Throwable $ignored) {}
}

// Remember what the person typed if the form failed validation
$old = ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'add') ? $_POST : [];
$oldVal = function (string $key, string $default = '') use ($old): string {
    return isset($old[$key]) ? (string)$old[$key] : $default;
};

$tabLabels = [
    'incident'   => 'Incident Reports',
    'history'    => 'History of Reports',
    'residents'  => 'Active Reports (Residents)',
    'caretakers' => 'Active Reports (Caretakers)',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reports | Home for the Aged</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    * { box-sizing: border-box; }

    body {
        margin: 0;
        background: #f4f8f5;
        color: #304b3b;
        font-family: Arial, Helvetica, sans-serif;
        overflow-x: hidden;
    }

    .sidebar-logo { display: block; padding: 10px 4px 6px; }
    .sidebar-logo a { display: block; }
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
    .report-header h1 { color: #17683a; font-size: 22px; font-weight: 700; margin: 0 0 4px; }
    .report-header p { color: #61796a; margin: 0; font-size: 13px; }

    /* Tabs */
    .report-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 16px;
    }
    .report-tabs a {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 16px;
        background: #fff;
        border: 1px solid #e3eee6;
        border-radius: 8px;
        color: #304b3b;
        font-size: 13px;
        text-decoration: none;
    }
    .report-tabs a:hover { background: #f1f8f3; }
    .report-tabs a.active {
        color: #17683a;
        font-weight: 700;
        border-bottom: 3px solid #17683a;
    }
    .tab-count {
        background: #edf8ef;
        color: #17683a;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        padding: 1px 7px;
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
    .stat-grid .panel { margin-bottom: 0; }
    .stat-label { color: #61796a; font-size: 13px; }
    .stat-value { font-size: 24px; font-weight: 700; color: #17683a; margin-top: 4px; }

    .form-label { font-weight: 600; color: #17683a; font-size: 13px; margin-bottom: 4px; }
    .form-label .req { color: #c0262d; }

    .form-control, .form-select {
        min-height: 38px;
        font-size: 13px;
        border-color: #cedbd1;
        border-radius: 6px;
    }
    .form-control:focus, .form-select:focus {
        border-color: #33b960;
        box-shadow: 0 0 0 0.15rem rgba(51, 185, 96, 0.15);
    }

    /* Add report form sections */
    .form-section-title {
        background: #edf8ef;
        color: #17683a;
        font-weight: 700;
        font-size: 15px;
        padding: 10px 14px;
        border-radius: 8px;
        margin: 0 0 14px;
    }

    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td {
        padding: 10px 14px;
        border-bottom: 1px solid #dcecdf;
        text-align: left;
        vertical-align: middle;
    }
    th { background: #edf8ef; color: #17683a; font-weight: 700; white-space: nowrap; }

    .pill {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 20px;
        background: #edf8ef;
        color: #19582a;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }
    .pill-archived { background: #fff3e0; color: #b78103; }
    .st-open     { background: #fde2e2; color: #c0262d; }
    .st-progress { background: #fff1c9; color: #b26a00; }
    .st-resolved { background: #d9f5e3; color: #17683a; }

    .small-muted { font-size: 12px; color: #61796a; }

    .btn-success {
        background-color: #198754;
        border-color: #198754;
        font-size: 13px;
        padding: 6px 16px;
        border-radius: 6px;
        font-weight: 600;
    }
    .btn-success:hover { background-color: #157347; border-color: #146c43; }

    .btn-outline-success, .btn-outline-secondary, .btn-outline-primary, .btn-outline-danger {
        font-size: 13px;
        padding: 6px 14px;
        border-radius: 6px;
    }
    .btn-sm { font-size: 12px !important; padding: 4px 10px !important; }

    .sidebar-link.active { box-shadow: none !important; outline: none !important; }

    .detail-box {
        background: #f4f8f5;
        border: 1px solid #e3eee6;
        border-radius: 8px;
        padding: 10px 12px;
        font-size: 13px;
        white-space: pre-wrap;
        word-break: break-word;
    }

    @media (max-width: 760px) {
        .app-main { margin-left: 0 !important; width: 100% !important; padding: 10px !important; }
        .stat-grid { grid-template-columns: 1fr; }
        .report-header, .panel { padding: 14px; }
    }

    @media print {
        .app-sidebar, .no-print, .report-tabs { display: none !important; }
        .app-main { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        body { background: #fff !important; }
        .panel { border: 0 !important; padding: 8px !important; box-shadow: none !important; }
        table { font-size: 11px !important; }
        th, td { padding: 6px !important; }
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
        <p>Submit incident reports, track their status, and manage saved resident report snapshots.</p>
    </div>
    <div class="no-print d-flex gap-2">
        <a class="btn btn-success btn-sm" href="reports.php?tab=add">+ Add Report</a>
        <a class="btn btn-outline-success btn-sm" href="reports.php?tab=<?= h($tab) ?>">Refresh</a>
        <button class="btn btn-outline-success btn-sm" onclick="window.print()" type="button">Print current view</button>
    </div>
</div>

<nav class="report-tabs no-print" aria-label="Report sections">
    <?php foreach ($tabLabels as $key => $label): ?>
        <a href="reports.php?tab=<?= h($key) ?>" class="<?= $tab === $key ? 'active' : '' ?>">
            <?= h($label) ?> <span class="tab-count"><?= (int)$tabCounts[$key] ?></span>
        </a>
    <?php endforeach; ?>
    <a href="reports.php?tab=add" class="<?= $tab === 'add' ? 'active' : '' ?>">Add Report</a>
    <a href="reports.php?tab=snapshots" class="<?= $tab === 'snapshots' ? 'active' : '' ?>">Resident Reports</a>
    <a href="reports.php?tab=caretaker_list" class="<?= $tab === 'caretaker_list' ? 'active' : '' ?>">Caretaker Reports</a>
</nav>

<?php if ($error !== ''): ?><div class="alert alert-danger py-2 px-3 mb-3 fs-6"><?= h($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success py-2 px-3 mb-3 fs-6"><?= h($success) ?></div><?php endif; ?>

<?php /* =====================================================
   TAB: ADD REPORT
   ===================================================== */ ?>
<?php if ($tab === 'add'): ?>
<section class="panel">
    <h2 class="h5 fw-bold text-success mb-1">Add Report</h2>
    <p class="small-muted mb-3">Submit a new incident or issue report.</p>

    <form method="post" action="reports.php?tab=add" id="addReportForm">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['reports_csrf']) ?>">
        <input type="hidden" name="action" value="add_incident">
        <input type="hidden" name="tab" value="add">

        <div class="form-section-title">Report Information</div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="report_type">Report Type <span class="req">*</span></label>
                <select class="form-select" id="report_type" name="report_type" required>
                    <?php foreach (INCIDENT_TYPES as $t): ?>
                        <option value="<?= h($t) ?>" <?= $oldVal('report_type', 'Incident Report') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="related_to">Related To <span class="req">*</span></label>
                <select class="form-select" id="related_to" name="related_to" required>
                    <?php foreach (INCIDENT_RELATED as $t): ?>
                        <option value="<?= h($t) ?>" <?= $oldVal('related_to', 'Resident') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6" id="residentGroup">
                <label class="form-label" for="resident_ref">Select Resident <span class="req">*</span></label>
                <div class="input-group">
                    <select class="form-select" id="resident_ref" name="resident_ref">
                        <option value="">— Select a resident —</option>
                        <?php foreach ($residentOptions as $r):
                            $label = residentFullName($r) . ($r['resident_id'] ? ' (' . $r['resident_id'] . ')' : '');
                        ?>
                            <option value="<?= (int)$r['id'] ?>" <?= $oldVal('resident_ref') === (string)$r['id'] ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-secondary" id="clearResident" title="Deselect resident">Deselect</button>
                </div>
            </div>

            <div class="col-md-6" id="caretakerGroup" style="display:none">
                <label class="form-label" for="caretaker_name">Select Caretaker <span class="req">*</span></label>
                <div class="input-group">
                    <select class="form-select" id="caretaker_name" name="caretaker_name">
                        <option value="">— Select a caretaker —</option>
                        <?php foreach ($caretakerOptions as $c): ?>
                            <option value="<?= h($c) ?>" <?= $oldVal('caretaker_name') === (string)$c ? 'selected' : '' ?>><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-secondary" id="clearCaretaker" title="Deselect caretaker">Deselect</button>
                </div>
            </div>

            <div class="col-md-3">
                <label class="form-label" for="report_date">Date <span class="req">*</span></label>
                <input class="form-control" type="date" id="report_date" name="report_date"
                       value="<?= h($oldVal('report_date', date('Y-m-d'))) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="location">Location</label>
                <input class="form-control" id="location" name="location" maxlength="100"
                       value="<?= h($oldVal('location')) ?>" placeholder="e.g. Room 101, Common Area">
            </div>

            <div class="col-12">
                <label class="form-label" for="details">Details <span class="req">*</span></label>
                <textarea class="form-control" id="details" name="details" rows="4" maxlength="5000"
                          placeholder="Enter report details..." required><?= h($oldVal('details')) ?></textarea>
            </div>
        </div>

        <div class="form-section-title mt-4">Actions</div>
        <div class="d-flex justify-content-center gap-2">
            <button type="reset" class="btn btn-outline-secondary" id="resetReport">Reset</button>
            <button type="submit" class="btn btn-success">Submit Report</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php /* =====================================================
   TABS: INCIDENT / HISTORY / RESIDENTS / CARETAKERS / ARCHIVE
   ===================================================== */ ?>
<?php if (isset($tabLabels[$tab])): ?>
<section class="panel">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 class="h6 mb-1 text-success fw-bold"><?= h($tabLabels[$tab]) ?></h2>
            <div class="small-muted">
                <?php if ($tab === 'history'): ?>
                    Reports marked as Resolved.
                <?php else: ?>
                    Open and in-progress reports. Use <strong>Edit status</strong> to update, or <strong>Delete</strong> to move a report to the archive.
                <?php endif; ?>
            </div>
        </div>
        <a class="btn btn-success btn-sm no-print" href="reports.php?tab=add">+ Add Report</a>
    </div>
    <?php renderIncidentTable($items, $tab, $_SESSION['reports_csrf']); ?>
</section>
<?php endif; ?>

<?php /* =====================================================
   TAB: RESIDENT SNAPSHOT REPORTS (original feature)
   ===================================================== */ ?>
<?php if ($tab === 'caretaker_list'):
    $ctCols = $caretakerRows ? array_slice(array_keys($caretakerRows[0]['cols']), 0, 8) : [];
?>
<section class="panel">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h2 class="h6 mb-1 text-success fw-bold">Caretaker Reports</h2>
            <div class="small-muted"><?= count($caretakerRows) ?> caretaker(s) · with the number of incident reports linked to each one.</div>
        </div>
        <div class="no-print d-flex flex-wrap gap-2">
            <a class="btn btn-outline-success btn-sm" href="<?= h(reportUrl(['tab' => 'caretaker_list', 'export_caretakers' => '1'])) ?>">Export CSV</a>
            <button class="btn btn-success btn-sm" onclick="window.print()" type="button">Print</button>
        </div>
    </div>

    <form method="get" action="reports.php" class="no-print row g-2 mb-3">
        <input type="hidden" name="tab" value="caretaker_list">
        <div class="col-md-6">
            <input class="form-control" name="cq" maxlength="150" value="<?= h($cq) ?>" placeholder="Search caretaker name or details">
        </div>
        <div class="col-md-6 d-flex gap-2">
            <button class="btn btn-success btn-sm" type="submit">Search</button>
            <a class="btn btn-outline-secondary btn-sm" href="reports.php?tab=caretaker_list">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Name</th>
                <?php foreach ($ctCols as $k): ?><th><?= h(ucwords(str_replace('_', ' ', (string)$k))) ?></th><?php endforeach; ?>
                <th>Open Reports</th><th>Total Reports</th>
            </tr></thead>
            <tbody>
            <?php if (!$caretakerRows): ?>
                <tr><td colspan="<?= count($ctCols) + 3 ?>" class="text-center text-secondary py-4">No caretakers found.</td></tr>
            <?php else: foreach ($caretakerRows as $c): ?>
                <tr>
                    <td><?= h($c['name'] !== '' ? $c['name'] : '—') ?></td>
                    <?php foreach ($ctCols as $k): ?><td><?= h($c['cols'][$k] ?? '') ?></td><?php endforeach; ?>
                    <td><?= $c['open'] > 0 ? '<span class="pill st-open">' . (int)$c['open'] . '</span>' : '0' ?></td>
                    <td><?= (int)$c['total'] ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($tab === 'snapshots'): ?>

<div class="stat-grid">
    <div class="panel"><div class="stat-label">All Residents</div><div class="stat-value"><?= (int)($summary['total'] ?? 0) ?></div></div>
    <div class="panel"><div class="stat-label">Active Resident Records</div><div class="stat-value"><?= (int)($summary['active'] ?? 0) ?></div></div>
    <div class="panel"><div class="stat-label">Archived Resident Records</div><div class="stat-value"><?= (int)($summary['archived'] ?? 0) ?></div></div>
</div>

<?php if (!$reportToDisplay): ?>
<section class="panel no-print">
    <h2 class="h6 mb-3 text-success fw-bold">Generate a resident report</h2>
    <form method="post" action="reports.php?tab=snapshots">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['reports_csrf']) ?>">
        <input type="hidden" name="action" value="generate">
        <input type="hidden" name="tab" value="snapshots">
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
                <a class="btn btn-outline-secondary btn-sm" href="reports.php?tab=snapshots">Clear filters</a>
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
            <a class="btn btn-outline-secondary btn-sm" href="reports.php?tab=snapshots">Back to reports</a>
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
            <a class="btn <?= !$showArchived ? 'btn-success' : 'btn-outline-success' ?> btn-sm" href="reports.php?tab=snapshots&show_archived=0">Active history</a>
            <a class="btn <?= $showArchived ? 'btn-success' : 'btn-outline-success' ?> btn-sm" href="reports.php?tab=snapshots&show_archived=1">Archive</a>
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
                            <a class="btn btn-sm btn-outline-primary" href="reports.php?tab=snapshots&view=<?= (int)$item['id'] ?>">View</a>
                            <a class="btn btn-sm btn-outline-success" href="reports.php?tab=snapshots&export=<?= (int)$item['id'] ?>">CSV</a>
                            <form method="post" action="reports.php?tab=snapshots" onsubmit="return confirm('Continue with this report action?');" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['reports_csrf']) ?>">
                                <input type="hidden" name="tab" value="snapshots">
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
<?php if ($showArchived): ?>
<section class="panel">
    <h2 class="h6 mb-1 text-success fw-bold">Archived incident reports</h2>
    <div class="small-muted mb-3">Incident reports that were deleted. Restore them or delete them permanently.</div>
    <?php renderIncidentTable($archivedIncidents, 'archive', $_SESSION['reports_csrf']); ?>
</section>
<?php endif; ?>
<?php endif; /* end snapshots tab */ ?>

<!-- ============ Modal: view report details ============ -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-success fw-bold" id="viewModalTitle">Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body small">
                <div class="row g-2 mb-3">
                    <div class="col-6"><div class="small-muted">Type</div><div id="vType"></div></div>
                    <div class="col-6"><div class="small-muted">Status</div><div id="vStatus"></div></div>
                    <div class="col-6"><div class="small-muted">Related to</div><div id="vRelated"></div></div>
                    <div class="col-6"><div class="small-muted">Date</div><div id="vDate"></div></div>
                    <div class="col-6"><div class="small-muted">Location</div><div id="vLocation"></div></div>
                    <div class="col-6"><div class="small-muted">Submitted by</div><div id="vBy"></div></div>
                </div>
                <div class="small-muted mb-1">Details</div>
                <div class="detail-box" id="vDetails"></div>
                <div class="small-muted mt-2" id="vCreated"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ============ Modal: edit status ============ -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form class="modal-content" method="post" action="reports.php">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['reports_csrf']) ?>">
            <input type="hidden" name="action" value="update_incident_status">
            <input type="hidden" name="tab" value="<?= h(isset($tabLabels[$tab]) ? $tab : 'incident') ?>">
            <input type="hidden" name="incident_id" id="statusIncidentId" value="">
            <div class="modal-header">
                <h5 class="modal-title text-success fw-bold" id="statusModalTitle">Edit status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="statusSelect">Status</label>
                <select class="form-select" id="statusSelect" name="status">
                    <?php foreach (INCIDENT_STATUSES as $s): ?>
                        <option value="<?= h($s) ?>"><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">Save status</button>
            </div>
        </form>
    </div>
</div>

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

<script>
(function () {
    // View details modal
    document.addEventListener('click', function (e) {
        var viewBtn = e.target.closest('.btn-view-incident');
        if (viewBtn) {
            var d = viewBtn.dataset;
            document.getElementById('viewModalTitle').textContent = d.code;
            document.getElementById('vType').textContent = d.type;
            document.getElementById('vStatus').textContent = d.status;
            document.getElementById('vRelated').textContent = d.related;
            document.getElementById('vDate').textContent = d.date;
            document.getElementById('vLocation').textContent = d.location;
            document.getElementById('vBy').textContent = 'User ' + d.by;
            document.getElementById('vDetails').textContent = d.details;
            document.getElementById('vCreated').textContent = 'Submitted ' + d.created;
        }

        // Edit status modal
        var statusBtn = e.target.closest('.btn-edit-status');
        if (statusBtn) {
            document.getElementById('statusModalTitle').textContent = 'Edit status · ' + statusBtn.dataset.code;
            document.getElementById('statusIncidentId').value = statusBtn.dataset.id;
            document.getElementById('statusSelect').value = statusBtn.dataset.status;
        }
    });

    // Add report form behaviour
    var related = document.getElementById('related_to');
    if (!related) return;

    var residentGroup = document.getElementById('residentGroup');
    var caretakerGroup = document.getElementById('caretakerGroup');
    var residentSel = document.getElementById('resident_ref');
    var caretakerSel = document.getElementById('caretaker_name');

    function toggleGroups() {
        var v = related.value;
        residentGroup.style.display = v === 'Resident' ? '' : 'none';
        caretakerGroup.style.display = v === 'Caretaker' ? '' : 'none';
        residentSel.required = v === 'Resident';
        caretakerSel.required = v === 'Caretaker';
        if (v !== 'Resident') residentSel.value = '';
        if (v !== 'Caretaker') caretakerSel.value = '';
    }

    related.addEventListener('change', toggleGroups);
    document.getElementById('clearResident').addEventListener('click', function () { residentSel.value = ''; residentSel.focus(); });
    document.getElementById('clearCaretaker').addEventListener('click', function () { caretakerSel.value = ''; caretakerSel.focus(); });
    document.getElementById('resetReport').addEventListener('click', function () { setTimeout(toggleGroups, 0); });
    toggleGroups();
})();
</script>

</body>
</html>
