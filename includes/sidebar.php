
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

$currentRole = $_SESSION['role'];
$currentPage = basename($_SERVER['PHP_SELF']);

function sidebarLink(
    string $href,
    string $label,
    string $page,
    string $currentPage
): void {
    $active = ($page === $currentPage) ? 'active' : '';

    echo '<a class="sidebar-link ' . $active . '" href="' .
        htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' .
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
        '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --sidebar-width: 250px;
            --sidebar-green: #063b18;
            --page-bg: #f5f8f6;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--page-bg);
            font-family: Arial, sans-serif;
            color: #080c09;
        }

        .app-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            overflow-y: auto;
            padding: 24px 16px;
            background: var(--sidebar-green);
            color: white;
            z-index: 1000;
        }

        .sidebar-brand {
            font-size: 21px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .sidebar-subtitle {
            font-size: 13px;
            margin-bottom: 28px;
            opacity: .9;
        }

        .sidebar-section {
            margin: 20px 10px 8px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: .8;
        }

        .sidebar-link {
            display: block;
            color: white;
            text-decoration: none;
            padding: 11px 12px;
            border-radius: 8px;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .sidebar-link:hover,
        .sidebar-link.active {
            background: rgba(255, 255, 255, .22);
            color: white;
        }

        .app-main {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            min-height: 100vh;
            padding: 28px;
        }

        @media (max-width: 768px) {
            :root {
                --sidebar-width: 190px;
            }

            .app-sidebar {
                padding: 18px 10px;
            }

            .app-main {
                padding: 18px 12px;
            }
        }

        @media (max-width: 560px) {
            .app-sidebar {
                position: relative;
                width: 100%;
                height: auto;
            }

            .app-main {
                margin-left: 0;
                width: 100%;
                padding: 16px;
            }
        }
    </style>
</head>
<body>

<aside class="app-sidebar">
    <div class="sidebar-brand">Home for the Aged</div>
    <div class="sidebar-subtitle">Care Management System</div>

    <?php if (in_array($currentRole, ['admin', 'staff'], true)): ?>

        <div class="sidebar-section">Main Menu</div>

        <?php
        sidebarLink(
            '/homefor_thetander/admin/dashboard.php',
            'Dashboard',
            'dashboard.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/admin/residents.php',
            'Resident Management',
            'residents.php',
            $currentPage
        );
        ?>

        <div class="sidebar-section">Care Management</div>

        <?php
        sidebarLink(
            '/homefor_thetander/admin/rooms.php',
            'Rooms',
            'rooms.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/admin/health_records.php',
            'Health Records',
            'health_records.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/admin/caretakers_staff.php',
            'Caretakers ',
            'caretakers_staff.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/admin/schedules.php',
            'Schedules',
            'schedules.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/admin/messages.php',
            'Messages',
            'messages.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/admin/reports.php',
            'Reports',
            'reports.php',
            $currentPage
        );
        ?>

        <div class="sidebar-section">Administration</div>

        <?php
        sidebarLink(
            '/homefor_thetander/admin/settings.php',
            'Settings',
            'settings.php',
            $currentPage
        );
        ?>

        <div class="sidebar-section">Data Management</div>

        <?php
        sidebarLink(
            '/homefor_thetander/admin/archive.php',
            'Archive & Restore',
            'archive.php',
            $currentPage
        );
        ?>

    <?php elseif ($currentRole === 'caretaker'): ?>

        <div class="sidebar-section">Care Menu</div>

        <?php
        sidebarLink(
            '/homefor_thetander/caretaker/dashboard.php',
            'Dashboard',
            'dashboard.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/caretaker/my_residents.php',
            'My Residents',
            'my_residents.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/caretaker/care_tasks.php',
            'Care Tasks',
            'care_tasks.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/caretaker/my_schedule.php',
            'My Schedule',
            'my_schedule.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/caretaker/health_information.php',
            'Health Information',
            'health_information.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/caretaker/messages.php',
            'Messages',
            'messages.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/caretaker/care_reports.php',
            'Care Reports',
            'care_reports.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/caretaker/incident_reports.php',
            'Incident Reports',
            'incident_reports.php',
            $currentPage
        );

        sidebarLink(
            '/homefor_thetander/caretaker/profile.php',
            'My Profile',
            'profile.php',
            $currentPage
        );
        ?>

    <?php endif; ?>

    <div class="sidebar-section">Account</div>

    <?php
    sidebarLink(
        '/homefor_thetander/logout.php',
        'Logout',
        'logout.php',
        $currentPage
    );
    ?>
</aside>

<main class="app-main">
