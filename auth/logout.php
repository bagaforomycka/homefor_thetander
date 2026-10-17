
<?php
session_start();

// Clear all session data.
$_SESSION = [];

// Remove the session cookie if cookies are enabled.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// End the session.
session_destroy();

// Return to the login page.
header('Location: ../index.php');
exit;
