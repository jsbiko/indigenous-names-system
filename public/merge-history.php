<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['editor', 'admin']);

$pageTitle = 'Merge History';

$fieldLabels = [
    'meaning' => 'Meaning',
    'naming_context' => 'Naming Context',
    'cultural_explanation' => 'Cultural Explanation',
    'sources' => 'Sources',
    'overview' => 'Overview',
    'linguistic_origin' => 'Linguistic Origin',
    'cultural_significance' => 'Cultural Significance',
    'historical_context' => 'Historical Context',
    'variants' => 'Variants',
    'pronunciation' => 'Pronunciation',
    'related_names' => 'Related Names',
    'scholarly_notes' => 'Scholarly Notes',
    'references_text' => 'References',
];

$allowedFieldTargets = [
    'name_entries' => ['meaning', 'naming_context', 'cultural_explanation', 'sources'],
    'name_profiles' => [
        'overview',
        'linguistic_origin',
        'cultural_significance',
        'historical_context',
        'variants',
        'pronunciation',
        'related_names',
        'scholarly_notes',
        'references_text',
    ],
];

$successMessage = '';
$errorMessage = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logId = (int)($_POST['log_id'] ?? 0);
    $rollbackReason = trim($_POST['rollback_reason'] ?? '');

    if ($logId <= 0) {
        $errorMessage = 'Invalid rollback request.';
    } else {
        try {
            $pdo->beginTransaction();

            $logStmt = $pdo->prepare("
                SELECT *
                FROM suggestion_merge_logs
                WHERE id = :id
                LIMIT 1
            ");
            $logStmt->execute([':id' => $logId]);
            $log = $logStmt->fetch();

            if (!$log) {
                throw new RuntimeException('Merge log not found.');
            }

            if ((int)$log['entry_id'] !== $entryId) {
                throw new RuntimeException('Rollback entry mismatch.');
            }

            if ($log['merge_status'] !== 'merged') {
                throw new RuntimeException('Only merged fields can be rolled back.');
            }

            if (($log['action_type'] ?? 'merge') !== 'merge') {
                throw new RuntimeException('Only original merge records can be rolled back.');
            }

            $targetTable = $log['target_table'];
            $fieldName = $log['field_name'];

            if (!isset($allowedFieldTargets[$targetTable]) || !in_array($fieldName, $allowedFieldTargets[$targetTable], true)) {
                throw new RuntimeException('Rollback target is not allowed.');
            }

            if ($targetTable === 'name_entries') {
                $updateStmt = $pdo->prepare("
                    UPDATE name_entries
                    SET {$fieldName} = :old_value
                    WHERE id = :entry_id
                ");
                $updateStmt->execute([
                    ':old_value' => $log['old_value'],
                    ':entry_id' => $entryId,
                ]);
            } elseif ($targetTable === 'name_profiles') {
                $profileCheckStmt = $pdo->prepare("
                    SELECT id
                    FROM name_profiles
                    WHERE entry_id = :entry_id
                    LIMIT 1
                ");
                $profileCheckStmt->execute([':entry_id' => $entryId]);
                $profile = $profileCheckStmt->fetch();

                if (!$profile) {
                    $insertProfileStmt = $pdo->prepare("
                        INSERT INTO name_profiles (entry_id, last_edited_by)
                        VALUES (:entry_id, :editor)
                    ");
                    $insertProfileStmt->execute([
                        ':entry_id' => $entryId,
                        ':editor' => currentUser()['id'],
                    ]);
                }

                $updateStmt = $pdo->prepare("
                    UPDATE name_profiles
                    SET {$fieldName} = :old_value,
                        last_edited_by = :editor
                    WHERE entry_id = :entry_id
                ");
                $updateStmt->execute([
                    ':old_value' => $log['old_value'],
                    ':editor' => currentUser()['id'],
                    ':entry_id' => $entryId,
                ]);
            } else {
                throw new RuntimeException('Unsupported rollback target.');
            }

            $rollbackNewValue = $rollbackReason !== ''
                ? '[Rollback] ' . $rollbackReason
                : $log['old_value'];

            $insertAuditStmt = $pdo->prepare("
                INSERT INTO suggestion_merge_logs (
                    suggestion_id,
                    entry_id,
                    field_name,
                    target_table,
                    old_value,
                    new_value,
                    merge_status,
                    action_type,
                    merged_by
                ) VALUES (
                    :suggestion_id,
                    :entry_id,
                    :field_name,
                    :target_table,
                    :old_value,
                    :new_value,
                    :merge_status,
                    :action_type,
                    :merged_by
                )
            ");
            $insertAuditStmt->execute([
                ':suggestion_id' => $log['suggestion_id'],
                ':entry_id' => $entryId,
                ':field_name' => $fieldName,
                ':target_table' => $targetTable,
                ':old_value' => $log['new_value'],
                ':new_value' => $rollbackNewValue,
                ':merge_status' => 'merged',
                ':action_type' => 'rollback',
                ':merged_by' => currentUser()['id'],
            ]);

            $pdo->commit();
            $successMessage = 'Field rolled back successfully.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = 'Failed to roll back the field.';
        }
    }
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
        sml.action_type,
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
    } else {
        if ($log['created_at'] > $groupedLogs[$suggestionKey]['created_at']) {
            $groupedLogs[$suggestionKey]['created_at'] = $log['created_at'];
        }
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
        <p>
            <a href="review-suggestions.php">← Back to Review Dashboard</a>
        </p>
    </section>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

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
            <a href="edit-name-profile.php?entry_id=<?= (int)$entry['id'] ?>">Open Profile Editor</a>
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
                                        <th>Action</th>
                                        <th>Old Value</th>
                                        <th>New Value</th>
                                        <th>Rollback</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['items'] as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($fieldLabels[$item['field_name']] ?? $item['field_name']) ?></td>
                                            <td><?= htmlspecialchars($item['target_table']) ?></td>
                                            <td>
                                                <?php if ($item['merge_status'] === 'merged'): ?>
                                                    <span class="badge badge-approved">Merged</span>
                                                <?php else: ?>
                                                    <span class="badge badge-pending">Skipped</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars(ucfirst($item['action_type'] ?? 'merge')) ?></td>
                                            <td><?= nl2br(htmlspecialchars($item['old_value'] ?? '—')) ?></td>
                                            <td><?= nl2br(htmlspecialchars($item['new_value'] ?? '—')) ?></td>
                                            <td>
                                                <?php if (
                                                    $item['merge_status'] === 'merged'
                                                    && ($item['action_type'] ?? 'merge') === 'merge'
                                                ): ?>
                                                    <form method="post" action="merge-history.php?entry_id=<?= (int)$entry['id'] ?>">
                                                        <input type="hidden" name="log_id" value="<?= (int)$item['id'] ?>">
                                                        <input type="text" name="rollback_reason" placeholder="Optional reason">
                                                        <button type="submit">Rollback</button>
                                                    </form>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
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