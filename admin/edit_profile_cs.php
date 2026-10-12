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

$errors = [];
$success = '';

// Fetch existing record
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

// Handle Form Submission for Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = trim($_POST['employee_id'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $department = trim($_POST['department'] ?? ''); // Only used if staff
    $shift = trim($_POST['shift'] ?? 'Morning');
    $status = trim($_POST['status'] ?? 'Active');
    $username = trim($_POST['username'] ?? '');

    if (empty($employee_id)) { $errors[] = 'Employee ID is required.'; }
    if (empty($first_name)) { $errors[] = 'First name is required.'; }
    if (empty($last_name)) { $errors[] = 'Last name is required.'; }
    if (empty($username)) { $errors[] = 'Username is required.'; }

    if (empty($errors)) {
        try {
            $full_name = $first_name . ' ' . $last_name;

            if ($tab === 'staff') {
                $sql = "UPDATE staff SET employee_id = ?, full_name = ?, contact = ?, address = ?, position = ?, department = ?, shift = ?, status = ?, username = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$employee_id, $full_name, $contact, $address, $position, $department, $shift, $status, $username, $id]);
            } else {
                $sql = "UPDATE caretakers SET employee_id = ?, full_name = ?, contact = ?, address = ?, position = ?, shift = ?, status = ?, username = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$employee_id, $full_name, $contact, $address, $position, $shift, $status, $username, $id]);
            }

            // Refresh record data after update
            $stmt = $pdo->prepare("SELECT * FROM {$tab} WHERE id = ?");
            $stmt->execute([$id]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            $success = 'Profile successfully updated!';
        } catch (PDOException $e) {
            $errors[] = 'Database Error: ' . $e->getMessage();
        }
    }
}

// Split full_name back into first and last name for the form fields
$nameParts = explode(' ', $record['full_name'], 2);
$firstNameVal = $nameParts[0] ?? '';
$lastNameVal = $nameParts[1] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile | Home for the Aged</title>
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
        .form-label {
            font-weight: 600;
            color: var(--green);
            font-size: 0.9rem;
        }
        .section-title {
            color: var(--green);
            border-bottom: 2px solid var(--pale-green);
            padding-bottom: 0.5rem;
            margin-bottom: 1.2rem;
            font-weight: bold;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>

    <!-- Include Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="row justify-content-center">
            <div class="col-xl-10">
                
                <div class="mb-3">
                    <a href="caretakers_staff.php?tab=<?= e($tab) ?>" class="btn btn-outline-success">&larr; Back to List</a>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <?= e($success) ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow">
                    <div class="card-header">
                        <h4 class="mb-0">Edit <?= ucfirst(e($tab)) ?> Profile</h4>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST">
                            
                            <h5 class="section-title">Personal Information</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Employee ID</label>
                                    <input type="text" class="form-control" name="employee_id" value="<?= e($record['employee_id']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">First Name</label>
                                    <input type="text" class="form-control" name="first_name" value="<?= e($firstNameVal) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" class="form-control" name="last_name" value="<?= e($lastNameVal) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Contact Number</label>
                                    <input type="text" class="form-control" name="contact" value="<?= e($record['contact']) ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Address</label>
                                    <textarea class="form-control" name="address" rows="2" required><?= e($record['address']) ?></textarea>
                                </div>
                            </div>

                            <h5 class="section-title">Employment Details</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-<?= $tab === 'staff' ? '4' : '6' ?>">
                                    <label class="form-label">Position</label>
                                    <input type="text" class="form-control" name="position" value="<?= e($record['position']) ?>" required>
                                </div>
                                <?php if ($tab === 'staff'): ?>
                                    <div class="col-md-4">
                                        <label class="form-label">Department</label>
                                        <input type="text" class="form-control" name="department" value="<?= e($record['department'] ?? '') ?>" required>
                                    </div>
                                <?php endif; ?>
                                <div class="col-md-<?= $tab === 'staff' ? '4' : '6' ?>">
                                    <label class="form-label">Shift</label>
                                    <select class="form-select" name="shift" required>
                                        <option value="Morning" <?= $record['shift'] === 'Morning' ? 'selected' : '' ?>>Morning</option>
                                        <option value="Night" <?= $record['shift'] === 'Night' ? 'selected' : '' ?>>Night</option>
                                        <option value="Rotating" <?= $record['shift'] === 'Rotating' ? 'selected' : '' ?>>Rotating</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status" required>
                                        <option value="Active" <?= $record['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                        <option value="On Leave" <?= $record['status'] === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                                        <option value="Inactive" <?= $record['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>

                            <h5 class="section-title">Account Credentials</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control" name="username" value="<?= e($record['username']) ?>" required>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between pt-2">
                                <a href="caretakers_staff.php?tab=<?= e($tab) ?>" class="btn btn-secondary px-4">Cancel</a>
                                <button type="submit" class="btn btn-success px-4">Update Profile</button>
                            </div>

                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>