<?php
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'staff'], true)) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$success = $_GET['success'] ?? '';
$error = '';

/* Delete a health record */
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete') {

    if ($_SESSION['role'] !== 'admin') {
        http_response_code(403);
        exit('Only an administrator can delete health records.');
    }

    $deleteId = filter_input(INPUT_POST, 'record_id', FILTER_VALIDATE_INT);

    if ($deleteId) {
        try {
            $stmt = $pdo->prepare(
                'DELETE FROM health_records WHERE id = :id'
            );
            $stmt->execute(['id' => $deleteId]);

            header('Location: health_records.php?success=deleted');
            exit;
        } catch (PDOException $e) {
            error_log('Health record deletion error: ' . $e->getMessage());
            $error = 'Unable to delete this health record.';
        }
    } else {
        $error = 'Invalid health record selected.';
    }
}

/* Fetch health records and resident details */
$sql = "
    SELECT
        hr.id,
        hr.resident_id,
        hr.record_date,
        hr.blood_pressure,
        hr.temperature,
        hr.weight,
        hr.allergies,
        hr.medical_conditions,
        hr.medications,
        hr.notes,
        r.resident_id AS resident_code,
        r.first_name,
        r.middle_name,
        r.last_name,
        r.room_number
    FROM health_records hr
    INNER JOIN residents r ON r.id = hr.resident_id
    WHERE 1 = 1
";

$params = [];

if ($search !== '') {
    $sql .= " AND (
        r.resident_id LIKE :search_code
        OR r.first_name LIKE :search_first
        OR r.middle_name LIKE :search_middle
        OR r.last_name LIKE :search_last
        OR hr.medical_conditions LIKE :search_condition
        OR hr.allergies LIKE :search_allergies
    )";

    $term = '%' . $search . '%';
    $params = [
        'search_code' => $term,
        'search_first' => $term,
        'search_middle' => $term,
        'search_last' => $term,
        'search_condition' => $term,
        'search_allergies' => $term
    ];
}

if ($filter === 'allergies') {
    $sql .= " AND hr.allergies IS NOT NULL
              AND TRIM(hr.allergies) <> ''";
} elseif ($filter === 'conditions') {
    $sql .= " AND hr.medical_conditions IS NOT NULL
              AND TRIM(hr.medical_conditions) <> ''";
}

