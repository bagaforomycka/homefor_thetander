<?php
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$userId = (int) $_SESSION['user_id'];
$role   = strtolower((string) $_SESSION['role']);

$dashboardUrl = $role === 'caretaker'
    ? '/homefor_thetander/caretaker/dashboard.php'
    : '/homefor_thetander/admin/dashboard.php';

$uploadDir = __DIR__ . '/uploads/profiles/';
$uploadUrl = '/homefor_thetander/uploads/profiles/';

/* ------------------------------------------------------------------
   Make sure the extra profile columns exist (added once, automatically)
------------------------------------------------------------------ */
$migrationError = '';
$columns = [];

try {
    foreach ($pdo->query('SHOW COLUMNS FROM users') as $col) {
        $columns[] = $col['Field'];
    }

    $needed = [
        'profile_picture' => 'VARCHAR(255) NULL',
        'phone'           => 'VARCHAR(30) NULL',
        'address'         => 'VARCHAR(255) NULL',
    ];

    foreach ($needed as $name => $definition) {
        if (!in_array($name, $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `$name` $definition");
            $columns[] = $name;
        }
    }
} catch (PDOException $ex) {
    error_log('Profile column setup failed: ' . $ex->getMessage());
    $migrationError = 'The profile fields could not be added to the database automatically. '
        . 'Please run profile_migration.sql in phpMyAdmin, then reload this page.';
}

$hasCol = static function (string $name) use (&$columns): bool {
    return in_array($name, $columns, true);
};

/* ------------------------------------------------------------------
   Load the signed-in user
------------------------------------------------------------------ */
function loadUser(PDO $pdo, int $userId)
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$user = loadUser($pdo, $userId);

if (!$user) {
    header('Location: /homefor_thetander/logout.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors      = [];
$passwordErr = [];
$editMode    = false;

$success = $_SESSION['profile_flash'] ?? '';
unset($_SESSION['profile_flash']);

/* Form values (used to refill the form after an error) */
$form = [
    'full_name' => (string) ($user['full_name'] ?? ''),
    'username'  => (string) ($user['username'] ?? ''),
    'email'     => (string) ($user['email'] ?? ''),
    'phone'     => (string) ($user['phone'] ?? ''),
    'address'   => (string) ($user['address'] ?? ''),
];

/* ------------------------------------------------------------------
   Caretaker work details (read-only, from the caretakers table)
------------------------------------------------------------------ */
$work = null;

if ($role === 'caretaker') {
    try {
        $stmt = $pdo->prepare('SELECT * FROM caretakers WHERE username = ? LIMIT 1');
        $stmt->execute([(string) ($user['username'] ?? '')]);
        $work = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $ex) {
        $work = null;
    }

    if ($work) {
        if ($form['phone'] === '' && !empty($work['contact'])) {
            $form['phone'] = (string) $work['contact'];
        }
        if ($form['address'] === '' && !empty($work['address'])) {
            $form['address'] = (string) $work['address'];
        }
    }
}

/* ------------------------------------------------------------------
   Handle form submissions
------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Your session expired. Please try again.';
        $editMode = true;
    } elseif (($_POST['action'] ?? '') === 'update_profile') {
        $editMode = true;

        $form['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
        $form['username']  = trim((string) ($_POST['username'] ?? ''));
        $form['email']     = trim((string) ($_POST['email'] ?? ''));
        $form['phone']     = trim((string) ($_POST['phone'] ?? ''));
        $form['address']   = trim((string) ($_POST['address'] ?? ''));

        if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 100) {
            $errors[] = 'Please enter your full name (up to 100 characters).';
        }

        if ($form['username'] === '' || mb_strlen($form['username']) > 50) {
            $errors[] = 'Please enter a username (up to 50 characters).';
        }

        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if ($form['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $form['phone'])) {
            $errors[] = 'Please enter a valid phone number.';
        }

        if (mb_strlen($form['address']) > 255) {
            $errors[] = 'The address must not exceed 255 characters.';
        }

        if (!$errors) {
            $check = $pdo->prepare(
                'SELECT id FROM users
                 WHERE id <> :id
                   AND (username = :username OR (:email1 <> \'\' AND email = :email2))
                 LIMIT 1'
            );
            $check->execute([
                'id'       => $userId,
                'username' => $form['username'],
                'email1'   => $form['email'],
                'email2'   => $form['email'],
            ]);

            if ($check->fetch()) {
                $errors[] = 'That username or email address is already in use.';
            }
        }

        /* Profile picture */
        $newPicture    = null;   // new filename to store
        $removePicture = !empty($_POST['remove_photo']);
        $oldPicture    = basename((string) ($user['profile_picture'] ?? ''));

        $uploadState = $_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE;

        if (!$errors && $uploadState !== UPLOAD_ERR_NO_FILE) {
            if (!$hasCol('profile_picture')) {
                $errors[] = 'Profile pictures are not available until the database is updated.';
            } elseif ($uploadState !== UPLOAD_ERR_OK) {
                $errors[] = 'The picture could not be uploaded. Please try again.';
            } else {
                $file = $_FILES['profile_picture'];

                if ($file['size'] > 5 * 1024 * 1024) {
                    $errors[] = 'The picture must not exceed 5 MB.';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file($file['tmp_name']);

                    $allowed = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                        'image/gif'  => 'gif',
                    ];

                    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
                        $errors[] = 'Please upload a JPG, PNG, WEBP or GIF image.';
                    } else {
                        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                            $errors[] = 'Unable to create the picture upload folder.';
                        } else {
                            $filename = 'user_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];

                            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                                $newPicture = $filename;
                            } else {
                                $errors[] = 'Unable to save the uploaded picture.';
                            }
                        }
                    }
                }
            }
        }

        if (!$errors) {
            $sets   = ['full_name = ?', 'username = ?', 'email = ?'];
            $params = [
                $form['full_name'],
                $form['username'],
                $form['email'] !== '' ? $form['email'] : null,
            ];

            if ($hasCol('phone')) {
                $sets[]   = 'phone = ?';
                $params[] = $form['phone'] !== '' ? $form['phone'] : null;
            }

            if ($hasCol('address')) {
                $sets[]   = 'address = ?';
                $params[] = $form['address'] !== '' ? $form['address'] : null;
            }

            if ($hasCol('profile_picture')) {
                if ($newPicture !== null) {
                    $sets[]   = 'profile_picture = ?';
                    $params[] = $newPicture;
                } elseif ($removePicture) {
                    $sets[]   = 'profile_picture = ?';
                    $params[] = null;
                }
            }

            $params[] = $userId;

            try {
                $update = $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?');
                $update->execute($params);

                /* Delete the old picture file once it has been replaced or removed */
                if (($newPicture !== null || $removePicture) && $oldPicture !== '') {
                    $oldPath = $uploadDir . $oldPicture;
                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                /* Keep the caretaker work record in step (best effort) */
                if ($role === 'caretaker' && $work) {
                    try {
                        $sync = $pdo->prepare(
                            'UPDATE caretakers
                             SET full_name = ?, contact = ?, address = ?, username = ?
                             WHERE username = ?'
                        );
                        $sync->execute([
                            $form['full_name'],
                            $form['phone'],
                            $form['address'],
                            $form['username'],
                            (string) $user['username'],
                        ]);
                    } catch (PDOException $ex) {
                        error_log('Caretaker sync failed: ' . $ex->getMessage());
                    }
                }

                $_SESSION['full_name'] = $form['full_name'];
                $_SESSION['profile_flash'] = 'Your profile was updated successfully.';

                header('Location: /homefor_thetander/profile.php');
                exit;
            } catch (PDOException $ex) {
                error_log('Profile update failed: ' . $ex->getMessage());
                $errors[] = 'Unable to update your profile. Please try again.';

                if ($newPicture !== null && is_file($uploadDir . $newPicture)) {
                    @unlink($uploadDir . $newPicture);
                }
            }
        } elseif ($newPicture !== null && is_file($uploadDir . $newPicture)) {
            @unlink($uploadDir . $newPicture);
        }
    } elseif (($_POST['action'] ?? '') === 'change_password') {
        $editMode = true;

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($current === '' || $new === '' || $confirm === '') {
            $passwordErr[] = 'Please complete all password fields.';
        } elseif ($new !== $confirm) {
            $passwordErr[] = 'The new passwords do not match.';
        } elseif (strlen($new) < 8) {
            $passwordErr[] = 'The new password must contain at least 8 characters.';
        } elseif (!password_verify($current, (string) $user['password'])) {
            $passwordErr[] = 'Your current password is incorrect.';
        } else {
            try {
                $update = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                $update->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);

                $_SESSION['profile_flash'] = 'Your password was changed successfully.';
                header('Location: /homefor_thetander/profile.php');
                exit;
            } catch (PDOException $ex) {
                error_log('Password change failed: ' . $ex->getMessage());
                $passwordErr[] = 'Unable to change your password. Please try again.';
            }
        }
    }
}

