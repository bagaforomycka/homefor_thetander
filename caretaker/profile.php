
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'caretaker'
) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/avatar.php';

$user_id = (int) $_SESSION['user_id'];

$success_msg = '';
$error_msg = '';
$open_edit = false;

function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function show_val($value): string
{
    $value = trim((string) $value);

    return $value !== ''
        ? h($value)
        : '<span class="text-muted">Not set</span>';
}

/* CSRF protection */

if (empty($_SESSION['caretaker_profile_csrf'])) {
    $_SESSION['caretaker_profile_csrf'] = bin2hex(random_bytes(32));
}

/* Upload directory */

$upload_dir = __DIR__ . '/../uploads/profiles/';

if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        $error_msg = 'Unable to create the profile picture folder.';
    }
}

/* Ensure profile_picture exists in users */

$columns = [];

foreach ($pdo->query('SHOW COLUMNS FROM users') as $column) {
    $columns[] = $column['Field'];
}

if (!in_array('profile_picture', $columns, true)) {
    try {
        $pdo->exec(
            'ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL'
        );
    } catch (Throwable $e) {
        $error_msg = 'Unable to prepare the profile picture database column.';
    }
}

/* Get the logged-in caretaker's information */

function getCaretakerProfile(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare(
        "SELECT
            u.id AS user_id,
            u.full_name AS user_full_name,
            u.username,
            u.password AS user_password,
            u.role,
            u.status AS user_status,
            u.profile_picture,
            c.employee_id,
            c.full_name AS caretaker_full_name,
            c.contact,
            c.address,
            c.position,
            c.shift,
            c.status AS employment_status
         FROM users u
         INNER JOIN caretakers c
            ON c.username = u.username
         WHERE u.id = ?
           AND LOWER(u.role) = 'caretaker'
         LIMIT 1"
    );

    $stmt->execute([$user_id]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$user = getCaretakerProfile($pdo, $user_id);

if (!$user) {
    http_response_code(404);
    exit('Caretaker profile not found. Please contact the administrator.');
}

/* Handle submitted forms */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = (string) ($_POST['csrf'] ?? '');

    if (
        !hash_equals(
            $_SESSION['caretaker_profile_csrf'],
            $submitted_token
        )
    ) {
        $error_msg = 'Your session expired. Please refresh the page.';
    }

    /* Update profile */

    elseif (isset($_POST['update_profile'])) {

        $open_edit = true;

        $full_name = trim((string) ($_POST['full_name'] ?? ''));
        $contact = trim((string) ($_POST['contact'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));

        if ($full_name === '' || mb_strlen($full_name) > 100) {
            $error_msg = 'Please enter your full name (up to 100 characters).';
        } elseif (
            $contact !== '' &&
            !preg_match('/^[0-9+()\-\\s]{7,20}$/', $contact)
        ) {
            $error_msg = 'Please enter a valid contact number.';
        } elseif (mb_strlen($address) > 255) {
            $error_msg = 'Address must not exceed 255 characters.';
        }

        /* Handle profile picture upload */

        $new_filename = null;

        if (
            $error_msg === '' &&
            isset($_FILES['profile_picture']) &&
            $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE
        ) {
            $file = $_FILES['profile_picture'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error_msg = 'The photo could not be uploaded.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $error_msg = 'Photo is too large. Maximum size is 2 MB.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);

                $allowed_types = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp'
                ];

                if (
                    !isset($allowed_types[$mime]) ||
                    @getimagesize($file['tmp_name']) === false
                ) {
                    $error_msg = 'Please upload a valid JPG, PNG, or WEBP image.';
                } else {
                    $new_filename =
                        'user_' . $user_id . '_' . time() . '.' .
                        $allowed_types[$mime];

                    if (
                        !is_dir($upload_dir) ||
                        !is_writable($upload_dir) ||
                        !move_uploaded_file(
                            $file['tmp_name'],
                            $upload_dir . $new_filename
                        )
                    ) {
                        $error_msg = 'Unable to save the photo. Check uploads/profiles.';
                        $new_filename = null;
                    }
                }
            }
        }

        /* Save information in both tables */

        if ($error_msg === '') {
            $old_picture = basename(
                (string) ($user['profile_picture'] ?? '')
            );

            try {
                $pdo->beginTransaction();

                $updateUser = $pdo->prepare(
                    "UPDATE users
                     SET full_name = ?,
                         profile_picture = COALESCE(?, profile_picture)
                     WHERE id = ?
                       AND LOWER(role) = 'caretaker'"
                );

                $updateUser->execute([
                    $full_name,
                    $new_filename,
                    $user_id
                ]);

                $updateCaretaker = $pdo->prepare(
                    "UPDATE caretakers
                     SET full_name = ?,
                         contact = ?,
                         address = ?
                     WHERE username = ?"
                );

                $updateCaretaker->execute([
                    $full_name,
                    $contact !== '' ? $contact : null,
                    $address !== '' ? $address : null,
                    $user['username']
                ]);

                $pdo->commit();

                $_SESSION['full_name'] = $full_name;

                if (
                    $new_filename !== null &&
                    $old_picture !== '' &&
                    $old_picture !== $new_filename &&
                    is_file($upload_dir . $old_picture)
                ) {
                    @unlink($upload_dir . $old_picture);
                }

                $success_msg = 'Profile updated successfully!';
                $open_edit = false;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($new_filename !== null) {
                    @unlink($upload_dir . $new_filename);
                }

                error_log('Caretaker profile update: ' . $e->getMessage());

                $error_msg = 'Unable to update your profile. Please try again.';
            }
        }
    }

    /* Change password in both tables */

    elseif (isset($_POST['update_password'])) {

        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $user['user_password'])) {
            $error_msg = 'Incorrect current password.';
        } elseif ($new !== $confirm) {
            $error_msg = 'New passwords do not match.';
        } elseif (strlen($new) < 8) {
            $error_msg = 'New password must be at least 8 characters long.';
        } else {
            try {
                $hashed_password = password_hash($new, PASSWORD_DEFAULT);

                $pdo->beginTransaction();

                $updateUsers = $pdo->prepare(
                    "UPDATE users
                     SET password = ?
                     WHERE id = ?
                       AND LOWER(role) = 'caretaker'"
                );

                $updateUsers->execute([
                    $hashed_password,
                    $user_id
                ]);

                $updateCaretakers = $pdo->prepare(
                    "UPDATE caretakers
                     SET password = ?
                     WHERE username = ?"
                );

                $updateCaretakers->execute([
                    $hashed_password,
                    $user['username']
                ]);

                $pdo->commit();

                $success_msg = 'Password updated successfully!';

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Caretaker password update: ' . $e->getMessage());

                $error_msg = 'Unable to update your password.';
            }
        }
    }

    /* Reload the latest profile data */

    $user = getCaretakerProfile($pdo, $user_id);

    if (!$user) {
        exit('Unable to load caretaker profile.');
    }
}

