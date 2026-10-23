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
            overflow-x: hidden;
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
            margin-left: 250px !important;
            width: calc(100% - 250px) !important;
            max-width: none !important;
            min-width: 0;
            min-height: 100vh;
            padding: 16px 24px !important;
            margin-top: 0 !important;
            box-shadow: none !important;
            overflow-x: hidden;
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

        @media (max-width: 760px) {
            .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 10px !important;
            }
        }
    </style>
</head>

<body>

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

<!-- Puts the logo at the top of the sidebar and hides the old text title -->
<script>
document.addEventListener('DOMContentLoaded', function () {
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
});
</script>

<!-- sidebar.php already opened <main class="app-main">, close it here -->
</main>
</body>
</html>