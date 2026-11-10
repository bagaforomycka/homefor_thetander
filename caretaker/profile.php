<?php
/**
 * HOME FOR THE AGED
 * My Profile: view, edit personal info, profile photo, change password
 * Location: /homefor_thetander/admin/profile.php
 * (The same file also works in /homefor_thetander/caretaker/profile.php)
 *
 * includes/sidebar.php outputs the whole HTML head, the sidebar and the opening
 * <main class="app-main">, so this page must NOT add its own <!DOCTYPE>,
 * <html>, <head> or .app-main wrapper.
 */


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/avatar.php';

$user_id     = (int) $_SESSION['user_id'];
$role        = strtolower((string) ($_SESSION['role'] ?? ''));
$dashboard   = $role === 'caretaker'
    ? '/homefor_thetander/caretaker/dashboard.php'
    : '/homefor_thetander/admin/dashboard.php';
$success_msg = '';
$error_msg   = '';
$open_edit   = false;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/* Shows a value, or a muted "Not set" */
function show_val($v): string
{
    $v = trim((string) $v);
    return $v !== '' ? h($v) : '<span class="text-muted">Not set</span>';
}

/* ---------- Upload folder ---------- */
$upload_dir = __DIR__ . '/../uploads/profiles/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

/* ---------- Make sure the profile columns exist in `users` ---------- */
$cols = [];
foreach ($pdo->query('SHOW COLUMNS FROM users') as $c) {
    $cols[] = $c['Field'];
}

$needed = [
    'profile_picture' => 'VARCHAR(255) NULL',
    'phone'           => 'VARCHAR(30) NULL',
    'birthdate'       => 'DATE NULL',
    'gender'          => 'VARCHAR(20) NULL',
    'address'         => 'VARCHAR(255) NULL',
    'position'        => 'VARCHAR(100) NULL',
];
foreach ($needed as $col => $definition) {
    if (!in_array($col, $cols, true)) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $definition");
            $cols[] = $col;
        } catch (Throwable $e) {
            /* no permission to alter the table: that field is skipped */
        }
    }
}
$has = static fn(string $col): bool => in_array($col, $cols, true);

