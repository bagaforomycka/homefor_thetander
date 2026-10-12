<?php
/**
 * Home for the Aged - Settings
 * Location: admin/settings.php
 * Requires ../config/db.php to create a PDO connection named $pdo.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: /homefor_thetander/index.php');
    exit;
}

$currentRole = strtolower((string) $_SESSION['role']);
$currentPage = basename($_SERVER['PHP_SELF']);

function sidebarLink(string $href, string $label, string $page, string $currentPage): void {
    $active = ($page === $currentPage) ? 'active' : '';
    echo '<a class="sidebar-link ' . $active . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}

if ($currentRole !== 'admin') {
    http_response_code(403);
    exit('Access denied. Only administrators can access Settings.');
}

require_once __DIR__ . '/../config/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not found. Open config/db.php and ensure it creates a PDO variable named $pdo.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value LONGTEXT NULL,
        updated_by VARCHAR(100) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS settings_activity (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(100) NOT NULL,
        action VARCHAR(100) NOT NULL,
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

function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function settingValue(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}
function saveSetting(PDO $pdo, string $key, string $value, string $user): void {
    $stmt = $pdo->prepare("
        INSERT INTO app_settings (setting_key, setting_value, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
    ");
    $stmt->execute([$key, $value, $user]);
}
function logSettings(PDO $pdo, string $user, string $action, string $details = ''): void {
    $stmt = $pdo->prepare('INSERT INTO settings_activity (user_id, action, details) VALUES (?, ?, ?)');
    $stmt->execute([$user, $action, $details]);
}
function listSetting(PDO $pdo, string $key, array $default = []): array {
    $raw = settingValue($pdo, $key, '');
    if ($raw === '') return $default;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

/* Schema confirmed from login.php: users(id, full_name, username, password, role, status). */
function detectUsersTable(PDO $pdo): ?array {
    return ['table' => 'users', 'id' => 'id', 'user' => 'username', 'pass' => 'password', 'role' => 'role', 'name' => 'full_name', 'status' => 'status'];
}
function q(string $identifier): string {
    return '`' . str_replace('`', '', $identifier) . '`';
}
function isModernHash(string $hash): bool {
    return (bool) preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', $hash);
}
function findUserRow(PDO $pdo, array $u, string $sessionUserId): ?array {
    foreach ([$u['id'], $u['user']] as $col) {
        $stmt = $pdo->prepare('SELECT * FROM ' . q($u['table']) . ' WHERE ' . q($col) . ' = ? LIMIT 1');
        $stmt->execute([$sessionUserId]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    return null;
}
function redirectSection(string $section): void {
    header('Location: settings.php?section=' . rawurlencode($section));
    exit;
}

$usersTable = detectUsersTable($pdo);

$sections = [
    'facility' => 'Facility Information',
    'rooms' => 'Room Management',
    'care-levels' => 'Care Levels',
    'tasks' => 'Task Categories',
    'notifications' => 'Notification Settings',
    'admins' => 'Admin Accounts',
    'caretakers' => 'Caretaker Accounts',
    'permissions' => 'Permissions',
    'password' => 'Change Password',
    'login-history' => 'Login History',
    'activity' => 'Account Activity',
];
$section = (string) ($_GET['section'] ?? 'facility');
if (!isset($sections[$section])) {
    $section = 'facility';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $postedToken)) {
        $error = 'Security token expired. Refresh this page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'save_facility') {
                $fields = [
                    'facility_name' => 'Facility name',
                    'facility_address' => 'Address',
                    'facility_phone' => 'Phone',
                    'facility_email' => 'Email',
                    'facility_license' => 'License / registration number',
                    'facility_motto' => 'Motto',
                ];
                foreach ($fields as $key => $label) {
                    $value = trim((string) ($_POST[$key] ?? ''));
                    if (strlen($value) > 2000) {
                        throw new RuntimeException($label . ' is too long.');
                    }
                    saveSetting($pdo, $key, $value, $currentUser);
                }
                logSettings($pdo, $currentUser, 'Updated facility information');
                $notice = 'Facility information saved.';
                $section = 'facility';
            } elseif ($action === 'save_list') {
                $listKey = (string) ($_POST['list_key'] ?? '');
                $allowed = ['care_levels', 'task_categories', 'room_types'];
                if (!in_array($listKey, $allowed, true)) {
                    throw new RuntimeException('Invalid list selected.');
                }
                $raw = trim((string) ($_POST['list_values'] ?? ''));
                if (strlen($raw) > 20000) {
                    throw new RuntimeException('The list is too long.');
                }
                $items = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', $raw)), static function ($v) {
                    return $v !== '';
                })));
                saveSetting($pdo, $listKey, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $currentUser);
                logSettings($pdo, $currentUser, 'Updated setting list', $listKey);
                $notice = 'List saved successfully.';
                $section = $listKey === 'care_levels' ? 'care-levels' : ($listKey === 'task_categories' ? 'tasks' : 'rooms');
            } elseif ($action === 'save_notifications') {
                foreach (['email_notifications', 'in_app_notifications', 'schedule_reminders', 'health_alerts'] as $key) {
                    saveSetting($pdo, $key, isset($_POST[$key]) ? '1' : '0', $currentUser);
                }
                logSettings($pdo, $currentUser, 'Updated notification preferences');
                $notice = 'Notification preferences saved.';
                $section = 'notifications';
            } elseif ($action === 'save_permissions') {
                $permissions = [];
                foreach (['admin', 'caretaker'] as $role) {
                    $permissions[$role] = [];
                    foreach (['residents', 'health_records', 'rooms', 'schedules', 'messages', 'reports', 'settings'] as $perm) {
                        $permissions[$role][$perm] = isset($_POST[$role . '_' . $perm]);
                    }
                }
                saveSetting($pdo, 'role_permissions', json_encode($permissions), $currentUser);
                logSettings($pdo, $currentUser, 'Updated role permission preferences');
                $notice = 'Permission preferences saved. These are stored as configuration; enforce them in each module before relying on them for access control.';
                $section = 'permissions';
            } elseif ($action === 'change_password') {
                $section = 'password';
                if (!$usersTable) {
                    throw new RuntimeException('The users table could not be detected in this database.');
                }
                $current = (string) ($_POST['current_password'] ?? '');
                $new = (string) ($_POST['new_password'] ?? '');
                $confirm = (string) ($_POST['confirm_password'] ?? '');
                if ($current === '' || $new === '' || $confirm === '') {
                    throw new RuntimeException('Please fill in all password fields.');
                }
                if (strlen($new) < 8) {
                    throw new RuntimeException('The new password must be at least 8 characters.');
                }
                if (!hash_equals($new, $confirm)) {
                    throw new RuntimeException('The new password and confirmation do not match.');
                }
                $row = findUserRow($pdo, $usersTable, $currentUser);
                if (!$row) {
                    throw new RuntimeException('Your account could not be found in the users table.');
                }
                $stored = (string) $row[$usersTable['pass']];
                if (!password_verify($current, $stored)) {
                    throw new RuntimeException('Your current password is incorrect.');
                }
                $upd = $pdo->prepare('UPDATE ' . q($usersTable['table']) . ' SET ' . q($usersTable['pass']) . ' = ? WHERE ' . q($usersTable['id']) . ' = ?');
                $upd->execute([password_hash($new, PASSWORD_DEFAULT), $row[$usersTable['id']]]);
                logSettings($pdo, $currentUser, 'Changed own password');
                $notice = 'Your password was changed successfully.';
            } elseif ($action === 'reset_admin_password') {
                $section = 'admins';
                if (!$usersTable) throw new RuntimeException('The users table could not be detected in this database.');
                $targetId = (string) ($_POST['target_id'] ?? '');
                $new = (string) ($_POST['new_password'] ?? '');
                if (strlen($new) < 8) throw new RuntimeException('The new password must be at least 8 characters.');
                $upd = $pdo->prepare('UPDATE ' . q($usersTable['table']) . ' SET ' . q($usersTable['pass']) . ' = ? WHERE ' . q($usersTable['id']) . ' = ? AND LOWER(' . q($usersTable['role']) . ") = 'admin'");
                $upd->execute([password_hash($new, PASSWORD_DEFAULT), $targetId]);
                if ($upd->rowCount() < 1) throw new RuntimeException('No administrator account was updated.');
                logSettings($pdo, $currentUser, 'Reset admin password', 'account id ' . $targetId);
                $notice = 'Administrator password reset.';
            } elseif ($action === 'add_admin') {
                $section = 'admins';
                if (!$usersTable) throw new RuntimeException('The users table could not be detected in this database.');
                $username = trim((string) ($_POST['new_username'] ?? ''));
                $fullName = trim((string) ($_POST['new_fullname'] ?? ''));
                $new = (string) ($_POST['new_password'] ?? '');
                if ($fullName === '' || strlen($fullName) > 150) throw new RuntimeException('Enter the full name (up to 150 characters).');
                if ($username === '' || strlen($username) > 100) throw new RuntimeException('Enter a username of up to 100 characters.');
                if (strlen($new) < 8) throw new RuntimeException('The password must be at least 8 characters.');
                $dup = $pdo->prepare('SELECT COUNT(*) FROM ' . q($usersTable['table']) . ' WHERE ' . q($usersTable['user']) . ' = ?');
                $dup->execute([$username]);
                if ((int) $dup->fetchColumn() > 0) throw new RuntimeException('That username is already in use.');
                $cols = [q($usersTable['user']), q($usersTable['pass']), q($usersTable['role']), q($usersTable['name']), q($usersTable['status'])];
                $vals = [$username, password_hash($new, PASSWORD_DEFAULT), 'admin', $fullName, 'active'];
                $ins = $pdo->prepare('INSERT INTO ' . q($usersTable['table']) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')');
                try {
                    $ins->execute($vals);
                } catch (PDOException $e) {
                    throw new RuntimeException('The account could not be created. The users table may have another required column; run SHOW CREATE TABLE users and send the result.');
                }
                logSettings($pdo, $currentUser, 'Added admin account', $username);
                $notice = 'Administrator account created.';
            } else {
                throw new RuntimeException('That action is not available.');
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save settings. Check your database connection and table permissions.';
        }
    }
}

