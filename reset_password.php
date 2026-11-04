<?php
session_start();
require_once 'config/db.php';

$error = '';
$step = isset($_SESSION['reset_step']) ? $_SESSION['reset_step'] : 1;

// STEP 1: Verify Username and Admin Code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_verify_code'])) {
    $username = trim($_POST['username']);
    $code = trim($_POST['reset_code']);

    if (empty($username) || empty($code)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, pr.id AS reset_id 
            FROM users u
            JOIN password_resets pr ON u.id = pr.user_id
            WHERE u.username = :username 
              AND pr.reset_code = :code 
              AND pr.used = 0 
              AND pr.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute(['username' => $username, 'code' => $code]);
        $reset_request = $stmt->fetch();

        if ($reset_request) {
            $_SESSION['reset_user_id'] = $reset_request['id'];
            $_SESSION['reset_id'] = $reset_request['reset_id'];
            $_SESSION['reset_step'] = 2;
            $step = 2;
        } else {
            $error = "Invalid or expired username/code. Please ask an admin for a new code.";
        }
    }
}

// STEP 2: Update Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_password'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (!isset($_SESSION['reset_user_id'])) {
        $error = "Session expired. Please restart the process.";
        $step = 1;
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("UPDATE users SET password_hash = :password WHERE id = :id");
        $stmt->execute([
            'password' => $hashed_password,
            'id' => $_SESSION['reset_user_id']
        ]);

        $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = :id");
        $stmt->execute(['id' => $_SESSION['reset_id']]);

        unset($_SESSION['reset_user_id'], $_SESSION['reset_id'], $_SESSION['reset_step']);
        $step = 3;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
        body {
            /* This adds a soft dark tint over the picture so your white card stays easy to read */
            background: linear-gradient(rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.4)), 
                        url('images/aged-care-background.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .card-custom {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            background: rgba(255, 255, 255, 0.95);
        }
        .btn-primary-custom {
            background-color: #2ecc71;
            border-color: #2ecc71;
            color: #fff;
            font-weight: 600;
        }
        .btn-primary-custom:hover {
            background-color: #27ae60;
            border-color: #27ae60;
        }
        .brand-header {
            color: #1b5e20;
            font-weight: 700;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card card-custom p-4">
                <div class="text-center mb-4">
                    <h4 class="brand-header">⌂ Home for the Aged</h4>
                    <p class="text-muted small">Care Management System Password Recovery</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="action_verify_code" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Username</label>
                            <input type="text" name="username" class="form-control" placeholder="Enter your account username" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Admin Approval Code</label>
                            <input type="text" name="reset_code" class="form-control text-center fw-bold fs-5" maxlength="6" placeholder="6-Digit Code" required>
                            <div class="form-text small">Request a 6-digit code from your system administrator.</div>
                        </div>

                        <button type="submit" class="btn btn-primary-custom w-100 py-2 mb-3">Verify Code</button>
                        <div class="text-center">
                            <a href="index.php" class="text-decoration-none small text-secondary">← Back to Login</a>
                        </div>
                    </form>

                <?php elseif ($step === 2): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="action_update_password" value="1">

                        <div class="mb-3">
                            <label class="form-label fw-bold">New Password</label>
                            <input type="password" name="new_password" class="form-control" placeholder="At least 8 characters" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password" required>
                        </div>

                        <button type="submit" class="btn btn-primary-custom w-100 py-2 mb-3">Update Password</button>
                    </form>

                <?php elseif ($step === 3): ?>
                    <div class="text-center py-3">
                        <div class="text-success mb-3 fs-1">✓</div>
                        <h5 class="fw-bold">Password Updated!</h5>
                        <p class="text-muted small">Your account password has been successfully reset.</p>
                        <a href="index.php" class="btn btn-primary-custom w-100 py-2 mt-2">Proceed to Login</a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

</body>
</html>