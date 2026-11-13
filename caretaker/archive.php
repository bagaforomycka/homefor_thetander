<?php
/**
 * HOME FOR THE AGED
 * CARETAKER ARCHIVE & RESTORE
 * File: caretaker/archive.php
 *
 * Categories
 *   Incident Reports -> soft-delete / restore (archived_at)
 *   Care Reports     -> soft-delete / restore (archived_at)
 *   Messages         -> archive / restore     (archived_at)
 *   My Residents     -> archive / restore residents assigned to the caretaker
 *                       (caretaker_resident_archive)
 *
 * A caretaker only ever sees and restores their OWN records.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    strtolower(trim((string)$_SESSION['role'])) !== 'caretaker'
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

/* Optional helper that creates caretaker_resident_archive when it is missing. */
if (is_file(__DIR__ . '/../config/archive_helper.php')) {
    require_once __DIR__ . '/../config/archive_helper.php';
}

/*
 * While true, a small line under each tab shows which table / columns were
 * matched, to help with troubleshooting. Set to false when everything works.
 */
const CT_ARCHIVE_DEBUG = true;

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ctColumns(PDO $pdo, string $table): array
{
    try {
        return $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

function ctPick(array $existing, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $existing, true)) {
            return $candidate;
        }
    }
    return null;
}

function ctPickAll(array $existing, array $candidates): array
{
    return array_values(array_filter(
        $candidates,
        static fn($c) => in_array($c, $existing, true)
    ));
}

function ctTable(array $allTables, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $allTables, true)) {
            return $candidate;
        }
    }
    return null;
}

$userId = (int)$_SESSION['user_id'];

