
<?php
/**
 * HOME FOR THE AGED
 * ADMIN ARCHIVE, RESTORE AND PERMANENT DELETE
 * Residents + Caretakers + Reports
 * File: admin/archive.php
 *
 * Only archived records can be permanently deleted.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    strtolower(trim((string)$_SESSION['role'])) !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/archive_helper.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['admin_archive_csrf'])) {
    $_SESSION['admin_archive_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['admin_archive_csrf'];

$allowedTabs = ['residents', 'caretakers', 'reports'];
$tab = $_GET['tab'] ?? 'residents';

if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'residents';
}

$search = trim((string)($_GET['search'] ?? ''));
$success = '';
$error = '';
$rows = [];

$counts = [
    'residents' => 0,
    'caretakers' => 0,
    'reports' => 0
];

$hasArchivedAt = false;

try {
    $columns = $pdo->query("SHOW COLUMNS FROM residents")
        ->fetchAll(PDO::FETCH_COLUMN);

    $hasArchivedAt = in_array('archived_at', $columns, true);

    archiveEnsureCaretakerTable($pdo);

    // Ensure the Reports module's table exists.
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

} catch (Throwable $e) {
    error_log('Admin archive setup: ' . $e->getMessage());
    $error = 'Unable to check the database tables.';
}

$residentArchived = $hasArchivedAt
    ? "(r.status = 'archived' OR r.archived_at IS NOT NULL)"
    : "r.status = 'archived'";

/*
|--------------------------------------------------------------------------
| RESTORE AND PERMANENT DELETE ACTIONS
|--------------------------------------------------------------------------
*/

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page.');
    }

<<<<<<< HEAD
    $action = ($_POST['action'] ?? '') === 'purge' ? 'purge' : 'restore';
    $postedType = $_POST['type'] ?? '';
    $type = in_array($postedType, ['caretaker', 'health'], true) ? $postedType : 'resident';
    $recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
    $postTab = $type === 'caretaker' ? 'caretakers' : 'residents';
