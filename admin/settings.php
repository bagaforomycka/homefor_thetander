<?php
session_start();

/* DATABASE CONNECTION */
require_once __DIR__ . '/../config/db.php';

/* CHECK LOGIN */
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$userId = $_SESSION['user_id'];

$success = "";
$error = "";

/* GET USER INFORMATION */
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User account not found.");
}

/* UPDATE PROFILE */
if (isset($_POST['update_profile'])) {

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($username === "" || $email === "") {
        $error = "Please fill in all profile fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";

    } else {
        try {
            $update = $pdo->prepare(
                "UPDATE users SET username = ?, email = ? WHERE id = ?"
            );

            $update->execute([$username, $email, $userId]);

            $success = "Profile updated successfully!";

        } catch (PDOException $e) {
            $error = "Unable to update profile. Please check your database.";
        }
    }
}

/* CHANGE PASSWORD */
if (isset($_POST['change_password'])) {

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
        $error = "Please complete all password fields.";

    } elseif ($newPassword !== $confirmPassword) {
        $error = "New passwords do not match.";

    } elseif (strlen($newPassword) < 8) {
        $error = "Password must contain at least 8 characters.";

    } elseif (!password_verify($currentPassword, $user['password'])) {
        $error = "Your current password is incorrect.";

    } else {
        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $update = $pdo->prepare(
                "UPDATE users SET password = ? WHERE id = ?"
            );

            $update->execute([$hashedPassword, $userId]);

            $success = "Password changed successfully!";

        } catch (PDOException $e) {
            $error = "Unable to change password.";
        }
    }
}

/* REFRESH USER INFORMATION */
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

/* ESCAPE OUTPUT */
function clean($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Settings | Home for the Aged</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f4f8f5;
    font-family: Arial, Helvetica, sans-serif;
    color: #304b3b;
}

/* MAIN CONTENT */

.app-main {
    margin-left: 250px !important;
    width: calc(100% - 250px) !important;
    min-height: 100vh;
    padding: 25px !important;
}

/* PAGE HEADER */

.page-header {
    background: #edf8ef;
    border: 1px solid #dcecdf;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 22px;
}

.page-header h1 {
    color: #17683a;
    font-size: 25px;
    font-weight: bold;
    margin-bottom: 5px;
}

.page-header p {
    color: #718275;
    font-size: 14px;
    margin: 0;
}

/* SETTINGS CARD */

.settings-card {
    background: #ffffff;
    border: 1px solid #e1ebe3;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 22px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.04);
}

.card-title {
    color: #17683a;
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 5px;
}

.card-description {
    color: #89958c;
    font-size: 13px;
    margin-bottom: 22px;
}

/* PROFILE */

.profile-icon {
    width: 75px;
    height: 75px;
    background: #e5f3e8;
    color: #17683a;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    margin-bottom: 15px;
}

.form-label {
    color: #315b40;
    font-weight: 600;
    font-size: 14px;
}

.form-control {
    border: 1px solid #d4e1d7;
    border-radius: 7px;
    padding: 11px;
    font-size: 14px;
}

.form-control:focus {
    border-color: #198754;
    box-shadow: 0 0 0 0.15rem rgba(25,135,84,0.15);
}

/* BUTTONS */

.btn-save {
    background: #198754;
    color: white;
    border: none;
    border-radius: 7px;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 600;
}

.btn-save:hover {
    background: #146c43;
    color: white;
}

/* SYSTEM INFORMATION */

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 0;
    border-bottom: 1px solid #edf1ed;
    font-size: 14px;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    color: #718275;
}

.info-value {
    color: #17683a;
    font-weight: 600;
}

/* ALERT */

.alert {
    border-radius: 8px;
    font-size: 14px;
}

/* RESPONSIVE */

@media(max-width: 760px) {

    .app-main {
        margin-left: 0 !important;
        width: 100% !important;
        padding: 15px !important;
    }

    .settings-card {
        padding: 18px;
    }

}

</style>

</head>

<body>

<?php
/* SIDEBAR */
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- PAGE HEADER -->

<div class="page-header">

    <h1>
        <i class="fa-solid fa-gear"></i>
        Settings
    </h1>

    <p>Manage your account information and security settings.</p>

</div>

<!-- SUCCESS MESSAGE -->

<?php if ($success !== ""): ?>

<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <?= clean($success) ?>
</div>

<?php endif; ?>

<!-- ERROR MESSAGE -->

<?php if ($error !== ""): ?>

<div class="alert alert-danger">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?= clean($error) ?>
</div>

<?php endif; ?>

<!-- PROFILE SETTINGS -->

<div class="settings-card">

    <div class="card-title">
        <i class="fa-solid fa-user"></i>
        Profile Settings
    </div>

    <div class="card-description">
        Update your personal account information.
    </div>

    <div class="profile-icon">
        <i class="fa-solid fa-user"></i>
    </div>

    <form method="POST" action="settings.php">

        <div class="row">

            <div class="col-md-6 mb-3">

                <label class="form-label">Username</label>

                <input
                    type="text"
                    name="username"
                    class="form-control"
                    value="<?= clean($user['username'] ?? '') ?>"
                    required
                >

            </div>

            <div class="col-md-6 mb-3">

                <label class="form-label">Email Address</label>

                <input
                    type="email"
                    name="email"
                    class="form-control"
                    value="<?= clean($user['email'] ?? '') ?>"
                    required
                >

            </div>

        </div>

        <button type="submit" name="update_profile" class="btn btn-save">
            <i class="fa-solid fa-floppy-disk"></i>
            Save Changes
        </button>

    </form>

</div>

<!-- PASSWORD SETTINGS -->

<div class="settings-card">

    <div class="card-title">
        <i class="fa-solid fa-lock"></i>
        Change Password
    </div>

    <div class="card-description">
        Update your password to keep your account secure.
    </div>

    <form method="POST" action="settings.php">

        <div class="mb-3">

            <label class="form-label">Current Password</label>

            <input
                type="password"
                name="current_password"
                class="form-control"
                placeholder="Enter current password"
                required
            >

        </div>

        <div class="mb-3">

            <label class="form-label">New Password</label>

            <input
                type="password"
                name="new_password"
                class="form-control"
                placeholder="Enter new password"
                minlength="8"
                required
            >

        </div>

        <div class="mb-3">

            <label class="form-label">Confirm New Password</label>

            <input
                type="password"
                name="confirm_password"
                class="form-control"
                placeholder="Confirm new password"
                minlength="8"
                required
            >

        </div>

        <button type="submit" name="change_password" class="btn btn-save">
            <i class="fa-solid fa-key"></i>
            Update Password
        </button>

    </form>

</div>

<!-- SYSTEM INFORMATION -->

<div class="settings-card">

    <div class="card-title">
        <i class="fa-solid fa-circle-info"></i>
        System Information
    </div>

    <div class="card-description">
        Information about the Home for the Aged system.
    </div>

    <div class="info-row">
        <span class="info-label">System Name</span>
        <span class="info-value">Home for the Aged</span>
    </div>

    <div class="info-row">
        <span class="info-label">System Version</span>
        <span class="info-value">Version 1.0</span>
    </div>

    <div class="info-row">
        <span class="info-label">Account Role</span>
        <span class="info-value">
            <?= clean($_SESSION['role'] ?? 'User') ?>
        </span>
    </div>

    <div class="info-row">
        <span class="info-label">Account ID</span>
        <span class="info-value">
            <?= clean($userId) ?>
        </span>
    </div>

</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>