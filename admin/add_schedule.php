
<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$error = '';
$isEdit = false;
$scheduleId = 0;

$data = [
    'title' => '',
    'resident_id' => '',
    'assigned_staff' => '',
    'schedule_type' => 'Activity',
    'schedule_date' => date('Y-m-d'),
    'start_time' => '09:00',
    'end_time' => '',
    'location' => '',
    'description' => '',
    'status' => 'Scheduled'
];

/* Load an existing schedule for editing */
if (isset($_GET['edit'])) {
    $scheduleId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

    if (!$scheduleId) {
        exit('Invalid schedule ID.');
    }

    $stmt = $pdo->prepare('SELECT * FROM schedules WHERE id = :id');
    $stmt->execute(['id' => $scheduleId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        http_response_code(404);
        exit('Schedule not found.');
    }

    $isEdit = true;

    foreach ($data as $key => $value) {
        if (array_key_exists($key, $existing)) {
            $data[$key] = $existing[$key] ?? '';
        }
    }

    /* HTML time inputs require HH:MM */
    $data['start_time'] = substr((string)$data['start_time'], 0, 5);
    $data['end_time'] = $data['end_time'] !== ''
        ? substr((string)$data['end_time'], 0, 5)
        : '';
}

/* Load residents for the dropdown */
$residentStmt = $pdo->query(
    "SELECT id, resident_id, first_name, middle_name, last_name
     FROM residents
     ORDER BY first_name, last_name"
);
$residents = $residentStmt->fetchAll();

/* Save new or edited schedule */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scheduleId = filter_input(INPUT_POST, 'schedule_id', FILTER_VALIDATE_INT) ?: 0;
    $isEdit = $scheduleId > 0;

    $data = [
        'title' => trim($_POST['title'] ?? ''),
        'resident_id' => $_POST['resident_id'] ?? '',
        'assigned_staff' => trim($_POST['assigned_staff'] ?? ''),
        'schedule_type' => $_POST['schedule_type'] ?? 'Activity',
        'schedule_date' => $_POST['schedule_date'] ?? '',
        'start_time' => $_POST['start_time'] ?? '',
        'end_time' => $_POST['end_time'] ?? '',
        'location' => trim($_POST['location'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'status' => $_POST['status'] ?? 'Scheduled'
    ];

    $allowedTypes = [
        'Activity',
        'Medical Appointment',
        'Medication',
        'Meal',
        'Personal Care',
        'Other'
    ];

    $allowedStatuses = ['Scheduled', 'Completed', 'Cancelled'];

    $residentId = $data['resident_id'] === ''
        ? null
        : filter_var($data['resident_id'], FILTER_VALIDATE_INT);

    if (
        $data['title'] === '' ||
        $data['schedule_date'] === '' ||
        $data['start_time'] === ''
    ) {
        $error = 'Please fill in the title, date, and start time.';
    } elseif (
        !in_array($data['schedule_type'], $allowedTypes, true) ||
        !in_array($data['status'], $allowedStatuses, true)
    ) {
        $error = 'Please select a valid schedule type and status.';
    } elseif (
        $data['resident_id'] !== '' &&
        (!$residentId || $residentId < 1)
    ) {
        $error = 'Please select a valid resident.';
    } elseif (
        $data['end_time'] !== '' &&
        $data['end_time'] < $data['start_time']
    ) {
        $error = 'End time must not be earlier than start time.';
    } else {
        $date = DateTime::createFromFormat('!Y-m-d', $data['schedule_date']);
        $validDate = $date && $date->format('Y-m-d') === $data['schedule_date'];

        if (!$validDate) {
            $error = 'Please enter a valid schedule date.';
        } else {
            try {
                if ($residentId !== null) {
                    $checkResident = $pdo->prepare(
                        'SELECT id FROM residents WHERE id = :id'
                    );
                    $checkResident->execute(['id' => $residentId]);

                    if (!$checkResident->fetch()) {
                        throw new RuntimeException('The selected resident does not exist.');
                    }
                }

                $values = [
                    'title' => $data['title'],
                    'resident_id' => $residentId,
                    'assigned_staff' => $data['assigned_staff'] !== ''
                        ? $data['assigned_staff']
                        : null,
                    'schedule_type' => $data['schedule_type'],
                    'schedule_date' => $data['schedule_date'],
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'] !== ''
                        ? $data['end_time']
                        : null,
                    'location' => $data['location'] !== ''
                        ? $data['location']
                        : null,
                    'description' => $data['description'] !== ''
                        ? $data['description']
                        : null,
                    'status' => $data['status']
                ];

                if ($isEdit) {
                    $check = $pdo->prepare(
                        'SELECT id FROM schedules WHERE id = :id'
                    );
                    $check->execute(['id' => $scheduleId]);

                    if (!$check->fetch()) {
                        throw new RuntimeException('The schedule you are editing no longer exists.');
                    }

                    $values['id'] = $scheduleId;

                    $stmt = $pdo->prepare(
                        "UPDATE schedules SET
                            title = :title,
                            resident_id = :resident_id,
                            assigned_staff = :assigned_staff,
                            schedule_type = :schedule_type,
                            schedule_date = :schedule_date,
                            start_time = :start_time,
                            end_time = :end_time,
                            location = :location,
                            description = :description,
                            status = :status
                         WHERE id = :id"
                    );
                    $stmt->execute($values);

                    header('Location: schedules.php?success=updated');
                    exit;
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO schedules (
                        title, resident_id, assigned_staff,
                        schedule_type, schedule_date, start_time,
                        end_time, location, description, status
                    ) VALUES (
                        :title, :resident_id, :assigned_staff,
                        :schedule_type, :schedule_date, :start_time,
                        :end_time, :location, :description, :status
                    )"
                );
                $stmt->execute($values);

                header('Location: schedules.php?success=added');
                exit;
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            } catch (PDOException $e) {
                error_log('Schedule save error: ' . $e->getMessage());
                $error = 'Unable to save the schedule. Please check the database table and try again.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.schedule-form-header {
    background: #eaf6ee;
    border: 1px solid #d8eadc;
    border-radius: 14px;
    padding: 22px 26px;
    margin-bottom: 22px;
}

.schedule-form-header h1 {
    color: #17683a;
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 6px;
}

.schedule-form-header p {
    color: #61796a;
    margin: 0;
}

.schedule-form-panel {
    background: #fff;
    border: 1px solid #e3eee6;
    border-radius: 14px;
    padding: 24px;
    box-shadow: 0 4px 16px rgba(24,75,42,.05);
}

.schedule-form-panel label {
    font-weight: 600;
    color: #304b3b;
}

.schedule-form-panel .form-control,
.schedule-form-panel .form-select {
    min-height: 43px;
}

@media (max-width: 576px) {
    .schedule-form-header,
    .schedule-form-panel {
        padding: 16px;
    }
}
</style>

<div class="schedule-form-header d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <h1><?= $isEdit ? 'Edit Schedule' : 'Add Schedule' ?></h1>
        <p><?= $isEdit ? 'Update the schedule information below.' : 'Enter the details for a new schedule.' ?></p>
    </div>

    <a href="schedules.php" class="btn btn-outline-success">
        ← Back to Schedules
    </a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="schedule-form-panel">
    <form method="POST" action="">
        <input type="hidden" name="schedule_id"
               value="<?= $isEdit ? (int)$scheduleId : '' ?>">

        <div class="row g-3">
            <div class="col-md-8">
                <label for="title" class="form-label">Schedule Title *</label>
                <input type="text" class="form-control" id="title"
                       name="title" maxlength="150"
                       value="<?= h($data['title']) ?>" required>
            </div>

            <div class="col-md-4">
                <label for="schedule_type" class="form-label">Schedule Type *</label>
                <select class="form-select" id="schedule_type"
                        name="schedule_type" required>
                    <?php foreach ([
                        'Activity',
                        'Medical Appointment',
                        'Medication',
                        'Meal',
                        'Personal Care',
                        'Other'
                    ] as $type): ?>
                        <option value="<?= h($type) ?>"
                            <?= $data['schedule_type'] === $type ? 'selected' : '' ?>>
                            <?= h($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label for="resident_id" class="form-label">Resident</label>
                <select class="form-select" id="resident_id" name="resident_id">
                    <option value="">No specific resident</option>
                    <?php foreach ($residents as $resident): ?>
                        <?php
                        $residentName = trim(
                            $resident['first_name'] . ' ' .
                            ($resident['middle_name'] ?? '') . ' ' .
                            $resident['last_name']
                        );
                        ?>
                        <option value="<?= (int)$resident['id'] ?>"
                            <?= (string)$data['resident_id'] === (string)$resident['id'] ? 'selected' : '' ?>>
                            <?= h($residentName) ?> (<?= h($resident['resident_id']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label for="assigned_staff" class="form-label">Assigned Staff</label>
                <input type="text" class="form-control" id="assigned_staff"
                       name="assigned_staff" maxlength="150"
                       value="<?= h($data['assigned_staff']) ?>"
                       placeholder="Enter staff or caretaker name">
            </div>

            <div class="col-md-4">
                <label for="schedule_date" class="form-label">Date *</label>
                <input type="date" class="form-control" id="schedule_date"
                       name="schedule_date"
                       value="<?= h($data['schedule_date']) ?>" required>
            </div>

            <div class="col-md-4">
                <label for="start_time" class="form-label">Start Time *</label>
                <input type="time" class="form-control" id="start_time"
                       name="start_time"
                       value="<?= h($data['start_time']) ?>" required>
            </div>

            <div class="col-md-4">
                <label for="end_time" class="form-label">End Time</label>
                <input type="time" class="form-control" id="end_time"
                       name="end_time"
                       value="<?= h($data['end_time']) ?>">
            </div>

            <div class="col-md-6">
                <label for="location" class="form-label">Location</label>
                <input type="text" class="form-control" id="location"
                       name="location" maxlength="150"
                       value="<?= h($data['location']) ?>"
                       placeholder="e.g. Activity Room">
            </div>

            <div class="col-md-6">
                <label for="status" class="form-label">Status *</label>
                <select class="form-select" id="status" name="status" required>
                    <?php foreach (['Scheduled', 'Completed', 'Cancelled'] as $status): ?>
                        <option value="<?= h($status) ?>"
                            <?= $data['status'] === $status ? 'selected' : '' ?>>
                            <?= h($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <label for="description" class="form-label">Description / Notes</label>
                <textarea class="form-control" id="description"
                          name="description" rows="4"
                          placeholder="Add details or instructions"><?= h($data['description']) ?></textarea>
            </div>
        </div>

        <div class="d-flex gap-2 flex-wrap mt-4">
            <button type="submit" class="btn btn-success px-4">
                <?= $isEdit ? 'Save Changes' : 'Save Schedule' ?>
            </button>

            <a href="schedules.php" class="btn btn-outline-secondary">
                Cancel
            </a>
        </div>
    </form>
</div>

</main>
</body>
</html>