=======
    $action = ($_POST['action'] ?? '') === 'purge'
        ? 'purge'
        : 'restore';

    $type = $_POST['type'] ?? 'resident';

    if (!in_array($type, ['resident', 'caretaker', 'report'], true)) {
        $type = 'resident';
    }

    $recordId = filter_var(
        $_POST['record_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $postTab = [
        'resident' => 'residents',
        'caretaker' => 'caretakers',
        'report' => 'reports'
    ][$type];
>>>>>>> 6ad4877293bc4e3c58c84212c1a3416dc31ed9a5

    if (!$recordId || $recordId < 1) {
        $error = 'Invalid record selected.';
    } else {
        try {

<<<<<<< HEAD
            /* ---------- HEALTH RECORD (soft-deleted via archived_at) ---------- */
            if ($type === 'health') {

                if ($action === 'restore') {
                    $stmt = $pdo->prepare("
                        UPDATE health_records
                        SET archived_at = NULL
                        WHERE id = :id AND archived_at IS NOT NULL
                    ");
                    $stmt->execute(['id' => $recordId]);

                    if ($stmt->rowCount() > 0) {
                        header('Location: archive.php?tab=residents&restored=1&kind=health');
                        exit;
                    }
                    $error = 'Record not found or not archived.';
                } else {
                    /* Permanent delete: archived health records only. */
                    $stmt = $pdo->prepare("
                        DELETE FROM health_records
                        WHERE id = :id AND archived_at IS NOT NULL
                    ");
                    $stmt->execute(['id' => $recordId]);

                    if ($stmt->rowCount() > 0) {
                        header('Location: archive.php?tab=residents&deleted=1&kind=health');
                        exit;
                    }
                    $error = 'Only archived health records can be permanently deleted.';
                }
            }

            /* ---------- RESTORE ---------- */
=======
            /*
            |--------------------------------------------------------------------------
            | RESTORE RESIDENT
            |--------------------------------------------------------------------------
            */

>>>>>>> 6ad4877293bc4e3c58c84212c1a3416dc31ed9a5
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

            /*
            |--------------------------------------------------------------------------
            | RESTORE CARETAKER
            |--------------------------------------------------------------------------
            */

            if ($action === 'restore' && $type === 'caretaker') {

                $pdo->beginTransaction();

                $find = $pdo->prepare("
                    SELECT username, status
                    FROM caretakers
                    WHERE id = :id
                      AND archived_at IS NOT NULL
                    LIMIT 1
                ");

                $find->execute(['id' => $recordId]);
                $caretaker = $find->fetch();

                if (!$caretaker) {
                    $pdo->rollBack();
                    $error = 'Record not found or not archived.';
                } else {

                    $pdo->prepare("
                        UPDATE caretakers
                        SET archived_at = NULL
                        WHERE id = :id
                    ")->execute(['id' => $recordId]);

                    $pdo->prepare("
                        UPDATE users
                        SET status = :status
                        WHERE username = :username
                          AND role = 'caretaker'
                    ")->execute([
                        'status' => ($caretaker['status'] === 'Active')
                            ? 'active'
                            : 'inactive',
                        'username' => $caretaker['username']
                    ]);

                    $pdo->commit();

                    header('Location: archive.php?tab=caretakers&restored=1');
                    exit;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | RESTORE REPORT
            |--------------------------------------------------------------------------
            */

            if ($action === 'restore' && $type === 'report') {

                $stmt = $pdo->prepare("
                    UPDATE incident_reports
                    SET archived_at = NULL
                    WHERE id = :id
                      AND archived_at IS NOT NULL
                ");

                $stmt->execute(['id' => $recordId]);

                if ($stmt->rowCount() > 0) {
                    header('Location: archive.php?tab=reports&restored=1');
                    exit;
                }

                $error = 'Report not found or is not archived.';
            }

            /*
            |--------------------------------------------------------------------------
            | PERMANENTLY DELETE RESIDENT
            |--------------------------------------------------------------------------
            */

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
                $resident = $find->fetch();

                if (!$resident) {
                    $pdo->rollBack();
                    $error = 'Only archived residents can be permanently deleted.';
                } else {

                    foreach ([
                        'health_records' => 'resident_id',
                        'schedules' => 'resident_id',
                        'caretaker_resident_archive' => 'resident_id'
                    ] as $table => $column) {
<<<<<<< HEAD
                        $pdo->prepare("DELETE FROM $table WHERE $column = :id")
                            ->execute(['id' => $recordId]);
=======

                        $pdo->prepare(
                            "DELETE FROM `$table` WHERE `$column` = :id"
                        )->execute(['id' => $recordId]);
>>>>>>> 6ad4877293bc4e3c58c84212c1a3416dc31ed9a5
                    }

                    $pdo->prepare("
                        DELETE FROM residents
                        WHERE id = :id
                    ")->execute(['id' => $recordId]);

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
                            $pictureFile =
                                __DIR__ . '/uploads/residentspic/' . $picture;
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

            /*
            |--------------------------------------------------------------------------
            | PERMANENTLY DELETE CARETAKER
            |--------------------------------------------------------------------------
            */

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
                $caretaker = $find->fetch();

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
                            // Password reset table may not exist.
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

            /*
            |--------------------------------------------------------------------------
            | PERMANENTLY DELETE REPORT
            |--------------------------------------------------------------------------
            */

            if ($action === 'purge' && $type === 'report') {

                $stmt = $pdo->prepare("
                    DELETE FROM incident_reports
                    WHERE id = :id
                      AND archived_at IS NOT NULL
                ");

                $stmt->execute(['id' => $recordId]);

                if ($stmt->rowCount() > 0) {
                    header('Location: archive.php?tab=reports&deleted=1');
                    exit;
                }

                $error = 'Only archived reports can be permanently deleted.';
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

<<<<<<< HEAD
$isHealthMsg = ($_GET['kind'] ?? '') === 'health';

if (isset($_GET['restored']) && $isHealthMsg) {
    $success = 'Health record restored to the Health Records list.';
} elseif (isset($_GET['deleted']) && $isHealthMsg) {
    $success = 'Health record permanently deleted.';
} elseif (isset($_GET['restored'])) {
    $success = $tab === 'caretakers'
        ? 'Caretaker restored to the Caretakers list and their login re-enabled.'
        : 'Resident restored. Their status is now Active.';
=======
/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGES
|--------------------------------------------------------------------------
*/

if (isset($_GET['restored'])) {

    $success = match ($tab) {
        'caretakers' => 'Caretaker restored and their login status re-enabled.',
        'reports' => 'Report restored successfully. It is now available in the Reports module.',
        default => 'Resident restored. Their status is now Active.'
    };

>>>>>>> 6ad4877293bc4e3c58c84212c1a3416dc31ed9a5
} elseif (isset($_GET['deleted'])) {

    $success = match ($tab) {
        'caretakers' => 'Caretaker permanently deleted. Their residents are now unassigned.',
        'reports' => 'Report permanently deleted.',
        default => 'Resident permanently deleted, including their health records and schedules.'
    };
}

/*
|--------------------------------------------------------------------------
| TAB COUNTS
|--------------------------------------------------------------------------
*/

<<<<<<< HEAD
    /* Archived health records belong to the Residents tab. */
    try {
        $counts['residents'] += (int)$pdo
            ->query("SELECT COUNT(*) FROM health_records WHERE archived_at IS NOT NULL")
            ->fetchColumn();
    } catch (Throwable $e) {
        error_log('Admin archive health count: ' . $e->getMessage());
    }

    try {
        $counts['caretakers'] = (int)$pdo
            ->query("SELECT COUNT(*) FROM caretakers WHERE archived_at IS NOT NULL")
            ->fetchColumn();
    } catch (Throwable $e) {
        error_log('Admin archive caretaker count: ' . $e->getMessage());
    }
}

/* Load the active tab's archived records. */
=======
>>>>>>> 6ad4877293bc4e3c58c84212c1a3416dc31ed9a5
try {
    $counts['residents'] = (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM residents r
            WHERE $residentArchived
        ")
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

try {
    $counts['reports'] = (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM incident_reports
            WHERE archived_at IS NOT NULL
        ")
        ->fetchColumn();
} catch (Throwable $e) {
    error_log('Admin archive reports count: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| LOAD ARCHIVED RECORDS FOR THE SELECTED TAB
|--------------------------------------------------------------------------
*/

try {

    $params = [];

    /*
    |--------------------------------------------------------------------------
    | CARETAKERS
    |--------------------------------------------------------------------------
    */

    if ($tab === 'caretakers') {

        $sql = "
            SELECT id, employee_id, full_name, contact, position,
                   shift, status, archived_at
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

    /*
    |--------------------------------------------------------------------------
    | REPORTS
    |--------------------------------------------------------------------------
    */

    } elseif ($tab === 'reports') {

        $sql = "
            SELECT
                i.id,
                i.report_type,
                i.related_to,
                i.resident_id,
                i.caretaker_name,
                i.location,
                i.report_date,
                i.details,
                i.status,
                i.created_by,
                i.archived_at,
                r.first_name,
                r.middle_name,
                r.last_name
            FROM incident_reports i
            LEFT JOIN residents r ON r.id = i.resident_id
            WHERE i.archived_at IS NOT NULL
        ";

        if ($search !== '') {
            $sql .= "
                AND (
                    i.report_type LIKE :s1
                    OR i.related_to LIKE :s2
                    OR i.caretaker_name LIKE :s3
                    OR i.location LIKE :s4
                    OR i.details LIKE :s5
                    OR i.status LIKE :s6
                    OR CAST(i.id AS CHAR) LIKE :s7
                )
            ";

            $term = '%' . $search . '%';

            foreach (
                ['s1', 's2', 's3', 's4', 's5', 's6', 's7'] as $key
            ) {
                $params[$key] = $term;
            }
        }

        $sql .= " ORDER BY i.archived_at DESC, i.id DESC LIMIT 300";

    /*
    |--------------------------------------------------------------------------
    | RESIDENTS
    |--------------------------------------------------------------------------
    */

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
            LEFT JOIN users u ON u.id = r.assigned_caretaker
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

            foreach (
                ['s1', 's2', 's3', 's4', 's5', 's6'] as $key
            ) {
                $params[$key] = $term;
            }
        }

        $sql .= " ORDER BY r.id DESC LIMIT 300";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($tab === 'residents') {
        $merged = [];

        foreach ($rows as $row) {
            $merged[] = [
                'kind' => 'resident',
                'id' => $row['id'],
                'resident_code' => $row['resident_id'],
                'name' => preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([
                    $row['first_name'] ?? '', $row['middle_name'] ?? '', $row['last_name'] ?? ''
                ])))),
                'room_number' => $row['room_number'],
                'details' => 'Care level: ' . ($row['care_level'] ?: 'Not specified')
                    . ' | Caretaker: ' . ($row['caretaker_name'] ?: 'Unassigned'),
                'archived_at' => $row['archived_at']
            ];
        }

        /* Archived health records, shown in the same list. */
        try {
            $hSql = "
                SELECT hr.id, hr.record_date, hr.blood_pressure,
                       hr.medical_conditions, hr.archived_at,
                       r.resident_id AS resident_code, r.first_name,
                       r.middle_name, r.last_name, r.room_number
                FROM health_records hr
                INNER JOIN residents r ON r.id = hr.resident_id
                WHERE hr.archived_at IS NOT NULL
            ";
            $hParams = [];

            if ($search !== '') {
                $hSql .= " AND (
                    r.resident_id LIKE :h1
                    OR r.first_name LIKE :h2
                    OR r.middle_name LIKE :h3
                    OR r.last_name LIKE :h4
                    OR r.room_number LIKE :h5
                    OR hr.medical_conditions LIKE :h6
                )";
                $hTerm = '%' . $search . '%';
                foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $key) {
                    $hParams[$key] = $hTerm;
                }
            }

            $hSql .= " ORDER BY hr.archived_at DESC LIMIT 300";
            $hStmt = $pdo->prepare($hSql);
            $hStmt->execute($hParams);

            foreach ($hStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $merged[] = [
                    'kind' => 'health',
                    'id' => $row['id'],
                    'resident_code' => $row['resident_code'],
                    'name' => preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([
                        $row['first_name'] ?? '', $row['middle_name'] ?? '', $row['last_name'] ?? ''
                    ])))),
                    'room_number' => $row['room_number'],
                    'details' => 'Check-up: ' . $row['record_date']
                        . ' | BP: ' . ($row['blood_pressure'] ?: 'Not recorded')
                        . ' | Conditions: ' . ($row['medical_conditions'] ?: 'None recorded'),
                    'archived_at' => $row['archived_at']
                ];
            }
        } catch (Throwable $e) {
            error_log('Admin archive health load error: ' . $e->getMessage());
        }

        usort($merged, function ($a, $b) {
            return (strtotime((string)($b['archived_at'] ?? '')) ?: 0)
                <=> (strtotime((string)($a['archived_at'] ?? '')) ?: 0);
        });

        $rows = $merged;
    }

} catch (Throwable $e) {
    error_log('Admin archive load error: ' . $e->getMessage());
    $error = $error ?: 'Unable to load archived records.';
}

$isCaretakers = $tab === 'caretakers';
$isReports = $tab === 'reports';

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
        .admin-archive-page {
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

        .archive-tabs .nav-link {
            color: #17683a;
            font-weight: 600;
        }

        .archive-panel {
            background: #fff;
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
            color: #fff;
            border-radius: 6px;
            padding: 7px 15px;
        }

        .btn-restore:hover {
            background: #0b5425;
            color: #fff;
        }.btn-restore {
    background-color: #17683a;
    color: #ffffff;
    border: 2px solid #0b5425 !important;
    border-radius: 6px;
    padding: 7px 15px;
    font-weight: 600;
    box-shadow: none;
}

.btn-restore:hover {
    background-color: #0b5425;
    color: #ffffff;
    border: 2px solid #06421c !important;
}

.btn-restore:focus,
.btn-restore:active {
    border: 2px solid #06421c !important;
    outline: 2px solid #33b960;
    outline-offset: 2px;
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

        .report-details {
            max-width: 260px;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        @media (max-width: 576px) {
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

<style>
    ul.archive-tabs.nav-pills .nav-link.active,
    ul.archive-tabs.nav-pills .nav-link.active:hover,
    ul.archive-tabs.nav-pills .nav-link.active:focus {
        background: #17683a !important;
        background-color: #17683a !important;
        color: #fff !important;
    }

    ul.archive-tabs.nav-pills .nav-link:hover:not(.active) {
        background-color: #eaf6ee;
    }
</style>

<div class="admin-archive-page">

    <div class="archive-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1>Archive & Restore</h1>
            <p>
                Restore archived residents, caretakers, and reports, or
                permanently delete archived records.
            </p>
        </div>

        <a
            href="<?= $isCaretakers
                ? 'caretakers.php'
                : ($isReports ? 'reports.php' : 'residents.php') ?>"
            class="btn btn-outline-success"
        >
            Back to <?= $isCaretakers
                ? 'Caretakers'
                : ($isReports ? 'Reports' : 'Residents') ?>
        </a>
    </div>

    <!-- ARCHIVE TABS -->
    <ul class="nav nav-pills archive-tabs mb-3">

        <li class="nav-item">
            <a
                class="nav-link <?= $tab === 'residents' ? 'active' : '' ?>"
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
                class="nav-link <?= $tab === 'caretakers' ? 'active' : '' ?>"
                href="archive.php?tab=caretakers"
            >
                Caretakers
                <span class="badge text-bg-light">
                    <?= (int)$counts['caretakers'] ?>
                </span>
            </a>
        </li>

        <li class="nav-item">
            <a
                class="nav-link <?= $tab === 'reports' ? 'active' : '' ?>"
                href="archive.php?tab=reports"
            >
                Reports
                <span class="badge text-bg-light">
                    <?= (int)$counts['reports'] ?>
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

    <div class="archive-panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

            <div>
                <h2 class="h5 fw-bold mb-1">
                    Archived
                    <?= $isCaretakers
                        ? 'Caretakers'
                        : ($isReports ? 'Reports' : 'Residents') ?>
                </h2>

                <p class="text-secondary small mb-0">

                    <?php if ($isCaretakers): ?>

                        Caretakers archived from the Caretakers page.
                        Their login stays disabled until restored.

                    <?php elseif ($isReports): ?>

                        Reports archived from the Reports module.
                        Restore a report to return it to the active Reports list.

                    <?php else: ?>
<<<<<<< HEAD
                        Residents and health records archived by an administrator.
                        Caretakers' personal archives are not shown here.
=======

                        Residents archived by an administrator.
                        Caretakers' personal archives are not shown here.

>>>>>>> 6ad4877293bc4e3c58c84212c1a3416dc31ed9a5
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
<<<<<<< HEAD
                    placeholder="<?= $isCaretakers
                        ? 'Search name, employee ID, contact, or position...'
                        : 'Search name, ID, room, caretaker, or condition...' ?>"
=======
                    placeholder="<?=
                        $isCaretakers
                            ? 'Search name, employee ID, contact, or position...'
                            : ($isReports
                                ? 'Search report ID, type, related person, location, status, or details...'
                                : 'Search name, ID, room, or caretaker...')
                    ?>"
>>>>>>> 6ad4877293bc4e3c58c84212c1a3416dc31ed9a5
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
                    No Archived
                    <?= $isCaretakers
<<<<<<< HEAD
                        ? 'Caretakers you archive from the Caretakers page will appear here.'
                        : 'Residents and health records you archive will appear here.' ?>
=======
                        ? 'Caretakers'
                        : ($isReports ? 'Reports' : 'Residents') ?>
                </h5>

                <p class="mb-0">

                    <?php if ($isCaretakers): ?>

                        Caretakers you archive from the Caretakers page
                        will appear here.

                    <?php elseif ($isReports): ?>

                        Reports you archive from the Reports module
                        will appear here.

                    <?php else: ?>

                        Residents you archive from the Residents page
                        will appear here.

                    <?php endif; ?>

>>>>>>> 6ad4877293bc4e3c58c84212c1a3416dc31ed9a5
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

                            <?php elseif ($isReports): ?>

                                <th>Report ID</th>
                                <th>Report Date</th>
                                <th>Report Type</th>
                                <th>Related To</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Details</th>

                            <?php else: ?>

                                <th>Resident ID</th>
                                <th>Resident Name</th>
                                <th>Room Number</th>
<<<<<<< HEAD
                                <th>Type</th>
                                <th>Details</th>
=======
                                <th>Care Level</th>
                                <th>Assigned Caretaker</th>

>>>>>>> 6ad4877293bc4e3c58c84212c1a3416dc31ed9a5
                            <?php endif; ?>

                            <th>Date Archived</th>
                            <th>Action</th>

                        </tr>
                    </thead>

                    <tbody>
<<<<<<< HEAD
                        <?php foreach ($rows as $row): ?>
                            <?php
                            if ($isCaretakers) {
                                $label = (string)($row['full_name'] ?? 'this caretaker');
                                $warning = 'Their login is deleted and the residents assigned to them become unassigned.';
                            } elseif (($row['kind'] ?? '') === 'health') {
                                $label = 'the health record of ' . ($row['name'] ?? '');
                                $warning = 'This health record will be removed from the database.';
                            } else {
                                $label = (string)($row['name'] ?? '');
                                $warning = 'Their health records and schedules are deleted too.';
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
                                    <td><?= h($row['resident_code']) ?></td>
                                    <td><?= h($row['name']) ?></td>
                                    <td><?= h($row['room_number'] ?: 'Not assigned') ?></td>
                                    <td>
                                        <span class="badge <?= $row['kind'] === 'health' ? 'text-bg-info' : 'text-bg-success' ?>">
                                            <?= $row['kind'] === 'health' ? 'Health Record' : 'Resident' ?>
                                        </span>
                                    </td>
                                    <td><?= h($row['details']) ?></td>
                                <?php endif; ?>
                                <td>
                                    <?= !empty($row['archived_at'])
                                        ? h(date('M j, Y g:i A', strtotime($row['archived_at'])))
                                        : '—' ?>
=======

                    <?php foreach ($rows as $row): ?>

                        <?php

                        if ($isCaretakers) {

                            $label = (string)($row['full_name'] ?? 'this caretaker');

                            $warning =
                                'Their login is deleted and residents assigned to them become unassigned.';

                        } elseif ($isReports) {

                            $label = 'Report INC-' .
                                str_pad((string)$row['id'], 3, '0', STR_PAD_LEFT);

                            $warning =
                                'This incident report and its details will be permanently deleted.';

                            $related = $row['related_to'] ?? 'General';

                            if ($related === 'Resident') {

                                $residentName = trim(implode(' ', array_filter([
                                    $row['first_name'] ?? '',
                                    $row['middle_name'] ?? '',
                                    $row['last_name'] ?? ''
                                ])));

                                $related .= ': ' . ($residentName ?: 'Resident');

                            } elseif ($related === 'Caretaker') {

                                $related .= ': ' . ($row['caretaker_name'] ?? '');
                            }

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

                            <?php elseif ($isReports): ?>

                                <td>
                                    INC-<?= str_pad(
                                        (string)$row['id'],
                                        3,
                                        '0',
                                        STR_PAD_LEFT
                                    ) ?>
>>>>>>> 6ad4877293bc4e3c58c84212c1a3416dc31ed9a5
                                </td>

                                <td>
<<<<<<< HEAD
                                    <div class="d-flex gap-2 flex-wrap">

                                        <form
                                            method="POST"
                                            action="archive.php?tab=<?= h($tab) ?>"
                                            onsubmit="return confirm('<?= $isCaretakers
                                                ? 'Restore this caretaker and re-enable their login?'
                                                : (($row['kind'] ?? '') === 'health'
                                                    ? 'Restore this health record?'
                                                    : 'Restore this resident? Their status will be set to Active.') ?>');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="type" value="<?= $isCaretakers ? 'caretaker' : h($row['kind']) ?>">
                                            <input type="hidden" name="record_id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-restore btn-sm">Restore</button>
                                        </form>

                                        <form
                                            method="POST"
                                            action="archive.php?tab=<?= h($tab) ?>"
                                            onsubmit="return confirmPermanentDelete(<?= h(json_encode($label, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>, <?= h(json_encode($warning, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="purge">
                                            <input type="hidden" name="type" value="<?= $isCaretakers ? 'caretaker' : h($row['kind']) ?>">
                                            <input type="hidden" name="record_id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                Delete permanently
                                            </button>
                                        </form>

                                    </div>
=======
                                    <?= !empty($row['report_date'])
                                        ? h(date('M j, Y', strtotime($row['report_date'])))
                                        : '—' ?>
>>>>>>> 6ad4877293bc4e3c58c84212c1a3416dc31ed9a5
                                </td>

                                <td><?= h($row['report_type']) ?></td>

                                <td><?= h($related) ?></td>

                                <td><?= h($row['location'] ?: '—') ?></td>

                                <td><?= h($row['status']) ?></td>

                                <td class="report-details">
                                    <?= h($row['details']) ?>
                                </td>

                            <?php else: ?>

                                <td><?= h($row['resident_id']) ?></td>
                                <td><?= h($label) ?></td>
                                <td><?= h($row['room_number'] ?: 'Not assigned') ?></td>
                                <td><?= h($row['care_level'] ?: 'Not specified') ?></td>
                                <td><?= h($row['caretaker_name'] ?: 'Unassigned') ?></td>

                            <?php endif; ?>

                            <td>
                                <?= !empty($row['archived_at'])
                                    ? h(date(
                                        'M j, Y g:i A',
                                        strtotime($row['archived_at'])
                                    ))
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
                                            : ($isReports
                                                ? 'Restore this report to the active Reports list?'
                                                : 'Restore this resident? Their status will be set to Active.')
                                        ?>');"
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
                                            value="<?= $isCaretakers
                                                ? 'caretaker'
                                                : ($isReports ? 'report' : 'resident') ?>"
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
                                            <?= h(json_encode(
                                                $label,
                                                JSON_HEX_APOS | JSON_HEX_QUOT
                                            )) ?>,
                                            <?= h(json_encode(
                                                $warning,
                                                JSON_HEX_APOS | JSON_HEX_QUOT
                                            )) ?>
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
                                            value="<?= $isCaretakers
                                                ? 'caretaker'
                                                : ($isReports ? 'report' : 'resident') ?>"
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
                                            Delete permanently
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

<script>
function confirmPermanentDelete(name, warning) {

    var answer = prompt(
        'Permanently delete ' + name + '?\n\n' +
        warning +
        '\nThis cannot be undone.\n\n' +
        'Type DELETE to confirm.'
    );

    return answer !== null && answer.trim() === 'DELETE';
}
</script>

</body>
</html>