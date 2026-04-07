<?php
declare(strict_types=1);

ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized.'
    ]);
    exit;
}

function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);

    if (ob_get_length()) {
        ob_clean();
    }

    echo json_encode($data);
    exit;
}

function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute([':table_name' => $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            ':table_name'  => $tableName,
            ':column_name' => $columnName,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function detectColumn(PDO $pdo, string $tableName, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (columnExists($pdo, $tableName, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function scalarSafe(PDO $pdo, string $sql, array $params = [], int $default = 0): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value !== false ? (int)$value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

$allowedDays = [7, 30, 90];
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;

if (!in_array($days, $allowedDays, true)) {
    $days = 30;
}

try {
    $entriesTable = 'name_entries';
    $suggestionsTable = 'name_suggestions';

    if (!tableExists($pdo, $entriesTable)) {
        throw new RuntimeException("Table '{$entriesTable}' does not exist.");
    }

    if (!tableExists($pdo, $suggestionsTable)) {
        throw new RuntimeException("Table '{$suggestionsTable}' does not exist.");
    }

    if (!columnExists($pdo, $entriesTable, 'created_at')) {
        throw new RuntimeException("Column 'created_at' does not exist on '{$entriesTable}'.");
    }

    $suggestionsCreatedAtColumn = detectColumn($pdo, $suggestionsTable, [
        'created_at',
        'submitted_at',
        'suggested_at',
        'date_created'
    ]);

    if ($suggestionsCreatedAtColumn === null) {
        throw new RuntimeException(
            "Could not find a creation date column on '{$suggestionsTable}'."
        );
    }

    $suggestionsStatusColumn = detectColumn($pdo, $suggestionsTable, [
        'status',
        'review_status',
        'decision_status'
    ]);

    if ($suggestionsStatusColumn === null) {
        throw new RuntimeException(
            "Could not find a status column on '{$suggestionsTable}'."
        );
    }

    $approvalsDateColumn = detectColumn($pdo, $suggestionsTable, [
        'reviewed_at',
        'approved_at',
        'decision_at',
        'updated_at'
    ]);

    if ($approvalsDateColumn === null) {
        throw new RuntimeException(
            "Could not find an approval/review date column on '{$suggestionsTable}'."
        );
    }

    $today = new DateTimeImmutable('today');
    $startDate = $today->modify('-' . ($days - 1) . ' days');
    $startDateTime = $startDate->format('Y-m-d 00:00:00');
    $endDateTime = $today->format('Y-m-d 23:59:59');

    $labels = [];
    $submissionsMap = [];
    $suggestionsMap = [];
    $approvalsMap = [];

    $period = new DatePeriod(
        $startDate,
        new DateInterval('P1D'),
        $today->modify('+1 day')
    );

    foreach ($period as $date) {
        $key = $date->format('Y-m-d');
        $labels[] = $date->format('M j');
        $submissionsMap[$key] = 0;
        $suggestionsMap[$key] = 0;
        $approvalsMap[$key] = 0;
    }

    $stmt = $pdo->prepare("
        SELECT DATE(created_at) AS day, COUNT(*) AS total
        FROM {$entriesTable}
        WHERE created_at BETWEEN :start_date AND :end_date
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    $stmt->execute([
        ':start_date' => $startDateTime,
        ':end_date' => $endDateTime,
    ]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $day = (string)($row['day'] ?? '');
        if ($day !== '' && isset($submissionsMap[$day])) {
            $submissionsMap[$day] = (int)$row['total'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT DATE({$suggestionsCreatedAtColumn}) AS day, COUNT(*) AS total
        FROM {$suggestionsTable}
        WHERE {$suggestionsCreatedAtColumn} BETWEEN :start_date AND :end_date
        GROUP BY DATE({$suggestionsCreatedAtColumn})
        ORDER BY day ASC
    ");
    $stmt->execute([
        ':start_date' => $startDateTime,
        ':end_date' => $endDateTime,
    ]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $day = (string)($row['day'] ?? '');
        if ($day !== '' && isset($suggestionsMap[$day])) {
            $suggestionsMap[$day] = (int)$row['total'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT DATE({$approvalsDateColumn}) AS day, COUNT(*) AS total
        FROM {$suggestionsTable}
        WHERE {$suggestionsStatusColumn} = 'approved'
          AND {$approvalsDateColumn} IS NOT NULL
          AND {$approvalsDateColumn} BETWEEN :start_date AND :end_date
        GROUP BY DATE({$approvalsDateColumn})
        ORDER BY day ASC
    ");
    $stmt->execute([
        ':start_date' => $startDateTime,
        ':end_date' => $endDateTime,
    ]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $day = (string)($row['day'] ?? '');
        if ($day !== '' && isset($approvalsMap[$day])) {
            $approvalsMap[$day] = (int)$row['total'];
        }
    }

    $submissions = array_values($submissionsMap);
    $suggestions = array_values($suggestionsMap);
    $approvals = array_values($approvalsMap);

    // Status distribution from name_entries
    $statusPending = scalarSafe(
        $pdo,
        "SELECT COUNT(*) FROM {$entriesTable} WHERE status = 'pending'"
    );

    $statusApproved = scalarSafe(
        $pdo,
        "SELECT COUNT(*) FROM {$entriesTable} WHERE status = 'approved'"
    );

    $statusRejected = scalarSafe(
        $pdo,
        "SELECT COUNT(*) FROM {$entriesTable} WHERE status = 'rejected'"
    );

    jsonResponse([
        'success' => true,
        'days' => $days,
        'labels' => $labels,
        'datasets' => [
            'submissions' => $submissions,
            'suggestions' => $suggestions,
            'approvals' => $approvals,
        ],
        'totals' => [
            'submissions' => array_sum($submissions),
            'suggestions' => array_sum($suggestions),
            'approvals' => array_sum($approvals),
        ],
        'status_distribution' => [
            'labels' => ['Pending', 'Approved', 'Rejected'],
            'values' => [$statusPending, $statusApproved, $statusRejected],
            'totals' => [
                'pending' => $statusPending,
                'approved' => $statusApproved,
                'rejected' => $statusRejected,
            ]
        ]
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Analytics endpoint failed.',
        'debug' => $e->getMessage()
    ], 500);
}