/* ---------- Handle forms ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error_msg = 'Your session expired. Please try again.';
    }

    /* 1. Update personal info + photo */
    elseif (isset($_POST['update_profile'])) {
        $open_edit = true;

        $full_name = trim((string) ($_POST['full_name'] ?? ''));
        $phone     = trim((string) ($_POST['phone'] ?? ''));
        $birthdate = trim((string) ($_POST['birthdate'] ?? ''));
        $gender    = trim((string) ($_POST['gender'] ?? ''));
        $address   = trim((string) ($_POST['address'] ?? ''));

        if ($full_name === '' || mb_strlen($full_name) > 100) {
            $error_msg = 'Please enter your full name (up to 100 characters).';
        } elseif ($phone !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $phone)) {
            $error_msg = 'Please enter a valid phone number.';
        } elseif (!in_array($gender, ['', 'Male', 'Female', 'Other'], true)) {
            $error_msg = 'Please choose a valid gender option.';
        } elseif (mb_strlen($address) > 255) {
            $error_msg = 'Address is too long (255 characters maximum).';
        } elseif ($birthdate !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $birthdate);
            if (!$d || $d->format('Y-m-d') !== $birthdate
                || $d > new DateTime('today') || $d < new DateTime('1900-01-01')) {
                $error_msg = 'Please enter a valid birthday.';
            }
        }

        /* Photo */
        $new_filename = null;
        if ($error_msg === '' && $has('profile_picture')
            && isset($_FILES['profile_picture'])
            && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {

            $f = $_FILES['profile_picture'];

            if ($f['error'] !== UPLOAD_ERR_OK) {
                $error_msg = 'The photo could not be uploaded. Try a smaller image.';
            } elseif ($f['size'] > 2 * 1024 * 1024) {
                $error_msg = 'Photo is too large. Maximum size is 2 MB.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

                if (!isset($types[$mime]) || @getimagesize($f['tmp_name']) === false) {
                    $error_msg = 'Invalid image. Please use JPG, PNG or WEBP.';
                } else {
                    $new_filename = 'user_' . $user_id . '_' . time() . '.' . $types[$mime];
                    if (!is_dir($upload_dir) || !move_uploaded_file($f['tmp_name'], $upload_dir . $new_filename)) {
                        $error_msg    = 'Failed to save the photo. Check the uploads/profiles folder permissions.';
                        $new_filename = null;
                    }
                }
            }
        }

        if ($error_msg === '') {
            $sets   = ['full_name = ?'];
            $params = [$full_name];

            $editable = [
                'phone'     => $phone,
                'birthdate' => $birthdate,
                'gender'    => $gender,
                'address'   => $address,
            ];
            foreach ($editable as $col => $val) {
                if ($has($col)) {
                    $sets[]   = "`$col` = ?";
                    $params[] = $val === '' ? null : $val;
                }
            }

            $old_pic = '';
            if ($new_filename !== null) {
                $q = $pdo->prepare('SELECT profile_picture FROM users WHERE id = ?');
                $q->execute([$user_id]);
                $old_pic  = basename((string) $q->fetchColumn());
                $sets[]   = 'profile_picture = ?';
                $params[] = $new_filename;
            }

            $params[] = $user_id;
            $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?');

            if ($stmt->execute($params)) {
                $_SESSION['full_name'] = $full_name;
                if ($old_pic !== '' && $old_pic !== $new_filename && is_file($upload_dir . $old_pic)) {
                    @unlink($upload_dir . $old_pic);
                }
                $success_msg = 'Profile updated successfully!';
                $open_edit   = false;
            } else {
                $error_msg = 'Database update failed.';
                if ($new_filename !== null) {
                    @unlink($upload_dir . $new_filename);
                }
            }
        }
    }

    /* 2. Change password */
    elseif (isset($_POST['update_password'])) {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($current, $row['password'])) {
            $error_msg = 'Incorrect current password.';
        } elseif ($new !== $confirm) {
            $error_msg = 'New passwords do not match.';
        } elseif (strlen($new) < 6) {
            $error_msg = 'New password must be at least 6 characters long.';
        } else {
            $upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            if ($upd->execute([password_hash($new, PASSWORD_DEFAULT), $user_id])) {
                $success_msg = 'Password updated successfully!';
            } else {
                $error_msg = 'Failed to update password.';
            }
        }
    }
}

/* ---------- Fresh data for display ---------- */
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$user_name = $user['full_name'] ?? ($_SESSION['username'] ?? 'User');
$pic_file  = $user['profile_picture'] ?? '';
$role_text = ucfirst((string) ($user['role'] ?? ($role !== '' ? $role : 'user')));
$position  = trim((string) ($user['position'] ?? ''));
if ($position === '') {
    $position = $role === 'caretaker' ? 'Caretaker' : 'Administrator';
}
$birth_view = '';
if (!empty($user['birthdate']) && strtotime((string) $user['birthdate'])) {
    $birth_view = date('F j, Y', strtotime((string) $user['birthdate']));
}

/* Values shown in the edit form (keep what was typed if there was an error) */
$val = static function (string $col) use ($user, $open_edit): string {
    if ($open_edit && isset($_POST[$col])) {
        return (string) $_POST[$col];
    }
    return (string) ($user[$col] ?? '');
};
$gender_now = $val('gender');