if (empty($_SESSION['caretaker_archive_csrf'])) {
    $_SESSION['caretaker_archive_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['caretaker_archive_csrf'];

/* ------------------------------------------------------------------ */
/* Categories                                                         */
/* ------------------------------------------------------------------ */

$categories = [
    'incidents' => [
        'label' => 'Incident Reports',
        'noun' => 'incident report',
        'kind' => 'table',
        'tables' => ['incident_reports', 'incident_report', 'incidents'],
        'owner' => ['caretaker_user_id', 'reported_by', 'created_by', 'submitted_by', 'user_id', 'author_id', 'caretaker_id', 'staff_id'],
        'multi_owner' => false,
        'back' => 'incident_reports.php',
        'back_label' => 'Incident Reports',
        'behavior' => 'Soft-delete and restore incident reports'
    ],
    'care' => [
        'label' => 'Care Reports',
        'noun' => 'care report',
        'kind' => 'table',
        'tables' => ['care_reports', 'care_report'],
        'owner' => ['caretaker_user_id', 'reported_by', 'created_by', 'submitted_by', 'user_id', 'author_id', 'caretaker_id', 'staff_id'],
        'multi_owner' => false,
        'back' => 'care_reports.php',
        'back_label' => 'Care Reports',
        'behavior' => 'Soft-delete and restore care reports'
    ],
    'messages' => [
        'label' => 'Messages',
        'noun' => 'message',
        'kind' => 'table',
        'tables' => ['messages', 'message'],
        'owner' => ['sender_id', 'receiver_id', 'recipient_id', 'from_user_id', 'to_user_id', 'user_id'],
        'multi_owner' => true,
        'back' => 'messages.php',
        'back_label' => 'Messages',
        'behavior' => 'Archive and restore messages'
    ],
    'residents' => [
        'label' => 'My Residents',
        'noun' => 'resident',
        'kind' => 'residents',
        'back' => 'myresidents.php',
        'back_label' => 'My Residents',
        'behavior' => 'Archive and restore residents assigned to the caretaker'
    ]
];

$tab = $_GET['tab'] ?? 'incidents';
if (!isset($categories[$tab])) {
    $tab = 'incidents';
}

$search = trim((string)($_GET['search'] ?? ''));
$success = '';
$error = '';
$errorDetail = '';

/* ------------------------------------------------------------------ */
/* Work out which tables / columns each category uses                  */
/* ------------------------------------------------------------------ */

$allTables = [];
try {
    $allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('Caretaker archive SHOW TABLES: ' . $e->getMessage());
    $error = 'Unable to read the database tables.';
    $errorDetail = $e->getMessage();
}

/* Make sure the caretaker_resident_archive table exists. */
if (
    !in_array('caretaker_resident_archive', $allTables, true) &&
    function_exists('archiveEnsureCaretakerTable')
) {
    try {
        archiveEnsureCaretakerTable($pdo);
        $allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('Caretaker archive ensure table: ' . $e->getMessage());
    }
}

/*
 * Returns ['ok' => bool, 'reason' => string, ...details]
 */
function ctResolve(PDO $pdo, array $cfg, array $allTables): array
{
    /* ---- My Residents ---- */
    if ($cfg['kind'] === 'residents') {
        if (!in_array('caretaker_resident_archive', $allTables, true)) {
            return ['ok' => false, 'reason' => 'The caretaker_resident_archive table does not exist yet.'];
        }

        $cols = ctColumns($pdo, 'caretaker_resident_archive');

        foreach (['resident_id', 'caretaker_user_id'] as $needed) {
            if (!in_array($needed, $cols, true)) {
                return ['ok' => false, 'reason' => "caretaker_resident_archive has no `$needed` column."];
            }
        }

        return [
            'ok' => true,
            'table' => 'caretaker_resident_archive',
            'date' => ctPick($cols, ['archived_at', 'created_at'])
        ];
    }

    /* ---- Report / message tables ---- */
    $table = ctTable($allTables, $cfg['tables']);

    if ($table === null) {
        return ['ok' => false, 'reason' => 'No ' . $cfg['label'] . ' table was found (looked for: ' . implode(', ', $cfg['tables']) . ').'];
    }

    $cols = ctColumns($pdo, $table);

    if (!in_array('id', $cols, true)) {
        return ['ok' => false, 'reason' => "The `$table` table has no `id` column."];
    }

    /* Add archived_at automatically when missing. */
    if (!in_array('archived_at', $cols, true)) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `archived_at` TIMESTAMP NULL DEFAULT NULL");
            $cols[] = 'archived_at';
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => "Unable to add archived_at to `$table`: " . $e->getMessage()];
        }
    }

    /* Which column(s) tie a row to the logged-in caretaker. */
    if ($cfg['multi_owner']) {
        $ownerCols = ctPickAll($cols, $cfg['owner']);
    } else {
        $first = ctPick($cols, $cfg['owner']);
        $ownerCols = $first === null ? [] : [$first];
    }

    if (!$ownerCols) {
        return [
            'ok' => false,
            'reason' => "Could not tell which `$table` column links a record to a caretaker, so nothing is shown (to keep other people's records private)."
        ];
    }

    return [
        'ok' => true,
        'table' => $table,
        'cols' => $cols,
        'owner' => $ownerCols,
        'title' => ctPick($cols, ['title', 'subject', 'report_title', 'incident_type', 'report_type', 'category', 'type']),
        'detail' => ctPick($cols, ['description', 'details', 'summary', 'message', 'body', 'content', 'notes']),
        'date' => ctPick($cols, ['incident_date', 'report_date', 'date', 'sent_at', 'created_at']),
        'resident' => in_array('resident_id', $cols, true)
    ];
}

/* WHERE fragment + params that restrict rows to the logged-in caretaker. */
function ctOwnerWhere(array $meta, int $userId, string $alias = 't'): array
{
    $parts = [];
    $params = [];

    foreach ($meta['owner'] as $i => $column) {
        $key = 'uid' . $i;
        $parts[] = "$alias.`$column` = :$key";
        $params[$key] = $userId;
    }

    return ['(' . implode(' OR ', $parts) . ')', $params];
}

