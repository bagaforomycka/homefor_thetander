<?php
session_start();

require_once __DIR__ . '/../config/db.php';

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

$errors = [];

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['add_caretaker_csrf'])) {
    $_SESSION['add_caretaker_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['add_caretaker_csrf'], $token)
    ) {
        $errors[] = 'Your session security token is invalid. Refresh the page and try again.';
    }

    $employeeId = trim($_POST['employee_id'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $position = trim($_POST['position'] ?? 'Caretaker');
    $shift = trim($_POST['shift'] ?? 'Morning');
    $employmentStatus = trim($_POST['status'] ?? 'Active');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (
        $employeeId === '' ||
        $firstName === '' ||
        $lastName === '' ||
        $username === '' ||
        $password === '' ||
        $confirmPassword === ''
    ) {
        $errors[] = 'Please fill in all required fields.';
    }

    if (!in_array($employmentStatus, ['Active', 'On Leave', 'Inactive'], true)) {
        $errors[] = 'Please select a valid employment status.';
    }

    if (!in_array($shift, ['Morning', 'Afternoon', 'Night', 'Rotating'], true)) {
        $errors[] = 'Please select a valid shift.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
        $errors[] = 'Username may contain only letters, numbers, dots, underscores, and hyphens.';
    }

    if (!$errors) {
        try {
            $checkUsername = $pdo->prepare(
                "SELECT username FROM users WHERE username = :username1
                 UNION
                 SELECT username FROM caretakers WHERE username = :username2"
            );

            $checkUsername->execute([
                'username1' => $username,
                'username2' => $username
            ]);

            if ($checkUsername->fetchColumn() !== false) {
                $errors[] = 'That username already exists. Please choose another.';
            }

            $checkEmployee = $pdo->prepare(
                "SELECT id FROM caretakers
                 WHERE employee_id = :employee_id
                 LIMIT 1"
            );

            $checkEmployee->execute(['employee_id' => $employeeId]);

            if ($checkEmployee->fetchColumn() !== false) {
                $errors[] = 'That Employee ID already exists.';
            }

            if (!$errors) {
                $fullName = trim($firstName . ' ' . $lastName);
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $userStatus = $employmentStatus === 'Active'
                    ? 'active'
                    : 'inactive';

                $pdo->beginTransaction();

                $insertCaretaker = $pdo->prepare(
                    "INSERT INTO caretakers (
                        employee_id, full_name, contact, address,
                        position, shift, status, username, password,
                        archived_at
                    ) VALUES (
                        :employee_id, :full_name, :contact, :address,
                        :position, :shift, :status, :username, :password,
                        NULL
                    )"
                );

                $insertCaretaker->execute([
                    'employee_id' => $employeeId,
                    'full_name' => $fullName,
                    'contact' => $contact,
                    'address' => $address,
                    'position' => $position,
                    'shift' => $shift,
                    'status' => $employmentStatus,
                    'username' => $username,
                    'password' => $hashedPassword
                ]);

                $insertUser = $pdo->prepare(
                    "INSERT INTO users
                        (full_name, username, password, role, status)
                     VALUES
                        (:full_name, :username, :password, :role, :status)"
                );

                $insertUser->execute([
                    'full_name' => $fullName,
                    'username' => $username,
                    'password' => $hashedPassword,
                    'role' => 'caretaker',
                    'status' => $userStatus
                ]);

                $pdo->commit();

                header('Location: caretakers_staff.php?created=1');
                exit;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Add caretaker error: ' . $exception->getMessage());

            $errors[] = 'The caretaker could not be saved. Please verify your database columns and try again.';
        }
    }
}

$selectedShift = $_POST['shift'] ?? 'Morning';
$selectedStatus = $_POST['status'] ?? 'Active';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Caretaker | Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f8f5;
            color: #304b3b;
            font-family: Arial, Helvetica, sans-serif;
            overflow-x: hidden;
        }

        /* SIDEBAR LOGO */
        .sidebar-logo {
            display: block;
            padding: 10px 4px 6px;
        }

        .sidebar-logo a {
            display: block;
        }

        .sidebar-logo img {
            display: block;
            width: 100%;
            max-width: 300px;
            height: auto;
            margin: 0 auto;
            transform: scale(1.1);
        }

        /* app-main is opened in sidebar.php */
        .app-main {
            margin-left: 250px !important;
            width: calc(100% - 250px) !important;
            max-width: none !important;
            min-width: 0;
            min-height: 100vh;
            padding: 24px !important;
            box-shadow: none !important;
            overflow-x: hidden;
        }

        .page-heading {
            width: 100%;
            background: #2d6042;
            color: #fff;
            border-radius: 12px;
            padding: 18px 20px;
            margin: 0 0 18px;
        }

        .page-heading h1 {
            color: #fff;
            font-size: 24px;
            font-weight: 600;
            margin: 0 0 4px;
        }

        .page-heading p {
            color: #fff;
            margin: 0;
            font-size: 14px;
        }

        .form-card {
            width: 100%;
            max-width: none;
            margin: 0 0 20px;
            background: #fff;
            border: 1px solid #e3eee6;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(24, 75, 42, 0.05);
            overflow: hidden;
        }

        .card-body {
            padding: 20px;
            background: #fff;
        }

        .section-title {
            width: 100%;
            color: #17683a;
            font-size: 16px;
            font-weight: 700;
            background: #edf8ef;
            border-left: 4px solid #28a745;
            border-bottom: 1px solid #dcecdf;
            padding: 10px 14px;
            margin: 0 0 16px;
        }

        .section-title:not(:first-child) {
            margin-top: 20px;
        }

        .form-label {
            color: #17683a;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 14px;
        }

        .form-control,
        .form-select {
            width: 100%;
            min-height: 38px;
            padding: 6px 12px;
            border-color: #cedbd1;
            font-size: 14px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #33b960;
            box-shadow: 0 0 0 0.2rem rgba(51, 185, 96, 0.15);
        }

        textarea.form-control {
            min-height: 65px;
        }

        .form-actions {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 20px;
        }

        .action-btn {
            min-height: 38px;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-save {
            background: #198754;
            border: 1px solid #198754;
            color: #fff;
        }

        .btn-save:hover,
        .btn-save:focus {
            background: #157347;
            border-color: #146c43;
            color: #fff;
        }

        .btn-cancel {
            background: #fff;
            border: 1px solid #87928b;
            color: #65716a;
        }

        .btn-cancel:hover {
            background: #f1f4f2;
            color: #304b3b;
        }

        .form-alert {
            margin-bottom: 14px;
        }

        .sidebar-link.active {
            box-shadow: none !important;
            outline: none !important;
        }

        @media (max-width: 760px) {
            .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 14px 10px !important;
            }

            .page-heading {
                padding: 14px;
            }

            .card-body {
                padding: 14px;
            }

            .form-actions {
                flex-direction: column-reverse;
                align-items: stretch;
            }

            .form-actions .action-btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<?php
// sidebar.php opens <main class="app-main">
require_once __DIR__ . '/../includes/sidebar.php';
?>

<header class="page-heading">
    <h1>Add Caretaker</h1>
    <p>Enter the caretaker's information below.</p>
</header>

<?php if ($errors): ?>
    <div class="alert alert-danger form-alert" role="alert">
        <strong>Please correct the following:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="form-card">
    <div class="card-body">

        <form method="POST" action="" autocomplete="off">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['add_caretaker_csrf']) ?>"
            >

            <h2 class="section-title">Personal Information</h2>

            <div class="row g-2">
                <div class="col-md-6">
                    <label for="employee_id" class="form-label">
                        Employee ID *
                    </label>
                    <input
                        type="text"
                        class="form-control"
                        id="employee_id"
                        name="employee_id"
                        value="<?= e($_POST['employee_id'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label for="first_name" class="form-label">
                        First Name *
                    </label>
                    <input
                        type="text"
                        class="form-control"
                        id="first_name"
                        name="first_name"
                        value="<?= e($_POST['first_name'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label for="last_name" class="form-label">
                        Last Name *
                    </label>
                    <input
                        type="text"
                        class="form-control"
                        id="last_name"
                        name="last_name"
                        value="<?= e($_POST['last_name'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label for="contact" class="form-label">
                        Contact Number
                    </label>
                    <input
                        type="text"
                        class="form-control"
                        id="contact"
                        name="contact"
                        value="<?= e($_POST['contact'] ?? '') ?>"
                    >
                </div>

                <div class="col-12">
                    <label for="address" class="form-label">Address</label>
                    <textarea
                        class="form-control"
                        id="address"
                        name="address"
                        rows="2"
                    ><?= e($_POST['address'] ?? '') ?></textarea>
                </div>
            </div>

            <h2 class="section-title">Employment Details</h2>

            <div class="row g-2">
                <div class="col-md-6">
                    <label for="position" class="form-label">Position</label>
                    <input
                        type="text"
                        class="form-control"
                        id="position"
                        name="position"
                        value="<?= e($_POST['position'] ?? 'Caretaker') ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label for="shift" class="form-label">Shift</label>
                    <select
                        class="form-select"
                        id="shift"
                        name="shift"
                        required
                    >
                        <?php foreach (['Morning', 'Afternoon', 'Night', 'Rotating'] as $option): ?>
                            <option
                                value="<?= e($option) ?>"
                                <?= $selectedShift === $option ? 'selected' : '' ?>
                            ><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="status" class="form-label">
                        Employment Status
                    </label>
                    <select
                        class="form-select"
                        id="status"
                        name="status"
                        required
                    >
                        <?php foreach (['Active', 'On Leave', 'Inactive'] as $option): ?>
                            <option
                                value="<?= e($option) ?>"
                                <?= $selectedStatus === $option ? 'selected' : '' ?>
                            ><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">
                        Only Active caretakers should be allowed to log in.
                    </small>
                </div>
            </div>

            <h2 class="section-title">Login Credentials</h2>

            <div class="row g-2">
                <div class="col-md-4">
                    <label for="username" class="form-label">
                        Username *
                    </label>
                    <input
                        type="text"
                        class="form-control"
                        id="username"
                        name="username"
                        value="<?= e($_POST['username'] ?? '') ?>"
                        autocomplete="off"
                        required
                    >
                </div>

                <div class="col-md-4">
                    <label for="password" class="form-label">
                        Password *
                    </label>
                    <div class="input-group password-group">
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                        <button
                            class="btn btn-outline-secondary"
                            type="button"
                            onclick="togglePassword('password', this)"
                            aria-label="Show or hide password"
                        >Show</button>
                    </div>
                    <small class="text-muted">At least 8 characters.</small>
                </div>

                <div class="col-md-4">
                    <label for="confirm_password" class="form-label">
                        Confirm Password *
                    </label>
                    <div class="input-group password-group">
                        <input
                            type="password"
                            class="form-control"
                            id="confirm_password"
                            name="confirm_password"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                        <button
                            class="btn btn-outline-secondary"
                            type="button"
                            onclick="togglePassword('confirm_password', this)"
                            aria-label="Show or hide confirmation password"
                        >Show</button>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="action-btn btn-save">
                    Save Caretaker
                </button>

                <a href="caretakers_staff.php" class="action-btn btn-cancel">
                    Cancel
                </a>
            </div>
        </form>

    </div>
</section>

<!-- Puts the logo at the top of the sidebar and hides the old text title -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector(
        '.app-sidebar, .sidebar, #sidebar, .sidebar-container, aside, nav'
    );

    if (!sidebar) {
        return;
    }

    var oldTexts = ['home for the aged', 'care management system'];

    sidebar.querySelectorAll('*').forEach(function (el) {
        if (
            el.children.length === 0 &&
            oldTexts.indexOf(el.textContent.trim().toLowerCase()) !== -1
        ) {
            el.style.display = 'none';
        }
    });

    if (!sidebar.querySelector('.sidebar-logo')) {
        var logo = document.createElement('div');
        logo.className = 'sidebar-logo';
        logo.innerHTML =
            '<a href="/homefor_thetander/admin/dashboard.php">' +
            '<img src="/homefor_thetander/images/white1.png" ' +
            'alt="Home for the Aged - Care, Comfort, Dignity">' +
            '</a>';

        sidebar.insertBefore(logo, sidebar.firstChild);
    }
});
</script>

<script>
function togglePassword(fieldId, button) {
    const field = document.getElementById(fieldId);

    if (field.type === 'password') {
        field.type = 'text';
        button.textContent = 'Hide';
    } else {
        field.type = 'password';
        button.textContent = 'Show';
    }
}
</script>

<!-- sidebar.php already opened <main class="app-main">, close it here -->
</main>
</body>
</html>