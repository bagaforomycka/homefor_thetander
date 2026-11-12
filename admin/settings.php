<?php
/**
 * Home for the Aged - Admin Settings
 * File: admin/settings.php
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

if (strtolower(trim((string) $_SESSION['role'])) !== 'admin') {
    http_response_code(403);
    exit('Access denied. Only administrators can access Settings.');
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
| USER TABLE SETTINGS (used by Change Password / Forgot Password)
|--------------------------------------------------------------------------
| Change these to match your real users table.
|
|  $usersTable      table that stores the accounts
|  $userKeyCol      column that equals $_SESSION['user_id']
|  $accountLookupCol column an admin types in to find an account
|                   (username, email, or the same ID column)
|  $passwordCol     column that stores the password HASH
*/
$usersTable       = 'users';
$userKeyCol       = 'id';
$accountLookupCol = 'username';
$passwordCol      = 'password';

/*
|--------------------------------------------------------------------------
| Create Settings tables
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

/* Kept so password and settings changes are still recorded in the database. */
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

if (empty($_SESSION['settings_csrf'])) {
    $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['settings_csrf'];
$currentUser = (string) $_SESSION['user_id'];

$notice = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Helper functions
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

function settingsGetList(
    PDO $pdo,
    string $key,
    array $default = []
): array {
    $raw = settingsGet($pdo, $key);

    if ($raw === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : $default;
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

/*
|--------------------------------------------------------------------------
| Available settings sections
|--------------------------------------------------------------------------
*/

$sections = [
    'notifications'   => 'Notification Settings',
    'permissions'     => 'Permissions',
    'password'        => 'Change Password',
    'forgot-password' => 'Forgot Password',
];

$section = (string) ($_GET['section'] ?? '');

if ($section !== '' && !isset($sections[$section])) {
    $section = '';
}

/*
|--------------------------------------------------------------------------
| Process form submissions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrf, $postedToken)) {
        $error = 'Your security token expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        try {
            /*
            | Save notification preferences
            */
            if ($action === 'save_notifications') {
                $preferences = [
                    'email_notifications' =>
                        isset($_POST['email_notifications']) ? '1' : '0',

                    'in_app_notifications' =>
                        isset($_POST['in_app_notifications']) ? '1' : '0',

                    'schedule_reminders' =>
                        isset($_POST['schedule_reminders']) ? '1' : '0',

                    'health_alerts' =>
                        isset($_POST['health_alerts']) ? '1' : '0',
                ];

                foreach ($preferences as $key => $value) {
                    settingsSave(
                        $pdo,
                        $key,
                        $value,
                        $currentUser
                    );
                }

                settingsLog(
                    $pdo,
                    $currentUser,
                    'Updated notification preferences'
                );

                $notice = 'Notification preferences saved.';
                $section = 'notifications';
            }

            /*
            | Save permission preferences
            |
            | Only Admin and Caretaker are included.
            */
            elseif ($action === 'save_permissions') {
                $roles = ['admin', 'caretaker'];

                $permissionNames = [
                    'residents',
                    'health_records',
                    'rooms',
                    'schedules',
                    'messages',
                    'reports',
                    'settings',
                ];

                $permissions = [];

                foreach ($roles as $role) {
                    foreach ($permissionNames as $permission) {
                        $permissions[$role][$permission] =
                            isset($_POST[$role . '_' . $permission]);
                    }
                }

                settingsSave(
                    $pdo,
                    'role_permissions',
                    json_encode($permissions),
                    $currentUser
                );

                settingsLog(
                    $pdo,
                    $currentUser,
                    'Updated permission preferences'
                );

                $notice = 'Permission preferences saved. Each module must also enforce its own access rules.';
                $section = 'permissions';
            }

            /*
            | Change the signed-in admin's own password
            */
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

                settingsValidatePassword($new);

                $stmt = $pdo->prepare(
                    "SELECT `$passwordCol` FROM `$usersTable`
                     WHERE `$userKeyCol` = ? LIMIT 1"
                );
                $stmt->execute([$currentUser]);
                $storedHash = $stmt->fetchColumn();

                if (!$storedHash || !password_verify($current, (string) $storedHash)) {
                    throw new RuntimeException('Your current password is incorrect.');
                }

                $update = $pdo->prepare(
                    "UPDATE `$usersTable`
                     SET `$passwordCol` = ?
                     WHERE `$userKeyCol` = ?"
                );
                $update->execute([
                    password_hash($new, PASSWORD_DEFAULT),
                    $currentUser,
                ]);

                session_regenerate_id(true);

                settingsLog($pdo, $currentUser, 'Changed own password');

                $notice = 'Your password was changed successfully.';
            }

            /*
            | Forgot password: an admin sets a new password for another account
            */
            elseif ($action === 'reset_password') {
                $section = 'forgot-password';

                $target  = trim((string) ($_POST['target_account'] ?? ''));
                $new     = (string) ($_POST['new_password'] ?? '');
                $confirm = (string) ($_POST['confirm_password'] ?? '');

                if ($target === '') {
                    throw new RuntimeException('Enter the account to reset.');
                }

                if (!hash_equals($new, $confirm)) {
                    throw new RuntimeException('The new passwords do not match.');
                }

                settingsValidatePassword($new);

                $stmt = $pdo->prepare(
                    "SELECT `$userKeyCol` FROM `$usersTable`
                     WHERE `$accountLookupCol` = ? LIMIT 1"
                );
                $stmt->execute([$target]);
                $targetKey = $stmt->fetchColumn();

                if ($targetKey === false) {
                    throw new RuntimeException('No account was found with that value.');
                }

                $update = $pdo->prepare(
                    "UPDATE `$usersTable`
                     SET `$passwordCol` = ?
                     WHERE `$userKeyCol` = ?"
                );
                $update->execute([
                    password_hash($new, PASSWORD_DEFAULT),
                    $targetKey,
                ]);

                settingsLog(
                    $pdo,
                    $currentUser,
                    'Reset password for another account',
                    'Account: ' . $target
                );

                $notice = 'The password for that account was reset. Share the new password with the user securely and ask them to change it.';
            }

            else {
                throw new RuntimeException(
                    'That settings action is not available.'
                );
            }
        } catch (Throwable $exception) {
            error_log('Settings error: ' . $exception->getMessage());

            $error = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Unable to save your settings. Check the database connection and try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load saved settings
|--------------------------------------------------------------------------
*/

$permissions = settingsGetList(
    $pdo,
    'role_permissions',
    []
);

/*
|--------------------------------------------------------------------------
| Existing module links
|--------------------------------------------------------------------------
*/

$caretakerLink = '/homefor_thetander/admin/caretakers_staff.php';

if (is_file(__DIR__ . '/caretakers.php')) {
    $caretakerLink = '/homefor_thetander/admin/caretakers.php';
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

        /* SIDEBAR LOGO (injected at top of sidebar) */
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

        /*
         * IMPORTANT:
         * The shared includes/sidebar.php provides the sidebar and opens
         * <main class="app-main">.
         * Do not define another sidebar or open a second main element here.
         */

        /* The script at the bottom adds .js-sidebar to the real sidebar */
        .js-sidebar {
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .app-main {
            margin-left: 250px !important;
            width: calc(100% - 250px) !important;
            max-width: none !important;
            min-width: 0;
            min-height: 100vh;
            padding: 28px !important;
            box-shadow: none !important;
            overflow-x: hidden;
        }

        /* Mobile menu button + backdrop (hidden on desktop) */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 12px;
            left: 12px;
            z-index: 1101;
            width: 44px;
            height: 44px;
            align-items: center;
            justify-content: center;
            border: 1px solid #0b5426;
            border-radius: 10px;
            background: #0b5426;
            color: #fff;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(0, 0, 0, .25);
        }

        .menu-toggle:focus-visible,
        .back-to-top:focus-visible {
            outline: 3px solid #2f80d9;
            outline-offset: 2px;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1099;
            background: rgba(0, 0, 0, .45);
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

        /* Single settings card: grouped list of links */
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

        .btn.secondary {
            background: #fff;
            color: #0b5426;
        }

        .btn.secondary:hover {
            background: #eef5ef;
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

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 560px;
        }

        th,
        td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e5ebe6;
            font-size: 14px;
        }

        th {
            background: #f1f6f2;
            color: #284332;
            font-weight: 700;
        }

        .permissions-table th,
        .permissions-table td {
            text-align: center;
        }

        .permissions-table th:first-child,
        .permissions-table td:first-child {
            text-align: left;
        }

        .permissions-table input {
            width: 17px;
            height: 17px;
            accent-color: #0b5426;
        }

        /*
         * Remove the white active-link shadow without replacing the
         * sidebar styling from includes/sidebar.php.
         */
        .sidebar-link.active {
            box-shadow: none !important;
            outline: none !important;
        }

        @media (max-width: 760px) {
            .menu-toggle {
                display: inline-flex;
            }

            /* Sidebar becomes a slide-in menu */
            .js-sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;
                height: 100vh !important;
                width: 260px;
                max-width: 82vw;
                z-index: 1100;
                transform: translateX(-100%);
                transition: transform .25s ease;
            }

            body.sidebar-open .js-sidebar {
                transform: translateX(0);
            }

            body.sidebar-open .sidebar-backdrop {
                display: block;
            }

            body.sidebar-open {
                overflow: hidden;
            }

            .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 70px 12px 18px !important;
            }

            .page-heading h1 {
                font-size: 25px;
            }

            .panel {
                padding: 17px;
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
            html { scroll-behavior: auto; }
            .js-sidebar, .back-to-top { transition: none; }
        }
    </style>
</head>

<body>

<?php
/*
 * This is the shared sidebar used by the other admin modules.
 * It already opens <main class="app-main">.
 */
require_once __DIR__ . '/../includes/sidebar.php';
?>

<button type="button" class="menu-toggle" id="menuToggle"
        aria-label="Open menu" aria-expanded="false">&#9776;</button>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<header class="page-heading">
    <div>
        <h1>Settings</h1>
        <p>Manage notifications, accounts, permissions, and security.</p>
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

    <!-- SETTINGS OVERVIEW (one card, grouped) -->

    <section class="panel">
        <h2>System Settings</h2>
        <p class="description">
            Choose a setting to view or change.
        </p>

        <div class="settings-group">
            <h3>System</h3>

            <a class="setting-row" href="settings.php?section=notifications">
                <div class="setting-text">
                    <strong>Notification Settings</strong>
                    <span>Save preferences for alerts and reminders.</span>
                </div>
                <span class="setting-arrow" aria-hidden="true">&rsaquo;</span>
            </a>
        </div>

        <div class="settings-group">
            <h3>Account Management</h3>

            <a class="setting-row" href="<?= settingsEscape($caretakerLink) ?>">
                <div class="setting-text">
                    <strong>Caretaker Accounts</strong>
                    <span>Open caretaker account management.</span>
                </div>
                <span class="setting-arrow" aria-hidden="true">&rsaquo;</span>
            </a>

            <a class="setting-row" href="settings.php?section=permissions">
                <div class="setting-text">
                    <strong>Permissions</strong>
                    <span>Configure role permission preferences for Admin and Caretaker.</span>
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

            <a class="setting-row" href="settings.php?section=forgot-password">
                <div class="setting-text">
                    <strong>Forgot Password</strong>
                    <span>Set a new password for an account whose owner forgot it.</span>
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

    <?php if ($section === 'notifications'): ?>

        <section class="panel">
            <h2>Notification Settings</h2>

            <p class="description">
                Save your preferred notification options. Actual delivery must also be implemented in the relevant modules.
            </p>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= settingsEscape($csrf) ?>">
                <input type="hidden" name="action" value="save_notifications">

                <?php
                $notificationOptions = [
                    'email_notifications' => 'Email Notifications',
                    'in_app_notifications' => 'In-App Notifications',
                    'schedule_reminders' => 'Schedule Reminders',
                    'health_alerts' => 'Health Alerts',
                ];
                ?>

                <?php foreach ($notificationOptions as $key => $label): ?>
                    <label class="check-row">
                        <input
                            type="checkbox"
                            name="<?= settingsEscape($key) ?>"
                            value="1"
                            <?= settingsGet($pdo, $key, '1') === '1' ? 'checked' : '' ?>
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

    <?php elseif ($section === 'permissions'): ?>

        <?php
        $roleNames = [
            'admin' => 'Administrator',
            'caretaker' => 'Caretaker',
        ];

        $permissionNames = [
            'residents' => 'Residents',
            'health_records' => 'Health Records',
            'rooms' => 'Rooms',
            'schedules' => 'Schedules',
            'messages' => 'Messages',
            'reports' => 'Reports',
            'settings' => 'Settings',
        ];
        ?>

        <section class="panel">
            <h2>Permissions</h2>

            <p class="description">
                Save permission preferences for each role. These preferences do not replace access checks in each PHP module.
            </p>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= settingsEscape($csrf) ?>">
                <input type="hidden" name="action" value="save_permissions">

                <div class="table-wrap">
                    <table class="permissions-table">
                        <thead>
                            <tr>
                                <th>Module</th>

                                <?php foreach ($roleNames as $label): ?>
                                    <th><?= settingsEscape($label) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($permissionNames as $permission => $label): ?>
                                <tr>
                                    <td><?= settingsEscape($label) ?></td>

                                    <?php foreach ($roleNames as $role => $roleLabel): ?>
                                        <?php
                                        $default = $role === 'admin'
                                            || (
                                                $role === 'caretaker'
                                                && in_array(
                                                    $permission,
                                                    [
                                                        'residents',
                                                        'health_records',
                                                        'schedules',
                                                        'messages',
                                                        'reports',
                                                    ],
                                                    true
                                                )
                                            );

                                        $checked = isset($permissions[$role][$permission])
                                            ? (bool) $permissions[$role][$permission]
                                            : $default;
                                        ?>

                                        <td>
                                            <input
                                                type="checkbox"
                                                name="<?= settingsEscape($role . '_' . $permission) ?>"
                                                aria-label="<?= settingsEscape($roleLabel . ' ' . $label) ?>"
                                                <?= $checked ? 'checked' : '' ?>
                                            >
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="actions">
                    <button class="btn" type="submit">
                        Save Permission Preferences
                    </button>
                </div>
            </form>
        </section>

    <?php elseif ($section === 'password'): ?>

        <section class="panel">
            <h2>Change Password</h2>

            <p class="description">
                Enter your current password, then choose a new one (at least 8 characters).
            </p>

            <form method="post" class="narrow-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= settingsEscape($csrf) ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="field">
                    <label for="current_password">Current Password</label>
                    <input id="current_password" name="current_password" type="password"
                           autocomplete="current-password" required>
                </div>

                <div class="field">
                    <label for="new_password">New Password</label>
                    <input id="new_password" name="new_password" type="password"
                           minlength="8" maxlength="200" autocomplete="new-password" required>
                </div>

                <div class="field">
                    <label for="confirm_password">Confirm New Password</label>
                    <input id="confirm_password" name="confirm_password" type="password"
                           minlength="8" maxlength="200" autocomplete="new-password" required>
                </div>

                <div class="actions">
                    <button class="btn" type="submit">Change Password</button>
                    <a class="btn secondary" href="settings.php?section=forgot-password">
                        Forgot password?
                    </a>
                </div>
            </form>
        </section>

    <?php elseif ($section === 'forgot-password'): ?>

        <section class="panel">
            <h2>Forgot Password</h2>

            <p class="description">
                If a staff member forgot their password, set a new one for their account here.
                Share it with them securely and ask them to change it after signing in.
                Passwords are stored hashed, so the old one can never be viewed.
            </p>

            <form method="post" class="narrow-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= settingsEscape($csrf) ?>">
                <input type="hidden" name="action" value="reset_password">

                <div class="field">
                    <label for="target_account">Account (<?= settingsEscape($accountLookupCol) ?>)</label>
                    <input id="target_account" name="target_account" type="text"
                           maxlength="200" autocomplete="off" required>
                </div>

                <div class="field">
                    <label for="reset_new_password">New Password</label>
                    <input id="reset_new_password" name="new_password" type="password"
                           minlength="8" maxlength="200" autocomplete="new-password" required>
                </div>

                <div class="field">
                    <label for="reset_confirm_password">Confirm New Password</label>
                    <input id="reset_confirm_password" name="confirm_password" type="password"
                           minlength="8" maxlength="200" autocomplete="new-password" required>
                </div>

                <div class="actions">
                    <button class="btn" type="submit"
                            onclick="return confirm('Reset the password for this account?');">
                        Reset Password
                    </button>
                </div>
            </form>
        </section>

    <?php endif; ?>

<?php endif; ?>

<p class="help">
    Signed in as <?= settingsEscape($currentUser) ?>.
    Settings changes are saved to the database.
</p>

<button type="button" class="back-to-top" id="backToTop" aria-label="Back to top">&uarr;</button>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /* ---- Find the real sidebar (checked in priority order) ---- */
    var sidebar = null;
    ['.app-sidebar', '.sidebar', '#sidebar', '.sidebar-container', 'aside', 'nav']
        .some(function (selector) {
            sidebar = document.querySelector(selector);
            return !!sidebar;
        });

    var toggle   = document.getElementById('menuToggle');
    var backdrop = document.getElementById('sidebarBackdrop');

    function setMenu(open) {
        document.body.classList.toggle('sidebar-open', open);
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            toggle.innerHTML = open ? '&times;' : '&#9776;';
        }
    }

    if (sidebar) {
        sidebar.classList.add('js-sidebar');

        /* Hide the old text title and subtitle */
        var oldTexts = ['home for the aged', 'care management system'];

        sidebar.querySelectorAll('*').forEach(function (el) {
            if (
                el.children.length === 0 &&
                oldTexts.indexOf(el.textContent.trim().toLowerCase()) !== -1
            ) {
                el.style.display = 'none';
            }
        });

        /* Add the logo at the top of the sidebar */
        if (!sidebar.querySelector('.sidebar-logo')) {
            var logo = document.createElement('div');
            logo.className = 'sidebar-logo';
            logo.innerHTML =
                '<a href="/homefor_thetander/admin/dashboard.php">' +
                '<img src="/homefor_thetander/images/white1.png" ' +
                'alt="Home for the Aged - Care, Comfort, Dignity">' +
                '</a>';

            sidebar.insertBefore(logo, sidebar.firstChild);
        }

        /* Close the mobile menu after choosing a link */
        sidebar.addEventListener('click', function (e) {
            if (e.target.closest('a')) {
                setMenu(false);
            }
        });
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            setMenu(!document.body.classList.contains('sidebar-open'));
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', function () { setMenu(false); });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { setMenu(false); }
    });

    /* Leaving mobile width closes the menu */
    window.addEventListener('resize', function () {
        if (window.innerWidth > 760) { setMenu(false); }
    });

    /* ---- Back-to-top button ---- */
    var topBtn = document.getElementById('backToTop');

    function updateTopBtn() {
        var y = window.pageYOffset || document.documentElement.scrollTop || 0;
        topBtn.classList.toggle('show', y > 300);
    }

    if (topBtn) {
        window.addEventListener('scroll', updateTopBtn, { passive: true });
        updateTopBtn();

        topBtn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
});
</script>

<!-- Do not add another </main> here.
     The shared includes/sidebar.php already opened the main element. -->

</main>
</body>
</html>