/* Display values */

$user_name = $user['user_full_name'] ?: 'Caretaker';
$pic_file = $user['profile_picture'] ?? '';
$position = $user['position'] ?: 'Caretaker';
$shift = $user['shift'] ?: 'Not set';
$employee_id = $user['employee_id'] ?? '';

$val = static function (string $field) use ($user, $open_edit): string {
    if ($open_edit && isset($_POST[$field])) {
        return (string) $_POST[$field];
    }

    return (string) ($user[$field] ?? '');
};

/* Sidebar */

require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.profile-wrap {
    width: 100%;
    max-width: none;
    margin: 0;
}

.profile-wrap .card {
    background: #fff;
    border: 0;
    border-radius: 14px;
}

.pf-title {
    color: #17683a;
}

.pf-label {
    margin-bottom: 2px;
    color: #5a6b61;
    font-size: 13px;
    font-weight: 600;
}

.pf-value {
    color: #111;
    font-size: 16px;
    overflow-wrap: anywhere;
}

.pf-item {
    padding: 10px 0;
    border-bottom: 1px solid #eef2ef;
}

.pf-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 999px;
    background: #17683a;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
}

.pf-fixed {
    background: #f3f6f4 !important;
    color: #3d4a42;
}

.pf-edit-preview {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #17683a;
}

.btn-green,
.btn-outline-green {
    padding: 8px 22px;
    border: 1px solid #17683a;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    display: inline-block;
    line-height: 1.5;
}

.btn-green {
    background: #17683a;
    color: #fff;
}

