
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['admin', 'staff'])
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

    <title>Announcements</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f5f6fa;
            font-family: Arial, sans-serif;
        }

        .content {
            padding: 30px;
        }

        .card {
            border-radius: 12px;
        }

        .announcement-card {
            background: #ffffff;
            padding: 22px;
            border-radius: 10px;
            margin-bottom: 16px;
            border: 1px solid #e9ecef;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease,
                        box-shadow 0.2s ease;
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
        }

        .announcement-card h5 {
            color: #212529;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .announcement-card p {
            color: #495057;
            line-height: 1.6;
            margin-bottom: 15px;
            overflow-wrap: anywhere;
        }

        .btn-publish {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #198754, #146c43);
            color: #ffffff;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            letter-spacing: 0.3px;
            box-shadow: 0 4px 10px rgba(25, 135, 84, 0.2);
            transition: all 0.25s ease;
            cursor: pointer;
        }

        .btn-publish:hover {
            background: linear-gradient(135deg, #157347, #0f5132);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(25, 135, 84, 0.3);
        }

        .btn-publish:active {
            transform: translateY(0);
            box-shadow: 0 2px 5px rgba(25, 135, 84, 0.2);
        }

        .btn-publish:focus-visible {
            outline: 3px solid rgba(25, 135, 84, 0.35);
            outline-offset: 3px;
        }

        .form-control {
            padding: 11px 12px;
            border-radius: 7px;
        }

        .form-control:focus {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.15);
        }

        .page-title {
            font-weight: 700;
            color: #212529;
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<main class="content">
    <div class="container-fluid">

        <h2 class="page-title mb-4">Announcements</h2>

        <?php if ($message !== ''): ?>
            <div class="alert alert-info">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="mb-4">Create Announcement</h5>

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

                    <div class="mb-4">
                        <label for="message" class="form-label">
                            Announcement Message
                        </label>

                        <textarea
                            id="message"
                            name="message"
                            class="form-control"
                            rows="4"
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

        <h4 class="mb-3">Published Announcements</h4>

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

            <div class="alert alert-secondary">
                No announcements have been published yet.
            </div>

        <?php endif; ?>

    </div>
</main>

</body>
</html>
