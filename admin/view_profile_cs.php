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

$idParam = trim($_GET['id'] ?? '');

$record = null;
$fetchError = '';

if ($idParam === '') {
    $fetchError = "No caretaker ID was specified in the URL.";
} else {
    try {
        // Strategy 1: Try numeric primary key search first
        if (is_numeric($idParam)) {
            $stmt = $pdo->prepare("SELECT * FROM caretakers WHERE id = ? AND archived_at IS NULL");
            $stmt->execute([(int)$idParam]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Strategy 2: If primary key failed or is string, search by employee_id
        if (!$record) {
            $stmt = $pdo->prepare("SELECT * FROM caretakers WHERE employee_id = ? AND archived_at IS NULL");
            $stmt->execute([$idParam]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$record) {
            $fetchError = "Caretaker record matching ID '{$idParam}' was not found or is archived.";
        }
    } catch (PDOException $ex) {
        $fetchError = "Database error: " . $ex->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Caretaker Profile | Home for the Aged</title>
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
            overflow-x: hidden;
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
            background-color: #ffffff;
            border-bottom: 1px solid #e9ecef;
            padding: 1.2rem;
            border-radius: 12px 12px 0 0 !important;
        }

        .form-label {
            font-weight: 700;
            color: var(--green);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .section-title {
            color: var(--green);
            font-weight: bold;
            font-size: 1rem;
            margin-bottom: 1rem;
            margin-top: 0.5rem;
        }

        .info-box {
            background-color: #fff;
            border: 1px solid #cedbd1;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 0.95rem;
            color: #212529;
            min-height: 38px;
            display: flex;
            align-items: center;
        }

        .btn-success {
            background: var(--green);
            border-color: var(--green);
        }

        .btn-success:hover {
            background: #0f4b2a;
            border-color: #0f4b2a;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
        }
    </style>
</head>
<body>

    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="row justify-content-center">
            <div class="col-xl-11">
                
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <a href="caretakers.php" class="btn btn-outline-success">&larr; Back to List</a>
                    <?php if ($record): ?>
                        <a href="edit_profile_cs.php?id=<?= urlencode((string)$record['id']) ?>" class="btn btn-success">Edit Profile</a>
                    <?php endif; ?>
                </div>

                <?php if ($fetchError !== ''): ?>
                    <div class="alert alert-danger shadow-sm py-3 px-4">
                        <h5 class="alert-heading fw-bold mb-1">Unable to Load Caretaker Profile</h5>
                        <p class="mb-0"><?= e($fetchError) ?></p>
                    </div>
                <?php else: ?>
                    <div class="card shadow">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="mb-0 fw-bold fs-5">Caretaker Profile Details</h4>
                            <?php
                            $badge = ($record['status'] ?? '') === 'Active'
                                ? 'success'
                                : ((($record['status'] ?? '') === 'On Leave') ? 'warning text-dark' : 'secondary');
                            ?>
                            <span class="badge bg-<?= e($badge) ?> px-3 py-2">
                                <?= e($record['status'] ?? 'Active') ?>
                            </span>
                        </div>
                        <div class="card-body p-4">
                            
                            <div class="section-title">Personal Information</div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <div class="form-label">Full Name</div>
                                    <div class="info-box"><?= e($record['full_name']) ?></div>
                                </div>
                                <div class="col-12">
                                    <div class="form-label">Contact Number</div>
                                    <div class="info-box"><?= e($record['contact'] ?: 'Not provided') ?></div>
                                </div>
                                <div class="col-12">
                                    <div class="form-label">Address</div>
                                    <div class="info-box"><?= nl2br(e($record['address'] ?: 'Not provided')) ?></div>
                                </div>
                            </div>

                            <div class="section-title">Employment Details</div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <div class="form-label">Position</div>
                                    <div class="info-box"><?= e($record['position'] ?: 'Caretaker') ?></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-label">Shift</div>
                                    <div class="info-box"><?= e($record['shift']) ?></div>
                                </div>
                            </div>

                            <div class="section-title">Account Credentials</div>
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="form-label">Username</div>
                                    <div class="info-box"><?= e($record['username']) ?></div>
                                </div>
                            </div>

                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>