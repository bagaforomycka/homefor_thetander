<?php
session_start();

if (
    !isset($_SESSION['user_id'],$_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$view =$_GET['view'] ?? 'inbox';
$tab =$_GET['tab'] ?? 'admin_staff';
if (!in_array($tab, ['admin_staff', 'caretakers', 'unread'], true)) {$tab = 'admin_staff';
}

$errors = [];$successMessage = '';

// Handle Delete Action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $deleteId = (int)$_GET['id'];
    try {
        // Soft delete message by updating status
        $stmtDelete =$pdo->prepare("UPDATE messages SET status = 'deleted' WHERE id = ? AND recipient_id = ?");
        $stmtDelete->execute([$deleteId,$_SESSION['user_id']]);
        
        header("Location: messages.php?tab=" . urlencode($tab) . "&msg=deleted");
        exit;
    } catch (PDOException $e) {
        $errors[] = 'Failed to delete message: ' .$e->getMessage();
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'sent') {$successMessage = 'Message successfully sent!';
    } elseif ($_GET['msg'] === 'deleted') {$successMessage = 'Message deleted successfully.';
    }
}

$search = trim($_GET['search'] ?? '');

// Fetch Messages
$messages = [];
if ($view === 'inbox') {
    try {
        $viewRoles = match($tab) {
            'caretakers'  => ["'caretaker'"],
            'unread'      => ["'admin'", "'staff'", "'caretaker'"],
            'admin_staff' => ["'admin'", "'staff'"],
            default       => ["'admin'", "'staff'"]
        };
        $roleFilterSql = implode(",", $viewRoles);

        $sql = "SELECT m.id, u.full_name AS sender_name, u.role AS sender_role, m.subject, m.preview, m.date_recorded AS `date`, m.status
                FROM messages AS m
                JOIN users AS u ON m.sender_id = u.id
                WHERE m.recipient_id = ? AND m.status != 'deleted' AND u.role IN ({$roleFilterSql})";
        $params = [$_SESSION['user_id']];

        if ($tab === 'unread') {$sql .= " AND m.status = 'unread'";
        }
        if ($search !== '') {$sql .= " AND (m.subject LIKE ? OR u.full_name LIKE ?)";
            $params[] = "\%$search%";
            $params[] = "\%$search%";
        }
        $sql .= " ORDER BY m.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $messages =$stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {$messages = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages | Home for the Aged</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-bg: #0d3b1e;
            --green: #17683a;
            --pale-green: #edf7ef;
            --text: #304b3b;
        }

        body {
            background-color: #f8fbf8;
            color: var(--text);
            font-family: Arial, sans-serif;
            overflow-x: hidden;
        }

        .main-content {
            margin-left: 10px;
            padding: 30px;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            background: #ffffff;
        }

        .form-label {
            font-weight: 600;
            color: var(--green);
            font-size: 0.9rem;
        }

        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #dcdcdc;
            padding: 10px 14px;
        }

        .btn-success {
            background-color: var(--green);
            border-color: var(--green);
            color: #ffffff;
            font-weight: 600;
            border-radius: 6px;
        }

        .btn-success:hover {
            background-color: #0f4b2a;
            border-color: #0f4b2a;
        }

        .table thead th {
            background-color: var(--pale-green);
            color: var(--text);
            font-weight: 600;
            border-bottom: 1px solid #e0eee3;
            padding: 12px 16px;
        }

        .table tbody td {
            padding: 14px 16px;
        }

        /* Action Buttons Styling */
        .btn-action-view {
            color: #0d6efd;
            border-color: #0d6efd;
            border-radius: 4px;
            padding: 2px 10px;
            font-size: 0.85rem;
            text-decoration: none;
        }

        .btn-action-view:hover {
            background-color: #0d6efd;
            color: #fff;
        }

        .btn-action-delete {
            color: #dc3545;
            border-color: #dc3545;
            border-radius: 4px;
            padding: 2px 10px;
            font-size: 0.85rem;
            text-decoration: none;
        }

        .btn-action-delete:hover {
            background-color: #dc3545;
            color: #fff;
        }
    </style>
</head>
<body>

    <!-- Include Sidebar Navigation -->
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content">
        
        <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= e($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as$error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="fw-bold mb-1">Messages - Inbox</h2>
                <p class="text-muted mb-0">View and manage internal communication and system alerts.</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-outline-success me-2">&larr; Back to Dashboard</a>
                <a href="new_message.php" class="btn btn-success">+ New Message</a>
            </div>
        </div>

        <!-- Role Tabs -->
        <div class="mb-4">
            <div class="btn-group" role="group">
                <a href="messages.php?tab=admin_staff" class="btn btn-outline-success <?= $tab === 'admin_staff' ? 'active' : '' ?>">
                    Admin & Staff
                </a>
                <a href="messages.php?tab=caretakers" class="btn btn-outline-success <?= $tab === 'caretakers' ? 'active' : '' ?>">
                    Caretakers
                </a>
                <a href="messages.php?tab=unread" class="btn btn-outline-success <?= $tab === 'unread' ? 'active' : '' ?>">
                    Unread
                </a>
            </div>
        </div>

        <!-- Search Card -->
        <div class="card p-3 mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <div class="col-md-9">
                    <label class="form-label small mb-1">Search by subject or recipient/sender</label>
                    <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Enter keyword...">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-success flex-fill">Search</button>
                    <a href="messages.php?tab=<?= e($tab) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>

        <!-- Message List Card -->
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">
                    <?= $tab === 'admin_staff' ? 'Admin & Staff Messages' : ($tab === 'caretakers' ? 'Caretakers Messages' : 'Unread Messages') ?>
                </h5>
                <span class="text-muted small">Total: <?= count($messages) ?></span>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0 table-hover">
                    <thead>
                        <tr>
                            <th style="width: 20%;">Sender</th>
                            <th style="width: 25%;">Subject</th>
                            <th style="width: 30%;">Preview</th>
                            <th style="width: 12%;">Date</th>
                            <th style="width: 13%; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($messages)): ?>
                           <tr>
                               <td colspan="5" class="text-center text-muted py-4">No messages found.</td>
                           </tr>
                        <?php else: ?>
                            <?php foreach ($messages as$row): ?>
                                <tr class="<?= $row['status'] === 'unread' ? 'fw-bold' : '' ?>">
                                    <!-- Sender & Role -->
                                    <td>
                                        <div><?= e($row['sender_name']) ?></div>
                                        <small class="text-muted" style="font-size: 0.75rem;">
                                            (<?= ucfirst(e($row['sender_role'])) ?>)
                                        </small>
                                    </td>

                                    <!-- Subject -->
                                    <td><?= e($row['subject']) ?></td>

                                    <!-- Preview -->
                                    <td class="text-muted small text-truncate" style="max-width: 250px;">
                                        <?= e($row['preview']) ?>
                                    </td>

                                    <!-- Date -->
                                    <td class="small text-muted">
                                        <?= e(date('M d, Y', strtotime($row['date']))) ?>
                                    </td>

                                    <!-- Actions Column: View & Delete Buttons -->
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <a href="view_message.php?id=<?= e($row['id']) ?>" 
                                               class="btn btn-sm btn-outline-primary btn-action-view">View</a>
                                            <a href="messages.php?tab=<?= e($tab) ?>&action=delete&id=<?= e($row['id']) ?>" 
                                               class="btn btn-sm btn-outline-danger btn-action-delete" 
                                               onclick="return confirm('Are you sure you want to delete this message?');">Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>