/* ------------------------------------------------------------------
   Values used by the page
------------------------------------------------------------------ */
$pictureUrl = '';
$pictureFile = basename((string) ($user['profile_picture'] ?? ''));

if ($pictureFile !== '' && is_file($uploadDir . $pictureFile)) {
    $pictureUrl = $uploadUrl . rawurlencode($pictureFile) . '?v=' . (int) @filemtime($uploadDir . $pictureFile);
}

$displayName = (string) ($user['full_name'] ?: ($user['username'] ?? 'User'));

$initials = '';
foreach (array_slice(preg_split('/\s+/', trim($displayName)), 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === '') {
    $initials = 'U';
}

$memberSince = '';
if (!empty($user['created_at'])) {
    $ts = strtotime((string) $user['created_at']);
    if ($ts) {
        $memberSince = date('F j, Y', $ts);
    }
}

$status = ucfirst((string) ($user['status'] ?? 'active'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile | Home for the Aged</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --green: #17683a;
            --green-dark: #0f4b2a;
            --pale-green: #edf7ef;
            --text: #304b3b;
        }

        .profile-wrap {
            max-width: 980px;
            margin: 0 auto;
            color: var(--text);
        }

        .p-card {
            margin-bottom: 20px;
            padding: 24px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .p-hero {
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
        }

        .p-avatar {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 120px;
            height: 120px;
            overflow: hidden;
            border: 4px solid var(--pale-green);
            border-radius: 50%;
            background: var(--green);
            color: #ffffff;
            font-size: 40px;
            font-weight: 700;
        }

        .p-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .p-hero-info {
            flex: 1 1 240px;
            min-width: 0;
        }

        .p-hero-info h1 {
            margin: 0 0 6px;
            font-size: 26px;
            font-weight: 700;
            color: #1d2b22;
            overflow-wrap: anywhere;
        }

        .p-badge {
            display: inline-block;
            margin-right: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            background: var(--pale-green);
            color: var(--green);
            font-size: 12px;
            font-weight: 700;
        }

        .p-badge.status {
            background: #e3f4e8;
        }

        .p-title {
            margin: 0 0 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e6efe8;
            color: #1d2b22;
            font-size: 18px;
            font-weight: 700;
        }

        .p-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 28px;
        }

        .p-item label {
            display: block;
            margin-bottom: 2px;
            color: #6a7f71;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .p-item div {
            color: #1d2b22;
            font-size: 15px;
            overflow-wrap: anywhere;
        }

        .p-item .empty {
            color: #9aab9f;
            font-style: italic;
        }

        .p-item.full {
            grid-column: 1 / -1;
        }

        .btn-green {
            border: 1px solid var(--green);
            background: var(--green);
            color: #ffffff;
        }

        .btn-green:hover {
            border-color: var(--green-dark);
            background: var(--green-dark);
            color: #ffffff;
        }

        .photo-row {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .photo-row .p-avatar {
            width: 96px;
            height: 96px;
            font-size: 32px;
        }

        .photo-actions {
            flex: 1 1 240px;
        }

        @media (max-width: 640px) {
            .p-grid {
                grid-template-columns: 1fr;
            }

            .p-card {
                padding: 18px;
            }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="profile-wrap">

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($migrationError !== ''): ?>
        <div class="alert alert-warning"><?= h($migrationError) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger mb-3">
            <?php foreach ($errors as $err): ?>
                <div><?= h($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Header card -->
    <div class="p-card">
        <div class="p-hero">
            <div class="p-avatar" id="heroAvatar">
                <?php if ($pictureUrl !== ''): ?>
                    <img src="<?= h($pictureUrl) ?>" alt="Profile picture">
                <?php else: ?>
                    <span><?= h($initials) ?></span>
                <?php endif; ?>
            </div>

            <div class="p-hero-info">
                <h1><?= h($displayName) ?></h1>
                <span class="p-badge"><?= h(ucfirst($role)) ?></span>
                <span class="p-badge status"><?= h($status) ?></span>
                <?php if ($memberSince !== ''): ?>
                    <div class="text-muted small mt-2">Member since <?= h($memberSince) ?></div>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <a href="<?= h($dashboardUrl) ?>" class="btn btn-outline-success">&larr; Back to Dashboard</a>
                <button type="button" class="btn btn-green" id="editBtn"<?= $editMode ? ' hidden' : '' ?>>Edit Profile</button>
            </div>
        </div>
    </div>

    <!-- View mode -->
    <div id="viewPanel"<?= $editMode ? ' hidden' : '' ?>>
        <div class="p-card">
            <h2 class="p-title">Personal Information</h2>

            <div class="p-grid">
                <?php
                $viewItems = [
                    'Full name' => $form['full_name'],
                    'Username'  => $form['username'],
                    'Email'     => $form['email'],
                    'Phone'     => $form['phone'],
                ];
                foreach ($viewItems as $label => $value): ?>
                    <div class="p-item">
                        <label><?= h($label) ?></label>
                        <?php if (trim((string) $value) !== ''): ?>
                            <div><?= h($value) ?></div>
                        <?php else: ?>
                            <div class="empty">Not provided</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="p-item full">
                    <label>Address</label>
                    <?php if (trim($form['address']) !== ''): ?>
                        <div><?= h($form['address']) ?></div>
                    <?php else: ?>
                        <div class="empty">Not provided</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($work): ?>
            <div class="p-card">
                <h2 class="p-title">Work Details</h2>

                <div class="p-grid">
                    <?php
                    $workItems = [
                        'Employee ID' => $work['employee_id'] ?? '',
                        'Position'    => $work['position'] ?? '',
                        'Shift'       => $work['shift'] ?? '',
                    ];
                    foreach ($workItems as $label => $value): ?>
                        <div class="p-item">
                            <label><?= h($label) ?></label>
                            <?php if (trim((string) $value) !== ''): ?>
                                <div><?= h($value) ?></div>
                            <?php else: ?>
                                <div class="empty">Not provided</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="text-muted small mt-3">
                    Work details are managed by an administrator.
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Edit mode -->
    <div id="editPanel"<?= $editMode ? '' : ' hidden' ?>>
        <form method="POST" enctype="multipart/form-data" class="p-card" id="profileForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_profile">

            <h2 class="p-title">Edit Profile</h2>

            <div class="photo-row">
                <div class="p-avatar" id="previewAvatar">
                    <?php if ($pictureUrl !== ''): ?>
                        <img src="<?= h($pictureUrl) ?>" alt="Profile picture preview" id="previewImg">
                    <?php else: ?>
                        <span id="previewInitials"><?= h($initials) ?></span>
                    <?php endif; ?>
                </div>

                <div class="photo-actions">
                    <label for="profile_picture" class="form-label fw-semibold">Profile picture</label>
                    <input
                        type="file"
                        class="form-control"
                        id="profile_picture"
                        name="profile_picture"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                    >
                    <div class="form-text">JPG, PNG, WEBP or GIF. Maximum 5 MB.</div>

                    <?php if ($pictureUrl !== ''): ?>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="remove_photo">
                            <label class="form-check-label" for="remove_photo">Remove current picture</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="full_name" class="form-label">Full name *</label>
                    <input type="text" class="form-control" id="full_name" name="full_name" maxlength="100"
                           value="<?= h($form['full_name']) ?>" required>
                </div>

                <div class="col-md-6">
                    <label for="username" class="form-label">Username *</label>
                    <input type="text" class="form-control" id="username" name="username" maxlength="50"
                           value="<?= h($form['username']) ?>" required>
                </div>

                <div class="col-md-6">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" maxlength="120"
                           value="<?= h($form['email']) ?>">
                </div>

                <div class="col-md-6">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="text" class="form-control" id="phone" name="phone" maxlength="20"
                           value="<?= h($form['phone']) ?>" placeholder="e.g. 0917 123 4567">
                </div>

                <div class="col-12">
                    <label for="address" class="form-label">Address</label>
                    <textarea class="form-control" id="address" name="address" rows="2" maxlength="255"><?= h($form['address']) ?></textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-green px-4">Save Changes</button>
                <button type="button" class="btn btn-outline-secondary px-4" id="cancelBtn">Cancel</button>
            </div>
        </form>

        <form method="POST" class="p-card" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="change_password">

            <h2 class="p-title">Change Password</h2>

            <?php if ($passwordErr): ?>
                <div class="alert alert-danger">
                    <?php foreach ($passwordErr as $err): ?>
                        <div><?= h($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-4">
                    <label for="current_password" class="form-label">Current password</label>
                    <input type="password" class="form-control" id="current_password" name="current_password"
                           autocomplete="current-password">
                </div>

                <div class="col-md-4">
                    <label for="new_password" class="form-label">New password</label>
                    <input type="password" class="form-control" id="new_password" name="new_password"
                           autocomplete="new-password">
                    <div class="form-text">At least 8 characters.</div>
                </div>

                <div class="col-md-4">
                    <label for="confirm_password" class="form-label">Confirm new password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                           autocomplete="new-password">
                </div>
            </div>

            <button type="submit" class="btn btn-green px-4 mt-4">Update Password</button>
        </form>
    </div>

</div>

</main>

<script>
(function () {
    var viewPanel = document.getElementById('viewPanel');
    var editPanel = document.getElementById('editPanel');
    var editBtn   = document.getElementById('editBtn');
    var cancelBtn = document.getElementById('cancelBtn');
    var fileInput = document.getElementById('profile_picture');
    var preview   = document.getElementById('previewAvatar');
    var removeBox = document.getElementById('remove_photo');

    function showEdit() {
        viewPanel.hidden = true;
        editPanel.hidden = false;
        editBtn.hidden = true;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function showView() {
        // Reload the page so the form goes back to the saved values
        window.location.href = 'profile.php';
    }

    editBtn.addEventListener('click', showEdit);
    cancelBtn.addEventListener('click', showView);

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) return;

            if (removeBox) removeBox.checked = false;

            var img = document.getElementById('previewImg');
            if (!img) {
                preview.innerHTML = '';
                img = document.createElement('img');
                img.id = 'previewImg';
                img.alt = 'Profile picture preview';
                preview.appendChild(img);
            }
            img.src = URL.createObjectURL(file);
        });
    }

    if (removeBox) {
        removeBox.addEventListener('change', function () {
            if (removeBox.checked && fileInput) fileInput.value = '';
        });
    }
})();
</script>

</body>
</html>