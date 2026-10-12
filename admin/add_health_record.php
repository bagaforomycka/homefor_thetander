
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

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$errors = [];
$residentId = '';
$recordDate = date('Y-m-d');
$bloodPressure = '';
$temperature = '';
$weight = '';
$allergies = '';
$medicalConditions = '';
$medications = '';
$notes = '';

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$isEdit = $editId && $editId > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $isEdit = $editId && $editId > 0;

    $residentId = trim($_POST['resident_id'] ?? '');
    $recordDate = trim($_POST['record_date'] ?? '');
    $bloodPressure = trim($_POST['blood_pressure'] ?? '');
    $temperature = trim($_POST['temperature'] ?? '');
    $weight = trim($_POST['weight'] ?? '');
    $allergies = trim($_POST['allergies'] ?? '');
    $medicalConditions = trim($_POST['medical_conditions'] ?? '');
    $medications = trim($_POST['medications'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($residentId === '' || !ctype_digit($residentId)) {
        $errors[] = 'Please select a resident.';
    }

    $date = DateTime::createFromFormat('!Y-m-d', $recordDate);
    if (!$date || $date->format('Y-m-d') !== $recordDate) {
        $errors[] = 'Please enter a valid record date.';
    }

    if (!$errors) {
        try {
            $check = $pdo->prepare('SELECT id FROM residents WHERE id = ?');
            $check->execute([$residentId]);

            if (!$check->fetch()) {
                $errors[] = 'The selected resident was not found.';
            } else {
                if ($isEdit) {
                    $stmt = $pdo->prepare(
                        'UPDATE health_records SET
                        resident_id = ?, record_date = ?, blood_pressure = ?,
                        temperature = ?, weight = ?, allergies = ?,
                        medical_conditions = ?, medications = ?, notes = ?
                        WHERE id = ?'
                    );
                    $stmt->execute([
                        $residentId, $recordDate, $bloodPressure ?: null,
                        $temperature ?: null, $weight ?: null,
                        $allergies ?: null, $medicalConditions ?: null,
                        $medications ?: null, $notes ?: null, $editId
                    ]);
                    header('Location: health_records.php?success=updated');
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO health_records
                        (resident_id, record_date, blood_pressure, temperature,
                        weight, allergies, medical_conditions, medications, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $residentId, $recordDate, $bloodPressure ?: null,
                        $temperature ?: null, $weight ?: null,
                        $allergies ?: null, $medicalConditions ?: null,
                        $medications ?: null, $notes ?: null
                    ]);
                    header('Location: health_records.php?success=added');
                }
                exit;
            }
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = 'Could not save the health record. Please check the database table.';
        }
    }
}

if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare('SELECT * FROM health_records WHERE id = ?');
    $stmt->execute([$editId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        exit('Health record not found.');
    }

    $residentId = (string)$existing['resident_id'];
    $recordDate = $existing['record_date'];
    $bloodPressure = $existing['blood_pressure'] ?? '';
    $temperature = $existing['temperature'] ?? '';
    $weight = $existing['weight'] ?? '';
    $allergies = $existing['allergies'] ?? '';
    $medicalConditions = $existing['medical_conditions'] ?? '';
    $medications = $existing['medications'] ?? '';
    $notes = $existing['notes'] ?? '';
}

$residents = $pdo->query(
    'SELECT id, resident_id, first_name, last_name
     FROM residents ORDER BY first_name, last_name'
)->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/../includes/sidebar.php';
?>

<style>
    body { background: #f5f8f6; color: #24352b; }
    .health-heading {
        background: #2f5e3e;
        color: white;
        border-radius: 14px;
        padding: 22px;
    }
    .health-card {
        background: white;
        padding: 26px;
        border-radius: 14px;
        box-shadow: 0 3px 14px rgba(0,0,0,.06);
    }
    .form-label { font-weight: 600; }
</style>

<div class="health-heading mb-4">
    <h1 class="h3 mb-1"><?= $isEdit ? 'Edit Health Record' : 'Add Health Record' ?></h1>
    <p class="mb-0">Enter the resident's health information below.</p>
</div>

<div class="health-card">
    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$residents): ?>
        <div class="alert alert-warning">
            No residents are registered yet. Add a resident before recording health information.
        </div>
    <?php else: ?>
    <form method="post">
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= e($editId) ?>">
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-6">
                <label for="resident_id" class="form-label">Resident *</label>
                <select name="resident_id" id="resident_id" class="form-select" required>
                    <option value="">Select resident</option>
                    <?php foreach ($residents as $resident): ?>
                        <option value="<?= e($resident['id']) ?>"
                            <?= (string)$residentId === (string)$resident['id'] ? 'selected' : '' ?>>
                            <?= e($resident['resident_id']) ?> —
                            <?= e($resident['first_name'] . ' ' . $resident['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label for="record_date" class="form-label">Record Date *</label>
                <input type="date" name="record_date" id="record_date"
                       class="form-control" value="<?= e($recordDate) ?>" required>
            </div>

            <div class="col-md-4">
                <label for="blood_pressure" class="form-label">Blood Pressure</label>
                <input type="text" name="blood_pressure" id="blood_pressure"
                       class="form-control" placeholder="e.g. 120/80"
                       value="<?= e($bloodPressure) ?>">
            </div>

            <div class="col-md-4">
                <label for="temperature" class="form-label">Temperature (°C)</label>
                <input type="number" step="0.1" name="temperature" id="temperature"
                       class="form-control" value="<?= e($temperature) ?>">
            </div>

            <div class="col-md-4">
                <label for="weight" class="form-label">Weight (kg)</label>
                <input type="number" step="0.1" name="weight" id="weight"
                       class="form-control" value="<?= e($weight) ?>">
            </div>

            <div class="col-12">
                <label for="allergies" class="form-label">Allergies</label>
                <textarea name="allergies" id="allergies" class="form-control"
                          rows="2"><?= e($allergies) ?></textarea>
            </div>

            <div class="col-12">
                <label for="medical_conditions" class="form-label">Medical Conditions</label>
                <textarea name="medical_conditions" id="medical_conditions"
                          class="form-control" rows="2"><?= e($medicalConditions) ?></textarea>
            </div>

            <div class="col-12">
                <label for="medications" class="form-label">Medications</label>
                <textarea name="medications" id="medications" class="form-control"
                          rows="2"><?= e($medications) ?></textarea>
            </div>

            <div class="col-12">
                <label for="notes" class="form-label">Additional Notes</label>
                <textarea name="notes" id="notes" class="form-control"
                          rows="3"><?= e($notes) ?></textarea>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-success">
                <?= $isEdit ? 'Update Health Record' : 'Save Health Record' ?>
            </button>
            <a href="health_records.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>

</main>
</body>
</html>
