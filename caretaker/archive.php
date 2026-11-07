<?php
/**
 * HOME FOR THE AGED
 * CARETAKER ARCHIVE, RESTORE AND REMOVE
 * File: caretaker/archive.php
 *
 * A caretaker only ever sees and changes their own archive.
 *   Archive (My Residents)    -> hidden from My Residents, listed here
 *   Restore                   -> back in My Residents
 *   Remove permanently        -> gone from this caretaker's account for good
 *                                (the resident record stays with the admin)
 * Residents an admin archives or deletes disappear from this account.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================================
   SECURITY: CARETAKER ACCESS ONLY
========================================= */

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    strtolower((string)$_SESSION['role']) !== 'caretaker'
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/archive_helper.php';

$caretakerId = (int) $_SESSION['user_id'];

/* =========================================
   HELPER FUNCTION
========================================= */

function archiveEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* =========================================
   PRIVATE ARCHIVE TABLE
========================================= */

$error = '';
$success = '';

try {
    archiveEnsureCaretakerTable($pdo);
    $residentVisible = archiveResidentVisibleSql($pdo, 'r');
} catch (Throwable $e) {
    error_log('Caretaker archive setup error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to prepare the archive. Please contact the administrator.');
}

/* =========================================
   CSRF SECURITY TOKEN
========================================= */

if (empty($_SESSION['caretaker_archive_csrf'])) {
    $_SESSION['caretaker_archive_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['caretaker_archive_csrf'];

/* =========================================
   RESTORE OR REMOVE (own records only)
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page.');
    }

    $action = ($_POST['action'] ?? '') === 'remove' ? 'remove' : 'restore';

    $archiveId = filter_var(
        $_POST['archive_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$archiveId || $archiveId < 1) {

        $error = 'Invalid archived record.';

    } else {

        try {

            if ($action === 'restore') {

                /*
                 * Restore only if the entry is in this caretaker's own
                 * archive, the resident is still assigned to them and the
                 * admin has not archived or deleted the resident.
                 */
                $stmt = $pdo->prepare("
                    DELETE ca
                    FROM caretaker_resident_archive ca
                    INNER JOIN residents r
                        ON r.id = ca.resident_id
                    WHERE ca.id = :archive_id
                      AND ca.caretaker_user_id = :caretaker_id
                      AND ca.removed_at IS NULL
                      AND r.assigned_caretaker = :assigned_caretaker
                      AND $residentVisible
                ");

                $stmt->execute([
                    'archive_id' => $archiveId,
                    'caretaker_id' => $caretakerId,
                    'assigned_caretaker' => $caretakerId
                ]);

                if ($stmt->rowCount() > 0) {
                    header('Location: archive.php?restored=1');
                    exit;
                }

                $error = 'You cannot restore this record because it is not in your personal archive or is no longer assigned to you.';

            } else {

                /*
                 * Permanent removal: only from this caretaker's own
                 * archive, and only after the resident was archived.
                 */
                $stmt = $pdo->prepare("
                    UPDATE caretaker_resident_archive
                    SET removed_at = NOW()
                    WHERE id = :archive_id
                      AND caretaker_user_id = :caretaker_id
                      AND removed_at IS NULL
                ");

                $stmt->execute([
                    'archive_id' => $archiveId,
                    'caretaker_id' => $caretakerId
                ]);

                if ($stmt->rowCount() > 0) {
                    header('Location: archive.php?removed=1');
                    exit;
                }

                $error = 'You cannot remove this record because it is not in your personal archive.';
            }

        } catch (Throwable $e) {

            error_log('Caretaker archive action error: ' . $e->getMessage());

            $error = 'An error occurred. Please try again.';
        }
    }
}

/* =========================================
   SUCCESS MESSAGE
========================================= */

if (isset($_GET['restored'])) {
    $success = 'Resident successfully restored to your My Residents list.';
} elseif (isset($_GET['removed'])) {
    $success = 'Resident permanently removed from your account.';
}

/* =========================================
   SEARCH ARCHIVED RESIDENTS (own archive only)
========================================= */

$search = trim((string)($_GET['search'] ?? ''));

$archivedResidents = [];

