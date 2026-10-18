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

    <!-- Bootstrap 5 CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --sidebar-width: 250px;
            --sidebar-green: #063b18;
            --sidebar-active-hover: rgba(255, 255, 255, 0.22);
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

        /* Top Bar Header for Mobile (Hidden on Desktop) */
        .mobile-navbar {
            display: none;
            background-color: var(--sidebar-green);
            padding: 12px 16px;
            position: sticky;
            top: 0;
            z-index: 1050;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Sidebar Container */
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
            transition: background-color 0.2s ease;
        }

        .sidebar-link:hover,
        .sidebar-link.active {
            background: var(--sidebar-active-hover);
            color: white;
        }

        /* Main Content Panel */
        .app-main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: 28px;
        }

        /* ==========================================================
           RESPONSIVE / MEDIA QUERIES FOR PHONES & TABLETS
           ========================================================== */
        @media (max-width: 991.98px) {
            /* Show Mobile Navbar Header */
            .mobile-navbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
            }

            /* Convert Sidebar into Collapsible Menu */
            .app-sidebar {
                position: relative;
                width: 100%;
                height: auto;
                padding: 16px;
            }

            /* Reset Main Layout Margin */
            .app-main {
                margin-left: 0 !important;
                width: 100%;
                padding: 18px 12px;
            }
        }
    </style>
</head>
<body>

<!-- Mobile Header Bar (Only visible on screens smaller than 992px) -->
<header class="mobile-navbar">
    <div>
        <div class="fw-bold text-white fs-6">Home for the Aged</div>
        <div class="text-white-50 small" style="font-size: 11px;">Care Management System</div>
    </div>
    <button class="navbar-toggler border-0 text-white" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMobileCollapse" aria-controls="sidebarMobileCollapse" aria-expanded="false" aria-label="Toggle navigation">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor" class="bi bi-list" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5z"/>
        </svg>
    </button>
</header>

<!-- Collapsible Sidebar Wrapper -->
<div class="collapse d-lg-block" id="sidebarMobileCollapse">
    <aside class="app-sidebar">
        <!-- Desktop Header Brand (Hidden on Mobile top header) -->
        <div class="d-none d-lg-block">
            <div class="sidebar-brand">Home for the Aged</div>
            <div class="sidebar-subtitle">Care Management System</div>
        </div>

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
                'Caretakers',
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
</div>

<!-- Main Page Content Container Starts Here -->
<main class="app-main">

<!-- Bootstrap 5 JS Bundle for Hamburger Collapse functionality -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>