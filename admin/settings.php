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

function settingsRedirect(string $section): void
{
    header(
        'Location: settings.php?section=' . rawurlencode($section)
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Available settings sections
|--------------------------------------------------------------------------
*/

$sections = [
    'facility'      => 'Facility Information',
    'rooms'         => 'Room Management',
    'care-levels'   => 'Care Levels',
    'tasks'         => 'Task Categories',
    'notifications' => 'Notification Settings',
    'admins'        => 'Admin Accounts',
    'caretakers'    => 'Caretaker Accounts',
    'permissions'   => 'Permissions',
    'password'      => 'Change Password',
    'login-history' => 'Login History',
    'activity'      => 'Account Activity',
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
            | Save facility information
            */
            if ($action === 'save_facility') {
                $fields = [
                    'facility_name',
                    'facility_address',
                    'facility_phone',
                    'facility_email',
                    'facility_license',
                    'facility_motto',
                ];

                foreach ($fields as $key) {
                    $value = trim((string) ($_POST[$key] ?? ''));

                    if (strlen($value) > 2000) {
                        throw new RuntimeException(
                            'One of the facility fields is too long.'
                        );
                    }

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
                    'Updated facility information'
                );

                $notice = 'Facility information saved successfully.';
                $section = 'facility';
            }

            /*
            | Save care levels, task categories, or room types
            */
            elseif ($action === 'save_list') {
                $listKey = (string) ($_POST['list_key'] ?? '');

                $allowedLists = [
                    'care_levels',
                    'task_categories',
                    'room_types',
                ];

                if (!in_array($listKey, $allowedLists, true)) {
                    throw new RuntimeException(
                        'Invalid settings list.'
                    );
                }

                $raw = trim((string) ($_POST['list_values'] ?? ''));

                if (strlen($raw) > 20000) {
                    throw new RuntimeException(
                        'The submitted list is too long.'
                    );
                }

                $lines = preg_split('/\R/u', $raw);

                $items = array_values(array_unique(
                    array_filter(
                        array_map('trim', $lines ?: []),
                        static function ($item) {
                            return $item !== '';
                        }
                    )
                ));

                settingsSave(
                    $pdo,
                    $listKey,
                    json_encode(
                        $items,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    ),
                    $currentUser
                );

                settingsLog(
                    $pdo,
                    $currentUser,
                    'Updated settings list',
                    $listKey
                );

                $section = match ($listKey) {
                    'care_levels' => 'care-levels',
                    'task_categories' => 'tasks',
                    default => 'rooms',
                };

                $notice = 'List saved successfully.';
            }

            /*
            | Save notification preferences
            */
            elseif ($action === 'save_notifications') {
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

$facility = [];

foreach ([
    'facility_name',
    'facility_address',
    'facility_phone',
    'facility_email',
    'facility_license',
    'facility_motto',
] as $key) {
    $facility[$key] = settingsGet($pdo, $key);
}

$careLevels = settingsGetList(
    $pdo,
    'care_levels',
    ['Independent living', 'Assisted living', 'Full assistance']
);

$taskCategories = settingsGetList(
    $pdo,
    'task_categories',
    ['Medication', 'Meals', 'Bathing', 'Exercise', 'Appointment']
);

$roomTypes = settingsGetList(
    $pdo,
    'room_types',
    ['Private', 'Shared', 'Medical']
);

$permissions = settingsGetList(
    $pdo,
    'role_permissions',
    []
);

$activityRows = $pdo->query("
    SELECT user_id, action, details, created_at
    FROM settings_activity
    ORDER BY id DESC
    LIMIT 100
")->fetchAll();

/*
|--------------------------------------------------------------------------
| Existing module links
|--------------------------------------------------------------------------
*/

$adminLink = '/homefor_thetander/admin/users.php';

if (is_file(__DIR__ . '/admin_accounts.php')) {
    $adminLink = '/homefor_thetander/admin/admin_accounts.php';
}

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
            color: #163421;
        }

        .page-heading p {
            margin: 0;
            color: #657168;
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

        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            width: 100%;
        }

        .setting-card {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            min-width: 0;
            padding: 18px;
            background: #fff;
            color: #20372a;
            text-decoration: none;
            border: 1px solid #dce5de;
            border-radius: 12px;
            transition: transform .15s ease,
                        border-color .15s ease,
                        box-shadow .15s ease;
        }

        .setting-card:hover {
            border-color: #7aa889;
            box-shadow: 0 5px 15px rgba(16, 45, 25, .08);
            transform: translateY(-2px);
        }

        .setting-card .symbol {
            font-size: 25px;
            line-height: 1.2;
        }

        .setting-card strong {
            font-size: 16px;
            color: #0b5426;
        }

        .setting-card span {
            font-size: 13px;
            line-height: 1.5;
            color: #657168;
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
            margin-bottom: 15px;
            min-width: 0;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field label {
            font-size: 14px;
            font-weight: 700;
            color: #263b2c;
        }

        .field input,
        .field textarea,
        .field select {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #cbd7ce;
            border-radius: 8px;
            background: #fff;
            color: #1d2920;
            font: inherit;
            outline: none;
        }

        .field textarea {
            min-height: 110px;
            resize: vertical;
        }

        .field input:focus,
        .field textarea:focus,
        .field select:focus {
            border-color: #2e8050;
            box-shadow: 0 0 0 3px rgba(46, 128, 80, .12);
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

        @media (max-width: 1050px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 18px 12px !important;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .page-heading h1 {
                font-size: 25px;
            }

            .panel {
                padding: 17px;
            }
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

<header class="page-heading">
    <div>
        <h1>⚙ Settings</h1>
        <p>Manage facility information, configuration, accounts, and security.</p>
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

    <!-- SETTINGS OVERVIEW -->

    <section class="panel">
        <h2>System Settings</h2>
        <p class="description">
            Configure facility information and the options used by your team.
        </p>

        <div class="cards">

            <a class="setting-card" href="settings.php?section=facility">
                <div class="symbol">🏡</div>
                <strong>Facility Information</strong>
                <span>Edit the facility name, contact details, address, and registration information.</span>
            </a>

            <a class="setting-card" href="settings.php?section=rooms">
                <div class="symbol">🛏️</div>
                <strong>Room Management</strong>
                <span>Manage room-type settings or open the existing Rooms module.</span>
            </a>

            <a class="setting-card" href="settings.php?section=care-levels">
                <div class="symbol">♥</div>
                <strong>Care Levels</strong>
                <span>Maintain the list of care levels used by the facility.</span>
            </a>

            <a class="setting-card" href="settings.php?section=tasks">
                <div class="symbol">☑</div>
                <strong>Task Categories</strong>
                <span>Maintain categories used to organize care tasks.</span>
            </a>

            <a class="setting-card" href="settings.php?section=notifications">
                <div class="symbol">🔔</div>
                <strong>Notification Settings</strong>
                <span>Save preferences for alerts and reminders.</span>
            </a>

        </div>
    </section>

    <section class="panel">
        <h2>Account Management</h2>
        <p class="description">
            Open the existing account modules or configure permission preferences.
        </p>

        <div class="cards">

            <a class="setting-card" href="<?= settingsEscape($adminLink) ?>">
                <div class="symbol">🛡️</div>
                <strong>Admin Accounts</strong>
                <span>Open the existing administrator account module.</span>
            </a>

            <a class="setting-card" href="<?= settingsEscape($caretakerLink) ?>">
                <div class="symbol">♧</div>
                <strong>Caretaker Accounts</strong>
                <span>Open caretaker account management.</span>
            </a>

            <a class="setting-card" href="settings.php?section=permissions">
                <div class="symbol">🔐</div>
                <strong>Permissions</strong>
                <span>Configure role permission preferences for Admin and Caretaker.</span>
            </a>

        </div>
    </section>

    <section class="panel">
        <h2>Security and Activity</h2>
        <p class="description">
            Review account security and changes made through Settings.
        </p>

        <div class="cards">

            <a class="setting-card" href="settings.php?section=password">
                <div class="symbol">🔑</div>
                <strong>Change Password</strong>
                <span>View password-change information for your account.</span>
            </a>

            <a class="setting-card" href="settings.php?section=login-history">
                <div class="symbol">◷</div>
                <strong>Login History</strong>
                <span>Review the available activity records.</span>
            </a>

            <a class="setting-card" href="settings.php?section=activity">
                <div class="symbol">📋</div>
                <strong>Account Activity</strong>
                <span>Review changes made from this Settings page.</span>
            </a>

        </div>
    </section>

<?php else: ?>

    <div class="topline">
        <a class="back-link" href="settings.php">← All Settings</a>
        <strong><?= settingsEscape($sections[$section]) ?></strong>
    </div>

    <?php if ($section === 'facility'): ?>

        <section class="panel">
            <h2>Facility Information</h2>
            <p class="description">
                Update the information saved in the application settings table.
            </p>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= settingsEscape($csrf) ?>">
                <input type="hidden" name="action" value="save_facility">

                <div class="form-grid">

                    <div class="field">
                        <label for="facility_name">Facility Name</label>
                        <input
                            id="facility_name"
                            name="facility_name"
                            type="text"
                            maxlength="200"
                            value="<?= settingsEscape($facility['facility_name']) ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="facility_phone">Phone Number</label>
                        <input
                            id="facility_phone"
                            name="facility_phone"
                            type="tel"
                            maxlength="100"
                            value="<?= settingsEscape($facility['facility_phone']) ?>"
                        >
                    </div>

                    <div class="field full">
                        <label for="facility_address">Address</label>
                        <textarea
                            id="facility_address"
                            name="facility_address"
                            maxlength="2000"
                        ><?= settingsEscape($facility['facility_address']) ?></textarea>
                    </div>

                    <div class="field">
                        <label for="facility_email">Email Address</label>
                        <input
                            id="facility_email"
                            name="facility_email"
                            type="email"
                            maxlength="200"
                            value="<?= settingsEscape($facility['facility_email']) ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="facility_license">License / Registration Number</label>
                        <input
                            id="facility_license"
                            name="facility_license"
                            type="text"
                            maxlength="200"
                            value="<?= settingsEscape($facility['facility_license']) ?>"
                        >
                    </div>

                    <div class="field full">
                        <label for="facility_motto">Motto / Description</label>
                        <textarea
                            id="facility_motto"
                            name="facility_motto"
                            maxlength="2000"
                        ><?= settingsEscape($facility['facility_motto']) ?></textarea>
                    </div>

                </div>

                <div class="actions">
                    <button class="btn" type="submit">
                        Save Facility Information
                    </button>
                </div>
            </form>
        </section>

    <?php elseif ($section === 'rooms'): ?>

        <section class="panel">
            <h2>Room Management</h2>
            <p class="description">
                Maintain room-type options. This list is separate from actual room records.
            </p>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= settingsEscape($csrf) ?>">
                <input type="hidden" name="action" value="save_list">
                <input type="hidden" name="list_key" value="room_types">

                <div class="field">
                    <label for="list_values">Room Types (one per line)</label>
                    <textarea id="list_values" name="list_values"><?= settingsEscape(implode("\n", $roomTypes)) ?></textarea>
                    <span class="help">
                        Saving this list does not create, edit, or delete actual room records.
                    </span>
                </div>

                <div class="actions">
                    <button class="btn" type="submit">Save Room Types</button>
                    <a class="btn secondary" href="/homefor_thetander/admin/rooms.php">
                        Open Rooms Module
                    </a>
                </div>
            </form>
        </section>

    <?php elseif ($section === 'care-levels' || $section === 'tasks'): ?>

        <?php
        $isCare = $section === 'care-levels';
        $listKey = $isCare ? 'care_levels' : 'task_categories';
        $items = $isCare ? $careLevels : $taskCategories;
        ?>

        <section class="panel">
            <h2><?= $isCare ? 'Care Levels' : 'Task Categories' ?></h2>

            <p class="description">
                Enter one item per line. Empty lines are ignored and duplicates are removed.
            </p>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= settingsEscape($csrf) ?>">
                <input type="hidden" name="action" value="save_list">
                <input type="hidden" name="list_key" value="<?= settingsEscape($listKey) ?>">

                <div class="field">
                    <label for="list_values">
                        <?= $isCare ? 'Care Levels' : 'Task Categories' ?>
                    </label>

                    <textarea id="list_values" name="list_values" required><?= settingsEscape(implode("\n", $items)) ?></textarea>
                </div>

                <div class="actions">
                    <button class="btn" type="submit">Save List</button>
                </div>
            </form>
        </section>

    <?php elseif ($section === 'notifications'): ?>

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

    <?php elseif ($section === 'admins' || $section === 'caretakers'): ?>

        <section class="panel">
            <h2><?= settingsEscape($sections[$section]) ?></h2>

            <p class="description">
                Open the existing account-management module to manage accounts.
            </p>

            <?php if ($section === 'admins'): ?>
                <a class="btn" href="<?= settingsEscape($adminLink) ?>">
                    Open Admin Accounts
                </a>
            <?php else: ?>
                <a class="btn" href="<?= settingsEscape($caretakerLink) ?>">
                    Open Caretaker Accounts
                </a>
            <?php endif; ?>
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
                Password updates must use the same password-hashing method as your login system.
                This Settings page does not change your password until the authentication code and user-table structure are verified.
            </p>

            <p>
                Do not store a plain-text password in the database. A safe password update needs to verify your current password and use PHP's password hashing functions.
            </p>

            <a class="btn secondary" href="/homefor_thetander/admin/dashboard.php">
                Return to Dashboard
            </a>
        </section>

    <?php elseif ($section === 'login-history'): ?>

        <section class="panel">
            <h2>Login History</h2>

            <p class="description">
                This page does not assume a login-history table exists. The table below displays Settings activity, not successful login events.
            </p>

            <?php if (!$activityRows): ?>
                <p>No activity recorded yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>Date</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($activityRows as $row): ?>
                                <tr>
                                    <td><?= settingsEscape($row['user_id']) ?></td>
                                    <td><?= settingsEscape($row['action']) ?></td>
                                    <td><?= settingsEscape($row['details']) ?></td>
                                    <td><?= settingsEscape($row['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ($section === 'activity'): ?>

        <section class="panel">
            <h2>Account Activity</h2>

            <p class="description">
                Review changes recorded through this Settings page.
            </p>

            <?php if (!$activityRows): ?>
                <p>No activity recorded yet. Saving a setting creates an entry.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>Date</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($activityRows as $row): ?>
                                <tr>
                                    <td><?= settingsEscape($row['user_id']) ?></td>
                                    <td><?= settingsEscape($row['action']) ?></td>
                                    <td><?= settingsEscape($row['details']) ?></td>
                                    <td><?= settingsEscape($row['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    <?php endif; ?>

<?php endif; ?>

<p class="help">
    Signed in as <?= settingsEscape($currentUser) ?>.
    Settings changes are saved to the database.
</p>

<!-- Puts the logo at the top of the sidebar and hides the old text title -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector(
        '.app-sidebar, .sidebar, #sidebar, .sidebar-container, aside, nav'
    );

    if (!sidebar) {
        return;
    }

    // Hide the old text title and subtitle
    var oldTexts = ['home for the aged', 'care management system'];

    sidebar.querySelectorAll('*').forEach(function (el) {
        if (
            el.children.length === 0 &&
            oldTexts.indexOf(el.textContent.trim().toLowerCase()) !== -1
        ) {
            el.style.display = 'none';
        }
    });

    // Add the logo
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
});
</script>

<!-- Do not add another </main> here.
     The shared includes/sidebar.php already opened the main element. -->

</main>
</body>
</html>