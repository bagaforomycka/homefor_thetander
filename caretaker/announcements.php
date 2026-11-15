
<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'caretaker'
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

$announcements = [];
$error = '';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM announcements
        WHERE status = 'Published'
        ORDER BY created_at DESC
    ");

    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $err) {
    $error = 'Unable to load announcements. Please try again later.';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Announcements | Home for the Aged</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            color: #333;
        }

        .main-content {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0;
            margin: 0 !important;
            padding: 0 !important;
            min-height: calc(100vh - 72px);
        }

        .page-header {
            width: 100%;
            margin-bottom: 25px;
        }

        .page-header h2 {
            color: #17683a;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .page-header p {
            color: #777;
            margin: 0;
            font-size: 14px;
        }

        .announcement-count {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #e6f3eb;
            color: #17683a;
            padding: 11px 17px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
        }

        /* SEARCH BOX */
        .search-container {
            width: 100%;
            margin-bottom: 25px;
        }

        .search-box {
            position: relative;
            width: 100%;
        }

        .search-box > i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            font-size: 17px;
            pointer-events: none;
        }

        .search-input {
            width: 100%;
            height: 48px;
            border: 1px solid #dce5df;
            border-radius: 10px;
            padding: 10px 45px 10px 45px;
            font-size: 14px;
            outline: none;
            transition: 0.2s ease;
        }

        .search-input:focus {
            border-color: #17683a;
            box-shadow: 0 0 0 3px rgba(23, 104, 58, 0.10);
        }

        .clear-search {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #888;
            font-size: 18px;
            cursor: pointer;
            display: none;
        }

        .clear-search:hover {
            color: #17683a;
        }

        .search-results {
            color: #888;
            font-size: 13px;
            margin-top: 9px;
        }

        /* ANNOUNCEMENT CARDS */
        .announcement-card {
            width: 100%;
            background: #ffffff;
            border: 1px solid #e6ebe8;
            border-left: 5px solid #17683a;
            border-radius: 12px;
            padding: 25px 30px;
            margin-bottom: 22px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: box-shadow 0.2s ease;
        }

        .announcement-card:hover {
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.09);
        }

        .announcement-title {
            color: #17683a;
            font-size: 21px;
            font-weight: 700;
            margin: 0;
            overflow-wrap: anywhere;
        }

        .announcement-date {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 9px;
            color: #888;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .announcement-date i {
            color: #777;
        }

        .announcement-message {
            color: #444;
            font-size: 16px;
            line-height: 1.8;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            margin-bottom: 0;
        }

        .published-badge {
            display: inline-block;
            background: #e6f3eb;
            color: #17683a;
            font-size: 12px;
            font-weight: 700;
            padding: 9px 15px;
            border-radius: 25px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* EMPTY STATE */
        .empty-state {
            width: 100%;
            background: #ffffff;
            border: 1px solid #e6ebe8;
            border-radius: 12px;
            padding: 65px 20px;
            text-align: center;
        }

        .empty-state i {
            font-size: 45px;
            color: #a8cbb5;
            margin-bottom: 15px;
        }

        .empty-state h5 {
            color: #17683a;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: #888;
            font-size: 14px;
            margin: 0;
        }

        /* NO SEARCH RESULTS */
        .no-results {
            display: none;
        }

        @media (max-width: 991.98px) {
            .main-content {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                min-height: auto;
            }

            .page-header h2 {
                font-size: 25px;
            }

            .announcement-card {
                padding: 22px;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                margin-bottom: 22px;
            }

            .page-header h2 {
                font-size: 23px;
            }

            .page-header p {
                font-size: 13px;
                line-height: 1.5;
            }

            .announcement-count {
                padding: 9px 13px;
                font-size: 12px;
            }

            .search-container {
                margin-bottom: 20px;
            }

            .search-input {
                height: 45px;
                font-size: 13px;
            }

            .announcement-card {
                padding: 18px;
                margin-bottom: 16px;
                border-radius: 10px;
            }

            .announcement-title {
                font-size: 17px;
            }

            .announcement-date {
                font-size: 12px;
                gap: 6px;
                margin-bottom: 15px;
            }

            .announcement-message {
                font-size: 14px;
                line-height: 1.7;
            }

            .published-badge {
                font-size: 10px;
                padding: 7px 11px;
            }

            .empty-state {
                padding: 45px 15px;
            }
        }

        @media (max-width: 400px) {
            .page-header h2 {
                font-size: 21px;
            }

            .announcement-card {
                padding: 15px;
            }

            .announcement-title {
                font-size: 16px;
            }

            .announcement-message {
                font-size: 13px;
            }

            .published-badge {
                padding: 6px 9px;
            }
        }
    </style>
</head>

<body>

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">

        <!-- PAGE HEADER -->
        <div class="page-header">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

                <div>
                    <h2>Announcements</h2>

                    <p>
                        Stay updated with important notices and announcements.
                    </p>
                </div>

                <div class="announcement-count">
                    <i class="bi bi-megaphone-fill"></i>

                    <?= count($announcements) ?> Published
                </div>

            </div>

        </div>

        <?php if ($error !== ''): ?>

            <div class="alert alert-danger">
                <?= e($error) ?>
            </div>

        <?php elseif (!empty($announcements)): ?>

            <!-- SEARCH BOX -->
            <div class="search-container">

                <div class="search-box">

                    <i class="bi bi-search"></i>

                    <input
                        type="text"
                        id="announcementSearch"
                        class="search-input"
                        placeholder="Search announcements by keywords..."
                        autocomplete="off"
                    >

                    <button
                        type="button"
                        id="clearSearch"
                        class="clear-search"
                        aria-label="Clear search"
                    >
                        <i class="bi bi-x-circle-fill"></i>
                    </button>

                </div>

                <div class="search-results" id="searchResults">
                    Showing <?= count($announcements) ?> announcements
                </div>

            </div>

            <!-- ANNOUNCEMENTS -->
            <div id="announcementList">

                <?php foreach ($announcements as $announcement): ?>

                    <div
                        class="announcement-card"
                        data-search="<?= e(($announcement['title'] ?? '') . ' ' . ($announcement['message'] ?? '')) ?>"
                    >

                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">

                            <h5 class="announcement-title">
                                <?= e($announcement['title'] ?? 'Untitled Announcement') ?>
                            </h5>

                            <span class="published-badge">
                                Published
                            </span>

                        </div>

                        <div class="announcement-date">

                            <i class="bi bi-calendar3"></i>

                            <?php if (!empty($announcement['created_at'])): ?>

                                <?= date(
                                    'F d, Y',
                                    strtotime($announcement['created_at'])
                                ) ?>

                                <span>•</span>

                                <?= date(
                                    'h:i A',
                                    strtotime($announcement['created_at'])
                                ) ?>

                            <?php else: ?>

                                Date unavailable

                            <?php endif; ?>

                        </div>

                        <p class="announcement-message"><?= e($announcement['message'] ?? '') ?></p>

                    </div>

                <?php endforeach; ?>

            </div>

            <!-- NO SEARCH RESULTS -->
            <div class="empty-state no-results" id="noResults">

                <i class="bi bi-search"></i>

                <h5>No Matching Announcements</h5>

                <p>
                    We couldn't find any announcements matching your keyword.
                    Try searching for another word.
                </p>

            </div>

        <?php else: ?>

            <div class="empty-state">

                <i class="bi bi-megaphone"></i>

                <h5>No Announcements Yet</h5>

                <p>
                    There are currently no published announcements.
                    Please check again later.
                </p>

            </div>

        <?php endif; ?>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {

            const searchInput = document.getElementById('announcementSearch');
            const clearButton = document.getElementById('clearSearch');
            const searchResults = document.getElementById('searchResults');
            const noResults = document.getElementById('noResults');

            if (!searchInput) {
                return;
            }

            const announcementCards = document.querySelectorAll('.announcement-card');

            function searchAnnouncements() {

                const keyword = searchInput.value.trim().toLowerCase();

                let visibleCount = 0;

                announcementCards.forEach(function (card) {

                    const searchableText = card.dataset.search.toLowerCase();

                    const matches = searchableText.includes(keyword);

                    card.style.display = matches ? '' : 'none';

                    if (matches) {
                        visibleCount++;
                    }

                });

                searchResults.textContent =
                    'Showing ' + visibleCount + ' of ' +
                    announcementCards.length + ' announcements';

                clearButton.style.display = keyword !== '' ? 'block' : 'none';

                noResults.style.display = visibleCount === 0 ? 'block' : 'none';
            }

            searchInput.addEventListener('input', searchAnnouncements);

            clearButton.addEventListener('click', function () {

                searchInput.value = '';

                searchAnnouncements();

                searchInput.focus();

            });

        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