$meta = [];
foreach ($categories as $key => $cfg) {
    $meta[$key] = $error ? ['ok' => false, 'reason' => $error] : ctResolve($pdo, $cfg, $allTables);
}

/* ------------------------------------------------------------------ */
/* Restore                                                            */
/* ------------------------------------------------------------------ */

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page.');
    }

    $postCategory = $_POST['category'] ?? '';
    $recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);

    if (!isset($categories[$postCategory])) {
        $error = 'Invalid category.';
    } elseif (!$recordId || $recordId < 1) {
        $error = 'Invalid record selected.';
    } elseif (!$meta[$postCategory]['ok']) {
        $error = $meta[$postCategory]['reason'];
    } else {
        $tab = $postCategory;

        try {
            $m = $meta[$postCategory];

            if ($categories[$postCategory]['kind'] === 'residents') {

                $stmt = $pdo->prepare("
                    DELETE FROM caretaker_resident_archive
                    WHERE resident_id = :rid
                      AND caretaker_user_id = :uid
                ");
                $stmt->execute(['rid' => $recordId, 'uid' => $userId]);

            } else {

                [$ownerSql, $ownerParams] = ctOwnerWhere($m, $userId);

                $stmt = $pdo->prepare("
                    UPDATE `{$m['table']}` t
                    SET t.`archived_at` = NULL
                    WHERE t.`id` = :id
                      AND t.`archived_at` IS NOT NULL
                      AND $ownerSql
                ");
                $stmt->execute(array_merge(['id' => $recordId], $ownerParams));
            }

            if ($stmt->rowCount() > 0) {
                header('Location: archive.php?tab=' . urlencode($postCategory) . '&restored=1');
                exit;
            }

            $error = 'Record not found or already restored.';

        } catch (Throwable $e) {
            error_log('Caretaker archive restore error: ' . $e->getMessage());
            $error = 'Unable to restore the selected record.';
            $errorDetail = $e->getMessage();
        }
    }
}

if (isset($_GET['restored'])) {
    $success = ucfirst($categories[$tab]['noun']) . ' restored successfully.';
}

/* ------------------------------------------------------------------ */
/* Counts                                                             */
/* ------------------------------------------------------------------ */

$counts = array_fill_keys(array_keys($categories), 0);

foreach ($categories as $key => $cfg) {
    if (!$meta[$key]['ok']) {
        continue;
    }

    try {
        $m = $meta[$key];

        if ($cfg['kind'] === 'residents') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM caretaker_resident_archive a
                JOIN residents r ON r.id = a.resident_id
                WHERE a.caretaker_user_id = :uid
                  AND (r.status IS NULL OR r.status <> 'archived')
            ");
            $stmt->execute(['uid' => $userId]);
        } else {
            [$ownerSql, $ownerParams] = ctOwnerWhere($m, $userId);
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM `{$m['table']}` t
                WHERE t.`archived_at` IS NOT NULL
                  AND $ownerSql
            ");
            $stmt->execute($ownerParams);
        }

        $counts[$key] = (int)$stmt->fetchColumn();

    } catch (Throwable $e) {
        error_log('Caretaker archive count (' . $key . '): ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Load the active tab                                                */
/* ------------------------------------------------------------------ */

$rows = [];
$tabNotice = '';
$cfg = $categories[$tab];
$m = $meta[$tab];

if (!$m['ok']) {
    $tabNotice = $m['reason'];
} else {
    try {
        $params = [];

        if ($cfg['kind'] === 'residents') {

            $dateSql = $m['date'] ? "a.`{$m['date']}`" : 'NULL';

            $sql = "
                SELECT
                    r.id AS rid,
                    r.resident_id AS resident_code,
                    r.first_name,
                    r.middle_name,
                    r.last_name,
                    r.room_number,
                    r.care_level,
                    $dateSql AS archived_at
                FROM caretaker_resident_archive a
                JOIN residents r ON r.id = a.resident_id
                WHERE a.caretaker_user_id = :uid
                  AND (r.status IS NULL OR r.status <> 'archived')
            ";
            $params['uid'] = $userId;

            if ($search !== '') {
                $sql .= " AND (
                    r.first_name LIKE :s1
                    OR r.middle_name LIKE :s2
                    OR r.last_name LIKE :s3
                    OR r.resident_id LIKE :s4
                    OR r.room_number LIKE :s5
                )";
                $term = '%' . $search . '%';
                foreach (['s1', 's2', 's3', 's4', 's5'] as $k) {
                    $params[$k] = $term;
                }
            }

            $sql .= " ORDER BY r.id DESC LIMIT 300";

        } else {

            [$ownerSql, $ownerParams] = ctOwnerWhere($m, $userId);

            $select = [
                't.`id` AS rid',
                't.`archived_at` AS archived_at',
                $m['title'] ? "t.`{$m['title']}` AS title" : 'NULL AS title',
                $m['detail'] ? "t.`{$m['detail']}` AS detail" : 'NULL AS detail',
                $m['date'] ? "t.`{$m['date']}` AS item_date" : 'NULL AS item_date'
            ];

            $join = '';

            if ($m['resident']) {
                $join = 'LEFT JOIN residents r ON r.id = t.`resident_id`';
                $select[] = 'r.first_name';
                $select[] = 'r.last_name';
                $select[] = 'r.resident_id AS resident_code';
            } else {
                $select[] = 'NULL AS first_name';
                $select[] = 'NULL AS last_name';
                $select[] = 'NULL AS resident_code';
            }

            $sql = "
                SELECT " . implode(', ', $select) . "
                FROM `{$m['table']}` t
                $join
                WHERE t.`archived_at` IS NOT NULL
                  AND $ownerSql
            ";
            $params = $ownerParams;

            if ($search !== '') {
                $conditions = [];
                $term = '%' . $search . '%';
                $i = 0;

                $searchColumns = [];
                if ($m['title']) {
                    $searchColumns[] = "t.`{$m['title']}`";
                }
                if ($m['detail']) {
                    $searchColumns[] = "t.`{$m['detail']}`";
                }
                if ($m['resident']) {
                    $searchColumns[] = 'r.first_name';
                    $searchColumns[] = 'r.last_name';
                }

                foreach ($searchColumns as $columnSql) {
                    $key = 'q' . $i++;
                    $conditions[] = "$columnSql LIKE :$key";
                    $params[$key] = $term;
                }

                if ($conditions) {
                    $sql .= ' AND (' . implode(' OR ', $conditions) . ')';
                }
            }

            $sql .= ' ORDER BY t.`archived_at` DESC LIMIT 300';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        error_log('Caretaker archive load (' . $tab . '): ' . $e->getMessage());
        $error = $error ?: 'Unable to load archived records.';
        $errorDetail = $errorDetail ?: $e->getMessage();
    }
}

function ctDate($value, string $format = 'M j, Y g:i A'): string
{
    $time = $value ? strtotime((string)$value) : false;
    return $time ? date($format, $time) : '—';
}

function ctSnippet($value, int $length = 90): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)($value ?? ''))));

    if ($text === '') {
        return '—';
    }

    return function_exists('mb_strimwidth')
        ? mb_strimwidth($text, 0, $length, '…', 'UTF-8')
        : (strlen($text) > $length ? substr($text, 0, $length - 1) . '…' : $text);
}

