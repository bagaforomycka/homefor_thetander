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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><= $isEdit ? 'Edit Health Record' : 'Add Health Record' ?> | Home for the Aged</title>

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
            background: #f5f8f6;
            color: #24352b;
            font-family: Arial, Helvetica, sans-serif;
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
            padding: 24px !important;
            flex-grow: 1;
            box-shadow: none !important;
            overflow-x: hidden;
            transition: margin-left 0.3s ease;
        }

        .health-heading {
            background: #2f5e3e;
            color: white;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 24px;
        }

        .health-card {
            background: white;
            padding: 26px;
            border-radius: 14px;
            box-shadow: 0 3px 14px rgba(0,0,0,.06);
        }

        .form-label { font-weight: 600; }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php require __DIR__ . '/../includes/sidebar.php'; ?>

<div class="app-main">
    <div class="health-heading">
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
</div>

<!-- Hamburger Toggle & Sidebar Behavior Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('sidebarOverlay');
    var sidebar = document.querySelector(
        '.sidebar, #sidebar, .sidebar-container, aside, nav'
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
            '<a href="dashboard.php">' +
            '<img src="../images/white1.png" ' +
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

</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>