from pathlib import Path

code = r'''<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

$error = '';
$success = '';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$allowedStatuses = ['Pending', 'In Progress', 'Completed'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $taskName = trim($_POST['task_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $date = $_POST['scheduled_date'] ?? '';
            $time = $_POST['scheduled_time'] ?? '';
            $assignedTo = trim($_POST['assigned_to'] ?? '');
            $status = $_POST['status'] ?? 'Pending';

            if ($taskName === '' || $date === '' || $time === '') {
                throw new Exception('Task name, date, and time are required.');
            }

            $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
            $timeObject = DateTime::createFromFormat('!H:i', $time);

            if (
                !$dateObject ||
                $dateObject->format('Y-m-d') !== $date ||
                !$timeObject ||
                $timeObject->format('H:i') !== $time
            ) {
                throw new Exception('Please enter a valid date and time.');
            }

            if (!in_array($status, $allowedStatuses, true)) {
                throw new Exception('Please select a valid task status.');
            }

            if ($action === 'add') {
                $stmt = $pdo->prepare(
                    "INSERT INTO scheduled_tasks
                    (task_name, description, scheduled_date, scheduled_time, assigned_to, status)
                    VALUES (?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $taskName,
                    $description,
                    $date,
                    $time,
                    $assignedTo,
                    $status
                ]);

                $success = 'Scheduled task added successfully.';
            } else {
                if ($id < 1) {
                    throw new Exception('Invalid task ID.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE scheduled_tasks
                    SET task_name = ?, description = ?, scheduled_date = ?,
                        scheduled_time = ?, assigned_to = ?, status = ?
                    WHERE id = ?"
                );

                $stmt->execute([
                    $taskName,
                    $description,
                    $date,
                    $time,
                    $assignedTo,
                    $status,
                    $id
                ]);

                $success = 'Scheduled task updated successfully.';
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id < 1) {
                throw new Exception('Invalid task ID.');
            }

            $stmt = $pdo->prepare(
                "DELETE FROM scheduled_tasks WHERE id = ?"
            );
            $stmt->execute([$id]);

            $success = 'Scheduled task deleted successfully.';
        } else {
            throw new Exception('Invalid action.');
        }
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Database error. Check that the scheduled_tasks table exists.';
} catch (Exception $exception) {
    $error = $exception->getMessage();
}

$editTask = null;

try {
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare(
            "SELECT * FROM scheduled_tasks WHERE id = ?"
        );
        $stmt->execute([(int) $_GET['edit']]);
        $editTask = $stmt->fetch() ?: null;
    }

    $tasks = $pdo->query(
        "SELECT * FROM scheduled_tasks
         ORDER BY scheduled_date ASC, scheduled_time ASC"
    )->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $tasks = [];
    $error = 'Could not load tasks. Make sure the scheduled_tasks table exists.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduled Tasks</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            margin: 0;
            background: #f4f6f9;
            font-family: Arial, sans-serif;
            color: #263238;
        }

        .main-content {
            max-width: 1400px;
            margin: auto;
            padding: 30px;
        }

        .page-card {
            background: #ffffff;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .table th {
            background: #f1f3f5;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }
    </style>
</head>

<body>

<div class="main-content">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h2>Scheduled Tasks</h2>
            <p class="text-muted mb-0">
                Manage daily activities and assigned tasks.
            </p>
        </div>

        <a href="../admin/dashboard.php" class="btn btn-secondary">
            Back to Dashboard
        </a>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="page-card">
        <h4 class="mb-4">
            <?= $editTask ? 'Edit Scheduled Task' : 'Add Scheduled Task' ?>
        </h4>

        <form method="POST" action="index.php<?= $editTask ? '?edit=' . (int) $editTask['id'] : '' ?>">
            <input
                type="hidden"
                name="action"
                value="<?= $editTask ? 'update' : 'add' ?>"
            >

            <?php if ($editTask): ?>
                <input
                    type="hidden"
                    name="id"
                    value="<?= (int) $editTask['id'] ?>"
                >
            <?php endif; ?>

            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label">Task Name *</label>
                    <input
                        type="text"
                        name="task_name"
                        class="form-control"
                        placeholder="e.g. Morning Exercise"
                        maxlength="150"
                        required
                        value="<?= e($editTask['task_name'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Assigned To</label>
                    <input
                        type="text"
                        name="assigned_to"
                        class="form-control"
                        placeholder="Staff or caretaker name"
                        maxlength="100"
                        value="<?= e($editTask['assigned_to'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-12">
                    <label class="form-label">Description</label>
                    <textarea
                        name="description"
                        class="form-control"
                        rows="3"
                        placeholder="Enter task details"
                    ><?= e($editTask['description'] ?? '') ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Scheduled Date *</label>
                    <input
                        type="date"
                        name="scheduled_date"
                        class="form-control"
                        required
                        value="<?= e($editTask['scheduled_date'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label">Scheduled Time *</label>
                    <input
                        type="time"
                        name="scheduled_time"
                        class="form-control"
                        required
                        value="<?= e(isset($editTask['scheduled_time'])
                            ? substr($editTask['scheduled_time'], 0, 5)
                            : '') ?>"
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach ($allowedStatuses as $status): ?>
                            <option
                                value="<?= e($status) ?>"
                                <?= ($editTask['status'] ?? 'Pending') === $status
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <?= $editTask ? 'Update Task' : 'Add Task' ?>
                    </button>

                    <?php if ($editTask): ?>
                        <a href="index.php" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </form>
    </div>

    <div class="page-card">
        <h4 class="mb-4">Scheduled Task List</h4>

        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>Task Name</th>
                        <th>Assigned To</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!empty($tasks)): ?>
                        <?php foreach ($tasks as $task): ?>
                            <tr>
                                <td>
                                    <strong><?= e($task['task_name']) ?></strong>

                                    <?php if (!empty($task['description'])): ?>
                                        <div class="small text-muted">
                                            <?= e($task['description']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= e($task['assigned_to'] ?: 'Not assigned') ?>
                                </td>

                                <td>
                                    <?= e(date(
                                        'M d, Y',
                                        strtotime($task['scheduled_date'])
                                    )) ?>
                                </td>

                                <td>
                                    <?= e(date(
                                        'g:i A',
                                        strtotime($task['scheduled_time'])
                                    )) ?>
                                </td>

                                <td>
                                    <?php
                                    $badgeClass = match ($task['status']) {
                                        'Pending' => 'secondary',
                                        'In Progress' => 'primary',
                                        'Completed' => 'success',
                                        default => 'secondary'
                                    };
                                    ?>

                                    <span class="badge text-bg-<?= $badgeClass ?>">
                                        <?= e($task['status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <a
                                        href="index.php?edit=<?= (int) $task['id'] ?>"
                                        class="btn btn-sm btn-warning"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="index.php"
                                        class="d-inline"
                                        onsubmit="return confirm('Are you sure you want to delete this task?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $task['id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-danger"
                                        >
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                No scheduled tasks found. Add your first task above.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</body>
</html>
'''
path = Path("/mnt/data/schedules_index.php")
path.write_text(code, encoding="utf-8")
print(f"Created {path} ({len(code.splitlines())} lines)")
