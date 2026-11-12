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

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['add_caretaker_csrf'])) {
    $_SESSION['add_caretaker_csrf'] = bin2hex(random_bytes(32));
}

$errorMsg = '';
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['add_caretaker_csrf'], $csrf)) {
        $errorMsg = 'Invalid CSRF token.';
    } else {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name);
        $contact = trim($_POST['contact'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $position = trim($_POST['position'] ?? 'Caretaker');
        $shift = trim($_POST['shift'] ?? 'Morning');
        $status = trim($_POST['status'] ?? 'Active');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($first_name === '' || $last_name === '' || $username === '' || $password === '') {
            $errorMsg = 'Please fill in all required fields.';
        } elseif (strlen($password) < 8) {
            $errorMsg = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirm_password) {
            $errorMsg = 'Passwords do not match.';
        } else {
            try {
                // Check if username already exists
                $stmt = $pdo->prepare("SELECT id FROM caretakers WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $errorMsg = 'Username is already taken.';
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    $insert = $pdo->prepare("
                        INSERT INTO caretakers (full_name, contact, address, position, shift, status, username, password, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $insert->execute([$full_name, $contact, $address, $position, $shift, $status, $username, $hashed_password]);

                    $_SESSION['success_msg'] = 'Caretaker added successfully.';
                    header('Location: caretakers.php');
                    exit;
                }
            } catch (PDOException $e) {
                $errorMsg = 'Database error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Caretaker | Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --green: #17683a;
            --green-dark: #13522e;
            --section-bg: #e8f5eb;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
        }

        .main-content {
            padding: 20px 28px 32px;
            min-height: 100vh;
        }

        .page-banner {
            background-color: var(--section-bg);
            border: 1px solid #d1e7dd;
            color: var(--green);
            border-radius: 12px;
            padding: 20px 28px;
            margin-bottom: 20px;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.03);
        }

        .page-banner h2 {
            font-size: 1.45rem;
            font-weight: 700;
            margin: 0 0 2px 0;
            color: var(--green);
        }

        .page-banner p {
            font-size: 0.85rem;
            color: #4b5563;
            margin: 0;
        }

        .form-card {
            width: 100%;
            background: #fff;
            border: 1px solid #e3eee6;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.05);
            padding: 28px 32px;
            box-sizing: border-box;
            margin-bottom: 20px;
        }

        .section-header {
            background-color: var(--section-bg);
            color: var(--green);
            font-weight: 700;
            padding: 10px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }

        .form-label {
            font-weight: 700;
            color: var(--green);
            font-size: 0.85rem;
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #d1d5db;
            padding: 9px 12px;
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 0.2rem rgba(23, 104, 58, 0.18);
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-banner">
        <h2>Add Caretaker</h2>
        <p>Enter the caretaker's information below.</p>
    </div>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <?= e($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['add_caretaker_csrf']) ?>">

            <div class="section-header">Personal Information</div>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">First Name *</label>
                    <input type="text" name="first_name" class="form-control" required value="<?= e($_POST['first_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Last Name *</label>
                    <input type="text" name="last_name" class="form-control" required value="<?= e($_POST['last_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact" class="form-control" value="<?= e($_POST['contact'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= e($_POST['address'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="section-header">Employment Details</div>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Position</label>
                    <input type="text" name="position" class="form-control" value="<?= e($_POST['position'] ?? 'Caretaker') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Shift</label>
                    <select name="shift" class="form-select">
                        <option value="Morning" <?= (($_POST['shift'] ?? '') === 'Morning') ? 'selected' : '' ?>>Morning</option>
                        <option value="Night" <?= (($_POST['shift'] ?? '') === 'Night') ? 'selected' : '' ?>>Night</option>
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Employment Status</label>
                    <select name="status" class="form-select">
                        <option value="Active" <?= (($_POST['status'] ?? '') === 'Active') ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= (($_POST['status'] ?? '') === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <div class="form-text text-muted mt-1" style="font-size: 0.8rem;">Only Active caretakers should be allowed to log in.</div>
                </div>
            </div>

            <div class="section-header">Login Credentials</div>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label">Username *</label>
                    <input type="text" name="username" class="form-control" required value="<?= e($_POST['username'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Password *</label>
                    <div class="input-group">
                        <input type="password" name="password" id="password" class="form-control" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('password', this)">Show</button>
                    </div>
                    <div class="form-text" style="font-size: 0.75rem;">At least 8 characters.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Confirm Password *</label>
                    <div class="input-group">
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('confirm_password', this)">Show</button>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success px-4">Save Caretaker</button>
                <a href="caretakers.php" class="btn btn-outline-secondary px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
function togglePassword(fieldId, btn) {
    const field = document.getElementById(fieldId);
    if (field.type === 'password') {
        field.type = 'text';
        btn.textContent = 'Hide';
    } else {
        field.type = 'password';
        btn.textContent = 'Show';
    }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>