<?php
/**
 * Home for the Tender - Health Records Dashboard
 * Integrated with the facility's existing Bootstrap 5 design tokens & color palette.
 */
session_start();

// Mock database connection test or placeholder data
$db_connected = true; 

// Sample Resident Health Records Data (can be replaced with PDO/MySQL queries)
$residents = [
    [
        'id' => 1,
        'name' => 'Mang Dre',
        'room' => 'Room 204',
        'last_updated' => 'Oct. 10, 2026',
        'blood_type' => 'A+',
        'allergies' => 'Penicillin, Peanuts',
        'medical_conditions' => 'Mild Hypertension, Osteoarthritis',
        'dietary_restrictions' => 'Low Sodium, Soft Foods',
        'bp' => '120/80 mmHg',
        'heart_rate' => '72 bpm',
        'temp' => '36.6 °C',
        'weight' => '58 kg',
        'medicine' => 'Amlodipine, Calcium Supplements',
        'dosage' => '5mg once daily / 500mg twice daily',
        'schedule' => 'Morning (8:00 AM)',
        'instructions' => 'Take with breakfast and plenty of water.',
        'prev_conditions' => 'None significant',
        'prev_incidents' => 'Slight slip in hallway (May 2025) - No fractures',
        'doctor_notes' => 'Patient is responding well to physical therapy and blood pressure medication.'
    ],
    [
        'id' => 2,
        'name' => 'Lola Nena',
        'room' => 'Room 101',
        'last_updated' => 'Sept. 18, 2026',
        'blood_type' => 'O+',
        'allergies' => 'Sulfa drugs',
        'medical_conditions' => 'Type 2 Diabetes, Early Stage Dementia',
        'dietary_restrictions' => 'Diabetic / No Added Sugar',
        'bp' => '130/85 mmHg',
        'heart_rate' => '78 bpm',
        'temp' => '36.8 °C',
        'weight' => '65 kg',
        'medicine' => 'Metformin, Donepezil',
        'dosage' => '500mg twice daily / 10mg nightly',
        'schedule' => 'Morning & Evening',
        'instructions' => 'Administer Metformin right after meals.',
        'prev_conditions' => 'Gestational neuropathy history',
        'prev_incidents' => 'None recorded in facility',
        'doctor_notes' => 'Monitor blood glucose levels twice weekly.'
    ],
    [
        'id' => 3,
        'name' => 'Mang Albert',
        'room' => 'Room 110',
        'last_updated' => 'Sept. 01, 2026',
        'blood_type' => 'B-',
        'allergies' => 'Latex',
        'medical_conditions' => 'Asthma, Chronic Bronchitis',
        'dietary_restrictions' => 'Gluten-Free',
        'bp' => '118/76 mmHg',
        'heart_rate' => '70 bpm',
        'temp' => '36.5 °C',
        'weight' => '52 kg',
        'medicine' => 'Salbutamol Inhaler, Multivitamins',
        'dosage' => '2 puffs as needed / 1 tablet daily',
        'schedule' => 'As needed / Daily morning',
        'instructions' => 'Keep rescue inhaler accessible at bedside table.',
        'prev_conditions' => 'Seasonal respiratory allergies',
        'prev_incidents' => 'Mild asthmatic flare-up triggered by dust (August)',
        'doctor_notes' => 'Ensure room air filters are cleaned monthly.'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Health Records Dashboard | Home for the Tender</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome for Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --green: #33b960;
            --dark-green: #17683a;
            --pale-green: #edf8ef;
            --text: #304b3b;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 25px;
        }

        body {
            margin: 0;
            background: #f8fbf8;
            color: var(--text);
            font-family: Arial, sans-serif;
        }

        .navbar {
            background: #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,.06);
        }

        .brand-name {
            color: var(--dark-green);
            font-size: 1.45rem;
            font-weight: 700;
        }

        .brand-tagline {
            color: #64816c;
            font-size: .8rem;
            letter-spacing: 2px;
        }

        .dashboard-header {
            background: linear-gradient(120deg, #eaf7ec, #fff 60%, #e3f3e4);
            padding: 40px 0;
            border-bottom: 1px solid #e1eee3;
        }

        .section-title {
            color: var(--dark-green);
            font-weight: 700;
        }

        .form-control, .form-select {
            padding: 12px;
            border-radius: 8px;
            border-color: #d2e4d5;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 .2rem rgba(51,185,96,.16);
        }

        .btn-green {
            background: var(--green);
            color: #fff;
            border: 1px solid var(--green);
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 700;
        }

        .btn-green:hover {
            background: #269b4b;
            border-color: #269b4b;
            color: #fff;
        }

        .btn-outline-green {
            color: var(--dark-green);
            border: 1px solid var(--green);
            border-radius: 8px;
            padding: 9px 16px;
            font-weight: 700;
        }

        .btn-outline-green:hover {
            background: var(--pale-green);
            color: var(--dark-green);
        }

        .record-card {
            background: #fff;
            border: 1px solid #e1eee3;
            border-radius: 14px;
            transition: transform .2s, box-shadow .2s;
            box-shadow: 0 4px 15px rgba(28,90,48,.04);
        }

        .record-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(28,90,48,.09);
        }

        .badge-room {
            background-color: var(--pale-green);
            color: var(--dark-green);
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 6px;
        }

        .modal-content {
            border-radius: 16px;
            border: 1px solid #e1eee3;
        }

        .modal-header {
            background: var(--pale-green);
            color: var(--dark-green);
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }

        footer {
            background: #175c38;
            color: #fff;
            padding: 25px 0;
            margin-top: 60px;
        }

        footer a {
            color: #fff;
            text-decoration: none;
        }
        footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container py-2">
        <a class="navbar-brand text-decoration-none" href="index.php">
            <span class="brand-name">🏡 Home for the Tender</span>
            <span class="brand-tagline d-block">CARE · COMFORT · DIGNITY</span>
        </a>

        <div class="ms-auto d-flex gap-3 align-items-center">
            <span class="text-secondary small d-none d-sm-inline">
                <i class="fa-solid fa-user-shield text-success me-1"></i> Authorized Staff Portal
            </span>
            <a href="index.php" class="btn btn-outline-success btn-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Home
            </a>
        </div>
    </div>
</nav>

<header class="dashboard-header">
    <div class="container">
        <div class="row align-items-center justify-content-between g-3">
            <div class="col-lg-7">
                <span class="badge rounded-pill text-bg-success mb-2">Care Management System</span>
                <h1 class="section-title display-6 mb-1">Health Records</h1>
                <p class="text-secondary mb-0">
                    Manage, review, and update vital resident health records securely and compassionately.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <button type="button" class="btn btn-green shadow-sm" data-bs-toggle="modal" data-bs-target="#addRecordModal">
                    <i class="fa-solid fa-plus me-2"></i> Add Health Record
                </button>
            </div>
        </div>
    </div>
</header>

<main class="container my-5">
    <!-- Search Bar Section -->
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="input-group shadow-sm">
                <span class="input-group-text bg-white border-end-0 text-success">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </span>
                <input type="text" id="residentSearch" class="form-control border-start-0 ps-0" placeholder="Search resident by name or room...">
                <button class="btn btn-outline-secondary bg-white border-start-0" type="button" onclick="clearSearch()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>
        <div class="col-lg-6 text-lg-end mt-3 mt-lg-0 d-flex align-items-center justify-content-lg-end gap-2">
            <span class="text-secondary small">Showing <strong id="visibleCount"><?= count($residents) ?></strong> of <?= count($residents) ?> records</span>
        </div>
    </div>

    <!-- Health Record Cards List -->
    <div class="row g-4" id="recordsContainer">
        <?php foreach ($residents as $res): ?>
        <div class="col-md-6 col-xl-4 resident-card-item" data-name="<?= strtolower($res['name']) ?>" data-room="<?= strtolower($res['room']) ?>">
            <div class="record-card p-4 h-d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h4 class="section-title h5 mb-0 fw-bold"><?= htmlspecialchars($res['name']) ?></h4>
                        <span class="badge badge-room"><?= htmlspecialchars($res['room']) ?></span>
                    </div>
                    <p class="text-secondary small mb-3">
                        <i class="fa-regular fa-clock me-1 text-success"></i> Last Updated: <?= htmlspecialchars($res['last_updated']) ?>
                    </p>
                    
                    <hr class="text-muted opacity-25 my-3">

                    <div class="row g-2 small mb-4 text-secondary">
                        <div class="col-6">
                            <strong>Blood Type:</strong> <span class="text-dark"><?= htmlspecialchars($res['blood_type']) ?></span>
                        </div>
                        <div class="col-6">
                            <strong>Vitals (BP):</strong> <span class="text-dark"><?= htmlspecialchars($res['bp']) ?></span>
                        </div>
                        <div class="col-12 text-truncate">
                            <strong>Allergies:</strong> <span class="text-danger"><?= htmlspecialchars($res['allergies']) ?></span>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-green btn-sm flex-grow-1" 
                            data-bs-toggle="modal" 
                            data-bs-target="#viewRecordModal"
                            data-name="<?= htmlspecialchars($res['name']) ?>"
                            data-room="<?= htmlspecialchars($res['room']) ?>"
                            data-updated="<?= htmlspecialchars($res['last_updated']) ?>"
                            data-blood="<?= htmlspecialchars($res['blood_type']) ?>"
                            data-allergies="<?= htmlspecialchars($res['allergies']) ?>"
                            data-conditions="<?= htmlspecialchars($res['medical_conditions']) ?>"
                            data-diet="<?= htmlspecialchars($res['dietary_restrictions']) ?>"
                            data-bp="<?= htmlspecialchars($res['bp']) ?>"
                            data-heart="<?= htmlspecialchars($res['heart_rate']) ?>"
                            data-temp="<?= htmlspecialchars($res['temp']) ?>"
                            data-weight="<?= htmlspecialchars($res['weight']) ?>"
                            data-medicine="<?= htmlspecialchars($res['medicine']) ?>"
                            data-dosage="<?= htmlspecialchars($res['dosage']) ?>"
                            data-schedule="<?= htmlspecialchars($res['schedule']) ?>"
                            data-instructions="<?= htmlspecialchars($res['instructions']) ?>"
                            data-prevcond="<?= htmlspecialchars($res['prev_conditions']) ?>"
                            data-previnc="<?= htmlspecialchars($res['prev_incidents']) ?>"
                            data-notes="<?= htmlspecialchars($res['doctor_notes']) ?>">
                        <i class="fa-solid fa-eye me-1"></i> View Record
                    </button>
                    
                    <button type="button" class="btn btn-outline-green btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#updateRecordModal"
                            data-id="<?= $res['id'] ?>"
                            data-name="<?= htmlspecialchars($res['name']) ?>"
                            data-room="<?= htmlspecialchars($res['room']) ?>"
                            data-blood="<?= htmlspecialchars($res['blood_type']) ?>"
                            data-allergies="<?= htmlspecialchars($res['allergies']) ?>"
                            data-conditions="<?= htmlspecialchars($res['medical_conditions']) ?>"
                            data-diet="<?= htmlspecialchars($res['dietary_restrictions']) ?>"
                            data-bp="<?= htmlspecialchars($res['bp']) ?>"
                            data-heart="<?= htmlspecialchars($res['heart_rate']) ?>"
                            data-temp="<?= htmlspecialchars($res['temp']) ?>"
                            data-weight="<?= htmlspecialchars($res['weight']) ?>"
                            data-medicine="<?= htmlspecialchars($res['medicine']) ?>"
                            data-dosage="<?= htmlspecialchars($res['dosage']) ?>"
                            data-schedule="<?= htmlspecialchars($res['schedule']) ?>"
                            data-instructions="<?= htmlspecialchars($res['instructions']) ?>"
                            data-prevcond="<?= htmlspecialchars($res['prev_conditions']) ?>"
                            data-previnc="<?= htmlspecialchars($res['prev_incidents']) ?>"
                            data-notes="<?= htmlspecialchars($res['doctor_notes']) ?>">
                        <i class="fa-solid fa-pen-to-square me-1"></i> Update
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Empty search notice -->
    <div id="noResults" class="text-center py-5 d-none">
        <div class="display-4 text-muted mb-3">🔍</div>
        <h4 class="section-title">No residents found</h4>
        <p class="text-secondary">Try searching with a different resident name or room number.</p>
    </div>
</main>

<div class="modal fade" id="viewRecordModal" tabindex="-1" aria-labelledby="viewRecordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="badge bg-success mb-1" id="modalRoomBadge">Room 204</span>
                    <h3 class="modal-title fs-4 fw-bold" id="modalResidentName">Resident Name</h3>
                    <small class="text-secondary" id="modalLastUpdated">Last Updated: Sept. 19, 2026</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                
                <!-- Basic Information -->
                <div class="mb-4">
                    <h5 class="section-title border-bottom pb-2 mb-3">
                        <i class="fa-solid fa-id-card text-success me-2"></i> Basic Information
                    </h5>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="p-3 bg-light rounded-3">
                                <span class="text-secondary small d-block">Blood Type</span>
                                <strong class="text-dark fs-6" id="valBloodType">-</strong>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 bg-light rounded-3">
                                <span class="text-secondary small d-block">Allergies</span>
                                <strong class="text-danger fs-6" id="valAllergies">-</strong>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 bg-light rounded-3">
                                <span class="text-secondary small d-block">Medical Conditions</span>
                                <strong class="text-dark" id="valConditions">-</strong>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 bg-light rounded-3">
                                <span class="text-secondary small d-block">Dietary Restrictions</span>
                                <strong class="text-dark" id="valDiet">-</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vital Signs -->
                <div class="mb-4">
                    <h5 class="section-title border-bottom pb-2 mb-3">
                        <i class="fa-solid fa-heart-pulse text-success me-2"></i> Vital Signs
                    </h5>
                    <div class="row g-3 text-center">
                        <div class="col-6 col-sm-3">
                            <div class="p-3 bg-light rounded-3 border">
                                <span class="text-secondary small d-block">Blood Pressure</span>
                                <strong class="text-dark" id="valBP">-</strong>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="p-3 bg-light rounded-3 border">
                                <span class="text-secondary small d-block">Heart Rate</span>
                                <strong class="text-dark" id="valHeart">-</strong>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="p-3 bg-light rounded-3 border">
                                <span class="text-secondary small d-block">Temperature</span>
                                <strong class="text-dark" id="valTemp">-</strong>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="p-3 bg-light rounded-3 border">
                                <span class="text-secondary small d-block">Weight</span>
                                <strong class="text-dark" id="valWeight">-</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Medication -->
                <div class="mb-4">
                    <h5 class="section-title border-bottom pb-2 mb-3">
                        <i class="fa-solid fa-pills text-success me-2"></i> Medication Schedule & Instructions
                    </h5>
                    <div class="p-3 bg-light rounded-3">
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <span class="text-secondary small d-block">Medicine</span>
                                <strong id="valMedicine">-</strong>
                            </div>
                            <div class="col-md-6">
                                <span class="text-secondary small d-block">Dosage</span>
                                <strong id="valDosage">-</strong>
                            </div>
                        </div>
                        <div class="row g-2 pt-2 border-top">
                            <div class="col-md-6">
                                <span class="text-secondary small d-block">Schedule</span>
                                <strong id="valSchedule">-</strong>
                            </div>
                            <div class="col-md-6">
                                <span class="text-secondary small d-block">Instructions</span>
                                <span class="text-dark" id="valInstructions">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Medical History -->
                <div>
                    <h5 class="section-title border-bottom pb-2 mb-3">
                        <i class="fa-solid fa-notes-medical text-success me-2"></i> Medical History & Doctor Notes
                    </h5>
                    <div class="p-3 bg-light rounded-3">
                        <div class="mb-2">
                            <span class="text-secondary small d-block">Previous Conditions</span>
                            <span id="valPrevCond">-</span>
                        </div>
                        <div class="mb-2 pt-2 border-top">
                            <span class="text-secondary small d-block">Previous Incidents</span>
                            <span id="valPrevInc">-</span>
                        </div>
                        <div class="pt-2 border-top">
                            <span class="text-secondary small d-block">Doctor Notes</span>
                            <p class="text-dark mb-0 fst-italic" id="valNotes">-</p>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addRecordModal" tabindex="-1" aria-labelledby="addRecordModalLabel" aria-hidden="true">
    <!-- Notice: modal-dialog-scrollable class here -->
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold text-success" id="addRecordModalLabel">
                    <span class="fs-4 me-1">＋</span> Add New Health Record
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form action="save_record.php" method="POST">
                <!-- Inline scroll style added as a fail-safe -->
                <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">

                    <!-- Resident Identification -->
                    <h5 class="section-title mb-3 fw-bold text-success fs-6">Resident Identification</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Resident Name</label>
                            <input type="text" class="form-control" name="resident_name" placeholder="" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Room Number</label>
                            <input type="text" class="form-control" name="room_number" placeholder="" required>
                        </div>
                    </div>

                    <!-- Basic Information -->
                    <h5 class="section-title mb-3 fw-bold text-success fs-6">Basic Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Blood Type</label>
                            <select class="form-select" name="blood_type" required>
                                <option value="">Select Blood Type</option>
                                <option value="A+">A+</option>
                                <option value="A-">A-</option>
                                <option value="B+">B+</option>
                                <option value="B-">B-</option>
                                <option value="AB+">AB+</option>
                                <option value="AB-">AB-</option>
                                <option value="O+">O+</option>
                                <option value="O-">O-</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Allergies</label>
                            <input type="text" class="form-control" name="allergies" placeholder="e.g. Penicillin, Peanuts (or None)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Medical Conditions</label>
                            <input type="text" class="form-control" name="medical_conditions" placeholder="e.g. Hypertension, Arthritis">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Dietary Restrictions</label>
                            <input type="text" class="form-control" name="dietary_restrictions" placeholder="e.g. Low Sodium, Diabetic">
                        </div>
                    </div>

                    <!-- Vital Signs -->
                    <h5 class="section-title mb-3 fw-bold text-success fs-6">Vital Signs</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-semibold">Blood Pressure</label>
                            <input type="text" class="form-control" name="blood_pressure" placeholder="120/80">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-semibold">Heart Rate</label>
                            <input type="text" class="form-control" name="heart_rate" placeholder="72 bpm">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-semibold">Temperature</label>
                            <input type="text" class="form-control" name="temperature" placeholder="36.5 °C">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-semibold">Weight</label>
                            <input type="text" class="form-control" name="weight" placeholder="60 kg">
                        </div>
                    </div>

                    <!-- Medication -->
                    <h5 class="section-title mb-3 fw-bold text-success fs-6">Medication</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Prescribed Medications</label>
                            <textarea class="form-control" name="medication_list" rows="3" placeholder="List medications and dosages..."></textarea>
                        </div>
                    </div>

                    <!-- Medical History -->
                    <h5 class="section-title mb-3 fw-bold text-success fs-6">Medical History</h5>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Previous Conditions</label>
                            <input type="text" class="form-control" name="previous_conditions" placeholder="List any past illnesses">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Previous Incidents</label>
                            <input type="text" class="form-control" name="previous_incidents" placeholder="Record any falls or events">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Doctor Notes</label>
                            <textarea class="form-control" name="doctor_notes" rows="3" placeholder="Enter clinical observations or recommendations..."></textarea>
                        </div>
                    </div>

                </div>

                <!-- Modal Footer -->
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-green px-4">Save Health Record</button>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- UPDATE HEALTH RECORD MODAL -->
<div class="modal fade" id="updateRecordModal" tabindex="-1" aria-labelledby="updateRecordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            
            <!-- Modal Header -->
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold text-success fs-4" id="updateRecordModalLabel">
                    <span class="me-1">✏️</span> Update Health Record
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form action="#" method="POST" onsubmit="handleFormSubmit(event, 'Record updated successfully!')">
                <!-- Modal Body with explicit scroll constraints -->
                <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                    
                    <!-- 1. Resident Identification -->
                    <h5 class="section-title mb-3 fw-bold text-success fs-6">Resident Identification</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Resident Name</label>
                            <input type="text" class="form-control" id="up_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Room Number</label>
                            <input type="text" class="form-control" id="up_room" required>
                        </div>
                    </div>

                    <!-- 2. Basic Information -->
                    <h5 class="section-title mb-3 fw-bold text-success fs-6">Basic Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Blood Type</label>
                            <input type="text" class="form-control" id="up_blood">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Allergies</label>
                            <input type="text" class="form-control" id="up_allergies">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Medical Conditions</label>
                            <input type="text" class="form-control" id="up_conditions">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Dietary Restrictions</label>
                            <input type="text" class="form-control" id="up_diet">
                        </div>
                    </div>

                    <!-- 3. Vital Signs -->
                    <h5 class="section-title mb-3 fw-bold text-success fs-6">Vital Signs</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold">Blood Pressure</label>
                            <input type="text" class="form-control" id="up_bp">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold">Heart Rate</label>
                            <input type="text" class="form-control" id="up_heart">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold">Temperature</label>
                            <input type="text" class="form-control" id="up_temp">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold">Weight</label>
                            <input type="text" class="form-control" id="up_weight">
                        </div>
                    </div>

                    <!-- 4. Medication -->
                    <h5 class="section-title mb-3 fw-bold text-success fs-6">Medication</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Medicine</label>
                            <input type="text" class="form-control" id="up_medicine">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Dosage</label>
                            <input type="text" class="form-control" id="up_dosage">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Schedule</label>
                            <input type="text" class="form-control" id="up_schedule">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Instructions</label>
                            <input type="text" class="form-control" id="up_instructions">
                        </div>
                    </div>

                    <!-- 5. Medical History -->
                    <h5 class="section-title mb-3 fw-bold text-success fs-6">Medical History</h5>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Previous Conditions</label>
                            <input type="text" class="form-control" id="up_prevcond">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Previous Incidents</label>
                            <input type="text" class="form-control" id="up_previnc">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Doctor Notes</label>
                            <textarea class="form-control" rows="3" id="up_notes"></textarea>
                        </div>
                    </div>

                </div>

                <!-- Modal Footer with Update Button -->
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-green px-4">Update Health Record</button>
                </div>
            </form>

        </div>
    </div>
</div>

<footer>
    <div class="container text-center">
        <strong>🏡 Home for the Tender</strong>
        <p class="mb-2 mt-2">Care · Comfort · Dignity</p>
        <a href="terms.php">Terms and Conditions</a>
        <span class="mx-2">|</span>
        <span>© <?= date('Y') ?> Home for the Tender</span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Live Search filtering functionality
    const searchInput = document.getElementById('residentSearch');
    const residentCards = document.querySelectorAll('.resident-card-item');
    const noResultsDiv = document.getElementById('noResults');
    const visibleCountSpan = document.getElementById('visibleCount');

    searchInput.addEventListener('input', function() {
        const query = searchInput.value.toLowerCase().trim();
        let visibleCount = 0;

        residentCards.forEach(card => {
            const name = card.getAttribute('data-name');
            const room = card.getAttribute('data-room');
            if (name.includes(query) || room.includes(query)) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        visibleCountSpan.textContent = visibleCount;
        if (visibleCount === 0) {
            noResultsDiv.classList.remove('d-none');
        } else {
            noResultsDiv.classList.add('d-none');
        }
    });

    function clearSearch() {
        searchInput.value = '';
        residentCards.forEach(card => card.style.display = '');
        visibleCountSpan.textContent = residentCards.length;
        noResultsDiv.classList.add('d-none');
    }

    // Populate View Record Modal with data attributes
    const viewRecordModal = document.getElementById('viewRecordModal');
    viewRecordModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        
        document.getElementById('modalResidentName').textContent = button.getAttribute('data-name');
        document.getElementById('modalRoomBadge').textContent = button.getAttribute('data-room');
        document.getElementById('modalLastUpdated').textContent = 'Last Updated: ' + button.getAttribute('data-updated');
        
        document.getElementById('valBloodType').textContent = button.getAttribute('data-blood');
        document.getElementById('valAllergies').textContent = button.getAttribute('data-allergies');
        document.getElementById('valConditions').textContent = button.getAttribute('data-conditions');
        document.getElementById('valDiet').textContent = button.getAttribute('data-diet');
        
        document.getElementById('valBP').textContent = button.getAttribute('data-bp');
        document.getElementById('valHeart').textContent = button.getAttribute('data-heart');
        document.getElementById('valTemp').textContent = button.getAttribute('data-temp');
        document.getElementById('valWeight').textContent = button.getAttribute('data-weight');
        
        document.getElementById('valMedicine').textContent = button.getAttribute('data-medicine');
        document.getElementById('valDosage').textContent = button.getAttribute('data-dosage');
        document.getElementById('valSchedule').textContent = button.getAttribute('data-schedule');
        document.getElementById('valInstructions').textContent = button.getAttribute('data-instructions');
        
        document.getElementById('valPrevCond').textContent = button.getAttribute('data-prevcond');
        document.getElementById('valPrevInc').textContent = button.getAttribute('data-previnc');
        document.getElementById('valNotes').textContent = button.getAttribute('data-notes');
    });

    // Populate Update Record Modal with existing record values
    const updateRecordModal = document.getElementById('updateRecordModal');
    updateRecordModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        
        document.getElementById('up_name').value = button.getAttribute('data-name');
        document.getElementById('up_room').value = button.getAttribute('data-room');
        document.getElementById('up_blood').value = button.getAttribute('data-blood');
        document.getElementById('up_allergies').value = button.getAttribute('data-allergies');
        document.getElementById('up_conditions').value = button.getAttribute('data-conditions');
        document.getElementById('up_diet').value = button.getAttribute('data-diet');
        document.getElementById('up_bp').value = button.getAttribute('data-bp');
        document.getElementById('up_heart').value = button.getAttribute('data-heart');
        document.getElementById('up_temp').value = button.getAttribute('data-temp');
        document.getElementById('up_weight').value = button.getAttribute('data-weight');
        document.getElementById('up_medicine').value = button.getAttribute('data-medicine');
        document.getElementById('up_dosage').value = button.getAttribute('data-dosage');
        document.getElementById('up_schedule').value = button.getAttribute('data-schedule');
        document.getElementById('up_instructions').value = button.getAttribute('data-instructions');
        document.getElementById('up_prevcond').value = button.getAttribute('data-prevcond');
        document.getElementById('up_previnc').value = button.getAttribute('data-previnc');
        document.getElementById('up_notes').value = button.getAttribute('data-notes');
    });

    // Custom success notification handler (avoiding alert())
    function handleFormSubmit(event, message) {
        event.preventDefault();
        
        // Close any open modals
        const openModals = document.querySelectorAll('.modal.show');
        openModals.forEach(modalEl => {
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
        });

        // Create elegant floating toast
        const toastContainer = document.createElement('div');
        toastContainer.className = 'position-fixed bottom-0 end-0 p-3';
        toastContainer.style.zIndex = '1080';
        toastContainer.innerHTML = `
            <div class="toast align-items-center text-bg-success border-0 show shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body fw-semibold">
                        <i class="fa-solid fa-circle-check me-2"></i> ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;
        document.body.appendChild(toastContainer);
        setTimeout(() => toastContainer.remove(), 4000);
    }
</script>

</body>
</html>