.btn-green:hover {
    background: #0f5230;
    color: #fff;
}

.btn-outline-green {
    background: #fff;
    color: #17683a;
}

.btn-outline-green:hover {
    background: #eaf6ee;
    color: #0f5230;
}

.profile-wrap .form-control:focus,
.profile-wrap .form-select:focus {
    border-color: #17683a;
    box-shadow: 0 0 0 .2rem rgba(23,104,58,.2);
}
</style>

<div class="profile-wrap py-3">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <h2 class="mb-0">My Profile</h2>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success">
            <?= h($success_msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger">
            <?= h($error_msg) ?>
        </div>
    <?php endif; ?>

    <!-- VIEW PROFILE -->

    <div class="card p-4 shadow-sm mb-4"
         id="viewCard"
         <?= $open_edit ? 'hidden' : '' ?>>

        <div class="d-flex align-items-center flex-wrap gap-3 mb-3">

            <?= avatar_html($user_name, $pic_file, 110) ?>

            <div class="flex-grow-1">
                <h3 class="mb-1"><?= h($user_name) ?></h3>
                <span class="pf-badge">Caretaker</span>
            </div>

            <button type="button" class="btn-green" id="editBtn">
                Edit Profile
            </button>

        </div>

        <h5 class="pf-title mt-2">Personal Information</h5>

        <div class="row g-4">

            <div class="col-md-6 pf-item">
                <div class="pf-label">Full Name</div>
                <div class="pf-value">
                    <?= show_val($user['user_full_name']) ?>
                </div>
            </div>

            <div class="col-md-6 pf-item">
                <div class="pf-label">Employee ID</div>
                <div class="pf-value">
                    <?= show_val($employee_id) ?>
                </div>
            </div>

            <div class="col-md-6 pf-item">
                <div class="pf-label">Contact Number</div>
                <div class="pf-value">
                    <?= show_val($user['contact']) ?>
                </div>
            </div>

            <div class="col-md-6 pf-item">
                <div class="pf-label">Address</div>
                <div class="pf-value">
                    <?= show_val($user['address']) ?>
                </div>
            </div>

        </div>

        <h5 class="pf-title mt-4">Employment Information</h5>

        <div class="row g-4">

            <div class="col-md-4 pf-item">
                <div class="pf-label">Position</div>
                <div class="pf-value">
                    <?= show_val($position) ?>
                </div>
            </div>

            <div class="col-md-4 pf-item">
                <div class="pf-label">Shift</div>
                <div class="pf-value">
                    <?= show_val($shift) ?>
                </div>
            </div>

            <div class="col-md-4 pf-item">
                <div class="pf-label">Employment Status</div>
                <div class="pf-value">
                    <?= show_val($user['employment_status']) ?>
                </div>
            </div>

        </div>

        <h5 class="pf-title mt-4">Account Information</h5>

        <div class="row">

            <div class="col-md-6 pf-item">
                <div class="pf-label">Username</div>
                <div class="pf-value">
                    <?= show_val($user['username']) ?>
                </div>
            </div>

            <div class="col-md-6 pf-item">
                <div class="pf-label">Role</div>
                <div class="pf-value">Caretaker</div>
            </div>

        </div>

    </div>

    <!-- EDIT PROFILE -->

    <form method="POST"
          enctype="multipart/form-data"
          class="card p-4 shadow-sm mb-4"
          id="editCard"
          <?= $open_edit ? '' : 'hidden' ?>>

        <input type="hidden"
               name="csrf"
               value="<?= h($_SESSION['caretaker_profile_csrf']) ?>">

        <h4 class="mb-3 pf-title">Edit Profile</h4>

        <div class="d-flex align-items-center flex-wrap gap-3 mb-4">

            <?php $current_photo = avatar_url($pic_file); ?>

            <img id="photoPreview"
                 class="pf-edit-preview"
                 alt="Profile photo"
                 src="<?= $current_photo !== '' ? h($current_photo) : '' ?>"
                 <?= $current_photo === '' ? 'hidden' : '' ?>>

            <div class="flex-grow-1">

                <label class="form-label fw-semibold">
                    Profile Photo
                </label>

                <input type="file"
                       name="profile_picture"
                       id="photoInput"
                       class="form-control"
                       accept="image/png,image/jpeg,image/webp">

                <div class="form-text">
                    JPG, PNG or WEBP. Maximum size: 2 MB.
                </div>

            </div>

        </div>

        <h5 class="pf-title">Personal Information</h5>

        <div class="row g-3 mb-4">

            <div class="col-md-6">
                <label class="form-label fw-semibold">Full Name</label>
                <input type="text"
                       name="full_name"
                       class="form-control"
                       maxlength="100"
                       required
                       value="<?= h($val('user_full_name')) ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Contact Number</label>
                <input type="tel"
                       name="contact"
                       class="form-control"
                       maxlength="20"
                       value="<?= h($val('contact')) ?>">
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold">Address</label>
                <textarea name="address"
                          class="form-control"
                          rows="2"
                          maxlength="255"><?= h($val('address')) ?></textarea>
            </div>

        </div>

        <h5 class="pf-title">
            Employment Information
            <small class="text-muted fs-6">(cannot be edited)</small>
        </h5>

        <div class="row g-3 mb-4">

            <div class="col-md-4">
                <label class="form-label fw-semibold">Employee ID</label>
                <input type="text"
                       class="form-control pf-fixed"
                       readonly
                       value="<?= h($employee_id) ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Position</label>
                <input type="text"
                       class="form-control pf-fixed"
                       readonly
                       value="<?= h($position) ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Shift</label>
                <input type="text"
                       class="form-control pf-fixed"
                       readonly
                       value="<?= h($shift) ?>">
            </div>

        </div>

        <h5 class="pf-title">
            Account Information
            <small class="text-muted fs-6">(cannot be edited)</small>
        </h5>

        <div class="row g-3 mb-4">

            <div class="col-md-6">
                <label class="form-label fw-semibold">Username</label>
                <input type="text"
                       class="form-control pf-fixed"
                       readonly
                       value="<?= h($user['username']) ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Role</label>
                <input type="text"
                       class="form-control pf-fixed"
                       readonly
                       value="Caretaker">
            </div>

        </div>

        <div class="d-flex justify-content-end gap-2">

            <button type="button"
                    class="btn-outline-green"
                    id="cancelBtn">
                Cancel
            </button>

            <button type="submit"
                    name="update_profile"
                    class="btn-green">
                Save Changes
            </button>

        </div>

    </form>

    <!-- CHANGE PASSWORD -->

    <form method="POST" class="card p-4 shadow-sm">

        <input type="hidden"
               name="csrf"
               value="<?= h($_SESSION['caretaker_profile_csrf']) ?>">

        <h4 class="mb-3 pf-title">Change Password</h4>

        <div class="row g-3">

            <div class="col-md-4">
                <label class="form-label fw-semibold">Current Password</label>
                <input type="password"
                       name="current_password"
                       class="form-control"
                       required
                       autocomplete="current-password">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">New Password</label>
                <input type="password"
                       name="new_password"
                       class="form-control"
                       required
                       minlength="8"
                       autocomplete="new-password">
                <div class="form-text">At least 8 characters.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Confirm New Password</label>
                <input type="password"
                       name="confirm_password"
                       class="form-control"
                       required
                       minlength="8"
                       autocomplete="new-password">
            </div>

        </div>

        <div class="text-end mt-3">
            <button type="submit"
                    name="update_password"
                    class="btn-green">
                Change Password
            </button>
        </div>

    </form>

</div>

<script>
(function () {

    const view = document.getElementById('viewCard');
    const edit = document.getElementById('editCard');

    const editBtn = document.getElementById('editBtn');
    const cancelBtn = document.getElementById('cancelBtn');

    if (editBtn && view && edit) {
        editBtn.addEventListener('click', function () {
            view.hidden = true;
            edit.hidden = false;

            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }

    if (cancelBtn && view && edit) {
        cancelBtn.addEventListener('click', function () {
            edit.reset();
            edit.hidden = true;
            view.hidden = false;
        });
    }

    const photoInput = document.getElementById('photoInput');

    if (photoInput) {
        photoInput.addEventListener('change', function () {

            const file = photoInput.files && photoInput.files[0];

            if (!file) {
                return;
            }

            const preview = document.getElementById('photoPreview');

            preview.src = URL.createObjectURL(file);
            preview.hidden = false;

        });
    }

})();
</script>