$sql .= ' ORDER BY hr.record_date DESC, hr.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
    .page-header {
        background: #e7f5ec;
        border: 1px solid #d5eadc;
        border-radius: 14px;
        padding: 22px 26px;
        margin-bottom: 22px;
    }

    .page-header h1 {
        color: #17683a;
        font-size: 28px;
        margin: 0 0 6px;
        font-weight: 700;
    }

    .page-header p {
        color: #61796a;
        margin: 0;
    }

    .health-panel {
        background: #fff;
        border: 1px solid #e3eee6;
        border-radius: 14px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 4px 16px rgba(24, 75, 42, .05);
    }

    .health-search-panel {
        background: #f3faf5;
    }

    .health-card {
        background: #fff;
        border: 1px solid #e0ebe3;
        border-radius: 12px;
        padding: 18px;
        margin-bottom: 14px;
        transition: box-shadow .2s;
    }

    .health-card:hover {
        box-shadow: 0 5px 16px rgba(24, 75, 42, .07);
    }

    .health-card h3 {
        color: #17683a;
        font-size: 19px;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .resident-meta {
        color: #66786c;
        font-size: 13px;
        margin-bottom: 14px;
    }

    .health-detail {
        background: #f7faf8;
        border: 1px solid #e5ede7;
        border-radius: 9px;
        padding: 10px;
        min-height: 42px;
        overflow-wrap: anywhere;
    }

    .health-detail strong {
        color: #294b35;
    }

    .btn-view {
        color: #198754;
        background: #fff;
        border: 1px solid #198754;
    }

    .btn-view:hover {
        color: #fff;
        background: #198754;
    }

    .btn-edit {
        color: #d6a000;
        background: #fff;
        border: 1px solid #e2b422;
    }

    .btn-edit:hover {
        color: #5d4600;
        background: #fff8d9;
        border-color: #d6a000;
    }

    .btn-delete {
        color: #dc3545;
        background: #fff;
        border: 1px solid #dc3545;
    }

    .btn-delete:hover {
        color: #fff;
        background: #dc3545;
    }

    .record-actions {
        display: flex;
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: 7px;
        margin-top: 12px;
    }

    .record-actions .btn {
        font-size: 13px;
        padding: 5px 10px;
    }

    .health-search-panel .form-control,
    .health-search-panel .form-select {
        min-height: 40px;
    }

    @media (max-width: 576px) {
        .page-header {
            padding: 18px;
        }

        .health-panel {
            padding: 14px;
        }
    }
</style>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <h1>Health Records</h1>
        <p>View and manage residents' health information.</p>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="dashboard.php" class="btn btn-outline-success">
            ← Back to Dashboard
        </a>

        <a href="add_health_record.php" class="btn btn-success">
            + Add Health Record
        </a>
    </div>
</div>

<?php if ($success === 'added'): ?>
    <div class="alert alert-success">Health record added successfully.</div>
<?php elseif ($success === 'updated'): ?>
    <div class="alert alert-success">Health record updated successfully.</div>
<?php elseif ($success === 'deleted'): ?>
    <div class="alert alert-success">Health record deleted successfully.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="health-panel health-search-panel">
    <form method="GET" action="health_records.php"
          class="d-flex flex-wrap gap-2 align-items-center">

        <input
            type="text"
            name="search"
            class="form-control flex-grow-1"
            style="min-width:220px"
            placeholder="Search resident, name, or ID..."
            value="<?= h($search) ?>"
        >

        <select name="filter" class="form-select" style="width:auto; min-width:170px">
            <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>
                All records
            </option>
            <option value="allergies" <?= $filter === 'allergies' ? 'selected' : '' ?>>
                With allergies
            </option>
            <option value="conditions" <?= $filter === 'conditions' ? 'selected' : '' ?>>
                With medical conditions
            </option>
        </select>

        <button type="submit" class="btn btn-success">
            Search / Filter
        </button>

        <a href="health_records.php" class="btn btn-outline-secondary">
            Reset
        </a>
    </form>
</div>

<div class="health-panel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">List of Patients</h2>
        <span class="text-secondary"><?= count($records) ?> record(s)</span>
    </div>

    <?php if (empty($records)): ?>
        <div class="alert alert-info mb-0">
            No health records found. Add a health record or change your search.
        </div>
    <?php else: ?>

        <?php foreach ($records as $record): ?>
            <?php
                $fullName = trim(
                    $record['first_name'] . ' ' .
                    ($record['middle_name'] ?? '') . ' ' .
                    $record['last_name']
                );
                $fullName = preg_replace('/\s+/', ' ', $fullName);
            ?>

            <div class="health-card">
                <h3><?= h($fullName) ?></h3>

                <div class="resident-meta">
                    Resident ID: <?= h($record['resident_code']) ?>
                    &nbsp;|&nbsp;
                    Room: <?= h($record['room_number'] ?: 'Not assigned') ?>
                    &nbsp;|&nbsp;
                    Check-up: <?= h($record['record_date']) ?>
                </div>

                <div class="row g-2">
                    <div class="col-md-6">
                        <div class="health-detail">
                            <strong>Blood Pressure:</strong>
                            <?= h($record['blood_pressure'] ?: 'Not recorded') ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="health-detail">
                            <strong>Allergies:</strong>
                            <?= h($record['allergies'] ?: 'None recorded') ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="health-detail">
                            <strong>Temperature:</strong>
                            <?= h($record['temperature'] !== null
                                ? $record['temperature'] . ' °C'
                                : 'Not recorded') ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="health-detail">
                            <strong>Medication:</strong>
                            <?= h($record['medications'] ?: 'None recorded') ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="health-detail">
                            <strong>Weight:</strong>
                            <?= h($record['weight'] !== null
                                ? $record['weight'] . ' kg'
                                : 'Not recorded') ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="health-detail">
                            <strong>Medical Condition:</strong>
                            <?= h($record['medical_conditions'] ?: 'None recorded') ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="health-detail">
                            <strong>Notes:</strong>
                            <?= h($record['notes'] ?: 'No notes') ?>
                        </div>
                    </div>
                </div>

                <div class="record-actions">
                    <a
                        href="view_health_record.php?id=<?= (int)$record['id'] ?>"
                        class="btn btn-view"
                    >View</a>

                    <a
                        href="add_health_record.php?edit=<?= (int)$record['id'] ?>"
                        class="btn btn-edit"
                    >Edit</a>

                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <form method="POST"
                              action="health_records.php"
                              onsubmit="return confirm('Are you sure you want to permanently delete this health record?');"
                              class="d-inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="record_id"
                                   value="<?= (int)$record['id'] ?>">
                            <button type="submit" class="btn btn-delete">
                                Delete
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>

</main>
</body>
</html>
