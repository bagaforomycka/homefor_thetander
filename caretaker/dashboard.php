
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Only logged-in caretaker accounts may access this page.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'caretaker'
) {
    header('Location: ../index.php');
    exit;
}

$username = $_SESSION['username'] ?? '';
$fullName = $_SESSION['full_name'] ?? 'Caretaker';

// Verify that the account is still active.
try {
    $stmt = $pdo->prepare(
        "SELECT full_name, status
         FROM users
         WHERE id = :id AND role = 'caretaker'
         LIMIT 1"
    );

    $stmt->execute(['id' => $_SESSION['user_id']]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account || strtolower((string)$account['status']) !== 'active') {
        $_SESSION = [];
        session_destroy();

        header('Location: ../index.php?error=account_inactive');
        exit;
    }

    $fullName = $account['full_name'] ?: $fullName;
} catch (Throwable $exception) {
    error_log('Caretaker dashboard error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to load the caretaker dashboard. Please check the database configuration.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Caretaker Dashboard | Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --green: #28a745;
            --dark-green: #17683a;
            --pale-green: #edf8ef;
            --text: #304b3b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f8f5;
            color: var(--text);
            font-family: Arial, sans-serif;
        }

        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 236px;
            background: var(--dark-green);
            color: #fff;
            padding: 25px 16px;
        }

        .brand {
            font-size: 21px;
            font-weight: 700;
            padding: 0 10px 25px;
            border-bottom: 1px solid rgba(255,255,255,.2);
            margin-bottom: 24px;
        }

        .sidebar-label {
            color: #d8efdd;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0 10px 10px;
        }

        .nav-link-custom {
            display: block;
            padding: 12px 14px;
            margin-bottom: 7px;
            color: #fff;
            text-decoration: none;
            border-radius: 7px;
        }

        .nav-link-custom.active,
        .nav-link-custom:hover {
            background: rgba(255,255,255,.16);
            color: #fff;
        }

        .main {
            margin-left: 236px;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .welcome-card {
            background: #fff;
            border: 1px solid #d9e4dc;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 4px 15px rgba(0,0,0,.05);
        }

        .welcome-card h2 {
            color: var(--dark-green);
            font-weight: 700;
        }

        .info-card {
            background: #fff;
            border: 1px solid #d9e4dc;
            border-radius: 10px;
            padding: 22px;
            height: 100%;
        }

        .info-card h5 {
            color: var(--dark-green);
            font-weight: 700;
        }

        .logout {
            background: var(--green);
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
        }

        .logout:hover {
            background: var(--dark-green);
            color: #fff;
        }

        @media (max-width: 768px) {
            .sidebar {
                position: static;
                width: 100%;
                min-height: auto;
            }

            .main {
                margin-left: 0;
                padding: 18px 12px;
            }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="brand">Home for the Aged</div>

    <div class="sidebar-label">Caretaker Menu</div>

    <a href="dashboard.php" class="nav-link-custom active">
        Dashboard
    </a>
</aside>

<main class="main">
    <div class="topbar">
        <div>
            <h3 class="mb-1">Caretaker Dashboard</h3>
            <p class="text-muted mb-0">Care, comfort, and dignity.</p>
        </div>

        <a href="../auth/logout.php" class="logout">
    <span aria-hidden="true"></span>
    Logout
</a>
    </div>

    <section class="welcome-card mb-4">
        <h2>Welcome, <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>!</h2>
        <p class="mb-0">
            You are signed in to your caretaker account. Use this page
            as your starting point for your caretaker activities.
        </p>
    </section>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="info-card">
                <h5>Resident Care</h5>
                <p class="mb-0">
                    Your caretaker workspace is ready. Resident management
                    links can be connected to your existing modules next.
                </p>
            </div>
        </div>

        <div class="col-md-6">
            <div class="info-card">
                <h5>Account Status</h5>
                <p class="mb-0">
                    Your account is active and you can access this dashboard.
                </p>
            </div>
        </div>
    </div>
</main>

</body>
</html>
