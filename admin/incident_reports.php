<?php
/*
 * incident_reports.php
 * Put this file in:  /homefor_thetander/admin/incident_reports.php
 * (the same file also works in /caretaker/ - caretakers can view reports,
 * only admins can resolve or delete them).
 *
 * SETUP: change $sidebarFile below to your real sidebar file name.
 * The table is created automatically on first load if it does not exist.
 */

$sidebarFile = __DIR__ . '/../includes/sidebar.php';   // <-- change if your sidebar file is named differently

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$role     = strtolower((string) $_SESSION['role']);
$isAdmin  = ($role === 'admin');
$selfUrl  = basename($_SERVER['PHP_SELF'] ?? 'incident_reports.php');

$severities = ['Low', 'Medium', 'High', 'Critical'];

/* Create the table if it is missing */
try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS incident_reports (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT \'Medium\',
            category VARCHAR(50) NOT NULL DEFAULT \'Other\',
            resident_name VARCHAR(150) DEFAULT NULL,
            reported_by VARCHAR(150) NOT NULL,
            reported_by_id INT UNSIGNED DEFAULT NULL,
            incident_date DATE NOT NULL,
            description TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'Open\',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
} catch (Throwable $e) {
    /* Table may already exist or the user may lack CREATE rights */
}

/* CSRF token */
if (empty($_SESSION['ir_csrf'])) {
    $_SESSION['ir_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['ir_csrf'];

$flash = $_SESSION['ir_flash'] ?? null;
unset($_SESSION['ir_flash']);

/* Handle form actions, then redirect (post/redirect/get) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    $act   = (string) ($_POST['action'] ?? '');

    if (!hash_equals($csrf, $token)) {
        $_SESSION['ir_flash'] = ['error', 'Your session expired. Please try again.'];
    } else {
        try {
            if ($act === 'toggle' && $isAdmin) {
                $stmt = $pdo->prepare(
                    "UPDATE incident_reports
                     SET status = IF(status = 'Open', 'Resolved', 'Open')
                     WHERE id = ?"
                );
                $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                $_SESSION['ir_flash'] = ['ok', 'Status updated.'];
            } elseif ($act === 'delete' && $isAdmin) {
                $stmt = $pdo->prepare('DELETE FROM incident_reports WHERE id = ?');
                $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                $_SESSION['ir_flash'] = ['ok', 'Report deleted.'];
            }
        } catch (Throwable $e) {
            $_SESSION['ir_flash'] = ['error', 'A database operation failed. Confirm the incident_reports table exists and check your PHP error log.'];
        }
    }

    header('Location: ' . $selfUrl . (!empty($_POST['back']) ? '?' . ltrim((string) $_POST['back'], '?') : ''));
    exit;
}

/* Filters */
$fStatus   = (string) ($_GET['status'] ?? 'all');
$fSeverity = (string) ($_GET['severity'] ?? 'all');
$fSearch   = trim((string) ($_GET['q'] ?? ''));

if (!in_array($fStatus, ['all', 'open', 'resolved'], true)) { $fStatus = 'all'; }
if ($fSeverity !== 'all' && !in_array($fSeverity, $severities, true)) { $fSeverity = 'all'; }

$where  = [];
$params = [];

if ($fStatus === 'open')     { $where[] = "status = 'Open'"; }
if ($fStatus === 'resolved') { $where[] = "status = 'Resolved'"; }
if ($fSeverity !== 'all')    { $where[] = 'severity = ?'; $params[] = $fSeverity; }
if ($fSearch !== '') {
    $where[] = '(title LIKE ? OR description LIKE ? OR reported_by LIKE ? OR resident_name LIKE ?)';
    $like = '%' . $fSearch . '%';
    array_push($params, $like, $like, $like, $like);
}

$reports = [];
$stats   = ['total' => 0, 'open' => 0, 'urgent' => 0, 'resolved' => 0];

try {
    $sql = 'SELECT * FROM incident_reports'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY incident_date DESC, id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $row = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(status = 'Open') AS open_count,
                SUM(status = 'Open' AND severity IN ('High','Critical')) AS urgent,
                SUM(status = 'Resolved') AS resolved
         FROM incident_reports"
    )->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $stats = [
            'total'    => (int) $row['total'],
            'open'     => (int) $row['open_count'],
            'urgent'   => (int) $row['urgent'],
            'resolved' => (int) $row['resolved'],
        ];
    }
} catch (Throwable $e) {
    $flash = $flash ?: ['error', 'A database operation failed. Confirm the incident_reports table exists and check your PHP error log.'];
}

$backQuery = http_build_query(array_filter([
    'status'   => $fStatus !== 'all' ? $fStatus : null,
    'severity' => $fSeverity !== 'all' ? $fSeverity : null,
    'q'        => $fSearch !== '' ? $fSearch : null,
]));