/* The sidebar is included AFTER saving, so the new name and photo show in the
   top-right menu immediately. It also outputs the page head. */
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
    /* Use the full width, starting from the left side of the page */
    .profile-wrap { width: 100%; max-width: none; margin: 0; }
    .profile-wrap .card { background: #ffffff; border: 0; border-radius: 14px; }
    .pf-title { color: #17683a; }

    .pf-label { margin-bottom: 2px; color: #5a6b61; font-size: 13px; font-weight: 600; }
    .pf-value { color: #111111; font-size: 16px; overflow-wrap: anywhere; }
    .pf-item  { padding: 10px 0; border-bottom: 1px solid #eef2ef; }

    .pf-badge {
        display: inline-block; padding: 3px 12px; border-radius: 999px;
        background: #17683a; color: #ffffff; font-size: 13px; font-weight: 600;
    }
    .pf-fixed { background: #f3f6f4 !important; color: #3d4a42; }
    .pf-edit-preview {
        width: 96px; height: 96px; border-radius: 50%; object-fit: cover;
        border: 3px solid #17683a;
    }

    /* Buttons: solid green, or white with a green border. No other colours. */
    .btn-green, .btn-outline-green {
        padding: 8px 22px; border: 1px solid #17683a; border-radius: 8px;
        font-weight: 600; text-decoration: none; cursor: pointer;
        display: inline-block; line-height: 1.5;
    }
    .btn-green { background: #17683a; color: #ffffff; }
    .btn-green:hover, .btn-green:focus { background: #0f5230; border-color: #0f5230; color: #ffffff; }
    .btn-outline-green { background: #ffffff; color: #17683a; }
    .btn-outline-green:hover, .btn-outline-green:focus { background: #eaf6ee; color: #0f5230; border-color: #0f5230; }

    /* "Choose File" button inside the file input */
    .profile-wrap input[type="file"]::file-selector-button,
    .profile-wrap input[type="file"]:hover:not(:disabled):not([readonly])::file-selector-button {
        background: #ffffff !important; color: #17683a !important;
        border: 0; border-right: 1px solid #17683a; font-weight: 600;
    }

    /* Green focus instead of the default blue */
    .profile-wrap .form-control:focus,
    .profile-wrap .form-select:focus {
        border-color: #17683a;
        box-shadow: 0 0 0 0.2rem rgba(23, 104, 58, 0.2);
    }
</style>

<div class="profile-wrap py-3">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <h2 class="mb-0">My Profile</h2>
        
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?= h($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger"><?= h($error_msg) ?></div>
    <?php endif; ?>

    <!-- ===== VIEW MODE ===== -->
    <div class="card p-4 shadow-sm mb-4" id="viewCard" <?= $open_edit ? 'hidden' : '' ?>>
        <div class="d-flex align-items-center flex-wrap gap-3 mb-3">
            <?= avatar_html((string) $user_name, $pic_file, 110) ?>
            <div class="flex-grow-1">
                <h3 class="mb-1"><?= h($user_name) ?></h3>
                <span class="pf-badge"><?= h($role_text) ?></span>
            </div>
            <button type="button" class="btn-green" id="editBtn">Edit Profile</button>
        </div>

        <h5 class="pf-title mt-2">Personal Information</h5>
        <div class="row g-x-4">
            <div class="col-md-6 pf-item"><div class="pf-label">Full Name</div><div class="pf-value"><?= show_val($user['full_name'] ?? '') ?></div></div>
            <div class="col-md-6 pf-item"><div class="pf-label">Phone Number</div><div class="pf-value"><?= show_val($user['phone'] ?? '') ?></div></div>
            <div class="col-md-6 pf-item"><div class="pf-label">Birthday</div><div class="pf-value"><?= show_val($birth_view) ?></div></div>
            <div class="col-md-6 pf-item"><div class="pf-label">Gender</div><div class="pf-value"><?= show_val($user['gender'] ?? '') ?></div></div>
            <div class="col-12 pf-item"><div class="pf-label">Address</div><div class="pf-value"><?= show_val($user['address'] ?? '') ?></div></div>
        </div>

        <h5 class="pf-title mt-4">Account Information</h5>
        <div class="row">
            <div class="col-md-4 pf-item"><div class="pf-label">Username</div><div class="pf-value"><?= show_val($user['username'] ?? '') ?></div></div>
            <div class="col-md-4 pf-item"><div class="pf-label">Position</div><div class="pf-value"><?= h($position) ?></div></div>
            <div class="col-md-4 pf-item"><div class="pf-label">Role</div><div class="pf-value"><?= h($role_text) ?></div></div>
        </div>
    </div>

    <!-- ===== EDIT MODE ===== -->
    <form method="POST" enctype="multipart/form-data" class="card p-4 shadow-sm mb-4" id="editCard" <?= $open_edit ? '' : 'hidden' ?>>
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <h4 class="mb-3 pf-title">Edit Profile</h4>

        <?php if ($has('profile_picture')): ?>
            <div class="d-flex align-items-center flex-wrap gap-3 mb-4">
                <?php $cur = avatar_url($pic_file); ?>
                <img id="photoPreview" class="pf-edit-preview" alt="Profile photo"
                     src="<?= $cur !== '' ? h($cur) : '' ?>" <?= $cur === '' ? 'hidden' : '' ?>>
                <div id="photoFallback" <?= $cur !== '' ? 'hidden' : '' ?>>
                    <?= avatar_html((string) $user_name, null, 96) ?>
                </div>
                <div class="flex-grow-1">
                    <label class="form-label fw-semibold mb-1">Profile Photo</label>
                    <input type="file" name="profile_picture" id="photoInput" class="form-control"
                           accept="image/png, image/jpeg, image/webp">
                    <div class="form-text">JPG, PNG or WEBP, up to 2 MB.</div>
                </div>
            </div>
        <?php endif; ?>

        <h5 class="pf-title">Personal Information</h5>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Full Name</label>
                <input type="text" name="full_name" class="form-control" maxlength="100" required
                       value="<?= h($val('full_name')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Phone Number</label>
                <input type="tel" name="phone" class="form-control" maxlength="20"
                       placeholder="e.g. 0917 123 4567" value="<?= h($val('phone')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Birthday</label>
                <input type="date" name="birthdate" class="form-control"
                       max="<?= date('Y-m-d') ?>" min="1900-01-01" value="<?= h($val('birthdate')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Gender</label>
                <select name="gender" class="form-select">
                    <option value="">Select</option>
                    <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                        <option value="<?= h($g) ?>" <?= $gender_now === $g ? 'selected' : '' ?>><?= h($g) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Address</label>
                <textarea name="address" class="form-control" rows="2" maxlength="255"><?= h($val('address')) ?></textarea>
            </div>
        </div>

        <h5 class="pf-title">Account Information <small class="text-muted fs-6">(fixed, cannot be edited)</small></h5>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Username</label>
                <input type="text" class="form-control pf-fixed" readonly value="<?= h($user['username'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Position</label>
                <input type="text" class="form-control pf-fixed" readonly value="<?= h($position) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Role</label>
                <input type="text" class="form-control pf-fixed" readonly value="<?= h($role_text) ?>">
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn-outline-green" id="cancelBtn">Cancel</button>
            <button type="submit" name="update_profile" class="btn-green">Save Changes</button>
        </div>
    </form>

    <!-- ===== CHANGE PASSWORD ===== -->
    <form method="POST" class="card p-4 shadow-sm">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <h4 class="mb-3 pf-title">Change Password</h4>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Current Password</label>
                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">New Password</label>
                <input type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password">
                <div class="form-text">At least 6 characters.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="6" autocomplete="new-password">
            </div>
        </div>

        <div class="text-end mt-3">
            <button type="submit" name="update_password" class="btn-green">Change Password</button>
        </div>
    </form>
</div>

<script>
(function () {
    var view = document.getElementById('viewCard');
    var edit = document.getElementById('editCard');

    document.getElementById('editBtn').addEventListener('click', function () {
        view.hidden = true;
        edit.hidden = false;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    document.getElementById('cancelBtn').addEventListener('click', function () {
        edit.reset();
        edit.hidden = true;
        view.hidden = false;
    });

    /* Live preview of the chosen photo */
    var input = document.getElementById('photoInput');
    if (input) {
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            var img = document.getElementById('photoPreview');
            var fb  = document.getElementById('photoFallback');
            img.src = URL.createObjectURL(file);
            img.hidden = false;
            if (fb) fb.hidden = true;
        });
    }
})();
</script>