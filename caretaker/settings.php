
<?php
/**
 * Home for the Aged - Caretaker Settings
 * File: caretaker/settings.php
 *
 * Uses the existing shared sidebar:
 * includes/sidebar.php
 *
 * Requires config/db.php to provide a PDO connection named $pdo.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

$sessionRole = strtolower(trim((string) $_SESSION['role']));

/* Admins have their own Settings page. */
if ($sessionRole === 'admin') {
    header('Location: /homefor_thetander/admin/settings.php');
    exit;
}

if ($sessionRole !== 'caretaker') {
    http_response_code(403);
    exit('Access denied. Only caretakers can access this page.');
}

require_once __DIR__ . '/../config/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection error. Ensure config/db.php creates a PDO variable named $pdo.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| USER TABLE SETTINGS
|--------------------------------------------------------------------------
*/

$usersTable  = 'users';
$userKeyCol  = 'id';
$passwordCol = 'password';

/*
|--------------------------------------------------------------------------
| TABLES
|--------------------------------------------------------------------------
*/

$pdo->exec("
    CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value LONGTEXT NULL,
        updated_by VARCHAR(100) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS settings_activity (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(100) NOT NULL,
        action VARCHAR(150) NOT NULL,
        details TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_settings_activity_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

if (empty($_SESSION['caretaker_settings_csrf'])) {
    $_SESSION['caretaker_settings_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['caretaker_settings_csrf'];
$currentUser = (string) $_SESSION['user_id'];

$notice = '';
$error = '';

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function settingsEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function settingsGet(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare(
        'SELECT setting_value FROM app_settings WHERE setting_key = ?'
    );

    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string) $value;
}

function settingsSave(
    PDO $pdo,
    string $key,
    string $value,
    string $user
): void {
    $stmt = $pdo->prepare("
        INSERT INTO app_settings
            (setting_key, setting_value, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by)
    ");

    $stmt->execute([$key, $value, $user]);
}

function settingsLog(
    PDO $pdo,
    string $user,
    string $action,
    string $details = ''
): void {
    $stmt = $pdo->prepare("
        INSERT INTO settings_activity (user_id, action, details)
        VALUES (?, ?, ?)
    ");

    $stmt->execute([$user, $action, $details]);
}

function settingsValidatePassword(string $password): void
{
    if (strlen($password) < 8) {
        throw new RuntimeException('The new password must be at least 8 characters long.');
    }

    if (strlen($password) > 200) {
        throw new RuntimeException('The new password is too long.');
    }
}

function notificationKey(string $userId, string $option): string
{
    return 'user_' . $userId . '_' . $option;
}

/*
|--------------------------------------------------------------------------
| SETTINGS SECTIONS
|--------------------------------------------------------------------------
*/

$sections = [
    'account'       => 'Account Information',
    'notifications' => 'Notification Settings',
    'password'      => 'Change Password',
];

$notificationOptions = [
    'in_app_notifications' => 'In-App Notifications',
    'email_notifications'  => 'Email Notifications',
    'schedule_reminders'   => 'Schedule Reminders',
    'health_alerts'        => 'Health Alerts',
];

$section = (string) ($_GET['section'] ?? '');

if ($section !== '' && !isset($sections[$section])) {
    $section = '';
}

/*
|--------------------------------------------------------------------------
| PROCESS FORM SUBMISSIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrf, $postedToken)) {
        $error = 'Your security token expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        try {
            if ($action === 'save_notifications') {
                foreach (array_keys($notificationOptions) as $option) {
                    settingsSave(
                        $pdo,
                        notificationKey($currentUser, $option),
                        isset($_POST[$option]) ? '1' : '0',
                        $currentUser
                    );
                }

                settingsLog(
                    $pdo,
                    $currentUser,
                    'Caretaker updated notification preferences'
                );

                $notice = 'Notification preferences saved.';
                $section = 'notifications';
            }

            elseif ($action === 'change_password') {
                $section = 'password';

                $current = (string) ($_POST['current_password'] ?? '');
                $new     = (string) ($_POST['new_password'] ?? '');
                $confirm = (string) ($_POST['confirm_password'] ?? '');

                if ($current === '' || $new === '' || $confirm === '') {
                    throw new RuntimeException('Please fill in all password fields.');
                }

                if (!hash_equals($new, $confirm)) {
                    throw new RuntimeException('The new passwords do not match.');
                }

                if (hash_equals($current, $new)) {
                    throw new RuntimeException('The new password must be different from your current password.');
                }

                settingsValidatePassword($new);

                $stmt = $pdo->prepare(
                    "SELECT `$passwordCol` FROM `$usersTable`
                     WHERE `$userKeyCol` = ? AND role = 'caretaker'
                     LIMIT 1"
                );

                $stmt->execute([$currentUser]);
                $storedHash = $stmt->fetchColumn();

                if (!$storedHash || !password_verify($current, (string) $storedHash)) {
                    throw new RuntimeException('Your current password is incorrect.');
                }

                $update = $pdo->prepare(
                    "UPDATE `$usersTable`
                     SET `$passwordCol` = ?
                     WHERE `$userKeyCol` = ? AND role = 'caretaker'"
                );

                $update->execute([
                    password_hash($new, PASSWORD_DEFAULT),
                    $currentUser,
                ]);

                session_regenerate_id(true);

                settingsLog(
                    $pdo,
                    $currentUser,
                    'Caretaker changed own password'
                );

                $notice = 'Your password was changed successfully.';
            }

            else {
                throw new RuntimeException(
                    'That settings action is not available.'
                );
            }
        } catch (Throwable $exception) {
            error_log('Caretaker settings error: ' . $exception->getMessage());

            $error = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Unable to save your settings. Please try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD ACCOUNT DETAILS
|--------------------------------------------------------------------------
*/

$account = [
    'full_name'   => '',
    'username'    => '',
    'employee_id' => '',
    'position'    => '',
    'shift'       => '',
    'contact'     => '',
    'status'      => '',
];

if ($section === 'account') {
    try {
        $stmt = $pdo->prepare("
            SELECT username, full_name
            FROM `$usersTable`
            WHERE `$userKeyCol` = ? AND role = 'caretaker'
            LIMIT 1
        ");

        $stmt->execute([$currentUser]);
        $userRow = $stmt->fetch();

        if ($userRow) {
            $account['username']  = (string) ($userRow['username'] ?? '');
            $account['full_name'] = (string) ($userRow['full_name'] ?? '');
        } else {
            $error = $error ?: 'Your account could not be found.';
        }
    } catch (Throwable $exception) {
        error_log('Caretaker settings (users lookup): ' . $exception->getMessage());
        $error = $error ?: 'Unable to load your account information.';
    }

    if ($account['username'] !== '') {
        try {
            $stmt = $pdo->prepare("
                SELECT employee_id, full_name, position, shift, contact, status
                FROM caretakers
                WHERE username = ?
                LIMIT 1
            ");

            $stmt->execute([$account['username']]);
            $staff = $stmt->fetch();

            if ($staff) {
                foreach (['employee_id', 'position', 'shift', 'contact', 'status'] as $field) {
                    $account[$field] = (string) ($staff[$field] ?? '');
                }

                if ($account['full_name'] === '') {
                    $account['full_name'] = (string) ($staff['full_name'] ?? '');
                }
            }
        } catch (Throwable $exception) {
            error_log('Caretaker settings (caretakers lookup): ' . $exception->getMessage());
            $error = $error ?: 'Some staff details could not be loaded.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Settings | Home for the Aged</title>

    <style>
        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            background: #f3f6f3;
            color: #1d2920;
            font-family: Arial, Helvetica, sans-serif;
        }

        /*
         * The shared sidebar controls its own width, positioning,
         * mobile menu, and responsive layout.
         */

        .app-main {
            min-width: 0;
            min-height: 100vh;
            padding: 28px !important;
            box-shadow: none !important;
            overflow-x: hidden;
        }

        /* Back-to-top button */
        .back-to-top {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 1000;
            width: 46px;
            height: 46px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 50%;
            background: #0b5426;
            color: #fff;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .28);
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: opacity .2s ease, transform .2s ease, visibility .2s;
        }

        .back-to-top.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .back-to-top:hover {
            background: #063b18;
        }

        .back-to-top:focus-visible {
            outline: 3px solid #2f80d9;
            outline-offset: 2px;
        }

        .page-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }

        .page-heading h1 {
            margin: 0 0 8px;
            font-size: 30px;
            line-height: 1.2;
            color: #093d1c;
        }

        .page-heading p {
            margin: 0;
            color: #093d1c;
            line-height: 1.6;
        }

        .panel {
            width: 100%;
            min-width: 0;
            background: #fff;
            border: 1px solid #dce5de;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(16, 45, 25, .045);
        }

        .panel h2 {
            margin: 0 0 8px;
            font-size: 21px;
            color: #173b24;
        }

        .description {
            margin: 0 0 18px;
            color: #657168;
            line-height: 1.55;
        }

        .settings-group + .settings-group {
            margin-top: 26px;
        }

        .settings-group h3 {
            margin: 0 0 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e1ebe4;
            font-size: 13px;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #0b5426;
        }

        .setting-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 12px;
            border-bottom: 1px solid #edf1ed;
            border-radius: 8px;
            color: #20372a;
            text-decoration: none;
            transition: background .15s ease;
        }

        .setting-row:last-child {
            border-bottom: 0;
        }

        .setting-row:hover {
            background: #f1f6f2;
        }

        .setting-row:focus-visible {
            outline: 3px solid #2f80d9;
            outline-offset: -2px;
        }

        .setting-text {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
        }

        .setting-text strong {
            font-size: 16px;
            color: #0b5426;
        }

        .setting-text span {
            font-size: 13px;
            line-height: 1.5;
            color: #657168;
        }

        .setting-arrow {
            flex: 0 0 auto;
            font-size: 20px;
            color: #7aa889;
        }

        .notice {
            padding: 13px 16px;
            border: 1px solid #a8d5b5;
            border-radius: 9px;
            background: #edf8ef;
            color: #19582a;
            margin-bottom: 18px;
        }

        .notice.error {
            border-color: #e8b5b5;
            background: #fff0f0;
            color: #8b2020;
        }

        .topline {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin: 0 0 18px;
            color: #173b24;
        }

        .back-link {
            color: #0b5426;
            text-decoration: none;
            font-weight: bold;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
            margin-bottom: 15px;
            min-width: 0;
        }

        .field label {
            font-size: 14px;
            font-weight: 700;
            color: #263b2c;
        }

        .field input {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #cbd7ce;
            border-radius: 8px;
            background: #fff;
            color: #1d2920;
            font: inherit;
            outline: none;
        }

        .field input:focus {
            border-color: #2e8050;
            box-shadow: 0 0 0 3px rgba(46, 128, 80, .12);
        }

        .narrow-form {
            max-width: 520px;
        }

        .help {
            display: block;
            color: #657168;
            font-size: 12px;
            line-height: 1.5;
            margin-top: 8px;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 16px;
            border: 1px solid #0b5426;
            border-radius: 8px;
            background: #0b5426;
            color: #fff;
            text-decoration: none;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .btn:hover {
            background: #063b18;
            color: #fff;
        }

        .check-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 0;
            border-bottom: 1px solid #edf1ed;
            color: #263b2c;
        }

        .check-row input {
            width: 17px;
            height: 17px;
            accent-color: #0b5426;
        }

        .detail-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin: 0;
        }

        .detail-list div {
            min-width: 0;
            padding: 12px 14px;
            background: #f6faf7;
            border: 1px solid #e3eee6;
            border-radius: 10px;
        }

        .detail-list dt {
            margin: 0 0 4px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #657168;
        }

        .detail-list dd {
            margin: 0;
            font-size: 15px;
            color: #1d2920;
            word-break: break-word;
        }

        .sidebar-link.active {
            box-shadow: none !important;
            outline: none !important;
        }

        @media (max-width: 991.98px) {
            .app-main {
                padding: 18px 12px !important;
            }
        }

        @media (max-width: 760px) {
            .page-heading h1 {
                font-size: 25px;
            }

            .panel {
                padding: 17px;
            }

            .detail-list {
                grid-template-columns: 1fr;
            }

            .actions .btn {
                width: 100%;
            }

            .back-to-top {
                right: 14px;
                bottom: 14px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            .back-to-top {
                transition: none;
            }
        }
    </style>
</head>

<body>

<?php
/*
 * Shared sidebar opens <main class="app-main">.
 * It also handles the desktop and mobile navigation.
 */
require_once __DIR__ . '/../includes/sidebar.php';
?>

<header class="page-heading">
    <div>
        <h1>Settings</h1>
        <p>View your account details, manage your notifications, and keep your password secure.</p>
    </div>
</header>

<?php if ($notice !== ''): ?>
    <div class="notice" role="status">
        <?= settingsEscape($notice) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="notice error" role="alert">
        <?= settingsEscape($error) ?>
    </div>
<?php endif; ?>

<?php if ($section === ''): ?>

    <section class="panel">
        <h2>My Settings</h2>
        <p class="description">
            Choose a setting to view or change.
        </p>

        <div class="settings-group">
            <h3>Account</h3>

            <a class="setting-row" href="settings.php?section=account">
                <div class="setting-text">
                    <strong>Account Information</strong>
                    <span>View your name, username, employee ID, position, and shift.</span>
                </div>
                <span class="setting-arrow" aria-hidden="true">&rsaquo;</span>
            </a>
        </div>

        <div class="settings-group">
            <h3>Preferences</h3>

            <a class="setting-row" href="settings.php?section=notifications">
                <div class="setting-text">
                    <strong>Notification Settings</strong>
                    <span>Choose which alerts and reminders you want to receive.</span>
                </div>
                <span class="setting-arrow" aria-hidden="true">&rsaquo;</span>
            </a>
        </div>

        <div class="settings-group">
            <h3>Security</h3>

            <a class="setting-row" href="settings.php?section=password">
                <div class="setting-text">
                    <strong>Change Password</strong>
                    <span>Change the password of your own account.</span>
                </div>
                <span class="setting-arrow" aria-hidden="true">&rsaquo;</span>
            </a>
        </div>
    </section>

<?php else: ?>

    <div class="topline">
        <a class="back-link" href="settings.php">← All Settings</a>
        <strong><?= settingsEscape($sections[$section]) ?></strong>
    </div>

    <?php if ($section === 'account'): ?>

        <section class="panel">
            <h2>Account Information</h2>

            <p class="description">
                These details are managed by an administrator. To change any of them, ask your administrator.
            </p>

            <dl class="detail-list">
                <div>
                    <dt>Full Name</dt>
                    <dd><?= settingsEscape($account['full_name'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Username</dt>
                    <dd><?= settingsEscape($account['username'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Employee ID</dt>
                    <dd><?= settingsEscape($account['employee_id'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Position</dt>
                    <dd><?= settingsEscape($account['position'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Shift</dt>
                    <dd><?= settingsEscape($account['shift'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Contact</dt>
                    <dd><?= settingsEscape($account['contact'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Employment Status</dt>
                    <dd><?= settingsEscape($account['status'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Role</dt>
                    <dd>Caretaker</dd>
                </div>
            </dl>
        </section>

    <?php elseif ($section === 'notifications'): ?>

        <section class="panel">
            <h2>Notification Settings</h2>

            <p class="description">
                These preferences apply to your account only. Actual delivery must also be implemented in the relevant modules.
            </p>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= settingsEscape($csrf) ?>">
                <input type="hidden" name="action" value="save_notifications">

                <?php foreach ($notificationOptions as $key => $label): ?>
                    <label class="check-row">
                        <input
                            type="checkbox"
                            name="<?= settingsEscape($key) ?>"
                            value="1"
                            <?= settingsGet($pdo, notificationKey($currentUser, $key), '1') === '1' ? 'checked' : '' ?>
                        >
                        <?= settingsEscape($label) ?>
                    </label>
                <?php endforeach; ?>

                <div class="actions">
                    <button class="btn" type="submit">
                        Save Notification Settings
                    </button>
                </div>
            </form>
        </section>

    <?php elseif ($section === 'password'): ?>

        <section class="panel">
            <h2>Change Password</h2>

            <p class="description">
                Enter your current password, then choose a new one (at least 8 characters).
                If you forgot your current password, ask an administrator to reset it.
            </p>

            <form method="post" class="narrow-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= settingsEscape($csrf) ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="field">
                    <label for="current_password">Current Password</label>
                    <input
                        id="current_password"
                        name="current_password"
                        type="password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <div class="field">
                    <label for="new_password">New Password</label>
                    <input
                        id="new_password"
                        name="new_password"
                        type="password"
                        minlength="8"
                        maxlength="200"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="field">
                    <label for="confirm_password">Confirm New Password</label>
                    <input
                        id="confirm_password"
                        name="confirm_password"
                        type="password"
                        minlength="8"
                        maxlength="200"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="actions">
                    <button class="btn" type="submit">Change Password</button>
                </div>
            </form>
        </section>

    <?php endif; ?>

<?php endif; ?>

<p class="help">
    Signed in as <?= settingsEscape($currentUser) ?>.
    Settings changes are saved to the database.
</p>

<button
    type="button"
    class="back-to-top"
    id="backToTop"
    aria-label="Back to top"
>
    &uarr;
</button>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const topBtn = document.getElementById('backToTop');

    function updateTopBtn() {
        const y = window.pageYOffset ||
                  document.documentElement.scrollTop ||
                  0;

        if (topBtn) {
            topBtn.classList.toggle('show', y > 300);
        }
    }

    if (topBtn) {
        window.addEventListener('scroll', updateTopBtn, {
            passive: true
        });

        updateTopBtn();

        topBtn.addEventListener('click', function () {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
});
</script>

<!-- The shared sidebar already opened <main class="app-main">. -->

</main>
</body>
</html>
