<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName        = trim($_POST['full_name'] ?? '');
    $username        = trim($_POST['username'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role            = $_POST['role'] ?? '';
    $acceptedTerms   = isset($_POST['accept_terms']);

    if ($fullName === '' || $username === '' || $email === '') {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
        $error = 'Username must be 3–30 characters and use only letters, numbers, dots, underscores, or hyphens.';
    } elseif (strlen($password) < 12) {
        $error = 'Your password must contain at least 12 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'The passwords do not match.';
    } elseif (!$acceptedTerms) {
        $error = 'You must read and accept the Terms and Conditions.';
    } elseif (!in_array($role, ['admin', 'caretaker'], true)) {
        $error = 'Please select a valid account type.';
    } else {
        try {
            // Check if username or email already exists
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $check->execute([$username, $email]);

            if ($check->fetch()) {
                $error = 'That username or email address is already registered.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $status = 'active';

                $insert = $pdo->prepare(
                    'INSERT INTO users (full_name, username, email, password, role, status)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );

                $insert->execute([
                    $fullName,
                    $username,
                    $email,
                    $passwordHash,
                    $role,
                    $status
                ]);

                $success = ucfirst($role) . ' account created successfully. You can now log in.';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'The account could not be created. Check your users table columns and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | Home for the Aged</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f6f8d6;
            font-family: Arial, sans-serif;
        }

        .register-card {
            max-width: 540px;
            margin: 35px auto;
            padding: 30px;
            background: white;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, .08);
        }

        .brand {
            color: #218838;
            font-weight: bold;
        }

        .btn-green {
            background: #33b960;
            color: white;
            border: none;
        }

        .btn-green:hover {
            background: #269b4b;
            color: white;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="register-card">
        <h2 class="brand text-center">Home for the Aged</h2>
        <h4 class="text-center mb-4">Create an Account</h4>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <a href="login.php" class="btn btn-green w-100">Go to Login</a>
        <?php else: ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label for="full_name" class="form-label">Full Name</label>
                <input type="text" class="form-control" id="full_name"
                       name="full_name" maxlength="100" required
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username"
                       name="username" minlength="3" maxlength="30" required
                       value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" class="form-control" id="email"
                       name="email" maxlength="255" required
                       value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="mb-3">
                <label for="role" class="form-label">Account Type</label>
                <select class="form-select" id="role" name="role" required>
                    <option value="" disabled <?= empty($_POST['role']) ? 'selected' : '' ?>>Choose account type</option>
                    <option value="admin" <?= (($_POST['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
                    <option value="caretaker" <?= (($_POST['role'] ?? '') === 'caretaker') ? 'selected' : '' ?>>Caretaker</option>
                </select>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password"
                       name="password" minlength="12" required>
                <div class="form-text">Use at least 12 characters.</div>
            </div>

            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <input type="password" class="form-control" id="confirm_password"
                       name="confirm_password" minlength="12" required>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox"
                       id="showPasswords" onclick="togglePasswords()">
                <label class="form-check-label" for="showPasswords">
                    Show passwords
                </label>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox"
                       id="accept_terms" name="accept_terms" value="1" required
                       <?= isset($_POST['accept_terms']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="accept_terms">
                    I have read and agree to the
                    <a href="../terms.php" target="_blank" rel="noopener">
                        Terms and Conditions
                    </a>.
                </label>
            </div>

            <button type="submit" class="btn btn-green w-100">
                Create Account
            </button>

            <p class="text-center mt-3 mb-0">
                Already have an account?
                <a href="login.php">Log in</a>
            </p>
        </form>

        <?php endif; ?>
    </div>
</div>

<script>
function togglePasswords() {
    const fields = [
        document.getElementById('password'),
        document.getElementById('confirm_password')
    ];

    const show = document.getElementById('showPasswords').checked;

    fields.forEach(function(field) {
        field.type = show ? 'text' : 'password';
    });
}
</script>

</body>
</html>