$facility = [];
foreach (['facility_name','facility_address','facility_phone','facility_email','facility_license','facility_motto'] as $key) {
    $facility[$key] = settingValue($pdo, $key);
}
$careLevels = listSetting($pdo, 'care_levels', ['Independent living', 'Assisted living', 'Full assistance']);
$taskCategories = listSetting($pdo, 'task_categories', ['Medication', 'Meals', 'Bathing', 'Exercise', 'Appointment']);
$roomTypes = listSetting($pdo, 'room_types', ['Private', 'Shared', 'Medical']);
$permissions = listSetting($pdo, 'role_permissions', []);
$activityRows = $pdo->query('SELECT user_id, action, details, created_at FROM settings_activity ORDER BY id DESC LIMIT 100')->fetchAll();

$adminRows = [];
if ($usersTable && $section === 'admins') {
    $sel = q($usersTable['id']) . ' AS uid, ' . q($usersTable['user']) . ' AS uname, ' . q($usersTable['name']) . ' AS fname, ' . q($usersTable['status']) . ' AS ustatus';
    $stmt = $pdo->query('SELECT ' . $sel . ' FROM ' . q($usersTable['table']) . ' WHERE LOWER(' . q($usersTable['role']) . ") = 'admin' ORDER BY uid");
    $adminRows = $stmt->fetchAll();
}

