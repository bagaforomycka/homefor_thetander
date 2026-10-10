
<?php
session_start();

require_once __DIR__ . '/../config/db.php';

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: residents.php');
    exit;
}


$token = $_POST['csrf_token'] ?? '';

if (
    !is_string($token) ||
    !isset($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $token)
) {
    http_response_code(403);
    exit('Invalid security token. Refresh the page and try again.');
}


$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';

if (
    !$id ||
    !in_array($action, ['soft_delete', 'hard_delete'], true)
) {
    header('Location: residents.php');
    exit;
}


if (
    $action === 'hard_delete' &&
    $_SESSION['role'] !== 'admin'
) {
    http_response_code(403);
    exit('Only administrators can permanently delete residents.');
}

try {
    if ($action === 'soft_delete') {

        $stmt = $pdo->prepare(
            "UPDATE residents
             SET deleted_at = NOW()
             WHERE id = :id
             AND deleted_at IS NULL"
        );

        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            header('Location: residents.php');
            exit;
        }

        header('Location: residents.php?deleted=soft');
        exit;
    }

    if ($action === 'hard_delete') {

        $stmt = $pdo->prepare(
            "DELETE FROM residents
             WHERE id = :id"
        );

        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            header('Location: residents.php');
            exit;
        }

        header('Location: residents.php?deleted=hard');
        exit;
    }

} catch (PDOException $e) {
    error_log('Resident deletion failed: ' . $e->getMessage());

    http_response_code(500);
    exit(
        'Unable to delete the resident. Check whether related records '
        . 'prevent deletion, then try again.'
    );
}
