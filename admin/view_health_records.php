<?php
session_start();
require_once __DIR__ . '/../config/db.php';

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: health_records.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT hr.*, r.resident_id AS resident_number,
            r.first_name, r.middle_name, r.last_name, r.room_number
     FROM health_records hr
     INNER JOIN residents r ON r.id = hr.resident_id
     WHERE hr.id = ?"
);
$stmt->execute([$id]);
$record = $stmt->fetch();

if (!$record) {
    header('Location: health_records.php');
    exit;
}

$fullName = trim(
    ($record['first_name'] ?? '') . ' ' .
    ($record['middle_name'] ?? '') . ' ' .
    ($record['last_name'] ?? '')
);
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.record-view-card {
    background:#f6fbdc;
    border:1px solid #dce5c5;
    border-radius:14px;
    padding:24px;
}
.record-view-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
}
.record-view-field {
    background:#fff;
    border:1px solid #d5ddc7;
    border-radius:9px;
    padding:12px;
    overflow-wrap:anywhere;
}
@media(max-width:650px) {
    .record-view-grid { grid-template-columns:1fr; }
}
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h1>Health Record Details</h1>
        <p class="text-muted mb-0">Resident: <?= h($fullName) ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="health_records.php" class="btn btn-outline-success">
            &larr; Back to Health Records
        </a>
        <a href="add_health_record.php?edit=<?= (int)$record['id'] ?>"
           class="btn btn-primary">Edit Record</a>
    </div>
</div>

<div class="record-view-card">
    <div class="record-view-grid">
        <div class="record-view-field"><strong>Resident ID</strong><br><?= h($record['resident_number']) ?></div>
        <div class="record-view-field"><strong>Room</strong><br><?= h($record['room_number'] ?: 'Not assigned') ?></div>
        <div class="record-view-field"><strong>Record Date</strong><br><?= h($record['record_date']) ?></div>
        <div class="record-view-field"><strong>Blood Pressure</strong><br><?= h($record['blood_pressure'] ?: 'Not recorded') ?></div>
        <div class="record-view-field"><strong>Temperature</strong><br><?= h($record['temperature'] !== null ? $record['temperature'] . ' °C' : 'Not recorded') ?></div>
        <div class="record-view-field"><strong>Weight</strong><br><?= h($record['weight'] !== null ? $record['weight'] . ' kg' : 'Not recorded') ?></div>
        <div class="record-view-field"><strong>Allergies</strong><br><?= nl2br(h($record['allergies'] ?: 'Not recorded')) ?></div>
        <div class="record-view-field"><strong>Medical Conditions</strong><br><?= nl2br(h($record['medical_conditions'] ?: 'Not recorded')) ?></div>
        <div class="record-view-field"><strong>Medications</strong><br><?= nl2br(h($record['medications'] ?: 'Not recorded')) ?></div>
        <div class="record-view-field"><strong>Notes</strong><br><?= nl2br(h($record['notes'] ?: 'No notes')) ?></div>
    </div>
</div>

</main>
</body>
</html>
