
<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Only an authenticated administrator may create accounts.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    http_response_code(403);
    exit('Access denied. Admin login required.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Create Caretaker</title>
    </head>
    <body>
        <h2>Create Caretaker Account</h2>
        <form method="post">
            <label>Full name</label><br>
            <input name="full_name" required maxlength="150"><br><br>

            <label>Username</label><br>
            <input name="username" required maxlength="100"><br><br>

            <label>Email (optional)</label><br>
            <input type="email" name="email" maxlength="150"><br><br>

            <label>Temporary password</label><br>
            <input type="password" name="password"
                   required minlength="12"><br><br>

            <button type="submit">Create Account</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

$full_name = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (
    $full_name === '' ||
    $username === '' ||
    strlen($password) < 12 ||
    ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
) {
    exit('Invalid details. Check the name, username, email and password.');
}

try {
    $check = $pdo->prepare(
        'SELECT id FROM users WHERE username = ?'
    );
    $check->execute([$username]);

    if ($check->fetch()) {
        exit('That username already exists.');
    }

    if ($email !== '') {
        $checkEmail = $pdo->prepare(
            'SELECT id FROM users WHERE email = ?'
        );
        $checkEmail->execute([$email]);

        if ($checkEmail->fetch()) {
            exit('That email is already in use.');
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $insert = $pdo->prepare(
        "INSERT INTO users
         (full_name, username, email, password, role, status)
         VALUES (?, ?, ?, ?, 'caretaker', 'active')"
    );

    $insert->execute([
        $full_name,
        $username,
        $email !== '' ? $email : null,
        $hash
    ]);

    echo 'Caretaker account created successfully.';

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo 'Unable to create the account. Check the server error log.';
}
