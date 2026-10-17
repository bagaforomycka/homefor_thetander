
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    if ($username !== '' && !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
        $errors[] = 'Username may contain only letters, numbers, dots, underscores, and hyphens.';
    }

    if (!$errors) {
        try {
            $checkUsername = $pdo->prepare(
                "SELECT username
                 FROM users
                 WHERE username = :user_username
                 UNION
                 SELECT username
                 FROM caretakers
                 WHERE username = :caretaker_username"
            );

            $checkUsername->execute([
                'user_username' => $username,
                'caretaker_username' => $username
            ]);

            if ($checkUsername->fetch()) {
                $errors[] = 'That username already exists. Please choose another.';
            }

            $checkEmployee = $pdo->prepare(
                "SELECT id
                 FROM caretakers
                 WHERE employee_id = :employee_id
                 LIMIT 1"
            );

            $checkEmployee->execute([
                'employee_id' => $employeeId
            ]);

            if ($checkEmployee->fetch()) {
                $errors[] = 'That Employee ID already exists.';
            }

            if (!$errors) {
                $fullName = trim($firstName . ' ' . $lastName);
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $userStatus = ($employmentStatus === 'Active')
                    ? 'active'
                    : 'inactive';

                $pdo->beginTransaction();

                $insertCaretaker = $pdo->prepare(
                    "INSERT INTO caretakers
                    (
                        employee_id, full_name, contact, address,
                        position, shift, status, username, password,
                        archived_at
                    )
                    VALUES
                    (
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
            font-family: Arial, sans-serif;
        }

        /*
         * Assumes the sidebar is approximately 250px wide.
         * Adjust this value if your existing sidebar uses a different width.
         */



/* Main content positioning */
.page-content {
    margin-left: 236px;
    width: calc(100% - 236px);
    min-height: 100vh;
    padding: 12px 20px;
    box-sizing: border-box;
}

/* Center the white card and move it left */
.form-card {
    width: calc(100% - 40px);
    max-width: 1000px;
    margin: 8px auto 20px;
    position: relative;
    left: -35px;
    background: #ffffff;
    border: 1px solid #d9e4dc;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    box-sizing: border-box;
}

/* Main green title bar */
.card-heading {
    display: block;
    width: 100%;
    background: #17683a;
    color: #ffffff;
    padding: 18px 24px;
}

.card-heading h4 {
    margin: 0;
    color: #ffffff;
    font-size: 23px;
    font-weight: 700;
}

/* Restore green section heading bars */
.section-title {
    display: block;
    width: 100%;
    color: #17683a;
    font-size: 18px;
    font-weight: 700;
    background: #edf8ef;
    border-left: 5px solid #28a745;
    border-bottom: 1px solid #dcecdf;
    padding: 12px 15px;
    margin: 22px 0;
}

/* Form area */
.card-body {
    padding: 24px;
    background: #ffffff;
}

/* Green Save Caretaker button */
.btn-save {
    background-color: #28a745;
    border: 1px solid #28a745;
    color: #ffffff;
}

.btn-save:hover {
    background-color: #218838;
    border-color: #1e7e34;
    color: #ffffff;
}

@media (max-width: 768px) {
    .page-content {
        margin-left: 0;
        width: 100%;
        padding: 12px;
    }

    .form-card {
        width: 100%;
        max-width: 100%;
        left: 0;
        margin: 0 auto;
    }

    .card-body {
        padding: 18px;
    }
}


        .section-title {
            color: #17683a;
            font-size: 18px;
            font-weight: 700;
            border-bottom: 1px solid #dcecdf;
            padding-bottom: 10px;
            margin: 22px 0;
        }

        .form-label {
            color: #17683a;
            font-weight: 600;
        }

        .form-control,
        .form-select {
            min-height: 42px;
            border-color: #cedbd1;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #33b960;
            box-shadow: 0 0 0 0.2rem rgba(51, 185, 96, 0.15);
        }

        textarea.form-control {
            min-height: 75px;
        }

        /* Consistent sizing for all action buttons */
        .action-btn {
            min-height: 43px;
            padding: 10px 22px;
            border-radius: 6px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 0.15s ease;
        }

        /* Save Caretaker: green background and white text */
        .btn-save {
            background-color: #28a745;
            border: 1px solid #28a745;
            color: #ffffff;
        }

        .btn-save:hover,
        .btn-save:focus {
            background-color: #218838;
            border-color: #1e7e34;
            color: #ffffff;
        }

        /* Cancel: white background with green outline */
        .btn-cancel {
            background-color: #ffffff;
            border: 1px solid #17683a;
            color: #17683a;
        }

        .btn-cancel:hover {
            background-color: #edf8ef;
            border-color: #17683a;
            color: #17683a;
        }

        .password-group .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }

        .password-group .btn {
            min-width: 72px;
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        .form-alert {
            max-width: 1150px;
            margin: 0 auto 18px;
        }

        @media (max-width: 768px) {
            .page-content {
                margin-left: 0;
                padding: 18px 12px;
            }

            .card-body {
                padding: 18px;
            }

            .card-heading {
                padding: 17px 18px;
            }

            .form-actions {
                display: flex;
                flex-direction: column-reverse;
                gap: 10px;
            }

            .form-actions .action-btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<main class="page-content">

    <?php if ($errors): ?>
        <div class="alert alert-danger form-alert" role="alert">
            <strong>Please correct the following:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Only ONE card container: prevents nested white-card sizing issues -->
    <section class="form-card">

        <div class="card-heading">
            <h4>Add Caretaker</h4>
        </div>

        <div class="card-body">
            <form method="POST" action="" autocomplete="off">

                <h5 class="section-title">Personal Information</h5>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="employee_id" class="form-label">Employee ID *</label>
                        <input
                            type="text"
                            class="form-control"
                            id="employee_id"
                            name="employee_id"
                            value="<?= e($_POST['employee_id'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="col-md-4">
                        <label for="first_name" class="form-label">First Name *</label>
                        <input
                            type="text"
                            class="form-control"
                            id="first_name"
                            name="first_name"
                            value="<?= e($_POST['first_name'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="col-md-4">
                        <label for="last_name" class="form-label">Last Name *</label>
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
                        <label for="contact" class="form-label">Contact Number</label>
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

                <h5 class="section-title">Employment Details</h5>

                <div class="row g-3">
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
                        <select class="form-select" id="shift" name="shift" required>
                            <?php foreach (['Morning', 'Afternoon', 'Night', 'Rotating'] as $option): ?>
                                <option
                                    value="<?= e($option) ?>"
                                    <?= $selectedShift === $option ? 'selected' : '' ?>
                                ><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="status" class="form-label">Employment Status</label>
                        <select class="form-select" id="status" name="status" required>
                            <?php foreach (['Active', 'On Leave', 'Inactive'] as $option): ?>
                                <option
                                    value="<?= e($option) ?>"
                                    <?= $selectedStatus === $option ? 'selected' : '' ?>
                                ><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Only Active caretakers can log in.</small>
                    </div>
                </div>

                <h5 class="section-title">Login Credentials</h5>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="username" class="form-label">Username *</label>
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
                        <label for="password" class="form-label">Password *</label>
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
                            >Show</button>
                        </div>
                        <small class="text-muted">At least 8 characters.</small>
                    </div>

                    <div class="col-md-4">
                        <label for="confirm_password" class="form-label">Confirm Password *</label>
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
                            >Show</button>
                        </div>
                    </div>
                </div>

                <div class="form-actions d-flex flex-wrap justify-content-end gap-2 mt-4">
                    <a href="caretakers_staff.php" class="action-btn btn-cancel">
                        Cancel
                    </a>

                    <button type="submit" class="action-btn btn-save">
                        Save Caretaker
                    </button>
                </div>

            </form>
        </div>
    </section>
</main>

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

</body>
</html>
