<?php
/**
 * HOME FOR THE AGED
 * ADMIN ARCHIVE, RESTORE AND PERMANENT DELETE
 * File: admin/archive.php
 */
include_once __DIR__ . '/../includes/session.php';

session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    strtolower((string)$_SESSION['role']) !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/archive_helper.php';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['admin_archive_csrf'])) {
    $_SESSION['admin_archive_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['admin_archive_csrf'];
$tab = (($_GET['tab'] ?? 'residents') === 'caretakers') ? 'caretakers' : 'residents';
$search = trim((string)($_GET['search'] ?? ''));
$success = '';
$error = '';
$rows = [];
$counts = ['residents' => 0, 'caretakers' => 0];
$hasArchivedAt = false;

try {
    $columns = $pdo->query("SHOW COLUMNS FROM residents")->fetchAll(PDO::FETCH_COLUMN);
    $hasArchivedAt = in_array('archived_at', $columns, true);
    archiveEnsureCaretakerTable($pdo);
} catch (Throwable $e) {
    error_log('Admin archive setup: ' . $e->getMessage());
    $error = 'Unable to check the database tables.';
}

$residentArchived = $hasArchivedAt
    ? "(r.status = 'archived' OR r.archived_at IS NOT NULL)"
    : "r.status = 'archived'";

/* RESTORE OR PERMANENTLY DELETE */
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page.');
    }

    $action = ($_POST['action'] ?? '') === 'purge' ? 'purge' : 'restore';
    $type = ($_POST['type'] ?? '') === 'caretaker' ? 'caretaker' : 'resident';
    $recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
    $postTab = $type === 'caretaker' ? 'caretakers' : 'residents';

    if (!$recordId || $recordId < 1) {
        $error = 'Invalid record selected.';
    } else {
        try {

            /* RESTORE RESIDENT */
            if ($action === 'restore' && $type === 'resident') {

                $set = $hasArchivedAt
                    ? "r.status = 'active', r.archived_at = NULL"
                    : "r.status = 'active'";

                $stmt = $pdo->prepare("
                    UPDATE residents r
                    SET $set
                    WHERE r.id = :id
                      AND $residentArchived
                ");

                $stmt->execute(['id' => $recordId]);

                if ($stmt->rowCount() > 0) {
                    header('Location: archive.php?tab=residents&restored=1');
                    exit;
                }

                $error = 'Record not found or not archived.';
            }

            /* RESTORE CARETAKER */
            if ($action === 'restore' && $type === 'caretaker') {

                $pdo->beginTransaction();

                $find = $pdo->prepare("
                    SELECT username, status
                    FROM caretakers
                    WHERE id = :id AND archived_at IS NOT NULL
                    LIMIT 1
                ");

                $find->execute(['id' => $recordId]);
                $caretaker = $find->fetch(PDO::FETCH_ASSOC);

                if (!$caretaker) {
                    $pdo->rollBack();
                    $error = 'Record not found or not archived.';
                } else {

                    $pdo->prepare("
                        UPDATE caretakers
                        SET archived_at = NULL
                        WHERE id = :id
                    ")->execute(['id' => $recordId]);

                    /* Login follows caretaker employment status. */
                    $pdo->prepare("
                        UPDATE users
                        SET status = :status
                        WHERE username = :username
                          AND role = 'caretaker'
                    ")->execute([
                        'status' => (
                            strtolower(trim((string)$caretaker['status'])) === 'active'
                        ) ? 'active' : 'inactive',
                        'username' => $caretaker['username']
                    ]);

                    $pdo->commit();

                    header('Location: archive.php?tab=caretakers&restored=1');
                    exit;
                }
            }

            /* PERMANENTLY DELETE RESIDENT */
            if ($action === 'purge' && $type === 'resident') {

                $pdo->beginTransaction();

                $find = $pdo->prepare("
                    SELECT r.id, r.profile_picture
                    FROM residents r
                    WHERE r.id = :id
                      AND $residentArchived
                    LIMIT 1
                    FOR UPDATE
                ");

                $find->execute(['id' => $recordId]);
                $resident = $find->fetch(PDO::FETCH_ASSOC);

                if (!$resident) {
                    $pdo->rollBack();
                    $error = 'Only archived residents can be permanently deleted.';
                } else {

                    /* Remove related records. */
                    foreach ([
                        'health_records' => 'resident_id',
                        'schedules' => 'resident_id',
                        'caretaker_resident_archive' => 'resident_id'
                    ] as $table => $column) {

                        $pdo->prepare("
                            DELETE FROM `$table`
                            WHERE `$column` = :id
                        ")->execute(['id' => $recordId]);
                    }

                    $pdo->prepare("
                        DELETE FROM residents
                        WHERE id = :id
                    ")->execute(['id' => $recordId]);

                    /* Remove picture only when no other resident uses it. */
                    $pictureFile = '';
                    $picture = basename(
                        trim((string)($resident['profile_picture'] ?? ''))
                    );

                    if ($picture !== '') {

                        $inUse = $pdo->prepare("
                            SELECT COUNT(*)
                            FROM residents
                            WHERE profile_picture = :p
                        ");

                        $inUse->execute([
                            'p' => $resident['profile_picture']
                        ]);

                        if ((int)$inUse->fetchColumn() === 0) {
                            $pictureFile = __DIR__ .
                                '/uploads/residentspic/' . $picture;
                        }
                    }

                    $pdo->commit();

                    if ($pictureFile !== '' && is_file($pictureFile)) {
                        @unlink($pictureFile);
                    }

                    header('Location: archive.php?tab=residents&deleted=1');
                    exit;
                }
            }

            /* PERMANENTLY DELETE CARETAKER */
            if ($action === 'purge' && $type === 'caretaker') {

                $pdo->beginTransaction();

                $find = $pdo->prepare("
                    SELECT id, username
                    FROM caretakers
                    WHERE id = :id
                      AND archived_at IS NOT NULL
                    LIMIT 1
                    FOR UPDATE
                ");

                $find->execute(['id' => $recordId]);
                $caretaker = $find->fetch(PDO::FETCH_ASSOC);

                if (!$caretaker) {
                    $pdo->rollBack();
                    $error = 'Only archived caretakers can be permanently deleted.';
                } else {

                    $userFind = $pdo->prepare("
                        SELECT id
                        FROM users
                        WHERE username = :username
                          AND role = 'caretaker'
                        LIMIT 1
                    ");

                    $userFind->execute([
                        'username' => $caretaker['username']
                    ]);

                    $userId = (int)$userFind->fetchColumn();

                    if ($userId > 0) {

                        /* Residents remain but become unassigned. */
                        $pdo->prepare("
                            UPDATE residents
                            SET assigned_caretaker = NULL
                            WHERE assigned_caretaker = :uid
                        ")->execute(['uid' => $userId]);

                        $pdo->prepare("
                            DELETE FROM caretaker_resident_archive
                            WHERE caretaker_user_id = :uid
                        ")->execute(['uid' => $userId]);

                        try {
                            $pdo->prepare("
                                DELETE FROM password_resets
                                WHERE user_id = :uid
                            ")->execute(['uid' => $userId]);
                        } catch (Throwable $ignored) {
                            /* Password reset table may not exist. */
                        }

                        $pdo->prepare("
                            DELETE FROM users
                            WHERE id = :uid
                              AND role = 'caretaker'
                        ")->execute(['uid' => $userId]);
                    }

                    $pdo->prepare("
                        DELETE FROM caretakers
                        WHERE id = :id
                    ")->execute(['id' => $recordId]);

                    $pdo->commit();

                    header('Location: archive.php?tab=caretakers&deleted=1');
                    exit;
                }
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Admin archive action error: ' . $e->getMessage());

            $error = $action === 'purge'
                ? 'Unable to permanently delete this record. Other records may still depend on it.'
                : 'Unable to restore the selected record.';
        }
    }

    $tab = $postTab;
}

/* SUCCESS MESSAGES */
if (isset($_GET['restored'])) {
    $success = $tab === 'caretakers'
        ? 'Caretaker restored to the Caretakers list and their login status updated.'
        : 'Resident restored. Their status is now Active.';

} elseif (isset($_GET['deleted'])) {
    $success = $tab === 'caretakers'
        ? 'Caretaker permanently deleted. Their residents are now unassigned.'
        : 'Resident permanently deleted, including their health records and schedules.';
}

/* TAB COUNTS */
if (!$error || $_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        $counts['residents'] = (int)$pdo
            ->query("SELECT COUNT(*) FROM residents r WHERE $residentArchived")
            ->fetchColumn();
    } catch (Throwable $e) {
        error_log('Admin archive resident count: ' . $e->getMessage());
    }

    try {
        $counts['caretakers'] = (int)$pdo
            ->query("
                SELECT COUNT(*)
                FROM caretakers
                WHERE archived_at IS NOT NULL
            ")
            ->fetchColumn();
    } catch (Throwable $e) {
        error_log('Admin archive caretaker count: ' . $e->getMessage());
    }
}

/* LOAD ARCHIVED RECORDS */
try {

    $params = [];

    if ($tab === 'caretakers') {

        $sql = "
            SELECT id, employee_id, full_name, contact, position, shift,
                   status, archived_at
            FROM caretakers
            WHERE archived_at IS NOT NULL
        ";

        if ($search !== '') {

            $sql .= "
                AND (
                    employee_id LIKE :s1
                    OR full_name LIKE :s2
                    OR contact LIKE :s3
                    OR position LIKE :s4
                )
            ";

            $term = '%' . $search . '%';

            foreach (['s1', 's2', 's3', 's4'] as $key) {
                $params[$key] = $term;
            }
        }

        $sql .= " ORDER BY archived_at DESC LIMIT 300";

    } else {

        $dateColumn = $hasArchivedAt ? 'r.archived_at' : 'NULL';

        $sql = "
            SELECT
                r.id,
                r.resident_id,
                r.first_name,
                r.middle_name,
                r.last_name,
                r.room_number,
                r.care_level,
                $dateColumn AS archived_at,
                u.full_name AS caretaker_name
            FROM residents r
            LEFT JOIN users u
                ON u.id = r.assigned_caretaker
            WHERE $residentArchived
        ";

        if ($search !== '') {

            $sql .= "
                AND (
                    r.first_name LIKE :s1
                    OR r.middle_name LIKE :s2
                    OR r.last_name LIKE :s3
                    OR r.resident_id LIKE :s4
                    OR r.room_number LIKE :s5
                    OR u.full_name LIKE :s6
                )
            ";

            $term = '%' . $search . '%';

            foreach (['s1', 's2', 's3', 's4', 's5', 's6'] as $key) {
                $params[$key] = $term;
            }
        }

        $sql .= " ORDER BY r.id DESC LIMIT 300";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Admin archive load error: ' . $e->getMessage());
    $error = $error ?: 'Unable to load archived records.';
}

$isCaretakers = $tab === 'caretakers';
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

    <!-- PLACE YOUR CUSTOM OVERRIDE STYLE RIGHT AFTER BOOTSTRAP -->
    <style>
        * {
            box-sizing: border-box;
        }

        .admin-archive-page {
            width: 100%;
            max-width: 1600px;
            min-width: 0;
            margin: 0 auto;
            padding: 20px;
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

        /* FORCEFULLY OVERRIDE BOOTSTRAP BLUE PILLS */
        .nav-pills .nav-link.active, 
        .nav-pills .show > .nav-link,
        .archive-tabs .nav-link.active {
            background-color: #17683a !important;
            background: #17683a !important;
            color: #ffffff !important;
            border-color: #17683a !important;
        }

        .archive-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            border-bottom: 1px solid #d8eadc;
            padding: 0 0 12px;
        }

        .archive-tabs .nav-link {
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

        /* NUMBER BADGES */
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
            box-shadow: 0 4px 15px rgba(24,75,42,.05);
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
            border: 1px solid #17683a;
            color: #fff;
            border-radius: 6px;
            padding: 7px 15px;
        }

        .btn-restore:hover {
            background: #0b5425;
            border-color: #0b5425;
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

        @media (max-width: 576px) {
            .admin-archive-page {
                padding: 12px;
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
                justify-content: center;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="admin-archive-page">

    <!-- HEADER -->
    <div class="archive-header d-flex justify-content-between align-items-center flex-wrap gap-3">

        <div>
            <h1>Archive &amp; Restore</h1>

            <p>
                Restore archived residents and caretakers, or delete them
                permanently. Only archived records can be permanently deleted.
            </p>
        </div>

        <a
            href="<?= $isCaretakers ? 'caretakers.php' : 'residents.php' ?>"
            class="btn btn-outline-success"
        >
            Back to <?= $isCaretakers ? 'Caretakers' : 'Residents' ?>
        </a>

    </div>

    <!-- RESIDENTS AND CARETAKERS TABS -->
    <ul class="nav nav-pills archive-tabs mb-3">

        <li class="nav-item">
            <a
                class="nav-link <?= !$isCaretakers ? 'active' : '' ?>"
                href="archive.php?tab=residents"
            >
                Residents
                <span class="badge text-bg-light">
                    <?= (int)$counts['residents'] ?>
                </span>
            </a>
        </li>

        <li class="nav-item">
            <a
                class="nav-link <?= $isCaretakers ? 'active' : '' ?>"
                href="archive.php?tab=caretakers"
            >
                Caretakers
                <span class="badge text-bg-light">
                    <?= (int)$counts['caretakers'] ?>
                </span>
            </a>
        </li>

    </ul>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= h($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <!-- ARCHIVE CONTENT -->
    <div class="archive-panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

            <div>
                <h2 class="h5 fw-bold mb-1">
                    Archived <?= $isCaretakers ? 'Caretakers' : 'Residents' ?>
                </h2>

                <p class="text-secondary small mb-0">

                    <?php if ($isCaretakers): ?>

                        Caretakers archived from the Caretakers page. Their login
                        stays disabled until you restore them.

                    <?php else: ?>

                        Residents archived by an administrator. Caretakers'
                        personal archives are not shown here.

                    <?php endif; ?>

                </p>
            </div>

            <span class="badge text-bg-secondary">
                <?= count($rows) ?> record(s)
            </span>

        </div>

        <!-- SEARCH -->
        <form method="GET" class="row g-2 mb-4">

            <input
                type="hidden"
                name="tab"
                value="<?= h($tab) ?>"
            >

            <div class="col-md-9">

                <input
                    type="search"
                    name="search"
                    class="form-control"
                    placeholder="<?= $isCaretakers
                        ? 'Search name, employee ID, contact, or position...'
                        : 'Search name, ID, room, or caretaker...' ?>"
                    value="<?= h($search) ?>"
                >

            </div>

            <div class="col-md-3 d-grid">

                <button type="submit" class="btn btn-success">
                    Search
                </button>

            </div>

        </form>

        <?php if (empty($rows)): ?>

            <div class="empty-state">

                <h5>
                    No Archived <?= $isCaretakers ? 'Caretakers' : 'Residents' ?>
                </h5>

                <p class="mb-0">
                    <?= $isCaretakers
                        ? 'Caretakers you archive from the Caretakers page will appear here.'
                        : 'Residents you archive from the Residents page will appear here.' ?>
                </p>

            </div>

        <?php else: ?>

            <div class="table-responsive">

                <table class="table table-bordered table-hover archive-table">

                    <thead>
                        <tr>

                            <?php if ($isCaretakers): ?>

                                <th>Employee ID</th>
                                <th>Full Name</th>
                                <th>Contact</th>
                                <th>Position</th>
                                <th>Shift</th>
                                <th>Status</th>

                            <?php else: ?>

                                <th>Resident ID</th>
                                <th>Resident Name</th>
                                <th>Room Number</th>
                                <th>Care Level</th>
                                <th>Assigned Caretaker</th>

                            <?php endif; ?>

                            <th>Date Archived</th>
                            <th>Action</th>

                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($rows as $row): ?>

                            <?php

                            if ($isCaretakers) {
                                $label = (string)($row['full_name'] ?? 'this caretaker');

                                $warning =
                                    'Their login is deleted and the residents assigned to them become unassigned.';

                            } else {

                                $label = trim(implode(' ', array_filter([
                                    $row['first_name'] ?? '',
                                    $row['middle_name'] ?? '',
                                    $row['last_name'] ?? ''
                                ])));

                                $warning =
                                    'Their health records and schedules are deleted too.';
                            }

                            ?>

                            <tr>

                                <?php if ($isCaretakers): ?>

                                    <td><?= h($row['employee_id']) ?></td>
                                    <td><?= h($row['full_name']) ?></td>
                                    <td><?= h($row['contact'] ?: 'N/A') ?></td>
                                    <td><?= h($row['position'] ?: 'N/A') ?></td>
                                    <td><?= h($row['shift'] ?: 'N/A') ?></td>
                                    <td><?= h($row['status'] ?: 'Unspecified') ?></td>

                                <?php else: ?>

                                    <td><?= h($row['resident_id']) ?></td>
                                    <td><?= h($label) ?></td>
                                    <td><?= h($row['room_number'] ?: 'Not assigned') ?></td>
                                    <td><?= h($row['care_level'] ?: 'Not specified') ?></td>
                                    <td><?= h($row['caretaker_name'] ?: 'Unassigned') ?></td>

                                <?php endif; ?>

                                <td>
                                    <?= !empty($row['archived_at'])
                                        ? h(date('M j, Y g:i A', strtotime($row['archived_at'])))
                                        : '&mdash;' ?>
                                </td>

                                <td>

                                    <div class="d-flex gap-2 flex-wrap">

                                        <!-- RESTORE -->
                                        <form
                                            method="POST"
                                            action="archive.php?tab=<?= h($tab) ?>"
                                            onsubmit="return confirm('<?= $isCaretakers
                                                ? 'Restore this caretaker and re-enable their login?'
                                                : 'Restore this resident? Their status will be set to Active.' ?>');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= h($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="restore"
                                            >

                                            <input
                                                type="hidden"
                                                name="type"
                                                value="<?= $isCaretakers ? 'caretaker' : 'resident' ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="record_id"
                                                value="<?= (int)$row['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-restore btn-sm"
                                            >
                                                Restore
                                            </button>

                                        </form>

                                        <!-- PERMANENT DELETE -->
                                        <form
                                            method="POST"
                                            action="archive.php?tab=<?= h($tab) ?>"
                                            onsubmit="return confirmPermanentDelete(
                                                <?= h(json_encode($label, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>,
                                                <?= h(json_encode($warning, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>
                                            );"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= h($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="purge"
                                            >

                                            <input
                                                type="hidden"
                                                name="type"
                                                value="<?= $isCaretakers ? 'caretaker' : 'resident' ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="record_id"
                                                value="<?= (int)$row['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-outline-danger btn-sm"
                                            >
                                                Delete Permanently
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

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function confirmPermanentDelete(name, warning) {
        return confirm('PERMANENT DELETE: Are you sure you want to completely delete ' + name + '?\n\n' + warning + '\n\nThis action CANNOT be undone.');
    }
</script>

</body>
</html>