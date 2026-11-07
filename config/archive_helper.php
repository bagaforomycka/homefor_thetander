<?php
/**
 * Shared helpers for the archive / soft delete / hard delete flow.
 *
 * Residents
 *   soft delete (admin)      residents.status = 'archived' (+ archived_at)
 *   soft delete (caretaker)  row in caretaker_resident_archive
 *   hard delete (admin)      admin/archive.php, archived residents only
 *   hard delete (caretaker)  caretaker_resident_archive.removed_at is set
 *                            (permanently gone from that caretaker's account)
 */

/**
 * Creates the caretaker's private archive table and adds the removed_at
 * column to older copies of the table.
 */
function archiveEnsureCaretakerTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS caretaker_resident_archive (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            caretaker_user_id INT NOT NULL,
            resident_id INT NOT NULL,
            archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            removed_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_caretaker_resident (caretaker_user_id, resident_id),
            INDEX idx_caretaker_archive (caretaker_user_id, archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM caretaker_resident_archive")
        ->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('removed_at', $columns, true)) {
        $pdo->exec(
            "ALTER TABLE caretaker_resident_archive
             ADD COLUMN removed_at DATETIME NULL DEFAULT NULL"
        );
    }
}

/**
 * SQL condition (no leading AND) that is true for residents an admin has
 * NOT archived or deleted. $alias is the residents table alias, or '' for
 * none.
 */
function archiveResidentVisibleSql(PDO $pdo, string $alias = 'r'): string
{
    $p = $alias !== '' ? $alias . '.' : '';

    $columns = $pdo->query("SHOW COLUMNS FROM residents")
        ->fetchAll(PDO::FETCH_COLUMN);

    $sql = "({$p}status IS NULL OR LOWER({$p}status) <> 'archived')";

    if (in_array('archived_at', $columns, true)) {
        $sql .= " AND {$p}archived_at IS NULL";
    }

    if (in_array('deleted_at', $columns, true)) {
        $sql .= " AND {$p}deleted_at IS NULL";
    }

    return '(' . $sql . ')';
}
