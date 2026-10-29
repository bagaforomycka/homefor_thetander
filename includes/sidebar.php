
<?php
/**
 * Home for the Aged
 * Shared Sidebar for Admin and Caretaker
 * File: includes/sidebar.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

$currentRole = strtolower($_SESSION['role']);
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

if (!function_exists('sidebarLink')) {
    function sidebarLink(
        string $href,
        string $label,
        string $page,
        string $currentPage
    ): void {
        $active = ($page === $currentPage) ? ' active' : '';

        echo '<a class="sidebar-link' . $active . '" href="' .
            htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' .
            '<span class="sidebar-link-label">' .
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
            '</span></a>';
    }
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
            --sidebar-active: rgba(255, 255, 255, 0.18);
            --sidebar-hover: rgba(255, 255, 255, 0.10);
            --sidebar-divider: rgba(255, 255, 255, 0.23);
            --sidebar-heading: #c4d5c8;
            --page-bg: #f5f8f6;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--page-bg);
            color: #080c09;
            font-family: Arial, sans-serif;
        }

        /* ==========================
           MOBILE HEADER
        ========================== */

        .mobile-navbar {
            display: none;
            align-items: center;
            justify-content: space-between;
            background: var(--sidebar-green);
            padding: 12px 16px;
            position: sticky;
            top: 0;
            z-index: 1050;
        }

        /* ==========================
           SHARED SIDEBAR
           Identical for both roles
        ========================== */

        .app-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;

            width: var(--sidebar-width);
            height: 100vh;

            overflow-y: auto;
            overflow-x: hidden;

            padding: 16px 12px;

            background: var(--sidebar-green);
            color: #ffffff;

            z-index: 1000;

            font-family: Arial, sans-serif;
            font-size: 14px;

            scrollbar-width: thin;
            scrollbar-color: #42654b var(--sidebar-green);
        }

        .app-sidebar,
        .app-sidebar * {
            box-sizing: border-box;
        }

        /* BRAND */

        .sidebar-brand {
            margin: 3px 0 6px;
            color: #ffffff;
            font-size: 21px;
            font-weight: 700;
            line-height: 1.4;
        }

        .sidebar-subtitle {
            margin-bottom: 12px;
            padding-bottom: 11px;

            color: #ffffff;
            opacity: 0.85;

            font-size: 12px;
            line-height: 1.4;

            border-bottom: 1px solid var(--sidebar-divider);
        }

        /* CATEGORY HEADINGS */

        .sidebar-section {
            margin: 14px 0 5px;
            padding: 12px 0 5px;

            border-top: 1px solid var(--sidebar-divider);

            color: var(--sidebar-heading);

            font-size: 11px;
            font-weight: 700;
            line-height: 1.4;

            text-transform: uppercase;
            letter-spacing: 0.35px;
        }

        .sidebar-subtitle + .sidebar-section {
            border-top: none;
            padding-top: 0;
        }

        /* NAVIGATION LINKS */

        .sidebar-link {
            display: flex;
            align-items: center;

            width: 100%;
            min-height: 42px;

            margin-bottom: 3px;
            padding: 11px 9px;

            border-radius: 8px;

            color: #ffffff;
            text-decoration: none;

            font-size: 14px;
            font-weight: 400;
            line-height: 1.4;

            transition: background-color 0.2s ease;
        }

        .sidebar-link-label {
            display: block;
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #ffffff;
        }

        .sidebar-link.active {
            background: var(--sidebar-active);
            color: #ffffff;
            font-weight: 600;
        }

        /* ACCOUNT */

        .sidebar-account {
            margin-top: 18px;
            padding-top: 10px;
            border-top: 1px solid var(--sidebar-divider);
        }

        .sidebar-user {
            padding: 8px 9px;
            color: #ffffff;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .sidebar-user-role {
            display: block;
            margin-top: 3px;
            color: var(--sidebar-heading);
            font-size: 11px;
        }

        /* ==========================
           MAIN CONTENT
        ========================== */

        .app-main {
            width: calc(100% - var(--sidebar-width));
            min-height: 100vh;
            margin-left: var(--sidebar-width);
            padding: 28px;
        }

        /* ==========================
           RESPONSIVE LAYOUT
        ========================== */

        @media (max-width: 991.98px) {
            .mobile-navbar {
                display: flex;
            }

            .app-sidebar {
                position: relative;
                top: auto;
                left: auto;
                bottom: auto;

                width: 100%;
                height: auto;
                max-height: none;

                padding: 16px;
            }

            .app-main {
                width: 100%;
                margin-left: 0;
                padding: 18px 12px;
            }
        }
    </style>
