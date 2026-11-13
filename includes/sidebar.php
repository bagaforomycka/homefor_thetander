<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

$currentRole = strtolower($_SESSION['role']);
$sbScript    = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
$currentPage = basename($sbScript);

/* admin/rooms/index.php and admin/schedules/index.php report "index.php":
   use the folder name instead so the right menu item is highlighted. */
if ($currentPage === 'index.php') {
    $currentPage = basename(dirname($sbScript)) . '.php';
}

/* "View Profile" goes to the profile page of the logged-in role */
$sbProfileUrl = $currentRole === 'caretaker'
    ? '/homefor_thetander/caretaker/profile.php'
    : '/homefor_thetander/admin/profile.php';

/* Profile info for the top-right account menu */
require_once __DIR__ . '/../config/db.php';

$sbUserName  = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$sbUserEmail = '';
$sbPicUrl    = '';

try {
    $sbStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $sbStmt->execute([(int) $_SESSION['user_id']]);
    $sbRow = $sbStmt->fetch(PDO::FETCH_ASSOC);

    if ($sbRow) {
        $sbUserName  = $sbRow['full_name'] ?: ($sbRow['username'] ?? $sbUserName);
        $sbUserEmail = (string) ($sbRow['email'] ?? '');

        $sbFile = basename((string) ($sbRow['profile_picture'] ?? ''));
        $sbPath = __DIR__ . '/../uploads/profiles/' . $sbFile;

        if ($sbFile !== '' && is_file($sbPath)) {
            $sbPicUrl = '/homefor_thetander/uploads/profiles/'
                . rawurlencode($sbFile) . '?v=' . (int) @filemtime($sbPath);
        }
    }
} catch (Throwable $sbErr) {
    /* Column or table missing: fall back to initials */
}

$sbInitials = '';
foreach (array_slice(preg_split('/\s+/', trim((string) $sbUserName)), 0, 2) as $sbPart) {
    $sbInitials .= mb_strtoupper(mb_substr($sbPart, 0, 1));
}
if ($sbInitials === '') {
    $sbInitials = 'U';
}

