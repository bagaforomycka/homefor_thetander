
<?php
require_once __DIR__ . '/../config/db.php';

$totalResidents = 0;
$totalCaretakers = 0;
$totalStaff = 0;
$availableRooms = 0;
$residentsNeedingAttention = 0;

try {
    $totalResidents = (int) $pdo->query(
        "SELECT COUNT(*) FROM residents WHERE deleted_at IS NULL"
    )->fetchColumn();

    $totalCaretakers = (int) $pdo->query(
        "SELECT COUNT(*) FROM users
         WHERE role = 'caretaker' AND status = 'active'"
    )->fetchColumn();

    $totalStaff = (int) $pdo->query(
        "SELECT COUNT(*) FROM users
         WHERE role = 'staff' AND status = 'active'"
    )->fetchColumn();

    $availableRooms = (int) $pdo->query(
        "SELECT COUNT(*) FROM rooms WHERE status = 'Available'"
    )->fetchColumn();

    $residentsNeedingAttention = (int) $pdo->query(
        "SELECT COUNT(*) FROM residents
         WHERE status = 'needs_attention'
         AND deleted_at IS NULL"
    )->fetchColumn();

} catch (PDOException $e) {
    error_log('Dashboard error: ' . $e->getMessage());
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            margin: 0;
            background: #f5f7fa;
            font-family: Arial, sans-serif;
            color: #333;
        }

        .dashboard-content {
            padding: 30px;
        }

        .dashboard-title {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .dashboard-subtitle {
            color: #777;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 22px;
            height: 100%;
            border: 1px solid #e5e7eb;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.04);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
        }

        .stat-card p {
            color: #6c757d;
            margin-bottom: 12px;
            font-size: 15px;
        }

        .stat-card h3 {
            color: #198754 !important;
            font-size: 34px;
            font-weight: bold;
            margin-bottom: 18px;
        }

        .room-card-link {
            display: block;
            color: inherit;
            text-decoration: none;
            height: 100%;
        }

        .room-card-link:focus-visible {
            outline: 3px solid #198754;
            outline-offset: 3px;
            border-radius: 12px;
        }

        .manage-link {
            display: inline-block;
            color: #198754;
            font-weight: bold;
            font-size: 14px;
            text-decoration: none;
        }

        .manage-link:hover {
            color: #146c43;
            text-decoration: underline;
        }

        .section-title {
            font-size: 20px;
            font-weight: bold;
            margin: 32px 0 18px;
        }

        .quick-action {
            display: block;
            background: #198754;
            color: #fff;
            padding: 14px 18px;
            border-radius: 8px;
            text-decoration: none;
            text-align: center;
            font-weight: bold;
            transition: background 0.2s;
        }

        .quick-action:hover {
            background: #146c43;
            color: #fff;
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 18px;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<main class="dashboard-content">

    <h1 class="dashboard-title">Admin Dashboard</h1>

    <p class="dashboard-subtitle">
        Welcome to the Home for the Aged Management System.
    </p>

    <div class="row g-4">

        <!-- Total Residents: Entire card is clickable -->
        <div class="col-md-6 col-xl-4">
            <a href="residents/index.php" class="room-card-link">
                <div class="stat-card">
                    <p>Total Residents</p>

                    <h3><?= e($totalResidents) ?></h3>

                    <span class="manage-link">
                        Manage Residents &rarr;
                    </span>
                </div>
            </a>
        </div>

        <!-- Total Caretakers -->
        <div class="col-md-6 col-xl-4">
            <div class="stat-card">
                <p>Total Caretakers</p>

                <h3><?= e($totalCaretakers) ?></h3>

                <a href="caretakers/index.php" class="manage-link">
                    Manage Caretakers &rarr;
                </a>
            </div>
        </div>

        <!-- Total Staff -->
        <div class="col-md-6 col-xl-4">
            <div class="stat-card">
                <p>Total Staff</p>

                <h3><?= e($totalStaff) ?></h3>

                <a href="staff/index.php" class="manage-link">
                    Manage Staff &rarr;
                </a>
            </div>
        </div>

        <!-- Available Rooms: Entire card is clickable -->
        <div class="col-md-6 col-xl-4">
            <a href="rooms/index.php" class="room-card-link">
                <div class="stat-card">
                    <p>Available Rooms</p>

                    <h3><?= e($availableRooms) ?></h3>

                    <span class="manage-link">
                        Manage Rooms &rarr;
                    </span>
                </div>
            </a>
        </div>

        <!-- Residents Needing Attention -->
        <div class="col-md-6 col-xl-4">
            <div class="stat-card">
                <p>Residents Needing Attention</p>

                <h3><?= e($residentsNeedingAttention) ?></h3>

                <a href="residents/index.php" class="manage-link">
                    Manage Residents &rarr;
                </a>
            </div>
        </div>

    </div>

    <!-- Quick Actions -->
    <h2 class="section-title">Quick Actions</h2>

    <div class="row g-3">

        <div class="col-md-6 col-xl-3">
            <a href="residents/index.php" class="quick-action">
                Manage Residents
            </a>
        </div>

        <div class="col-md-6 col-xl-3">
            <a href="caretakers/index.php" class="quick-action">
                Manage Caretakers
            </a>
        </div>

        <div class="col-md-6 col-xl-3">
            <a href="rooms/index.php" class="quick-action">
                Manage Rooms
            </a>
        </div>

        <div class="col-md-6 col-xl-3">
            <a href="schedules/index.php" class="quick-action">
                Manage Schedules
            </a>
        </div>

    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