</head>

<body>

<!-- MOBILE HEADER -->
<header class="mobile-navbar">
    <div>
        <div class="fw-bold text-white">
            Home for the Aged
        </div>

        <div class="text-white-50" style="font-size: 11px;">
            Care Management System
        </div>
    </div>

    <button
        class="btn btn-sm btn-outline-light"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#sidebarMobileCollapse"
        aria-controls="sidebarMobileCollapse"
        aria-expanded="false"
        aria-label="Toggle navigation"
    >
        Menu
    </button>
</header>

<!-- SIDEBAR -->
<div class="collapse d-lg-block" id="sidebarMobileCollapse">

    <aside class="app-sidebar">

        <!-- BRAND -->
        <div class="d-none d-lg-block">
            <div class="sidebar-brand">
                Home for the Aged
            </div>

            <div class="sidebar-subtitle">
                CARE MANAGEMENT SYSTEM
            </div>
        </div>

        <?php if ($currentRole === 'admin'): ?>

            <!-- ==========================
                 ADMIN NAVIGATION
            =========================== -->

            <div class="sidebar-section">Main Menu</div>

            <?php
            sidebarLink(
                '/homefor_thetander/admin/dashboard.php',
                'Dashboard',
                'dashboard.php',
                $currentPage
            );
            ?>

            <div class="sidebar-section">Resident Management</div>

            <?php
            sidebarLink(
                '/homefor_thetander/admin/residents.php',
                'Residents',
                'residents.php',
                $currentPage
            );

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
            ?>

            <div class="sidebar-section">People Management</div>

            <?php
            sidebarLink(
                '/homefor_thetander/admin/caretakers_staff.php',
                'Caretakers',
                'caretakers_staff.php',
                $currentPage
            );
            ?>

            <div class="sidebar-section">Operations</div>

            <?php
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
            ?>

            <div class="sidebar-section">Monitoring</div>

            <?php
            sidebarLink(
                '/homefor_thetander/admin/reports.php',
                'Reports',
                'reports.php',
                $currentPage
            );

            sidebarLink(
                '/homefor_thetander/admin/incident_reports.php',
                'Incident Reports',
                'incident_reports.php',
                $currentPage
            );
            ?>

            <div class="sidebar-section">System</div>

            <?php
            sidebarLink(
                '/homefor_thetander/admin/announcements.php',
                'Announcements',
                'announcements.php',
                $currentPage
            );

            sidebarLink(
                '/homefor_thetander/admin/settings.php',
                'Settings',
                'settings.php',
                $currentPage
            );

            sidebarLink(
                '/homefor_thetander/admin/archive.php',
                'Archive & Restore',
                'archive.php',
                $currentPage
            );
            ?>

       
<?php elseif ($currentRole === 'caretaker'): ?>

    <!-- MY CARE -->
    <div class="sidebar-section">My Care</div>

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
    ?>

    <!-- RESIDENT INFORMATION -->
    <div class="sidebar-section">Resident Information</div>

    <?php
    sidebarLink(
        '/homefor_thetander/caretaker/health_information.php',
        'Health Information',
        'health_information.php',
        $currentPage
    );
    ?>

    <!-- COMMUNICATION -->
    <div class="sidebar-section">Communication</div>

    <?php
    sidebarLink(
        '/homefor_thetander/caretaker/messages.php',
        'Messages',
        'messages.php',
        $currentPage
    );

    sidebarLink(
        '/homefor_thetander/caretaker/announcements.php',
        'Announcements',
        'announcements.php',
        $currentPage
    );
    ?>

    <!-- DOCUMENTATION -->
    <div class="sidebar-section">Documentation</div>

    <?php
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
    ?>

    <!-- SYSTEM -->
    <div class="sidebar-section">System</div>

    <?php
    sidebarLink(
        '/homefor_thetander/caretaker/profile.php',
        'My Profile',
        'profile.php',
        $currentPage
    );
    ?>

<?php endif; ?>


        <!-- ACCOUNT -->
        <div class="sidebar-account">

            <div class="sidebar-user">
                <?= htmlspecialchars(
                    $_SESSION['full_name']
                        ?? $_SESSION['username']
                        ?? 'User',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

                <span class="sidebar-user-role">
                    <?= htmlspecialchars(
                        ucfirst($currentRole),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </span>
            </div>

            <?php
            sidebarLink(
                '/homefor_thetander/logout.php',
                'Logout',
                'logout.php',
                $currentPage
            );
            ?>

        </div>

    </aside>

</div>

<!-- MAIN CONTENT STARTS HERE -->
<main class="app-main">

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
