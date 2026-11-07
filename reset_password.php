<?php
session_start();
require_once 'config/db.php';

$error = '';
$step = isset($_SESSION['reset_step']) ? $_SESSION['reset_step'] : 1;

// PHASE 1: User requests a code by typing their username
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request_code'])) {
    $username = trim($_POST['username']);

    if (empty($username)) {
        $error = "Please enter your username.";
    } else {
        // Check if user exists
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user) {
            $userId = isset($user['id']) ? $user['id'] : (isset($user['user_id']) ? $user['user_id'] : null);
            
            if ($userId) {
                // Generate a random 6-digit code
                $code = sprintf("%06d", mt_rand(0, 999999));
                // Set expiration: 30 minutes from now
                $expiresAt = date('Y-m-d H:i:s', time() + (30 * 60));

                // Save code to password_resets table
                $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, reset_code, expires_at, used) VALUES (:user_id, :code, :expires_at, 0)");
                $stmt->execute([
                    'user_id' => $userId,
                    'code' => $code,
                    'expires_at' => $expiresAt
                ]);

                // Store in session for testing
                $_SESSION['temp_generated_code'] = $code;
                $_SESSION['request_username'] = $username;
                $_SESSION['reset_step'] = 1.5;
                $step = 1.5;
            } else {
                $error = "Could not identify user ID column in database.";
            }
        } else {
            $error = "Username not found in the database.";
        }
    }
}

// PHASE 2: Verify the Admin Code entered
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_verify_code'])) {
    $username = trim($_POST['username']);
    $code = trim($_POST['reset_code']);

    if (empty($username) || empty($code)) {
        $error = "Missing username or reset code.";
        $step = 1;
    } else {
        // Fetch matching active reset request
        $stmt = $pdo->prepare("
            SELECT u.id AS u_id, pr.id AS reset_id, pr.expires_at 
            FROM users u
            JOIN password_resets pr ON u.id = pr.user_id
            WHERE u.username = :username 
              AND pr.reset_code = :code 
              AND pr.used = 0
            ORDER BY pr.id DESC
            LIMIT 1
        ");
        $stmt->execute(['username' => $username, 'code' => $code]);
        $reset_request = $stmt->fetch();

        if ($reset_request) {
            $currentTime = time();
            $expirationTime = strtotime($reset_request['expires_at']);

            if ($expirationTime >= $currentTime) {
                $_SESSION['reset_user_id'] = $reset_request['u_id'];
                $_SESSION['reset_id'] = $reset_request['reset_id'];
                $_SESSION['reset_step'] = 2;
                $step = 2;
            } else {
                $error = "This code has expired. Please request a new one.";
                $_SESSION['reset_step'] = 1;
                $step = 1;
            }
        } else {
            $error = "Invalid code or user match found.";
            $_SESSION['reset_step'] = 1;
            $step = 1;
        }
    }
}

// PHASE 3: Update Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_password'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters long.";
        $step = 2;
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
        $step = 2;
    } elseif (!isset($_SESSION['reset_user_id'])) {
        $error = "Session lost. Please try requesting a code again.";
        $step = 1;
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

        // Update password using 'password' column matching your database table
        $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
        $stmt->execute([
            'password' => $hashed_password,
            'id' => $_SESSION['reset_user_id']
        ]);

        // Mark code as used
        $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = :id");
        $stmt->execute(['id' => $_SESSION['reset_id']]);

        // Clean up session
        unset($_SESSION['temp_generated_code'], $_SESSION['request_username'], $_SESSION['reset_user_id'], $_SESSION['reset_id'], $_SESSION['reset_step']);
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
    /* Same background as Terms and Conditions */
    background:
        linear-gradient(
            rgba(0, 0, 0, 0.4),
            rgba(0, 0, 0, 0.4)
        ),
        url('images/aged-care-background.jpg')
        no-repeat center center fixed;

    background-size: cover;
    background-position: center;
    background-attachment: fixed;

    min-height: 100vh;
    margin: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

       
.card-custom {
    border: none;
    border-radius: 1rem;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    background: rgba(255, 255, 255, 0.93);
    backdrop-filter: blur(8px);
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
                    <h4 class="brand-header">Home for the Aged</h4>
                    <p class="text-muted small">Care Management System Password Recovery</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <!-- STEP 1: REQUEST CODE -->
                <?php if ($step === 1): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="action_request_code" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Enter Your Username</label>
                            <input type="text" name="username" class="form-control" placeholder="Your account username" required>
                            <div class="form-text small">This will log a reset request for your administrator.</div>
                        </div>

                        <button type="submit" class="btn btn-primary-custom w-100 py-2 mb-3">Request Reset Code</button>
                        <div class="text-center">
                            <a href="index.php" class="text-decoration-none small text-secondary">Back to Login</a>
                        </div>
                    </form>

                <!-- STEP 1.5: SHOW GENERATED CODE -->
                <?php elseif ($step === 1.5): ?>
                    <div class="text-center">
                        <div class="alert alert-success py-2 mb-3">
                            <h6 class="fw-bold mb-1">Request Logged!</h6>
                            <p class="small mb-0">Provide this code to your admin or use it below:</p>
                        </div>
                        <div class="bg-light p-3 rounded mb-3 border">
                            <span class="text-muted small d-block">Your Temporary Code:</span>
                            <span class="fs-3 fw-bold text-success"><?= htmlspecialchars($_SESSION['temp_generated_code']) ?></span>
                        </div>

                        <form method="POST" action="">
                            <input type="hidden" name="action_verify_code" value="1">
                            <input type="hidden" name="username" value="<?= htmlspecialchars($_SESSION['request_username']) ?>">
                            <input type="hidden" name="reset_code" value="<?= htmlspecialchars($_SESSION['temp_generated_code']) ?>">
                            <button type="submit" class="btn btn-primary-custom w-100 py-2">Proceed with Code</button>
                        </form>
                    </div>

                <!-- STEP 2: SET NEW PASSWORD -->
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

                <!-- STEP 3: SUCCESS -->
                <?php elseif ($step === 3): ?>
                    <div class="text-center py-3">
                        <h5 class="fw-bold text-success mb-2">Password Updated</h5>
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