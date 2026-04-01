<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['editor', 'admin']);

$pageTitle = 'Merge History';

$entryId = isset($_GET['entry_id']) ? (int)$_GET['entry_id'] : 0;
$entry = null;
$logs = [];

if ($entryId <= 0) {
    http_response_code(400);
    $pageTitle = 'Invalid Request';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container page-section">
        <div class="detail-card">
            <h1>Invalid Request</h1>
            <p>A valid entry ID was not provided.</p>
            <p><a href="browse.php">Back to Browse</a></p>
        </div>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Fetch entry */
$entryStmt = $pdo->prepare("
    SELECT id, name, ethnic_group, region, gender, meaning, status
    FROM name_entries
    WHERE id = :id
    LIMIT 1
");
$entryStmt->execute([':id' => $entryId]);
$entry = $entryStmt->fetch();

if (!$entry) {
    http_response_code(404);
    $pageTitle = 'Entry Not Found';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container page-section">
        <div class="detail-card">
            <h1>Entry Not Found</h1>
            <p>The requested name entry does not exist.</p>
            <p><a href="browse.php">Back to Browse</a></p>
        </div>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Fetch merge logs */
$logStmt = $pdo->prepare("
    SELECT
        sml.id,
        sml.suggestion_id,
        sml.entry_id,
        sml.field_name,
        sml.target_table,
        sml.old_value,
        sml.new_value,
        sml.merge_status,
        sml.merged_by,
        sml.created_at,
        u.full_name AS editor_name
    FROM suggestion_merge_logs sml
    LEFT JOIN users u ON sml.merged_by = u.id
    WHERE sml.entry_id = :entry_id
    ORDER BY sml.created_at DESC, sml.id DESC
");
$logStmt->execute([':entry_id' => $entryId]);
$logs = $logStmt->fetchAll();

/* Group logs by suggestion */
$groupedLogs = [];
foreach ($logs as $log) {
    $suggestionKey = (string)$log['suggestion_id'];

    if (!isset($groupedLogs[$suggestionKey])) {
        $groupedLogs[$suggestionKey] = [
            'suggestion_id' => $log['suggestion_id'],
            'created_at' => $log['created_at'],
            'editor_name' => $log['editor_name'] ?? 'Unknown editor',
            'items' => [],
        ];
    }

    $groupedLogs[$suggestionKey]['items'][] = $log;
}

$pageTitle = 'Merge History - ' . $entry['name'];

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <div class="breadcrumb">
        <a href="index.php">Home</a> /
        <a href="name.php?id=<?= (int)$entry['id'] ?>"><?= htmlspecialchars($entry['name']) ?></a> /
        <span>Merge History</span>
    </div>

    <section class="detail-hero">
        <h1>Merge History</h1>
        <p class="detail-meaning">
            Editorial audit trail for <strong><?= htmlspecialchars($entry['name']) ?></strong>.
        </p>
    </section>

    <div class="detail-card">
        <h2>Entry Summary</h2>

        <div class="detail-grid">
            <div>
                <h3>Name</h3>
                <p><?= htmlspecialchars($entry['name']) ?></p>
            </div>

            <div>
                <h3>Ethnic Group</h3>
                <p><?= htmlspecialchars($entry['ethnic_group']) ?></p>
            </div>

            <div>
                <h3>Region</h3>
                <p><?= htmlspecialchars($entry['region'] ?: 'Not specified') ?></p>
            </div>

            <div>
                <h3>Gender</h3>
                <p><?= htmlspecialchars(ucfirst($entry['gender'])) ?></p>
            </div>

            <div>
                <h3>Current Meaning</h3>
                <p><?= htmlspecialchars($entry['meaning']) ?></p>
            </div>

            <div>
                <h3>Status</h3>
                <p><?= htmlspecialchars(ucfirst($entry['status'])) ?></p>
            </div>
        </div>

        <p>
            <a href="name.php?id=<?= (int)$entry['id'] ?>">View Public Page</a>
            |
            <a href="edit-profile.php?entry_id=<?= (int)$entry['id'] ?>">Open Profile Editor</a>
        </p>
    </div>

    <div class="detail-card">
        <h2>Audit Trail</h2>

        <?php if (empty($groupedLogs)): ?>
            <p>No merge history is available yet for this entry.</p>
        <?php else: ?>
            <div class="history-list">
                <?php foreach ($groupedLogs as $group): ?>
                    <div class="history-item">
                        <h3>Suggestion #<?= (int)$group['suggestion_id'] ?></h3>
                        <p><strong>Reviewed / Logged:</strong> <?= htmlspecialchars($group['created_at']) ?></p>
                        <p><strong>Editor:</strong> <?= htmlspecialchars($group['editor_name']) ?></p>

                        <div class="merge-history-table-wrapper">
                            <table class="merge-history-table">
                                <thead>
                                    <tr>
                                        <th>Field</th>
                                        <th>Target Table</th>
                                        <th>Status</th>
                                        <th>Old Value</th>
                                        <th>New Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['items'] as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['field_name']) ?></td>
                                            <td><?= htmlspecialchars($item['target_table']) ?></td>
                                            <td>
                                                <?php if ($item['merge_status'] === 'merged'): ?>
                                                    <span class="badge badge-approved">Merged</span>
                                                <?php else: ?>
                                                    <span class="badge badge-pending">Skipped</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= nl2br(htmlspecialchars($item['old_value'] ?? '—')) ?></td>
                                            <td><?= nl2br(htmlspecialchars($item['new_value'] ?? '—')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>