function irTabUrl(string $status, string $sev, string $q): string
{
    $query = http_build_query(array_filter([
        'status'   => $status !== 'all' ? $status : null,
        'severity' => $sev !== 'all' ? $sev : null,
        'q'        => $q !== '' ? $q : null,
    ]));
    return basename($_SERVER['PHP_SELF'] ?? '') . ($query ? '?' . $query : '');
}

/* Sidebar, header, profile menu and opening <main> tag */
require $sidebarFile;
?>

<style>
    :root { --ir-green: #187a4a; --ir-green-dark: #126138; --ir-border: #d5ded8; }

    .ir-wrap { width: 100%; max-width: none; box-sizing: border-box; }
    .ir-wrap * { box-sizing: border-box; }

    /* Header card */
    .ir-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
        background: #e6f4ea; border: 1px solid #cfe6d6; border-radius: 10px; padding: 16px 20px; margin-bottom: 14px; }
    .ir-head h1 { margin: 0; font-size: 20px; font-weight: 700; color: var(--ir-green); }
    .ir-head p { margin: 3px 0 0; font-size: 12px; color: #4a5c52; }
    .ir-head-actions { display: flex; gap: 8px; flex-wrap: wrap; }

    /* Buttons */
    .ir-btn { display: inline-flex; align-items: center; justify-content: center; padding: 7px 12px; border-radius: 5px;
        border: 1px solid var(--ir-green); background: #fff; color: var(--ir-green); font: 13px Arial, sans-serif; cursor: pointer; text-decoration: none; }
    .ir-btn:hover { background: #f1f8f4; }
    .ir-btn.primary { background: var(--ir-green); color: #fff; }
    .ir-btn.primary:hover { background: var(--ir-green-dark); }
    .ir-btn.gray { border-color: #9aa5a0; color: #555; }
    .ir-btn.danger { border-color: #b3261e; color: #b3261e; }
    .ir-btn.danger:hover { background: #fbeceb; }
    .ir-btn.small { padding: 4px 10px; font-size: 12px; }
    .ir-btn:focus-visible, .ir-field input:focus, .ir-field select:focus { outline: 3px solid #2f80d9; outline-offset: 2px; }

    /* Flash */
    .ir-flash { padding: 11px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 14px; }
    .ir-flash.ok { background: #e3f3e8; color: #14502a; border: 1px solid #b7dcc3; }
    .ir-flash.error { background: #f8d7da; color: #7a1c24; border: 1px solid #efc2bf; }

    /* Stat cards */
    .ir-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 14px; }
    .ir-stat { background: #fff; border-radius: 10px; padding: 14px 16px; }
    .ir-stat span { display: block; font-size: 12px; color: #5b6b62; }
    .ir-stat b { display: block; margin-top: 6px; font-size: 22px; color: var(--ir-green); }

    /* Panels */
    .ir-panel { background: #fff; border-radius: 10px; padding: 18px 16px; margin-bottom: 14px; }
    .ir-panel-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .ir-panel h2 { margin: 0; font-size: 15px; font-weight: 700; color: var(--ir-green); }
    .ir-panel .ir-note { margin: 3px 0 0; font-size: 11px; color: #5b6b62; }
    .ir-tabs { display: flex; gap: 6px; }
    .ir-btn.on { background: var(--ir-green); color: #fff; }

    /* Filter form */
    .ir-filter { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 14px; align-items: end; margin-top: 12px; }
    .ir-field { display: grid; gap: 5px; font-size: 12px; font-weight: 700; color: var(--ir-green); }
    .ir-field input, .ir-field select { font: 14px Arial, sans-serif; color: #111; background: #fff; border: 1px solid #c3cfc7; border-radius: 5px; padding: 9px 10px; width: 100%; }
    .ir-filter-actions { grid-column: 1 / -1; display: flex; gap: 8px; align-items: center; }

    /* Table */
    .ir-table-wrap { overflow-x: auto; }
    .ir-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .ir-table th { text-align: left; padding: 9px 8px; font-weight: 700; color: #222; border-bottom: 1px solid #e2e8e4; white-space: nowrap; }
    .ir-table td { padding: 10px 8px; border-bottom: 1px solid #edf1ee; vertical-align: top; color: #222; }
    .ir-title { font-weight: 700; font-size: 13px; }
    .ir-desc { margin: 4px 0 0; color: #53635a; white-space: pre-wrap; overflow-wrap: anywhere; max-width: 420px; }
    .ir-tag { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 99px; border: 1px solid currentColor; }
    .ir-tag.sev-Critical { color: #b3261e; } .ir-tag.sev-High { color: #b45f00; }
    .ir-tag.sev-Medium { color: #7d6e00; } .ir-tag.sev-Low { color: #2e7d4f; }
    .ir-tag.st-Open { color: #b45f00; } .ir-tag.st-Resolved { color: #2e7d4f; }
    .ir-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .ir-actions form { margin: 0; }
    .ir-empty { text-align: center; color: #5b6b62; padding: 28px 8px !important; }

    @media (max-width: 800px) {
        .ir-stats { grid-template-columns: repeat(2, 1fr); }
        .ir-filter { grid-template-columns: 1fr; }
    }
    @media print {
        .ir-head-actions, .ir-filter, .ir-tabs, .ir-actions-col { display: none !important; }
    }
</style>

<div class="ir-wrap">

    <div class="ir-head">
        <div>
            <h1>Incident Reports</h1>
            <p>Record, review, and resolve incidents involving residents and staff.</p>
        </div>
        <div class="ir-head-actions">
            <a class="ir-btn" href="<?= h($selfUrl) ?>">Refresh</a>
            <button type="button" class="ir-btn primary" onclick="window.print()">Print current view</button>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="ir-flash <?= $flash[0] === 'ok' ? 'ok' : 'error' ?>" role="status"><?= h($flash[1]) ?></div>
    <?php endif; ?>

    <div class="ir-stats">
        <div class="ir-stat"><span>Total Reports</span><b><?= $stats['total'] ?></b></div>
        <div class="ir-stat"><span>Open Reports</span><b><?= $stats['open'] ?></b></div>
        <div class="ir-stat"><span>Open, High or Critical</span><b><?= $stats['urgent'] ?></b></div>
        <div class="ir-stat"><span>Resolved Reports</span><b><?= $stats['resolved'] ?></b></div>
    </div>

    <section class="ir-panel">
        <h2>Filter incident reports</h2>
        <form method="get" action="<?= h($selfUrl) ?>" class="ir-filter">
            <?php if ($fStatus !== 'all'): ?>
                <input type="hidden" name="status" value="<?= h($fStatus) ?>">
            <?php endif; ?>
            <label class="ir-field">Search
                <input type="search" name="q" value="<?= h($fSearch) ?>" placeholder="Title, description, resident, reporter">
            </label>
            <label class="ir-field">Severity
                <select name="severity">
                    <option value="all">All severities</option>
                    <?php foreach ($severities as $s): ?>
                        <option value="<?= h($s) ?>"<?= $fSeverity === $s ? ' selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="ir-filter-actions">
                <button type="submit" class="ir-btn primary">Apply filters</button>
                <a class="ir-btn gray" href="<?= h($selfUrl) ?>">Clear filters</a>
            </div>
        </form>
    </section>

    <section class="ir-panel">
        <div class="ir-panel-head">
            <div>
                <h2>Incident report list</h2>
                <p class="ir-note"><?= count($reports) ?> report(s) match the current filters.</p>
            </div>
            <div class="ir-tabs">
                <a class="ir-btn<?= $fStatus === 'all' ? ' on' : '' ?>" href="<?= h(irTabUrl('all', $fSeverity, $fSearch)) ?>">All</a>
                <a class="ir-btn<?= $fStatus === 'open' ? ' on' : '' ?>" href="<?= h(irTabUrl('open', $fSeverity, $fSearch)) ?>">Open</a>
                <a class="ir-btn<?= $fStatus === 'resolved' ? ' on' : '' ?>" href="<?= h(irTabUrl('resolved', $fSeverity, $fSearch)) ?>">Resolved</a>
            </div>
        </div>

        <div class="ir-table-wrap">
            <table class="ir-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Incident</th>
                        <th>Category</th>
                        <th>Resident</th>
                        <th>Reported By</th>
                        <th>Date</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <?php if ($isAdmin): ?><th class="ir-actions-col">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$reports): ?>
                    <tr><td colspan="<?= $isAdmin ? 9 : 8 ?>" class="ir-empty">No records found.</td></tr>
                <?php endif; ?>

                <?php foreach ($reports as $r): ?>
                    <tr>
                        <td>#<?= (int) $r['id'] ?></td>
                        <td>
                            <div class="ir-title"><?= h($r['title']) ?></div>
                            <p class="ir-desc"><?= h($r['description']) ?></p>
                        </td>
                        <td><?= h($r['category']) ?></td>
                        <td><?= !empty($r['resident_name']) ? h($r['resident_name']) : '&mdash;' ?></td>
                        <td><?= h($r['reported_by']) ?></td>
                        <td style="white-space:nowrap"><?= h(date('M j, Y', strtotime((string) $r['incident_date']))) ?></td>
                        <td><span class="ir-tag sev-<?= h($r['severity']) ?>"><?= h($r['severity']) ?></span></td>
                        <td><span class="ir-tag st-<?= h($r['status']) ?>"><?= h($r['status']) ?></span></td>
                        <?php if ($isAdmin): ?>
                            <td class="ir-actions-col">
                                <div class="ir-actions">
                                    <form method="post" action="<?= h($selfUrl) ?>">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="back" value="<?= h($backQuery) ?>">
                                        <button type="submit" class="ir-btn small"><?= $r['status'] === 'Open' ? 'Mark resolved' : 'Reopen' ?></button>
                                    </form>
                                    <form method="post" action="<?= h($selfUrl) ?>" onsubmit="return confirm('Delete this report?');">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="back" value="<?= h($backQuery) ?>">
                                        <button type="submit" class="ir-btn small danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

</main>