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

$errors = [];
$success = '';

$values = [
    'employee_id' => '',
    'first_name' => '',
    'last_name' => '',
    'contact' => '',
    'address' => '',
    'position' => '',
    'department' => '',
    'date_hired' => '',
    'shift' => 'Morning',
    'status' => 'Active',
    'username' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $values[$key] = trim($_POST[$key]);
        }
    }

    if (empty($values['employee_id'])) {
        $errors[] = 'Employee ID is required.';
    }
    if (empty($values['first_name'])) {
        $errors[] = 'First name is required.';
    }
    if (empty($values['last_name'])) {
        $errors[] = 'Last name is required.';
    }
    if (empty($values['username'])) {
        $errors[] = 'Username is required.';
    }
    if (empty($_POST['password'])) {
        $errors[] = 'Password is required.';
    } elseif ($_POST['password'] !== $_POST['confirm_password']) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $full_name = $values['first_name'] . ' ' . $values['last_name'];

            $sql = "INSERT INTO staff (employee_id, full_name, contact, address, position, department, date_hired, shift, status, username, password) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $values['employee_id'],
                $full_name,
                $values['contact'],
                $values['address'],
                $values['position'],
                $values['department'],
                $values['date_hired'],
                $values['shift'],
                $values['status'],
                $values['username'],
                $password_hash
            ]);

            $success = 'Staff account successfully created!';
            
            // Clear form values after successful insert
            foreach ($values as $key => $val) {
                $values[$key] = '';
            }
        } catch (PDOException $e) {
            $errors[] = 'Database Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Staff | Home for the Aged</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-bg: #0d3b1e;
            --green: #33b960;
            --dark-green: #17683a;
            --pale-green: #edf8ef;
            --text: #304b3b;
        }

        body {
            background-color: #f8fbf8;
            color: var(--text);
            font-family: Arial, sans-serif;
            overflow-x: hidden;
        }

        .main-content {
            margin-left: 50px;
            padding: 30px;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 6px 15px rgba(51, 185, 96, 0.08);
        }

        .card-header {
            background-color: var(--sidebar-bg) !important;
            border-radius: 12px 12px 0 0 !important;
            padding: 1.2rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark-green);
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 0.25rem rgba(51, 185, 96, 0.25);
        }

        .section-title {
            color: var(--dark-green);
            border-bottom: 2px solid var(--pale-green);
            padding-bottom: 0.5rem;
            margin-bottom: 1.2rem;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .btn-success {
            background-color: var(--green);
            border-color: var(--green);
        }

        .btn-success:hover {
            background-color: var(--dark-green);
            border-color: var(--dark-green);
        }
    </style>
</head>
<body>

    <!-- Include Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="row justify-content-center">
            <div class="col-xl-10">

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
                    <div class="card-header text-white">
                        <h4 class="mb-0">Add Staff</h4>
                    </div>
                    <div class="card-body p-4">
                        <form action="add_staff.php" method="POST">
                            
                            <!-- Personal & Contact Information -->
                            <h5 class="section-title">Personal Information</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Employee ID</label>
                                    <input type="text" class="form-control" name="employee_id" value="<?= e($values['employee_id']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">First Name</label>
                                    <input type="text" class="form-control" name="first_name" value="<?= e($values['first_name']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" class="form-control" name="last_name" value="<?= e($values['last_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Contact Number</label>
                                    <input type="text" class="form-control" name="contact" value="<?= e($values['contact']) ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Address</label>
                                    <textarea class="form-control" name="address" rows="2" required><?= e($values['address']) ?></textarea>
                                </div>
                            </div>

                            <!-- Employment Information -->
                            <h5 class="section-title">Employment Details</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Position</label>
                                    <input type="text" class="form-control" name="position" value="<?= e($values['position']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Department</label>
                                    <input type="text" class="form-control" name="department" value="<?= e($values['department']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Date Hired</label>
                                    <input type="date" class="form-control" name="date_hired" value="<?= e($values['date_hired']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Shift</label>
                                    <select class="form-select" name="shift" required>
                                        <option value="Morning" <?= $values['shift'] === 'Morning' ? 'selected' : '' ?>>Morning</option>
                                        <option value="Night" <?= $values['shift'] === 'Night' ? 'selected' : '' ?>>Night</option>
                                        <option value="Rotating" <?= $values['shift'] === 'Rotating' ? 'selected' : '' ?>>Rotating</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status" required>
                                        <option value="Active" <?= $values['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                        <option value="On Leave" <?= $values['status'] === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                                        <option value="Inactive" <?= $values['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Account Credentials -->
                            <h5 class="section-title">Account Credentials</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control" name="username" value="<?= e($values['username']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Password</label>
                                    <input type="password" class="form-control" name="password" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Confirm Password</label>
                                    <input type="password" class="form-control" name="confirm_password" required>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="d-flex justify-content-between pt-2">
                                <a href="caretakers_staff.php?tab=staff" class="btn btn-secondary px-4">Back to List</a>
                                <button type="submit" class="btn btn-success px-4">Create Staff Account</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>