$isResidents = $cfg['kind'] === 'residents';
$isMessages = $tab === 'messages';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Archive &amp; Restore | Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        .caretaker-archive-page {
            width: 100%;
            max-width: 1600px;
            min-width: 0;
            margin: 0 auto;
        }

        .archive-header {
            background: #eaf6ee;
            border: 1px solid #d8eadc;
            border-radius: 14px;
            padding: 25px 28px;
            margin-bottom: 24px;
        }

        .archive-header h1 {
            color: #17683a;
            font-size: 29px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .archive-header p {
            color: #61796a;
            margin: 0;
        }


        /* TABS */
        .archive-tabs {
            position: relative;
            z-index: 5;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            border-bottom: 1px solid #d8eadc;
            padding: 0 0 12px;
        }

        .archive-tabs .nav-link {
            pointer-events: auto;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            color: #17683a;
            background-color: #ffffff;
            border: 1.5px solid #17683a;
            border-radius: 8px;
            font-weight: 700;
            text-decoration: none;
            transition: background-color .2s ease, color .2s ease;
        }

        .archive-tabs .nav-link:hover {
            color: #ffffff;
            background-color: #10552d;
            border-color: #10552d;
        }

        .nav-pills .nav-link.active,
        .nav-pills .show > .nav-link,
        .archive-tabs .nav-link.active {
            background-color: #17683a !important;
            background: #17683a !important;
            color: #ffffff !important;
            border-color: #17683a !important;
        }

        .archive-tabs .nav-link .badge {
            color: #17683a;
            background-color: #eaf6ee;
        }

        .archive-tabs .nav-link.active .badge {
            color: #17683a;
            background-color: #ffffff;
        }

        .archive-panel {
            background: #fff;
            border: 1px solid #e1ebe4;
            border-radius: 13px;
            padding: 22px;
            box-shadow: 0 4px 15px rgba(24, 75, 42, .05);
        }

        .archive-table {
            min-width: 720px;
        }

        .archive-table th {
            background: #eaf6ee;
            color: #174b2b;
            white-space: nowrap;
        }

        .archive-table td {
            vertical-align: middle;
        }

        .btn-restore {
            background: #17683a;
            border: 2px solid #ffc107 !important; /* Yellow border as requested */
            color: #fff;
            border-radius: 6px;
            padding: 7px 15px;
        }

        .btn-restore:hover {
            background: #0b5425;
            border-color: #e0a800 !important;
            color: #fff;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: #718078;
        }

        .empty-state h5 {
            color: #26352b;
            font-weight: 700;
        }

        .debug-line,
        .error-detail {
            font-family: Consolas, monospace;
            font-size: 12px;
            word-break: break-word;
        }

        @media (max-width: 576px) {
            .archive-header {
                padding: 18px;
            }

            .archive-header h1 {
                font-size: 23px;
            }

            

            .archive-tabs {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
                gap: 8px !important;
            }

            .archive-tabs .nav-link {
                width: 100%;
                padding: 10px 6px;
                font-size: 13px;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="caretaker-archive-page">

    <!-- HEADER -->
    <div class="archive-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1>Archive &amp; Restore</h1>
            <p>
                Items you archive are hidden from your active lists. Restore them
                here whenever you need them back.
            </p>
        </div>

        <a href="<?= h($cfg['back']) ?>" class="btn btn-outline-success">
            Back to <?= h($cfg['back_label']) ?>
        </a>
    </div>

   

        

    <!-- CATEGORY TABS -->
    <ul class="nav nav-pills archive-tabs mb-3">
        <?php foreach ($categories as $key => $categoryConfig): ?>
            <li class="nav-item">
                <a
                    class="nav-link <?= $tab === $key ? 'active' : '' ?>"
                    href="archive.php?tab=<?= h($key) ?>"
                >
                    <?= h($categoryConfig['label']) ?>
                    <span class="badge text-bg-light"><?= (int)$counts[$key] ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= h($error) ?>
            <?php if (CT_ARCHIVE_DEBUG && $errorDetail !== ''): ?>
                <div class="error-detail mt-2"><?= h($errorDetail) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($tabNotice !== ''): ?>
        <div class="alert alert-warning"><?= h($tabNotice) ?></div>
    <?php endif; ?>

    <!-- ARCHIVE CONTENT -->
    <div class="archive-panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1">Archived <?= h($cfg['label']) ?></h2>
                <p class="text-secondary small mb-0">
                    <?= h($cfg['behavior']) ?>. Only your own records are shown here.
                </p>

            </div>

            <span class="badge text-bg-secondary"><?= count($rows) ?> record(s)</span>
        </div>

        <!-- SEARCH -->
        <form method="GET" class="row g-2 mb-4">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">

            <div class="col-md-9">
                <input
                    type="search"
                    name="search"
                    class="form-control"
                    placeholder="Search archived <?= h(strtolower($cfg['label'])) ?>..."
                    value="<?= h($search) ?>"
                >
            </div>

            <div class="col-md-3 d-grid">
                <button type="submit" class="btn btn-success">Search</button>
            </div>
        </form>

        <?php if (empty($rows)): ?>

            <div class="empty-state">
                <h5>No Archived <?= h($cfg['label']) ?></h5>
                <p class="mb-0">
                    <?= h(ucfirst($cfg['noun'])) ?>s you archive will appear here.
                </p>
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="table table-bordered table-hover archive-table">
                    <thead>
                        <tr>
                            <?php if ($isResidents): ?>
                                <th>Resident ID</th>
                                <th>Resident Name</th>
                                <th>Room Number</th>
                                <th>Care Level</th>
                            <?php elseif ($isMessages): ?>
                                <th>Subject</th>
                                <th>Message</th>
                                <th>Date</th>
                            <?php else: ?>
                                <th>Title</th>
                                <th>Resident</th>
                                <th>Details</th>
                                <th>Date</th>
                            <?php endif; ?>

                            <th>Date Archived</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $residentName = trim(
                                ($row['first_name'] ?? '') . ' ' .
                                ($row['middle_name'] ?? '') . ' ' .
                                ($row['last_name'] ?? '')
                            );
                            $residentName = preg_replace('/\s+/', ' ', $residentName);

                            $confirmText = 'Restore this ' . $cfg['noun'] . '?';
                            ?>
                            <tr>
                                <?php if ($isResidents): ?>
                                    <td><?= h($row['resident_code']) ?></td>
                                    <td><?= h($residentName) ?></td>
                                    <td><?= h($row['room_number'] ?: 'Not assigned') ?></td>
                                    <td><?= h($row['care_level'] ?: 'Not specified') ?></td>
                                <?php elseif ($isMessages): ?>
                                    <td><?= h($row['title'] ?: '(No subject)') ?></td>
                                    <td><?= h(ctSnippet($row['detail'])) ?></td>
                                    <td><?= h(ctDate($row['item_date'])) ?></td>
                                <?php else: ?>
                                    <td><?= h($row['title'] ?: '(Untitled)') ?></td>
                                    <td>
                                        <?= h($residentName !== '' ? $residentName : 'Not specified') ?>
                                        <?php if (!empty($row['resident_code'])): ?>
                                            <span class="text-secondary small">(<?= h($row['resident_code']) ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h(ctSnippet($row['detail'])) ?></td>
                                    <td><?= h(ctDate($row['item_date'], 'M j, Y')) ?></td>
                                <?php endif; ?>

                                <td><?= h(ctDate($row['archived_at'])) ?></td>

                                <td>
                                    <form
                                        method="POST"
                                        action="archive.php?tab=<?= h($tab) ?>"
                                        onsubmit="return confirm(<?= h(json_encode($confirmText, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="category" value="<?= h($tab) ?>">
                                        <input type="hidden" name="record_id" value="<?= (int)$row['rid'] ?>">

                                        <button type="submit" class="btn btn-restore btn-sm" style="border: 2px solid #ffc107 !important;">
                                            Restore
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>
</div>

<!-- sidebar.php already opened <main class="app-main">, close it here -->
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>