$roomsModuleExists = is_file(__DIR__ . '/rooms/index.php');
$caretakerModule = '/homefor_thetander/admin/caretakers_staff.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings | Home for the Aged</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{--sidebar-width:250px;--sidebar-green:#063b18;--page-bg:#f5f8f6;--green:#063b18;--green2:#0b5426;--bg:#f5f8f6;--line:#dce5de;--muted:#657168;--white:#fff}
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:var(--page-bg);color:#080c09}
/* ===== SIDEBAR (keep identical to the other modules) ===== */
.app-sidebar{position:fixed;top:0;left:0;width:var(--sidebar-width);height:100vh;overflow-y:auto;padding:24px 16px;background:var(--sidebar-green);color:white;z-index:1000}
.sidebar-brand{font-size:21px;font-weight:bold;margin-bottom:6px}
.sidebar-subtitle{font-size:13px;margin-bottom:28px;opacity:.9}
.sidebar-section{margin:20px 10px 8px;font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:1px;opacity:.8}
.sidebar-link{display:block;color:white;text-decoration:none;padding:11px 12px;border-radius:8px;margin-bottom:5px;font-size:14px}
.sidebar-link:hover,.sidebar-link.active{background:rgba(255,255,255,.22);color:white}
.app-main{margin-left:var(--sidebar-width);width:calc(100% - var(--sidebar-width));min-height:100vh;padding:28px}
@media(max-width:768px){:root{--sidebar-width:190px}.app-sidebar{padding:18px 10px}.app-main{padding:18px 12px}}
@media(max-width:560px){.app-sidebar{position:relative;width:100%;height:auto}.app-main{margin-left:0;width:100%;padding:16px}}
/* ===== PAGE ===== */
.page-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:24px}
.page-heading h1{margin:0 0 8px;font-weight:700;font-size:30px;line-height:1.2;color:#163421}
.page-heading p{margin:0;color:var(--muted);line-height:1.6}
.panel{background:var(--white);border:1px solid var(--line);border-radius:14px;padding:22px;margin-bottom:22px;box-shadow:0 3px 12px rgba(16,45,25,.045)}
.panel h2{margin:0 0 8px;font-weight:700;font-size:21px;color:#173b24}
.description{margin:0 0 18px;color:var(--muted);line-height:1.55}
.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.setting-card{display:flex;flex-direction:column;align-items:flex-start;gap:8px;min-width:0;padding:18px;background:#fff;color:#20372a;text-decoration:none;border:1px solid var(--line);border-radius:12px;transition:transform .15s ease,border-color .15s ease,box-shadow .15s ease}
.setting-card:hover{border-color:#7aa889;box-shadow:0 5px 15px rgba(16,45,25,.08);transform:translateY(-2px)}
.setting-card .symbol{font-size:25px;line-height:1.2}
.setting-card strong{font-size:16px;color:#0b5426}
.setting-card span{font-size:13px;line-height:1.5;color:#657168}
.notice{padding:13px 16px;border:1px solid #a8d5b5;border-radius:9px;background:#edf8ef;color:#19582a;margin-bottom:18px}
.notice.error{border-color:#e8b5b5;background:#fff0f0;color:#8b2020}
.topline{display:flex;align-items:center;gap:16px;margin:0 0 18px;color:#173b24}
.back-link{color:#0b5426;text-decoration:none;font-weight:bold}
.back-link:hover{text-decoration:underline;color:#0b5426}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.field{display:flex;flex-direction:column;gap:7px;margin-bottom:15px;min-width:0}
.field.full{grid-column:1/-1}
.field label{font-size:14px;font-weight:700;color:#263b2c}
.field input,.field textarea,.field select{width:100%;padding:11px 12px;border:1px solid #cbd7ce;border-radius:8px;background:#fff;color:#1d2920;font:inherit;outline:none}
.field textarea{min-height:110px;resize:vertical}
.field input:focus,.field textarea:focus,.field select:focus{border-color:#2e8050;box-shadow:0 0 0 3px rgba(46,128,80,.12)}
.help{display:block;color:var(--muted);font-size:12px;line-height:1.5;margin-top:8px}
.actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:18px}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 16px;border:1px solid #0b5426;border-radius:8px;background:#0b5426;color:#fff;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}
.btn:hover{background:#063b18;color:#fff}
.btn.secondary{background:#fff;color:#0b5426}
.btn.secondary:hover{background:#eef5ef}
.check-row{display:flex;align-items:center;gap:10px;padding:13px 0;border-bottom:1px solid #edf1ed;color:#263b2c}
.check-row input{width:17px;height:17px;accent-color:#0b5426}
.table-wrap{width:100%;overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:560px}
th,td{text-align:left;padding:12px;border-bottom:1px solid #e5ebe6;font-size:14px}
th{background:#f1f6f2;color:#284332;font-weight:700}
.permissions-table th,.permissions-table td{text-align:center}
.permissions-table th:first-child,.permissions-table td:first-child{text-align:left}
.permissions-table input{width:17px;height:17px;accent-color:#0b5426}
.inline-form{display:flex;gap:8px;align-items:center}
.inline-form input{padding:8px 10px;border:1px solid #cbd7ce;border-radius:8px;font:inherit}
@media(max-width:1050px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){.cards{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.page-heading h1{font-size:25px}.panel{padding:17px}}
</style>
</head>
<body>
<!-- ===== SIDEBAR START ===== -->
<aside class="app-sidebar">
    <div class="sidebar-brand">Home for the Aged</div>
    <div class="sidebar-subtitle">Care Management System</div>

    <?php if (in_array($currentRole, ['admin', 'staff'], true)): ?>

        <div class="sidebar-section">Main Menu</div>
        <?php
        sidebarLink('/homefor_thetander/admin/dashboard.php', 'Dashboard', 'dashboard.php', $currentPage);
        sidebarLink('/homefor_thetander/admin/residents.php', 'Resident Management', 'residents.php', $currentPage);
        ?>

        <div class="sidebar-section">Care Management</div>
        <?php
        sidebarLink('/homefor_thetander/admin/health_records.php', 'Health Records', 'health_records.php', $currentPage);
        sidebarLink('/homefor_thetander/admin/caretakers_staff.php', 'Caretakers & Staff', 'caretakers_staff.php', $currentPage);
        sidebarLink('/homefor_thetander/admin/schedules.php', 'Schedules', 'schedules.php', $currentPage);
        sidebarLink('/homefor_thetander/admin/messages.php', 'Messages', 'messages.php', $currentPage);
        sidebarLink('/homefor_thetander/admin/reports.php', 'Reports', 'reports.php', $currentPage);
        ?>

        <div class="sidebar-section">Administration</div>
        <?php sidebarLink('/homefor_thetander/admin/settings.php', 'Settings', 'settings.php', $currentPage); ?>

        <div class="sidebar-section">Data Management</div>
        <?php sidebarLink('/homefor_thetander/admin/archive.php', '🗃 Archive & Restore', 'archive.php', $currentPage); ?>

    <?php elseif ($currentRole === 'caretaker'): ?>

        <div class="sidebar-section">Care Menu</div>
        <?php
        sidebarLink('/homefor_thetander/caretaker/dashboard.php', 'Dashboard', 'dashboard.php', $currentPage);
        sidebarLink('/homefor_thetander/caretaker/my_residents.php', 'My Residents', 'my_residents.php', $currentPage);
        sidebarLink('/homefor_thetander/caretaker/care_tasks.php', 'Care Tasks', 'care_tasks.php', $currentPage);
        sidebarLink('/homefor_thetander/caretaker/my_schedule.php', 'My Schedule', 'my_schedule.php', $currentPage);
        sidebarLink('/homefor_thetander/caretaker/health_information.php', 'Health Information', 'health_information.php', $currentPage);
        sidebarLink('/homefor_thetander/caretaker/messages.php', 'Messages', 'messages.php', $currentPage);
        sidebarLink('/homefor_thetander/caretaker/care_reports.php', 'Care Reports', 'care_reports.php', $currentPage);
        sidebarLink('/homefor_thetander/caretaker/incident_reports.php', 'Incident Reports', 'incident_reports.php', $currentPage);
        sidebarLink('/homefor_thetander/caretaker/profile.php', 'My Profile', 'profile.php', $currentPage);
        ?>

    <?php endif; ?>

    <div class="sidebar-section">Account</div>
    <?php sidebarLink('/homefor_thetander/logout.php', 'Logout', 'logout.php', $currentPage); ?>
</aside>
<!-- ===== SIDEBAR END ===== -->

<main class="app-main">
  <header class="page-heading">
    <div><h1>⚙ Settings</h1><p>Manage facility information, configuration, users, and security.</p></div>
  </header>

  <?php if ($notice !== ''): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>

  <?php if (!isset($_GET['section'])): ?>
    <section class="panel">
      <h2>System Settings</h2><p class="description">Configure the facility and the options used by your team.</p>
      <div class="cards">
        <a class="setting-card" href="settings.php?section=facility"><div class="symbol">🏡</div><strong>Facility Information</strong><span>Edit the facility name, contact details, address, and registration information.</span></a>
        <a class="setting-card" href="settings.php?section=rooms"><div class="symbol">🛏️</div><strong>Room Management</strong><span>Manage room types here or open the Rooms module for room records.</span></a>
        <a class="setting-card" href="settings.php?section=care-levels"><div class="symbol">♥</div><strong>Care Levels</strong><span>Maintain the list of care levels used by the facility.</span></a>
        <a class="setting-card" href="settings.php?section=tasks"><div class="symbol">☑</div><strong>Task Categories</strong><span>Maintain categories used to organize care tasks.</span></a>
        <a class="setting-card" href="settings.php?section=notifications"><div class="symbol">🔔</div><strong>Notification Settings</strong><span>Save preferences for alerts and reminders.</span></a>
      </div>
    </section>
    <section class="panel">
      <h2>User Management</h2><p class="description">Manage administrator and caretaker accounts, or configure permission preferences.</p>
      <div class="cards">
        <a class="setting-card" href="settings.php?section=admins"><div class="symbol">🛡️</div><strong>Admin Accounts</strong><span>View administrators, add one, or reset a password.</span></a>
        <a class="setting-card" href="<?= h($caretakerModule) ?>"><div class="symbol">♧</div><strong>Caretaker Accounts</strong><span>Open caretaker management.</span></a>
        <a class="setting-card" href="settings.php?section=permissions"><div class="symbol">🔐</div><strong>Permissions</strong><span>Configure role permission preferences.</span></a>
      </div>
    </section>
    <section class="panel">
      <h2>Security</h2><p class="description">Update your password and review settings-related activity.</p>
      <div class="cards">
        <a class="setting-card" href="settings.php?section=password"><div class="symbol">🔑</div><strong>Change Password</strong><span>Change the password for the account you are signed in with.</span></a>
        <a class="setting-card" href="settings.php?section=login-history"><div class="symbol">◷</div><strong>Login History</strong><span>View login records if your authentication module records them.</span></a>
        <a class="setting-card" href="settings.php?section=activity"><div class="symbol">📋</div><strong>Account Activity</strong><span>Review changes made from this Settings page.</span></a>
      </div>
    </section>
  <?php else: ?>
    <div class="topline"><a class="back-link" href="settings.php">← All Settings</a><strong><?= h($sections[$section]) ?></strong></div>

    <?php if ($section === 'facility'): ?>
      <section class="panel"><h2>Facility Information</h2><p class="description">These values are saved in the app_settings table.</p>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_facility">
        <div class="form-grid">
          <div class="field"><label for="facility_name">Facility name</label><input id="facility_name" name="facility_name" type="text" maxlength="200" value="<?= h($facility['facility_name']) ?>" required></div>
          <div class="field"><label for="facility_phone">Phone</label><input id="facility_phone" name="facility_phone" type="tel" maxlength="100" value="<?= h($facility['facility_phone']) ?>"></div>
          <div class="field full"><label for="facility_address">Address</label><textarea id="facility_address" name="facility_address" maxlength="2000"><?= h($facility['facility_address']) ?></textarea></div>
          <div class="field"><label for="facility_email">Email</label><input id="facility_email" name="facility_email" type="email" maxlength="200" value="<?= h($facility['facility_email']) ?>"></div>
          <div class="field"><label for="facility_license">License / registration number</label><input id="facility_license" name="facility_license" type="text" maxlength="200" value="<?= h($facility['facility_license']) ?>"></div>
          <div class="field full"><label for="facility_motto">Motto / description</label><textarea id="facility_motto" name="facility_motto" maxlength="2000"><?= h($facility['facility_motto']) ?></textarea></div>
        </div><div class="actions"><button class="btn" type="submit">Save Facility Information</button></div>
      </form></section>

    <?php elseif ($section === 'rooms'): ?>
      <section class="panel"><h2>Room Management</h2><p class="description">Maintain the room-type list used as configuration. Room assignments and actual room records live in the Rooms module.</p>
      <?php if (!$roomsModuleExists): ?><div class="notice error">admin/rooms/index.php was not found, so the Rooms module cannot open.</div><?php endif; ?>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_list"><input type="hidden" name="list_key" value="room_types">
        <div class="field"><label for="list_values">Room types (one per line)</label><textarea id="list_values" name="list_values"><?= h(implode("\n", $roomTypes)) ?></textarea><span class="help">Example: Private, Shared, Medical.</span></div>
        <div class="actions"><button class="btn" type="submit">Save Room Types</button><?php if ($roomsModuleExists): ?><a class="btn secondary" href="/homefor_thetander/admin/rooms/index.php">Open Rooms Module</a><?php endif; ?></div>
      </form></section>

    <?php elseif ($section === 'care-levels' || $section === 'tasks'): $isCare = $section === 'care-levels'; $key = $isCare ? 'care_levels' : 'task_categories'; $items = $isCare ? $careLevels : $taskCategories; ?>
      <section class="panel"><h2><?= $isCare ? 'Care Levels' : 'Task Categories' ?></h2><p class="description">Enter one item per line. Blank lines are ignored and duplicates are removed.</p>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_list"><input type="hidden" name="list_key" value="<?= h($key) ?>">
        <div class="field"><label for="list_values"><?= $isCare ? 'Care levels' : 'Task categories' ?></label><textarea id="list_values" name="list_values" required><?= h(implode("\n", $items)) ?></textarea></div>
        <div class="actions"><button class="btn" type="submit">Save List</button></div>
      </form></section>

    <?php elseif ($section === 'notifications'): ?>
      <section class="panel"><h2>Notification Settings</h2><p class="description">Save your preferred notification options. Delivery still needs to be implemented in the relevant modules.</p>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_notifications">
        <?php foreach (['email_notifications'=>'Email notifications','in_app_notifications'=>'In-app notifications','schedule_reminders'=>'Schedule reminders','health_alerts'=>'Health alerts'] as $key=>$label): ?>
          <label class="check-row"><input type="checkbox" name="<?= h($key) ?>" value="1" <?= settingValue($pdo,$key,'1') === '1' ? 'checked' : '' ?>> <?= h($label) ?></label>
        <?php endforeach; ?>
        <div class="actions"><button class="btn" type="submit">Save Notification Settings</button></div>
      </form></section>

    <?php elseif ($section === 'admins'): ?>
      <section class="panel"><h2>Admin Accounts</h2>
      <?php if (!$usersTable): ?>
        <div class="notice error">The users table could not be detected. Send the users table structure (or login.php) to finish wiring this.</div>
      <?php else: ?>
        <p class="description">Administrator accounts. Passwords are stored hashed and cannot be viewed.</p>
        <div class="table-wrap"><table><thead><tr><th>Full name</th><th>Username</th><th>Status</th><th>Reset password</th></tr></thead><tbody>
        <?php foreach ($adminRows as $a): ?>
          <tr><td><?= h($a['fname']) ?></td><td><?= h($a['uname']) ?></td><td><?= h($a['ustatus']) ?></td><td>
            <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="reset_admin_password"><input type="hidden" name="target_id" value="<?= h($a['uid']) ?>">
            <input type="password" name="new_password" placeholder="New password (8+ chars)" minlength="8" required autocomplete="new-password"><button class="btn secondary" type="submit">Reset</button></form>
          </td></tr>
        <?php endforeach; ?>
        <?php if (!$adminRows): ?><tr><td colspan="4">No administrator accounts found.</td></tr><?php endif; ?>
        </tbody></table></div>
      </section>
      <section class="panel"><h2>Add Administrator</h2>
        <form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add_admin">
          <div class="form-grid">
            <div class="field"><label for="new_fullname">Full name</label><input id="new_fullname" name="new_fullname" type="text" maxlength="150" required></div>
            <div class="field"><label for="new_username">Username</label><input id="new_username" name="new_username" type="text" maxlength="100" required></div>
            <div class="field"><label for="new_password_add">Password</label><input id="new_password_add" name="new_password" type="password" minlength="8" required autocomplete="new-password"></div>
          </div><div class="actions"><button class="btn" type="submit">Create Administrator</button></div>
        </form>
      <?php endif; ?>
      </section>

    <?php elseif ($section === 'caretakers'): ?>
      <section class="panel"><h2>Caretaker Accounts</h2><p class="description">This opens the existing caretaker management page.</p>
      <a class="btn" href="<?= h($caretakerModule) ?>">Open Caretaker Accounts</a></section>

    <?php elseif ($section === 'permissions'): ?>
      <?php $roleNames = ['admin'=>'Administrator','caretaker'=>'Caretaker']; $permNames = ['residents'=>'Residents','health_records'=>'Health records','rooms'=>'Rooms','schedules'=>'Schedules','messages'=>'Messages','reports'=>'Reports','settings'=>'Settings']; ?>
      <section class="panel"><h2>Permissions</h2><p class="description">This saves permission preferences only. Each PHP module must separately enforce them.</p>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_permissions">
        <div class="table-wrap"><table class="permissions-table"><thead><tr><th>Module</th><?php foreach ($roleNames as $role=>$label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($permNames as $perm=>$label): ?><tr><td><?= h($label) ?></td><?php foreach ($roleNames as $role=>$roleLabel): $default = $role === 'admin' || ($role === 'caretaker' && in_array($perm,['residents','health_records','schedules','messages'],true)); $checked = isset($permissions[$role][$perm]) ? (bool)$permissions[$role][$perm] : $default; ?><td><input type="checkbox" aria-label="<?= h($roleLabel . ' ' . $label) ?>" name="<?= h($role . '_' . $perm) ?>" <?= $checked ? 'checked' : '' ?>></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table></div><div class="actions"><button class="btn" type="submit">Save Permission Preferences</button></div>
      </form></section>

    <?php elseif ($section === 'password'): ?>
      <section class="panel"><h2>Change Password</h2><p class="description">Change the password for the account you are signed in with.</p>
      <?php if (!$usersTable): ?>
        <div class="notice error">The users table could not be detected, so the password cannot be changed from here. Send the users table structure and login.php.</div>
      <?php else: ?>
      <form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="change_password">
        <div class="form-grid">
          <div class="field full"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
          <div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" minlength="8" required autocomplete="new-password"><span class="help">At least 8 characters.</span></div>
          <div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" required autocomplete="new-password"></div>
        </div><div class="actions"><button class="btn" type="submit">Update Password</button></div>
      </form>
      <?php endif; ?>
      </section>

    <?php elseif ($section === 'login-history'): ?>
      <section class="panel"><h2>Login History</h2><p class="description">No login-history table is assumed. This list will be available after login code is configured to record sign-in events.</p><p class="help">The table below shows settings-page activity only, not successful logins.</p></section>
      <section class="panel"><h2>Recent Settings Activity</h2><?php if (!$activityRows): ?><p>No activity recorded yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>User ID</th><th>Action</th><th>Details</th><th>Date</th></tr></thead><tbody><?php foreach ($activityRows as $row): ?><tr><td><?= h($row['user_id']) ?></td><td><?= h($row['action']) ?></td><td><?= h($row['details']) ?></td><td><?= h($row['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

    <?php elseif ($section === 'activity'): ?>
      <section class="panel"><h2>Account Activity</h2><p class="description">Records of changes made through this Settings page.</p>
      <?php if (!$activityRows): ?><p>No activity recorded yet. Saving a setting will create an entry.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>User ID</th><th>Action</th><th>Details</th><th>Date</th></tr></thead><tbody><?php foreach ($activityRows as $row): ?><tr><td><?= h($row['user_id']) ?></td><td><?= h($row['action']) ?></td><td><?= h($row['details']) ?></td><td><?= h($row['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
  <p class="help">Signed in as <?= h($currentUser) ?> · Settings changes are saved to the database.</p>
</main>
</body>
</html>
