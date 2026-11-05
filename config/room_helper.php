<?php
declare(strict_types=1);

/**
 * Connects residents to the Rooms module.
 *
 * - A room can be picked for a new resident only if it is marked
 *   "Available" in Room Management and has nobody living in it.
 * - As soon as a resident is assigned, the room switches to "Occupied".
 *   When its resident is archived or deleted it goes back to "Available".
 *   Rooms in "Maintenance" are never changed automatically.
 *
 * A resident counts as living in a room when they are not discharged,
 * archived or deleted.
 */

function roomHelperColumns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (!isset($cache[$table])) {
        $cache[$table] = $pdo->query("SHOW COLUMNS FROM `$table`")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    return $cache[$table];
}

/**
 * SQL condition for "this resident currently occupies their room".
 */
function activeResidentCondition(PDO $pdo, string $alias = 'res'): string
{
    $columns = roomHelperColumns($pdo, 'residents');

    $condition = "($alias.status IS NULL "
        . "OR LOWER($alias.status) NOT IN ('discharged', 'archived'))";

    if (in_array('deleted_at', $columns, true)) {
        $condition .= " AND $alias.deleted_at IS NULL";
    }

    if (in_array('archived_at', $columns, true)) {
        $condition .= " AND $alias.archived_at IS NULL";
    }

    return $condition;
}

function countRoomOccupants(PDO $pdo, string $roomNumber): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM residents res
         WHERE res.room_number = ? AND ' . activeResidentCondition($pdo, 'res')
    );
    $stmt->execute([$roomNumber]);

    return (int) $stmt->fetchColumn();
}

/**
 * Rooms a new resident can be placed in: marked Available and empty.
 * Each row: room_number, room_type.
 */
function fetchAvailableRooms(PDO $pdo): array
{
    $archivedFilter = in_array('archived_at', roomHelperColumns($pdo, 'rooms'), true)
        ? ' AND r.archived_at IS NULL'
        : '';

    $sql = "SELECT r.room_number, r.room_type
            FROM rooms r
            WHERE r.status = 'Available'" . $archivedFilter . "
              AND NOT EXISTS (
                  SELECT 1 FROM residents res
                  WHERE res.room_number = r.room_number
                    AND " . activeResidentCondition($pdo, 'res') . "
              )
            ORDER BY LENGTH(r.room_number), r.room_number";

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Sets a room to Occupied when a resident lives in it and to Available when
 * it is empty. Leaves Maintenance rooms alone.
 */
function syncRoomStatus(PDO $pdo, string $roomNumber): void
{
    if ($roomNumber === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT id, status FROM rooms WHERE room_number = ? LIMIT 1'
    );
    $stmt->execute([$roomNumber]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room || $room['status'] === 'Maintenance') {
        return;
    }

    $newStatus = countRoomOccupants($pdo, $roomNumber) > 0
        ? 'Occupied'
        : 'Available';

    if ($newStatus !== $room['status']) {
        $update = $pdo->prepare('UPDATE rooms SET status = ? WHERE id = ?');
        $update->execute([$newStatus, (int) $room['id']]);
    }
}