if (!function_exists('sidebarLink')) {
    /**
     * $page may be one file name or a list of file names. A menu item stays
     * highlighted on its own page and on the add / view / edit pages under it.
     */
    function sidebarLink(
        string $href,
        string $label,
        $page,
        string $currentPage
    ): void {
        $isActive = in_array($currentPage, (array) $page, true);
        $active   = $isActive ? ' active' : '';
        $current  = $isActive ? ' aria-current="page"' : '';

        echo '<a class="sidebar-link' . $active . '"' . $current . ' href="' .
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
            background:
               
                url('/homefor_thetander/assets/images/aged-care-background.jpg?v=<?= (int) @filemtime(__DIR__ . '/../assets/images/aged-care-background.jpg') ?>')
                center center / cover no-repeat fixed !important;
            color: #080c09;
            font-family: Arial, sans-serif;
        }

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

        /* Logo at the top of the sidebar.
           Footprint matches the previous logo: same block height,
           same visible width (about 224px), centred. The single line
           under it is the first .sidebar-section's own top border. */
        .app-sidebar .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 125px;
            margin: 0;
            padding: 0;
            border: 0;
        }

        .app-sidebar .sidebar-logo a {
            display: block;
            line-height: 0;
        }

        .app-sidebar .sidebar-logo img {
            display: block;
            width: 224px;
            max-width: 100%;
            height: auto;
            margin: 0;
            transform: none;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            width: 100%;
            min-height: 42px;
            margin-bottom: 3px;
            padding: 11px 9px;
            border-radius: 8px;
            border-left: 4px solid transparent;
            color: #ffffff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 400;
            line-height: 1.4;
            transition: background-color 0.2s ease, border-color 0.2s ease;
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

        /* Current page: filled background, bright bar on the left, bold text.
           !important so a page's own ".sidebar-link.active" rule cannot undo it. */
        .app-sidebar .sidebar-link.active {
            background: rgba(255, 255, 255, 0.22) !important;
            border-left-color: #8be0a6 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
        }

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

        .app-main {
            width: calc(100% - var(--sidebar-width));
            min-height: 100vh;
            margin-left: var(--sidebar-width);
            padding: 28px;
        }

        /* ===== Collapsible sidebar (desktop) =====
           Hamburger stays fixed at the top-left. When the sidebar is
           hidden it slides fully off-screen and the page content
           expands to use the whole width. */
        .sidebar-toggle-float {
            display: none;
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 1100;
            width: 40px;
            height: 40px;
            padding: 0;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            background: var(--sidebar-green);
            border: 1px solid rgba(255, 255, 255, 0.35);
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
        }

        .sidebar-toggle-float:hover {
            background: #0b5224;
        }

        .sidebar-toggle-float .hamburger-line {
            display: block;
            width: 18px;
            height: 2px;
            background: #ffffff;
            border-radius: 2px;
        }

        @media (min-width: 992px) {
            .sidebar-toggle-float {
                display: inline-flex;
            }

            /* The old in-sidebar hamburger (added by each page's script)
               is replaced by the fixed one above. */
            .app-sidebar .sidebar-toggle-wrapper {
                display: none !important;
            }

            /* Keep the logo clear of the fixed hamburger */
            .app-sidebar {
                padding-top: 60px;
                width: var(--sidebar-width) !important;
                min-width: var(--sidebar-width) !important;
            }

            /* Header band for the hamburger (left) and profile icon (right) */
            body .app-main {
                padding-top: 72px !important;
            }

            body.sidebar-anim .app-sidebar {
                transition: transform 0.3s ease, visibility 0s linear 0s;
            }

            body.sidebar-anim .app-main {
                transition: margin-left 0.3s ease, width 0.3s ease, padding-top 0.3s ease;
            }

            body.sidebar-hidden .app-sidebar {
                transform: translateX(-100%);
                visibility: hidden;
            }

            body.sidebar-anim.sidebar-hidden .app-sidebar {
                transition: transform 0.3s ease, visibility 0s linear 0.3s;
            }

            /* !important: some pages (reports, settings, add_resident)
               hardcode margin-left: 250px !important on .app-main */
            body.sidebar-hidden .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                padding-top: 72px !important; /* room for the hamburger */
            }
        }

        @media (max-width: 991.98px) {
            .sidebar-toggle-float {
                display: none !important;
            }
        }

        /* ===== One sidebar size on every page =====
           Some pages still carry their own sidebar / .app-main CSS (a 260px
           width, a flex body, a "folded" state, ...). These rules come after
           the page's <style>, so they win and keep the layout identical
           everywhere. */
        body {
            display: block !important;
        }

        .sidebar-overlay,
        .app-sidebar .sidebar-toggle-wrapper {
            display: none !important;
        }

        .app-sidebar {
            display: block !important;
            min-width: 0;
        }

        @media (min-width: 992px) {
            body .app-sidebar,
            body.sidebar-folded .app-sidebar {
                width: var(--sidebar-width) !important;
                min-width: var(--sidebar-width) !important;
                max-width: var(--sidebar-width) !important;
                height: 100vh !important;
                position: fixed !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
            }

            body .app-main {
                margin-left: var(--sidebar-width) !important;
                width: calc(100% - var(--sidebar-width)) !important;
                max-width: none !important;
                min-width: 0;
                padding: 72px 28px 28px !important;
                transition: margin-left 0.3s ease, width 0.3s ease;
            }

            body.sidebar-hidden .app-main {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }

        @media (max-width: 991.98px) {
            body .app-sidebar {
                position: relative !important;
                transform: none !important;
                width: 100% !important;
                min-width: 0 !important;
                max-width: none !important;
                height: auto !important;
                overflow-y: visible !important;
            }

            body .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: none !important;
                padding: 18px 12px !important;
            }
        }

        /* ===== Top-right profile menu ===== */
        .profile-menu {
            position: fixed;
            top: 10px;
            right: 20px;
            z-index: 1100;
            font-family: Arial, sans-serif;
        }

        .profile-trigger {
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 320px;
            padding: 4px 12px 4px 4px;
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: #1a1a1a;
            text-align: left;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .profile-trigger:hover,
        .profile-trigger[aria-expanded="true"] {
            background: rgba(255, 255, 255, 0.55);
        }

        .profile-trigger:focus-visible {
            outline: 3px solid #2f80d9;
            outline-offset: 2px;
        }

        .profile-avatar {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            overflow: hidden;
            border: 3px solid #ffffff;
            border-radius: 50%;
            background: #1b4a3a;
            color: #ffffff;
            font-size: 17px;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .profile-avatar img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-text {
            display: flex;
            flex-direction: column;
            min-width: 0;
            line-height: 1.25;
            text-shadow: 0 0 6px rgba(255, 255, 255, 0.95), 0 0 2px rgba(255, 255, 255, 0.95);
        }

        .profile-name {
            overflow: hidden;
            color: #111111;
            font-size: 15px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .profile-role {
            color: #2d3b33;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .profile-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 216px;
            padding: 8px;
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        }

        .profile-dropdown::before {
            content: "";
            position: absolute;
            top: -7px;
            right: 40px;
            width: 14px;
            height: 14px;
            background: #ffffff;
            transform: rotate(45deg);
            border-radius: 2px;
        }

        .profile-dropdown[hidden] {
            display: none !important;
        }

        .pm-item {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 11px 12px;
            border: 0;
            border-radius: 8px;
            font-family: Arial, sans-serif;
            font-size: 16px;
            line-height: 1.2;
            text-align: left;
            text-decoration: none;
            cursor: pointer;
        }

        .pm-item svg {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
        }

        .pm-item span {
            flex: 1;
        }

        .pm-item .pm-chev {
            width: 16px;
            height: 16px;
        }

        .pm-item:focus-visible {
            outline: 3px solid #2f80d9;
            outline-offset: 1px;
        }

        .pm-view {
            margin-bottom: 8px;
            background: #9bd3c9;
            color: #123a2c;
        }

        .pm-view:hover {
            background: #88c7bb;
            color: #123a2c;
        }

        .pm-logout {
            background: #ececec;
            color: #a1201a;
        }

        .pm-logout:hover {
            background: #e0e0e0;
            color: #8c1a15;
        }

        /* Log out confirmation */
        .logout-modal {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(0, 0, 0, 0.45);
        }

        .logout-modal[hidden] {
            display: none !important;
        }

        .logout-modal-card {
            width: 100%;
            max-width: 372px;
            padding: 26px 26px 28px;
            background: #ffffff;
            border-radius: 14px;
            text-align: center;
            font-family: Arial, sans-serif;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.3);
        }

        .logout-modal-card h2 {
            margin: 0 0 22px;
            color: #1a1a1a;
            font-size: 22px;
            font-weight: 500;
        }

        .logout-modal-card .lm-strong {
            margin: 0 0 4px;
            color: #111111;
            font-size: 16px;
            font-weight: 700;
        }

        .logout-modal-card .lm-sub {
            margin: 0 0 24px;
            color: #333333;
            font-size: 14.5px;
        }

        .logout-modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .lm-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 1 1 0;
            min-width: 0;
            height: 44px;
            padding: 0 12px;
            border-radius: 8px;
            font-family: Arial, sans-serif;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .lm-btn:focus {
            outline: 3px solid #2f80d9;
            outline-offset: 2px;
        }

        .lm-no {
            border: 1px solid #1b4a3a;
            background: #ffffff;
            color: #111111;
        }

        .lm-no:hover {
            background: #f1f6f3;
        }

        .lm-yes {
            border: 1px solid #1b4a3a;
            background: #1b4a3a;
            color: #ffffff;
        }

        .lm-yes:hover {
            background: #143a2d;
            border-color: #143a2d;
            color: #ffffff;
        }

        @media (max-width: 991.98px) {
            /* Compact: avatar only, left of the mobile "Menu" button */
            .profile-menu {
                top: 9px;
                right: 84px;
            }

            .profile-trigger {
                padding: 0;
            }

            .profile-text {
                display: none;
            }

            .profile-avatar {
                width: 40px;
                height: 40px;
                font-size: 14px;
            }

            .profile-dropdown {
                right: 0;
            }

            .profile-dropdown::before {
                right: 14px;
            }
        }

        @media print {
            .profile-menu,
            .logout-modal,
            .sidebar-toggle-float {
                display: none !important;
            }
        }

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

<script>
    // Apply the saved sidebar state immediately (no flash on page load)
    try {
        if (localStorage.getItem('sidebarHidden') === '1') {
            document.body.classList.add('sidebar-hidden');
        }
    } catch (e) {}
</script>

<button
    type="button"
    class="sidebar-toggle-float"
    id="sidebarToggleFloat"
    aria-label="Toggle navigation sidebar"
    aria-controls="sidebarMobileCollapse"
    aria-expanded="true"
>
    <span class="hamburger-line"></span>
    <span class="hamburger-line"></span>
    <span class="hamburger-line"></span>
</button>

<div class="profile-menu" id="profileMenu">
    <button
        type="button"
        class="profile-trigger"
        id="profileBtn"
        aria-haspopup="menu"
        aria-expanded="false"
        aria-controls="profileDropdown"
        aria-label="Account menu"
    >
        <span class="profile-avatar">
            <?php if ($sbPicUrl !== ''): ?>
                <img src="<?= htmlspecialchars($sbPicUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php else: ?>
                <?= htmlspecialchars($sbInitials, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </span>
        <span class="profile-text">
            <span class="profile-name"><?= htmlspecialchars((string) $sbUserName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="profile-role"><?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?></span>
        </span>
    </button>

    <div class="profile-dropdown" id="profileDropdown" role="menu" hidden>
        <a class="pm-item pm-view" role="menuitem" href="<?= htmlspecialchars($sbProfileUrl, ENT_QUOTES, 'UTF-8') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="8" r="4"></circle>
                <path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"></path>
            </svg>
            <span>View Profile</span>
            <svg class="pm-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 6l6 6-6 6"></path>
            </svg>
        </a>

        <button type="button" class="pm-item pm-logout" role="menuitem" id="logoutOpen">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3v9"></path>
                <path d="M6.4 6.4a8 8 0 1 0 11.2 0"></path>
            </svg>
            <span>Logout</span>
        </button>
    </div>
</div>

<div class="logout-modal" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutTitle" hidden>
    <div class="logout-modal-card">
        <h2 id="logoutTitle">Log Out</h2>
        <p class="lm-strong">Are you sure you want to log out?</p>
        <p class="lm-sub">Unsaved changes on other tabs might be lost.</p>
        <div class="logout-modal-actions">
            <button type="button" class="lm-btn lm-no" id="logoutNo">No</button>
            <a class="lm-btn lm-yes" id="logoutYes" href="/homefor_thetander/logout.php">Yes, Log Out</a>
        </div>
    </div>
</div>

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

<div class="collapse d-lg-block" id="sidebarMobileCollapse">

    <aside class="app-sidebar">

        <div class="sidebar-logo">
            <a href="<?= $currentRole === 'caretaker'
                ? '/homefor_thetander/caretaker/dashboard.php'
                : '/homefor_thetander/admin/dashboard.php' ?>">
                <img
                    src="/homefor_thetander/images/logo-white.png?v=<?= (int) @filemtime(__DIR__ . '/../images/logo-white.png') ?>"
                    alt="Home for the Aged - Care, Comfort, Dignity"
                >
            </a>
        </div>

        <?php if ($currentRole === 'admin'): ?>

            <div class="sidebar-section">Main Menu</div>

            <?php
            sidebarLink(
                '/homefor_thetander/admin/dashboard.php',
                'Dashboard',
                ['dashboard.php', 'admission_tracker.php'],
                $currentPage
            );
            ?>

            <div class="sidebar-section">Resident Management</div>

            <?php
            sidebarLink(
                '/homefor_thetander/admin/residents.php',
                'Residents',
                ['residents.php', 'add_resident.php', 'view_resident.php', 'edit_resident.php', 'delete_resident.php'],
                $currentPage
            );

            sidebarLink(
                '/homefor_thetander/admin/rooms.php',
                'Rooms',
                ['rooms.php', 'rooms_index.php'],
                $currentPage
            );

            sidebarLink(
                '/homefor_thetander/admin/health_records.php',
                'Health Records',
                ['health_records.php', 'add_health_record.php', 'view_health_records.php'],
                $currentPage
            );
            ?>

            <div class="sidebar-section">People Management</div>

            <?php
            sidebarLink(
                '/homefor_thetander/admin/caretakers.php',
                'Caretakers',
                ['caretakers.php', 'add_caretaker.php', 'add_staff.php', 'view_profile_cs.php', 'edit_profile_cs.php', 'caretaker_profile.php'],
                $currentPage
            );
            ?>

            <div class="sidebar-section">Operations</div>

            <?php
            sidebarLink(
                '/homefor_thetander/admin/schedules.php',
                'Schedules',
                ['schedules.php', 'add_schedule.php', 'view_schedules.php'],
                $currentPage
            );

            sidebarLink(
                '/homefor_thetander/admin/messages.php',
                'Messages',
                ['messages.php', 'new_message.php'],
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
                ['archive.php', 'restore.php'],
                $currentPage
            );
            ?>

        <?php elseif ($currentRole === 'caretaker'): ?>

            <div class="sidebar-section">My Care</div>

            <?php
            sidebarLink(
                '/homefor_thetander/caretaker/dashboard.php',
                'Dashboard',
                'dashboard.php',
                $currentPage
            );

            sidebarLink(
                '/homefor_thetander/caretaker/myresidents.php',
                'My Residents',
                'myresidents.php',
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

            <div class="sidebar-section">Resident Information</div>

            <?php
            sidebarLink(
                '/homefor_thetander/caretaker/health_information.php',
                'Health Information',
                'health_information.php',
                $currentPage
            );
            ?>

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

            <div class="sidebar-section">System</div>

            <?php
            sidebarLink(
    '/homefor_thetander/caretaker/profile.php',
    'My Profile',
    'profile.php',
    $currentPage
);

sidebarLink(
    '/homefor_thetander/caretaker/settings.php',
    'Settings',
    'settings.php',
    $currentPage
);

sidebarLink(
    '/homefor_thetander/caretaker/archive.php',
    'Archive & Restore',
    'archive.php',
    $currentPage
);
            ?>

        <?php endif; ?>

    </aside>

</div>

<script>
/* Keep the sidebar scroll position between pages, so clicking a link near the
   bottom (Announcements, Settings, Archive...) does not jump back to the top. */
(function () {
    var side = document.querySelector('.app-sidebar');
    if (!side) return;

    var KEY = 'sidebarScrollTop';

    try {
        var saved = parseInt(sessionStorage.getItem(KEY), 10);
        if (!isNaN(saved)) side.scrollTop = saved;
    } catch (e) {}

    // No saved position (first visit): make sure the current item is visible.
    var active = side.querySelector('.sidebar-link.active');
    if (active && side.scrollHeight > side.clientHeight) {
        var top = active.offsetTop;
        var bottom = top + active.offsetHeight;
        if (top < side.scrollTop || bottom > side.scrollTop + side.clientHeight) {
            side.scrollTop = Math.max(0, top - side.clientHeight / 2);
        }
    }

    function save() {
        try { sessionStorage.setItem(KEY, String(side.scrollTop)); } catch (e) {}
    }

    side.addEventListener('scroll', save, { passive: true });
    side.addEventListener('click', save);
    window.addEventListener('pagehide', save);
})();
</script>

<main class="app-main">

<script>
(function () {
    var btn = document.getElementById('sidebarToggleFloat');
    if (!btn) return;

    function sync() {
        btn.setAttribute(
            'aria-expanded',
            document.body.classList.contains('sidebar-hidden') ? 'false' : 'true'
        );
    }
    sync();

    btn.addEventListener('click', function () {
        var hidden = document.body.classList.toggle('sidebar-hidden');
        try { localStorage.setItem('sidebarHidden', hidden ? '1' : '0'); } catch (e) {}
        sync();
    });

    // Enable slide animation only after first paint
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            document.body.classList.add('sidebar-anim');
        });
    });
})();
</script>

<script>
(function () {
    var btn = document.getElementById('profileBtn');
    var menu = document.getElementById('profileDropdown');
    var modal = document.getElementById('logoutModal');
    var openLogout = document.getElementById('logoutOpen');
    var no = document.getElementById('logoutNo');
    var yes = document.getElementById('logoutYes');
    if (!btn || !menu || !modal) return;

    function closeMenu() {
        menu.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
    }

    function openMenu() {
        menu.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
    }

    function closeModal() {
        modal.hidden = true;
        btn.focus();
    }

    btn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        if (menu.hidden) { openMenu(); } else { closeMenu(); }
    });

    document.addEventListener('click', function (ev) {
        if (!menu.hidden && !menu.contains(ev.target)) closeMenu();
    });

    openLogout.addEventListener('click', function () {
        closeMenu();
        modal.hidden = false;
        yes.focus();
    });

    no.addEventListener('click', closeModal);

    modal.addEventListener('click', function (ev) {
        if (ev.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        if (!modal.hidden) { closeModal(); }
        else if (!menu.hidden) { closeMenu(); btn.focus(); }
    });
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>