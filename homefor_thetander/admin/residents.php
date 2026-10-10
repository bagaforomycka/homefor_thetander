<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

$pageTitle = 'Resident Management';
$error = '';
$residents = [];

try {
    $stmt = $pdo->query(
        "SELECT
            id,
            resident_id,
            first_name,
            middle_name,
            last_name,
            date_of_birth,
            gender,
            contact_number,
            address,
            room_number,
            admission_date,
            care_level,
            assigned_caretaker,
            assigned_staff,
            status,
            created_at
         FROM residents
         ORDER BY created_at DESC, id DESC"
    );

    $residents = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'Unable to load residents. Please check the residents table structure.';
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | Home for the Tender</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <style>
        body {
            background: #f5f8f6;
            color: #24352b;
            font-family: Arial, sans-serif;
        }
        .page-header {
            background: #33b960;
            color: white;
            padding: 24px;
            border-radius: 14px;
        }
        .content-card {
            background: white;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 3px 14px rgba(0,0,0,.06);
        }
        .table thead th {
            background: #e7f7ec;
            white-space: nowrap;
        }
        .table td {
            vertical-align: middle;
        }
        .resident-table {
            font-size: 14px;
        }
        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #e7f7ec;
            color: #176b35;
            white-space: nowrap;
        }
    </style>
</head>
<body>
<div class="container-fluid px-3 px-lg-4 py-4">

    <div class="page-header mb-4">
        <h1 class="h3 mb-1">Resident Management</h1>
        <p class="mb-0">View and manage Home for the Tender resident records.</p>
    </div>

    <div class="content-card">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h2 class="h5 mb-1">Resident List</h2>
                <p class="text-muted mb-0">
                    Total records: <?= count($residents) ?>
                </p>
            </div>

            <div class="d-flex gap-2">
               <a href="add_resident.php" class="btn btn-success">
              + Add Resident
         </a>
            <a href="dashboard.php" class="btn btn-outline-success">
               Back to Dashboard
         </a>
</div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php elseif (count($residents) === 0): ?>
            <div class="alert alert-info">
                No residents have been added yet. Your resident table is ready,
                but it currently contains no records.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover resident-table">
                    <thead>
                    <tr>
                        <th>Resident ID</th>
                        <th>Full Name</th>
                        <th>Date of Birth</th>
                        <th>Gender</th>
                        <th>Contact</th>
                        <th>Room</th>
                        <th>Admission Date</th>
                        <th>Care Level</th>
                        <th>Assigned Caretaker</th>
                        <th>Assigned Staff</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($residents as $resident): ?>
                        <tr>
                            <td><?= e($resident['resident_id']) ?></td>
                            <td>
                                <?= e(trim(
                                    ($resident['first_name'] ?? '') . ' ' .
                                    ($resident['middle_name'] ?? '') . ' ' .
                                    ($resident['last_name'] ?? '')
                                )) ?>
                            </td>
                            <td><?= e($resident['date_of_birth']) ?></td>
                            <td><?= e($resident['gender']) ?></td>
                            <td><?= e($resident['contact_number']) ?></td>
                            <td><?= e($resident['room_number']) ?></td>
                            <td><?= e($resident['admission_date']) ?></td>
                            <td><?= e($resident['care_level']) ?></td>
                            <td><?= e($resident['assigned_caretaker']) ?></td>
                            <td><?= e($resident['assigned_staff']) ?></td>
                            <td><span class="status"><?= e($resident['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
