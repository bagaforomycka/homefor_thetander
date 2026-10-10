
<?php
session_start();

if (!in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    header('Location: ../auth/login.php');
    exit;
}

$fullName = htmlspecialchars(
    $_SESSION['full_name'] ?? 'Team Member',
    ENT_QUOTES,
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Home for the Aged</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
</head>
<body>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold">Dashboard</h1>
        <p class="text-secondary mb-0">Welcome back, <?= $fullName ?>.</p>
    </div>
    <span class="badge text-bg-success">
        <?= htmlspecialchars(ucfirst($_SESSION['role']), ENT_QUOTES, 'UTF-8') ?>
    </span>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="page-card">
            <p class="text-secondary mb-2">Total Residents</p>
            <h2 class="h3 mb-0">—</h2>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="page-card">
            <p class="text-secondary mb-2">Active Caretakers</p>
            <h2 class="h3 mb-0">—</h2>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="page-card">
            <p class="text-secondary mb-2">Today's Tasks</p>
            <h2 class="h3 mb-0">—</h2>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="page-card">
            <p class="text-secondary mb-2">Pending Reports</p>
            <h2 class="h3 mb-0">—</h2>
        </div>
    </div>
</div>

<div class="page-card">
    <h2 class="h5 fw-bold">Quick Overview</h2>
    <p class="text-secondary mb-0">
        Your resident records, schedules, care activities, and reports
        will appear here as those features are connected to the database.
    </p>
</div>

</main>
</div>
</body>
</html>
