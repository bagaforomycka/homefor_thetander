<?php
session_start();

require_once __DIR__ . '/../config/db.php';

if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)
) {
    header("Location: ../index.php");
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $announcement = trim($_POST['message'] ?? '');

    if ($title !== '' && $announcement !== '') {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO announcements
                (title, message, created_by, status)
                VALUES (?, ?, ?, 'Published')"
            );

            $stmt->execute([
                $title,
                $announcement,
                $_SESSION['user_id']
            ]);

            header("Location: announcements.php?success=1");
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $message = "Unable to publish announcement.";
        }
    } else {
        $message = "Please fill in all fields.";
    }
}

if (isset($_GET['success'])) {
    $message = "Announcement published successfully!";
}

try {
    $stmt = $pdo->query(
        "SELECT title, message, created_at
         FROM announcements
         WHERE status = 'Published'
         ORDER BY created_at DESC"
    );

    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log($e->getMessage());
    $announcements = [];
    $message = "Unable to load announcements.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Announcements | Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f8f5;
            font-family: Arial, Helvetica, sans-serif;
            color: #304b3b;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* SIDEBAR TOGGLE BUTTON STYLES */
        .sidebar-toggle-btn {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 6px;
            padding: 6px;
            cursor: pointer;
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 4px;
            width: 36px;
            height: 36px;
            margin: 12px 0;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .sidebar-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
        }

        .sidebar-toggle-btn .hamburger-line {
            display: block;
            width: 18px;
            height: 2px;
            background-color: #ffffff;
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        .sidebar-toggle-wrapper {
            padding-left: 15px;
            width: 100%;
            display: flex;
            justify-content: flex-start;
        }

        /* SIDEBAR OVERLAY FOR MOBILE */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.4);
            z-index: 998;
            display: none;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* SIDEBAR DEFAULT & COMPACT/FOLDED BEHAVIOR */
        .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar {
            width: 260px;
            min-width: 260px;
            transition: width 0.3s ease, transform 0.3s ease;
            z-index: 999;
            flex-shrink: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* DESKTOP FOLDED STATE */
        @media (min-width: 993px) {
            body.sidebar-folded .sidebar,
            body.sidebar-folded #sidebar,
            body.sidebar-folded .sidebar-container,
            body.sidebar-folded aside,
            body.sidebar-folded .app-sidebar {
                width: 70px;
                min-width: 70px;
            }

            /* Hide everything except the toggle button wrapper when folded */
            body.sidebar-folded .sidebar > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded #sidebar > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded .sidebar-container > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded aside > *:not(.sidebar-toggle-wrapper),
            body.sidebar-folded .app-sidebar > *:not(.sidebar-toggle-wrapper) {
                display: none !important;
            }

            /* Center button in the 70px bar when folded */
            body.sidebar-folded .sidebar-toggle-wrapper {
                display: flex !important;
                justify-content: center !important;
                padding-left: 0 !important;
                width: 100% !important;
                margin-top: 12px !important;
            }
        }

        /* MOBILE SIDEBAR BEHAVIOR */
        @media (max-width: 992px) {
            .sidebar, #sidebar, .sidebar-container, aside, .app-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100%;
                transform: translateX(-100%);
                width: 260px !important;
            }

            body.sidebar-open .sidebar,
            body.sidebar-open #sidebar,
            body.sidebar-open .sidebar-container,
            body.sidebar-open aside,
            body.sidebar-open .app-sidebar {
                transform: translateX(0);
            }
        }

        /* SIDEBAR LOGO */
        .sidebar-logo {
            display: block;
            padding: 10px 4px 6px;
        }

        .sidebar-logo a {
            display: block;
        }

        .sidebar-logo img {
            display: block;
            width: 100%;
            max-width: 300px;
            height: auto;
            margin: 0 auto;
            transform: scale(1.1);
        }

        /* app-main opened in sidebar.php */
        .app-main {
            width: 100% !important;
            max-width: none !important;
            min-width: 0;
            min-height: 100vh;
            padding: 16px 24px !important;
            flex-grow: 1;
            margin-top: 0 !important;
            box-shadow: none !important;
            overflow-x: hidden;
            transition: margin-left 0.3s ease;
        }

        .page-title {
            font-weight: 700;
            color: #17683a;
            font-size: 24px;
            margin-bottom: 14px !important;
        }

        .card {
            border-radius: 10px;
            border: 1px solid #e3eee6;
            box-shadow: 0 2px 10px rgba(24, 75, 42, 0.04);
        }

        .announcement-card {
            background: #ffffff;
            padding: 18px;
            border-radius: 10px;
            margin-bottom: 14px;
            border: 1px solid #e3eee6;
            box-shadow: 0 2px 10px rgba(24, 75, 42, 0.04);
            transition: transform 0.2s ease,
                        box-shadow 0.2s ease;
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(24, 75, 42, 0.08);
        }

        .announcement-card h5 {
            color: #17683a;
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 17px;
        }

        .announcement-card p {
            color: #304b3b;
            line-height: 1.5;
            margin-bottom: 10px;
            overflow-wrap: anywhere;
            font-size: 14px;
        }

        .btn-publish {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #198754;
            color: #ffffff;
            border: none;
            padding: 9px 18px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-publish:hover {
            background: #157347;
            color: #ffffff;
        }

        .form-label {
            color: #17683a;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .form-control {
            padding: 8px 12px;
            border-radius: 6px;
            border-color: #cedbd1;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: #33b960;
            box-shadow: 0 0 0 0.15rem rgba(51, 185, 96, 0.15);
        }

        .sidebar-link.active {
            box-shadow: none !important;
            outline: none !important;
        }
    </style>
</head>

<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
// sidebar.php opens <main class="app-main">
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="container-fluid px-0">

    <h2 class="page-title">Announcements</h2>

    <?php if ($message !== ''): ?>
        <div class="alert alert-info py-2 px-3 mb-3 fs-6">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-md-4">

            <h5 class="mb-3 text-success fw-bold fs-5">Create Announcement</h5>

            <form method="POST" action="announcements.php">

                <div class="mb-3">
                    <label for="title" class="form-label">
                        Announcement Title
                    </label>

                    <input
                        type="text"
                        id="title"
                        name="title"
                        class="form-control"
                        maxlength="150"
                        placeholder="Enter announcement title"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="message" class="form-label">
                        Announcement Message
                    </label>

                    <textarea
                        id="message"
                        name="message"
                        class="form-control"
                        rows="3"
                        placeholder="Write your announcement here..."
                        required
                    ></textarea>
                </div>

                <button type="submit" class="btn-publish">
                    Publish Announcement
                </button>

            </form>
        </div>
    </div>

    <h4 class="mb-3 text-success fw-bold fs-5">Published Announcements</h4>

    <?php if (!empty($announcements)): ?>

        <?php foreach ($announcements as $item): ?>

            <div class="announcement-card">

                <h5>
                    <?= htmlspecialchars($item['title']) ?>
                </h5>

                <p>
                    <?= nl2br(htmlspecialchars($item['message'])) ?>
                </p>

                <small class="text-muted">
                    Published:
                    <?= htmlspecialchars($item['created_at']) ?>
                </small>

            </div>

        <?php endforeach; ?>

    <?php else: ?>

        <div class="alert alert-secondary py-2 px-3 fs-6">
            No announcements have been published yet.
        </div>

    <?php endif; ?>

</div>

<!-- Hamburger Toggle & Sidebar Behavior Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('sidebarOverlay');
    var sidebar = document.querySelector(
        '.app-sidebar, .sidebar, #sidebar, .sidebar-container, aside, nav'
    );

    if (!sidebar) {
        return;
    }

    var oldTexts = ['home for the aged', 'care management system'];

    sidebar.querySelectorAll('*').forEach(function (el) {
        if (
            el.children.length === 0 &&
            oldTexts.indexOf(el.textContent.trim().toLowerCase()) !== -1
        ) {
            el.style.display = 'none';
        }
    });

    if (!sidebar.querySelector('.sidebar-logo')) {
        var logo = document.createElement('div');
        logo.className = 'sidebar-logo';
        logo.innerHTML =
            '<a href="/homefor_thetander/admin/dashboard.php">' +
            '<img src="/homefor_thetander/images/white1.png" ' +
            'alt="Home for the Aged - Care, Comfort, Dignity">' +
            '</a>';

        sidebar.insertBefore(logo, sidebar.firstChild);
    }

    if (!sidebar.querySelector('#sidebarToggle')) {
        var toggleWrapper = document.createElement('div');
        toggleWrapper.className = 'sidebar-toggle-wrapper';
        toggleWrapper.innerHTML = `
            <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Toggle Navigation Sidebar">
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
            </button>
        `;
        sidebar.insertBefore(toggleWrapper, sidebar.firstChild);
    }

    var toggleBtn = document.getElementById('sidebarToggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            if (window.innerWidth <= 992) {
                document.body.classList.toggle('sidebar-open');
                if (overlay) overlay.classList.toggle('active');
            } else {
                document.body.classList.toggle('sidebar-folded');
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            document.body.classList.remove('sidebar-open');
            overlay.classList.remove('active');
        });
    }
});
</script>

<!-- sidebar.php already opened <main class="app-main">, close it here -->
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>