try {

    $sql = "
        SELECT
            ca.id AS archive_id,
            ca.archived_at,
            r.id AS resident_record_id,
            r.resident_id,
            r.first_name,
            r.middle_name,
            r.last_name,
            r.room_number,
            r.care_level,
            r.status
        FROM caretaker_resident_archive ca
        INNER JOIN residents r
            ON r.id = ca.resident_id
        WHERE ca.caretaker_user_id = :caretaker_id
          AND ca.removed_at IS NULL
          AND r.assigned_caretaker = :assigned_caretaker
          AND $residentVisible
    ";

    $params = [
        'caretaker_id' => $caretakerId,
        'assigned_caretaker' => $caretakerId
    ];

    if ($search !== '') {

        $sql .= "
            AND (
                r.first_name LIKE :search_first
                OR r.middle_name LIKE :search_middle
                OR r.last_name LIKE :search_last
                OR r.resident_id LIKE :search_code
                OR r.room_number LIKE :search_room
            )
        ";

        $term = '%' . $search . '%';

        $params['search_first'] = $term;
        $params['search_middle'] = $term;
        $params['search_last'] = $term;
        $params['search_code'] = $term;
        $params['search_room'] = $term;
    }

    $sql .= "
        ORDER BY ca.archived_at DESC
        LIMIT 300
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $archivedResidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log('Caretaker archive loading error: ' . $e->getMessage());

    $error = $error ?: 'Unable to load your archived residents.';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Archive & Restore | Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        .caretaker-archive-page {
            width: 100%;
            max-width: 1600px;
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

        .archive-panel {
            background: #ffffff;
            border: 1px solid #e1ebe4;
            border-radius: 13px;
            padding: 22px;
            box-shadow: 0 4px 15px rgba(24,75,42,.05);
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
            color: #ffffff;
            border-radius: 6px;
            padding: 7px 15px;
        }

        .btn-restore:hover {
            background: #0b5425;
            color: #ffffff;
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

        @media(max-width: 576px) {

            .archive-header {
                padding: 18px;
            }

            .archive-header h1 {
                font-size: 23px;
            }

            .archive-panel {
                padding: 12px;
            }
        }

    </style>

</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="caretaker-archive-page">

    <!-- PAGE HEADER -->

    <div class="archive-header d-flex justify-content-between align-items-center flex-wrap gap-3">

        <div>

            <h1>Archive &amp; Restore</h1>

            <p>
                View, restore or permanently remove residents you archived from your personal list.
            </p>

        </div>

        <a
            href="myresidents.php"
            class="btn btn-outline-success"
        >
            Back to My Residents
        </a>

    </div>

    <!-- SUCCESS MESSAGE -->

    <?php if ($success): ?>

        <div class="alert alert-success">
            <?= archiveEscape($success) ?>
        </div>

    <?php endif; ?>

    <!-- ERROR MESSAGE -->

    <?php if ($error): ?>

        <div class="alert alert-danger">
            <?= archiveEscape($error) ?>
        </div>

    <?php endif; ?>

    <!-- ARCHIVE TABLE -->

    <div class="archive-panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

            <div>

                <h2 class="h5 fw-bold mb-1">
                    My Archived Residents
                </h2>

                <p class="text-secondary small mb-0">
                    Only residents archived by your own account are shown. Removing one is permanent for your account only.
                </p>

            </div>

            <span class="badge text-bg-secondary">
                <?= count($archivedResidents) ?> record(s)
            </span>

        </div>

        <!-- SEARCH -->

        <form method="GET" class="row g-2 mb-4">

            <div class="col-md-9">

                <input
                    type="search"
                    name="search"
                    class="form-control"
                    placeholder="Search resident name, ID, or room..."
                    value="<?= archiveEscape($search) ?>"
                >

            </div>

            <div class="col-md-3 d-grid">

                <button
                    type="submit"
                    class="btn btn-success"
                >
                    Search
                </button>

            </div>

        </form>

        <!-- EMPTY STATE -->

        <?php if (empty($archivedResidents)): ?>

            <div class="empty-state">

                <h5>No Archived Residents</h5>

                <p class="mb-0">
                    Residents that you archive from My Residents will appear here.
                </p>

            </div>

        <?php else: ?>

            <!-- RESIDENT TABLE -->

            <div class="table-responsive">

                <table class="table table-bordered table-hover archive-table">

                    <thead>

                        <tr>

                            <th>Resident ID</th>

                            <th>Resident Name</th>

                            <th>Room Number</th>

                            <th>Care Level</th>

                            <th>Status</th>

                            <th>Date Archived</th>

                            <th>Action</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($archivedResidents as $resident): ?>

                            <?php

                            $fullName = trim(implode(' ', array_filter([

                                $resident['first_name'] ?? '',

                                $resident['middle_name'] ?? '',

                                $resident['last_name'] ?? ''

                            ])));

                            ?>

                            <tr>

                                <td>
                                    <?= archiveEscape($resident['resident_id']) ?>
                                </td>

                                <td>
                                    <?= archiveEscape($fullName) ?>
                                </td>

                                <td>
                                    <?= archiveEscape($resident['room_number'] ?: 'Not assigned') ?>
                                </td>

                                <td>
                                    <?= archiveEscape($resident['care_level'] ?: 'Not specified') ?>
                                </td>

                                <td>
                                    <?= archiveEscape($resident['status'] ?: 'Unspecified') ?>
                                </td>

                                <td>
                                    <?= archiveEscape(
                                        date(
                                            'M j, Y g:i A',
                                            strtotime($resident['archived_at'])
                                        )
                                    ) ?>
                                </td>

                                <td>

                                    <div class="d-flex gap-2 flex-wrap">

                                        <form
                                            method="POST"
                                            action="archive.php"
                                            onsubmit="return confirm('Are you sure you want to restore this resident?');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= archiveEscape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="archive_id" value="<?= (int)$resident['archive_id'] ?>">

                                            <button type="submit" class="btn btn-restore btn-sm">
                                                Restore
                                            </button>
                                        </form>

                                        <form
                                            method="POST"
                                            action="archive.php"
                                            onsubmit="return confirm('Permanently remove this resident from your account? You will not be able to restore them. The resident record stays with the administrator.');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= archiveEscape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="archive_id" value="<?= (int)$resident['archive_id'] ?>">

                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                Remove permanently
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

</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

</body>
</html>
