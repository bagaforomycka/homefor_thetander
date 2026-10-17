
<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    http_response_code(403);
    exit('Access denied. Only administrators can manage the archive.');
}

require_once __DIR__ . '/../config/db.php';

if (empty($_SESSION['archive_csrf'])) {
    $_SESSION['archive_csrf'] = bin2hex(random_bytes(32));
}

/*
 * Tables managed by the Archive page.
 * Reports and Messages are included in the selector, but their
 * database tables must exist and contain archived_at to be managed.
 */
$modules = [
    'residents' => [
        'label' => 'Residents',
        'table' => 'residents',
        'description' => 'Resident information'
    ],
    'health_records' => [
        'label' => 'Health Records',
        'table' => 'health_records',
        'description' => 'Residents’ medical information'
    ],
    'rooms' => [
        'label' => 'Rooms',
        'table' => 'rooms',
        'description' => 'Room information'
    ],
    'staff' => [
        'label' => 'Staff',
        'table' => 'staff',
        'description' => 'Staff records'
    ],
    'caretakers' => [
        'label' => 'Caretakers',
        'table' => 'caretakers',
        'description' => 'Caretaker records'
    ],
    'schedules' => [
        'label' => 'Schedules',
        'table' => 'schedules',
        'description' => 'Scheduled activities'
    ],
    'scheduled_tasks' => [
        'label' => 'Scheduled Tasks',
        'table' => 'scheduled_tasks',
        'description' => 'Scheduled care tasks'
    ],
    'reports' => [
        'label' => 'Reports',
        'table' => 'reports',
        'description' => 'Report records'
    ],
    'messages' => [
        'label' => 'Messages',
        'table' => 'messages',
        'description' => 'Message records'
    ],
];

$selectedModule = $_GET['module'] ?? 'residents';

if (!isset($modules[$selectedModule])) {
    $selectedModule = 'residents';
}

$notice = '';
$error = '';
$archivedRecords = [];
$tableExists = false;
$hasArchiveColumn = false;
$columns = [];
$displayColumns = [];

function archiveEscape($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_scalar($value)) {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }

    return '[Data]';
}

/*
 * Check table existence without trusting user-supplied table names.
 */
function archiveTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
    ");

    $stmt->execute(['table_name' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function archiveGetColumns(PDO $pdo, string $table): array
{
    $allowedTables = [
        'residents',
        'health_records',
        'rooms',
        'staff',
        'caretakers',
        'schedules',
        'scheduled_tasks',
        'reports',
        'messages'
    ];

    if (!in_array($table, $allowedTables, true)) {
        return [];
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/*
 * Handle restore and permanent-delete actions.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($_SESSION['archive_csrf'], $postedToken)
    ) {
        $error = 'Security check failed. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $moduleKey = $_POST['module'] ?? '';
        $recordId = filter_var(
            $_POST['record_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            !isset($modules[$moduleKey]) ||
            !$recordId ||
            !in_array(
                $action,
                ['restore', 'permanent_delete'],
                true
            )
        ) {
            $error = 'Invalid archive action or record.';
        } else {
            $table = $modules[$moduleKey]['table'];

            try {
                if (!archiveTableExists($pdo, $table)) {
                    throw new RuntimeException(
                        'The database table "' . $table .
                        '" does not exist.'
                    );
                }

                $tableColumns = archiveGetColumns($pdo, $table);

                if (!in_array('archived_at', $tableColumns, true)) {
                    throw new RuntimeException(
                        'The "' . $table .
                        '" table does not have an archived_at column.'
                    );
                }

                if ($action === 'restore') {
                    $stmt = $pdo->prepare(
                        "UPDATE `$table`
                         SET archived_at = NULL
                         WHERE id = :id
                           AND archived_at IS NOT NULL"
                    );

                    if (!in_array('id', $tableColumns, true)) {
                        throw new RuntimeException(
                            'This table does not have an id column.'
                        );
                    }

                    $stmt->execute(['id' => $recordId]);

                    if ($stmt->rowCount() > 0) {
                        header(
                            'Location: archive.php?module=' .
                            urlencode($moduleKey) .
                            '&success=restored'
                        );
                        exit;
                    }

                    $error =
                        'The archived record was not found, or it was already restored.';
                }

                if ($action === 'permanent_delete') {
                    if (!in_array('id', $tableColumns, true)) {
                        throw new RuntimeException(
                            'This table does not have an id column.'
                        );
                    }

                    $stmt = $pdo->prepare(
                        "DELETE FROM `$table`
                         WHERE id = :id
                           AND archived_at IS NOT NULL"
                    );

                    $stmt->execute(['id' => $recordId]);

                    if ($stmt->rowCount() > 0) {
                        header(
                            'Location: archive.php?module=' .
                            urlencode($moduleKey) .
                            '&success=deleted'
                        );
                        exit;
                    }

                    $error =
                        'The archived record was not found, or it was already deleted.';
                }
            } catch (Throwable $e) {
                error_log('Archive action error: ' . $e->getMessage());

                $error = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'The action could not be completed. The record may be linked to other data.';
            }
        }
    }
}

/*
 * Check the selected table and retrieve its archived records.
 */
$table = $modules[$selectedModule]['table'];

try {
    $tableExists = archiveTableExists($pdo, $table);

    if ($tableExists) {
        $columns = archiveGetColumns($pdo, $table);
        $hasArchiveColumn = in_array(
            'archived_at',
            $columns,
            true
        );

        if ($hasArchiveColumn) {
            if (!in_array('id', $columns, true)) {
                $error = 'This table has no id column, so its records cannot be managed here.';
            } else {
                $search = trim($_GET['search'] ?? '');

                $stmt = $pdo->query(
                    "SELECT *
                     FROM `$table`
                     WHERE archived_at IS NOT NULL
                     ORDER BY archived_at DESC, id DESC
                     LIMIT 500"
                );

                $archivedRecords = $stmt->fetchAll();

                /*
                 * Hide sensitive fields such as password hashes.
                 */
                $hiddenColumns = [
                    'password',
                    'password_hash',
                    'token',
                    'reset_token',
                    'remember_token',
                    'verification_token'
                ];

                foreach ($columns as $column) {
                    if (
                        !in_array(
                            strtolower($column),
                            $hiddenColumns,
                            true
                        )
                    ) {
                        $displayColumns[] = $column;
                    }
                }

                $displayColumns = array_slice(
                    $displayColumns,
                    0,
                    6
                );

                /*
                 * Search the archived records in the displayed results.
                 */
                if ($search !== '') {
                    $archivedRecords = array_values(
                        array_filter(
                            $archivedRecords,
                            function ($record) use (
                                $search,
                                $displayColumns
                            ) {
                                foreach ($displayColumns as $column) {
                                    if (
                                        isset($record[$column]) &&
                                        stripos(
                                            (string) $record[$column],
                                            $search
                                        ) !== false
                                    ) {
                                        return true;
                                    }
                                }

                                return false;
                            }
                        )
                    );
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('Archive loading error: ' . $e->getMessage());
    $error = 'Unable to load archived records. Check the database configuration.';
}

$success = $_GET['success'] ?? '';

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
    .archive-header {
        background: #e7f5ec;
        border: 1px solid #d5eadc;
        border-radius: 14px;
        padding: 24px;
        margin-bottom: 22px;
    }

    .archive-header h1 {
        color: #17683a;
        font-size: 28px;
        font-weight: 700;
        margin: 0 0 6px;
    }

    .archive-header p {
        color: #61796a;
        margin: 0;
    }

    .archive-panel {
        background: #fff;
        border: 1px solid #e1ebe4;
        border-radius: 14px;
        padding: 22px;
        margin-bottom: 20px;
        box-shadow: 0 4px 16px rgba(24, 75, 42, .05);
    }

    .archive-module-card {
        display: block;
        text-decoration: none;
        color: #244633;
        background: #fff;
        border: 1px solid #dce9df;
        border-radius: 12px;
        padding: 16px;
        height: 100%;
        transition: .2s;
    }

    .archive-module-card:hover,
    .archive-module-card.selected {
        border-color: #198754;
        background: #f0f9f2;
        color: #17683a;
    }

    .archive-module-card strong {
        display: block;
        margin-bottom: 5px;
    }

    .archive-module-card small {
        color: #6b7c70;
    }

    .archive-table-wrap {
        overflow-x: auto;
    }

    .archive-table th {
        background: #f0f7f2;
        color: #244633;
        white-space: nowrap;
    }

    .archive-table td {
        vertical-align: middle;
        max-width: 260px;
        overflow-wrap: anywhere;
    }

    .archive-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        min-width: 190px;
    }

    .archive-actions .btn {
        font-size: 13px;
    }

    .archive-empty {
        background: #f7faf8;
        border: 1px dashed #cbded0;
        border-radius: 12px;
        padding: 32px 18px;
        text-align: center;
        color: #61796a;
    }

    .archive-label {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #6b7c70;
        font-weight: 700;
    }

    @media (max-width: 576px) {
        .archive-header,
        .archive-panel {
            padding: 16px;
        }

        .archive-header h1 {
            font-size: 24px;
        }
    }
</style>

<div class="archive-header d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <h1>Archive &amp; Restore</h1>
        <p>Find, restore, and manage archived information.</p>
    </div>

    <a href="dashboard.php" class="btn btn-outline-success">
        ← Back to Dashboard
    </a>
</div>

<?php if ($success === 'restored'): ?>
    <div class="alert alert-success">
        Record restored successfully.
    </div>
<?php elseif ($success === 'deleted'): ?>
    <div class="alert alert-success">
        Archived record permanently deleted.
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger">
        <?= archiveEscape($error) ?>
    </div>
<?php endif; ?>

<div class="archive-panel">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Choose a Module</h2>
            <p class="text-muted small mb-0">
                Select a module to view its archived records.
            </p>
        </div>
    </div>

    <div class="row g-3">
        <?php foreach ($modules as $key => $module): ?>
            <div class="col-12 col-sm-6 col-lg-4">
                <a
                    class="archive-module-card <?= $selectedModule === $key ? 'selected' : '' ?>"
                    href="archive.php?module=<?= urlencode($key) ?>"
                >
                    <strong>
                        <span aria-hidden="true">🗃</span>
                        <?= archiveEscape($module['label']) ?>
                    </strong>
                    <small>
                        <?= archiveEscape($module['description']) ?>
                    </small>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="archive-panel">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
        <div>
            <div class="archive-label">Selected Module</div>
            <h2 class="h4 mb-1">
                <?= archiveEscape($modules[$selectedModule]['label']) ?>
            </h2>
            <p class="text-muted small mb-0">
                Showing archived records only.
            </p>
        </div>

        <?php if ($tableExists && $hasArchiveColumn): ?>
            <form method="get" action="archive.php" class="d-flex flex-wrap gap-2">
                <input
                    type="hidden"
                    name="module"
                    value="<?= archiveEscape($selectedModule) ?>"
                >
                <input
                    type="search"
                    name="search"
                    class="form-control"
                    placeholder="Search archived records"
                    value="<?= archiveEscape($_GET['search'] ?? '') ?>"
                    aria-label="Search archived records"
                >
                <button type="submit" class="btn btn-success">
                    Search
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!$tableExists): ?>

        <div class="alert alert-warning mb-0">
            <strong>Not configured yet.</strong>
            The database table
            <code><?= archiveEscape($table) ?></code>
            does not exist. This module is listed here, but it cannot
            archive or restore records until its data storage is configured.
        </div>

    <?php elseif (!$hasArchiveColumn): ?>

        <div class="alert alert-warning mb-0">
            <strong>Archive column missing.</strong>
            The table
            <code><?= archiveEscape($table) ?></code>
            does not have an <code>archived_at</code> column.
            Add that column before using archive actions for this module.
        </div>

    <?php elseif (!$archivedRecords): ?>

        <div class="archive-empty">
            <div class="mb-2" style="font-size: 32px;">🗃</div>
            <h3 class="h5">No archived records found</h3>
            <p class="mb-0">
                Archived records from this module will appear here.
            </p>
        </div>

    <?php else: ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="text-muted small">
                <?= count($archivedRecords) ?> archived record(s) found
                (up to 500 loaded)
            </span>
        </div>

        <div class="archive-table-wrap">
            <table class="table table-bordered table-hover archive-table">
                <thead>
                    <tr>
                        <?php foreach ($displayColumns as $column): ?>
                            <th><?= archiveEscape(ucwords(str_replace('_', ' ', $column))) ?></th>
                        <?php endforeach; ?>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($archivedRecords as $record): ?>
                        <tr>
                            <?php foreach ($displayColumns as $column): ?>
                                <td>
                                    <?php
                                    $value = $record[$column] ?? '';

                                    if (
                                        $column === 'archived_at' &&
                                        $value !== ''
                                    ) {
                                        $timestamp = strtotime((string) $value);
                                        echo $timestamp
                                            ? archiveEscape(date('M j, Y g:i A', $timestamp))
                                            : archiveEscape($value);
                                    } else {
                                        echo archiveEscape($value);
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>

                            <td>
                                <div class="archive-actions">
                                    <form
                                        method="post"
                                        action="archive.php?module=<?= urlencode($selectedModule) ?>"
                                        onsubmit="return confirm('Restore this record to the active module?');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= archiveEscape($_SESSION['archive_csrf']) ?>">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="module" value="<?= archiveEscape($selectedModule) ?>">
                                        <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">

                                        <button type="submit" class="btn btn-outline-success btn-sm">
                                            Restore
                                        </button>
                                    </form>

                                    <form
                                        method="post"
                                        action="archive.php?module=<?= urlencode($selectedModule) ?>"
                                        onsubmit="return confirm('PERMANENTLY DELETE this archived record? This cannot be undone. Continue?');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= archiveEscape($_SESSION['archive_csrf']) ?>">
                                        <input type="hidden" name="action" value="permanent_delete">
                                        <input type="hidden" name="module" value="<?= archiveEscape($selectedModule) ?>">
                                        <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">

                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            Permanently Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
</div>

</main>
</body>
</html>
