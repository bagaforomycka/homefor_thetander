
<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$error = '';
$roleSelected = '';
$usernameEntered = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameEntered = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $roleSelected = $_POST['role'] ?? '';
    $acceptedTerms = ($_POST['accept_terms'] ?? '') === '1';

    if (!$acceptedTerms) {
        $error = 'Please agree to the Terms and Conditions to continue.';
    } elseif (
        $usernameEntered === '' ||
        $password === '' ||
        !in_array($roleSelected, ['admin', 'caretaker'], true)
    ) {
        $error = 'Please enter your username, password, and role.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, username, password, role
             FROM users
             WHERE username = :username
               AND role = :role
               AND status = 'active'
             LIMIT 1"
        );

        $stmt->execute([
            'username' => $usernameEntered,
            'role' => $roleSelected
        ]);

        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

            if ($user['role'] === 'caretaker') {
                header('Location: ../caretaker/dashboard.php');
            } else {
                header('Location: ../admin/dashboard.php');
            }
            exit;
        }

        $error = 'Invalid username, password, or role.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Home for the Aged</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f2f8f3;
            font-family: Arial, sans-serif;
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            border: none;
            border-radius: 16px;
        }

        .brand {
            color: #218c46;
            font-weight: bold;
        }

        .btn-green {
            background: #33b960;
            color: white;
            border: none;
        }

        .btn-green:hover {
            background: #218c46;
            color: white;
        }

        a {
            color: #218c46;
        }
    </style>
</head>
<body>

<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="card login-card shadow-sm p-4 p-md-5">

        <div class="text-center mb-4">
            <div class="fs-1">💚</div>
            <h1 class="h3 brand">Home for the Aged</h1>
            <p class="text-secondary">Sign in to your account</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label for="role" class="form-label">Account Role</label>
                <select class="form-select" id="role" name="role" required>
                    <option value="">Select your role</option>
                    <option value="admin"
                        <?= $roleSelected === 'admin' ? 'selected' : '' ?>>
                        Admin
                    </option>
                   
                    <option value="caretaker"
                        <?= $roleSelected === 'caretaker' ? 'selected' : '' ?>>
                        Caretaker
                    </option>
                </select>
            </div>

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input class="form-control"
                       type="text"
                       id="username"
                       name="username"
                       value="<?= htmlspecialchars($usernameEntered, ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="username"
                       required>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <input class="form-control"
                           type="password"
                           id="password"
                           name="password"
                           autocomplete="current-password"
                           required>
                    <button class="btn btn-outline-secondary"
                            type="button"
                            id="togglePassword"
                            aria-pressed="false">
                        Show
                    </button>
                </div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input"
                       type="checkbox"
                       id="accept_terms"
                       name="accept_terms"
                       value="1"
                       required>
                <label class="form-check-label" for="accept_terms">
                    I agree to the
                    <a href="../terms.php" target="_blank" rel="noopener">
                        Terms and Conditions
                    </a>.
                </label>
            </div>

            <button class="btn btn-green w-100 py-2" type="submit">
                Log In
            </button>

            
            <p class="text-center text-secondary small mt-4 mb-0">
                Authorized users only.
            </p>
        </form>
    </div>
</div>

<script>
const passwordInput = document.getElementById('password');
const toggleButton = document.getElementById('togglePassword');

toggleButton.addEventListener('click', function () {
    const showPassword = passwordInput.type === 'password';

    passwordInput.type = showPassword ? 'text' : 'password';
    toggleButton.textContent = showPassword ? 'Hide' : 'Show';
    toggleButton.setAttribute('aria-pressed', String(showPassword));
});
</script>

</body>
</html>
