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

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$tab = $_GET['tab'] ?? 'caretakers';
$id = $_GET['id'] ?? null;

if (!$id || !in_array($tab, ['caretakers', 'staff'], true)) {
    header("Location: caretakers_staff.php");
    exit;
}

$record = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM {$tab} WHERE id = ?");
    $stmt->execute([$id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $record = null;
}

if (!$record) {
    die("Record not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Profile | Home for the Aged</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-bg: #0d3b1e;
            --green: #17683a;
            --light-green: #33b960;
            --pale-green: #edf7ef;
            --text: #304b3b;
        }
        body {
            background-color: #f8fbf8;
            color: var(--text);
            font-family: Arial, sans-serif;
        }
        .main-content {
            margin-left: 10px;
            padding: 30px;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .card-header {
            background-color: var(--sidebar-bg);
            color: #ffffff;
            border-radius: 12px 12px 0 0 !important;
            padding: 1.2rem;
        }
        .label-title {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

    <!-- Include Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="row justify-content-center">
            <div class="col-xl-8">
                
                <div class="mb-3">
                    <a href="caretakers_staff.php?tab=<?= e($tab) ?>" class="btn btn-outline-success">&larr; Back to List</a>
                </div>

                <div class="card shadow">
                    <div class="card-header">
                        <h4 class="mb-0"><?= ucfirst(e($tab)) ?> Profile Details</h4>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="label-title">Employee ID</div>
                                <div class="fs-5 fw-semibold"><?= e($record['employee_id']) ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="label-title">Full Name</div>
                                <div class="fs-5 fw-semibold"><?= e($record['full_name']) ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="label-title">Contact Number</div>
                                <div class="fs-5"><?= e($record['contact']) ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="label-title">Position</div>
                                <div class="fs-5"><?= e($record['position'] ?? 'N/A') ?></div>
                            </div>
                            <div class="col-md-12">
                                <div class="label-title">Address</div>
                                <div class="fs-5"><?= e($record['address']) ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="label-title">Shift</div>
                                <div class="fs-5"><?= e($record['shift']) ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="label-title">Status</div>
                                <div>
                                    <span class="badge bg-success fs-6"><?= e($record['status']) ?></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="label-title">Username</div>
                                <div class="fs-5"><?= e($record['username']) ?></div>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-top d-flex justify-content-end gap-2">
                            <a href="caretakers_staff.php?tab=<?= e($tab) ?>" class="btn btn-secondary px-4">Close</a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>