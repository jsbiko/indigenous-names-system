<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['editor', 'admin']);

function renderDiffBlock(string $label, ?string $current, ?string $proposed): void
{
    $current = trim((string)$current);
    $proposed = trim((string)$proposed);

    if ($proposed === '' || $proposed === $current) {
        return;
    }

    echo '<div class="diff-block">';
    echo '<h3>' . htmlspecialchars($label) . '</h3>';
    echo '<div class="diff-grid">';

    echo '<div class="diff-old">';
    echo '<strong>Current</strong>';
    echo '<p>' . nl2br(htmlspecialchars($current !== '' ? $current : '—')) . '</p>';
    echo '</div>';

    echo '<div class="diff-new">';
    echo '<strong>Proposed</strong>';
    echo '<p>' . nl2br(htmlspecialchars($proposed)) . '</p>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
}

function hasChanged(?string $current, ?string $proposed): bool
{
    $current = trim((string)$current);
    $proposed = trim((string)$proposed);

    return $proposed !== '' && $proposed !== $current;
}

function hasContent(?string $value): bool
{
    return trim((string)$value) !== '';
}

function getMergeableFields(array $data, array $map): array
{
    $result = [];

    foreach ($map as $fieldName => $config) {
        $current = $data[$config['current_key']] ?? null;
        $proposed = $data[$config['proposed_key']] ?? null;

        if (hasChanged($current, $proposed)) {
            $result[$fieldName] = $config;
        }
    }

    return $result;
}

$pageTitle = 'Suggestion Review Dashboard';
$successMessage = '';
$errorMessage = '';

$entryFieldMap = [
    'meaning' => [
        'label' => 'Meaning',
        'target_table' => 'name_entries',
        'current_key' => 'meaning',
        'proposed_key' => 'proposed_meaning',
    ],
    'naming_context' => [
        'label' => 'Naming Context',
        'target_table' => 'name_entries',
        'current_key' => 'naming_context',
        'proposed_key' => 'proposed_naming_context',
    ],
    'cultural_explanation' => [
        'label' => 'Cultural Explanation',
        'target_table' => 'name_entries',
        'current_key' => 'cultural_explanation',
        'proposed_key' => 'proposed_cultural_explanation',
    ],
    'sources' => [
        'label' => 'Sources',
        'target_table' => 'name_entries',
        'current_key' => 'sources',
        'proposed_key' => 'proposed_sources',
    ],
];

$profileFieldMap = [
    'overview' => [
        'label' => 'Overview',
        'target_table' => 'name_profiles',
        'current_key' => 'overview',
        'proposed_key' => 'proposed_overview',
    ],
    'linguistic_origin' => [
        'label' => 'Linguistic Origin',
        'target_table' => 'name_profiles',
        'current_key' => 'linguistic_origin',
        'proposed_key' => 'proposed_linguistic_origin',
    ],
    'cultural_significance' => [
        'label' => 'Cultural Significance',
        'target_table' => 'name_profiles',
        'current_key' => 'cultural_significance',
        'proposed_key' => 'proposed_cultural_significance',
    ],
    'historical_context' => [
        'label' => 'Historical Context',
        'target_table' => 'name_profiles',
        'current_key' => 'historical_context',
        'proposed_key' => 'proposed_historical_context',
    ],
    'variants' => [
        'label' => 'Variants',
        'target_table' => 'name_profiles',
        'current_key' => 'variants',
        'proposed_key' => 'proposed_variants',
    ],
    'pronunciation' => [
        'label' => 'Pronunciation',
        'target_table' => 'name_profiles',
        'current_key' => 'pronunciation',
        'proposed_key' => 'proposed_pronunciation',
    ],
    'related_names' => [
        'label' => 'Related Names',
        'target_table' => 'name_profiles',
        'current_key' => 'related_names',
        'proposed_key' => 'proposed_related_names',
    ],
    'scholarly_notes' => [
        'label' => 'Scholarly Notes',
        'target_table' => 'name_profiles',
        'current_key' => 'scholarly_notes',
        'proposed_key' => 'proposed_scholarly_notes',
    ],
    'references_text' => [
        'label' => 'References',
        'target_table' => 'name_profiles',
        'current_key' => 'references_text',
        'proposed_key' => 'proposed_references_text',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $suggestionId = (int)($_POST['suggestion_id'] ?? 0);
    $action = trim($_POST['action'] ?? '');
    $reviewNotes = trim($_POST['review_notes'] ?? '');
    $mergeFields = $_POST['merge_fields'] ?? [];

    if (!is_array($mergeFields)) {
        $mergeFields = [];
    }

    $validActions = ['approve', 'approve_merge', 'reject'];

    if ($suggestionId <= 0 || !in_array($action, $validActions, true)) {
        $errorMessage = 'Invalid suggestion review request.';
    } elseif ($action === 'approve_merge' && empty($mergeFields)) {
        $errorMessage = 'Please select at least one field to merge.';
    } else {
        try {
            $pdo->beginTransaction();

            $suggestionStmt = $pdo->prepare("
                SELECT *
                FROM name_suggestions
                WHERE id = :id
                LIMIT 1
            ");
            $suggestionStmt->execute([':id' => $suggestionId]);
            $suggestion = $suggestionStmt->fetch();

            if (!$suggestion) {
                throw new RuntimeException('Suggestion not found.');
            }

            $currentEntryStmt = $pdo->prepare("
                SELECT id, meaning, naming_context, cultural_explanation, sources
                FROM name_entries
                WHERE id = :entry_id
                LIMIT 1
            ");
            $currentEntryStmt->execute([':entry_id' => $suggestion['entry_id']]);
            $currentEntry = $currentEntryStmt->fetch();

            if (!$currentEntry) {
                throw new RuntimeException('Related name entry not found.');
            }

            $profileCheckStmt = $pdo->prepare("
                SELECT id, overview, linguistic_origin, cultural_significance, historical_context,
                       variants, pronunciation, related_names, scholarly_notes, references_text
                FROM name_profiles
                WHERE entry_id = :entry_id
                LIMIT 1
            ");
            $profileCheckStmt->execute([':entry_id' => $suggestion['entry_id']]);
            $currentProfile = $profileCheckStmt->fetch();

            if (!$currentProfile) {
                $currentProfile = [
                    'id' => null,
                    'overview' => null,
                    'linguistic_origin' => null,
                    'cultural_significance' => null,
                    'historical_context' => null,
                    'variants' => null,
                    'pronunciation' => null,
                    'related_names' => null,
                    'scholarly_notes' => null,
                    'references_text' => null,
                ];
            }

            $newStatus = ($action === 'reject') ? 'rejected' : 'approved';

            $updateSuggestionStmt = $pdo->prepare("
                UPDATE name_suggestions
                SET status = :status,
                    reviewed_by = :reviewed_by,
                    reviewed_at = NOW(),
                    review_notes = :review_notes
                WHERE id = :id
            ");
            $updateSuggestionStmt->execute([
                ':status' => $newStatus,
                ':reviewed_by' => currentUser()['id'],
                ':review_notes' => $reviewNotes !== '' ? $reviewNotes : null,
                ':id' => $suggestionId,
            ]);

            if ($action === 'approve_merge') {
                $logStmt = $pdo->prepare("
                    INSERT INTO suggestion_merge_logs (
                        suggestion_id,
                        entry_id,
                        field_name,
                        target_table,
                        old_value,
                        new_value,
                        merge_status,
                        merged_by
                    ) VALUES (
                        :suggestion_id,
                        :entry_id,
                        :field_name,
                        :target_table,
                        :old_value,
                        :new_value,
                        :merge_status,
                        :merged_by
                    )
                ");

                $entryUpdateParts = [];
                $entryUpdateParams = [
                    ':entry_id' => $suggestion['entry_id'],
                ];

                foreach ($entryFieldMap as $fieldName => $config) {
                    $oldValue = $currentEntry[$config['current_key']] ?? null;
                    $newValue = $suggestion[$config['proposed_key']] ?? null;
                    $wasSelected = in_array($fieldName, $mergeFields, true);

                    if (!hasContent($newValue)) {
                        continue;
                    }

                    if ($wasSelected && hasChanged($oldValue, $newValue)) {
                        $entryUpdateParts[] = $fieldName . ' = :' . $fieldName;
                        $entryUpdateParams[':' . $fieldName] = $newValue;
                    }

                    $logStmt->execute([
                        ':suggestion_id' => $suggestion['id'],
                        ':entry_id' => $suggestion['entry_id'],
                        ':field_name' => $fieldName,
                        ':target_table' => $config['target_table'],
                        ':old_value' => $oldValue,
                        ':new_value' => $newValue,
                        ':merge_status' => ($wasSelected && hasChanged($oldValue, $newValue)) ? 'merged' : 'skipped',
                        ':merged_by' => currentUser()['id'],
                    ]);
                }

                if (!empty($entryUpdateParts)) {
                    $updateEntryStmt = $pdo->prepare("
                        UPDATE name_entries
                        SET " . implode(', ', $entryUpdateParts) . "
                        WHERE id = :entry_id
                    ");
                    $updateEntryStmt->execute($entryUpdateParams);
                }

                $profileUpdateParts = [];
                $profileUpdateParams = [
                    ':entry_id' => $suggestion['entry_id'],
                    ':editor' => currentUser()['id'],
                ];
                $needsProfileRow = false;

                foreach ($profileFieldMap as $fieldName => $config) {
                    $oldValue = $currentProfile[$config['current_key']] ?? null;
                    $newValue = $suggestion[$config['proposed_key']] ?? null;
                    $wasSelected = in_array($fieldName, $mergeFields, true);

                    if (!hasContent($newValue)) {
                        continue;
                    }

                    if ($wasSelected && hasChanged($oldValue, $newValue)) {
                        $needsProfileRow = true;
                        $profileUpdateParts[] = $fieldName . ' = :' . $fieldName;
                        $profileUpdateParams[':' . $fieldName] = $newValue;
                        }

                    $logStmt->execute([
                        ':suggestion_id' => $suggestion['id'],
                        ':entry_id' => $suggestion['entry_id'],
                        ':field_name' => $fieldName,
                        ':target_table' => $config['target_table'],
                        ':old_value' => $oldValue,
                        ':new_value' => $newValue,
                        ':merge_status' => ($wasSelected && hasChanged($oldValue, $newValue)) ? 'merged' : 'skipped',
                        ':merged_by' => currentUser()['id'],
                    ]);
                }

                if ($needsProfileRow && !$currentProfile['id']) {
                    $insertProfileStmt = $pdo->prepare("
                        INSERT INTO name_profiles (entry_id, last_edited_by)
                        VALUES (:entry_id, :editor)
                    ");
                    $insertProfileStmt->execute([
                        ':entry_id' => $suggestion['entry_id'],
                        ':editor' => currentUser()['id'],
                    ]);
                }

                if (!empty($profileUpdateParts)) {
                    $profileUpdateParts[] = 'last_edited_by = :editor';

                    $updateProfileStmt = $pdo->prepare("
                        UPDATE name_profiles
                        SET " . implode(', ', $profileUpdateParts) . "
                        WHERE entry_id = :entry_id
                    ");
                    $updateProfileStmt->execute($profileUpdateParams);
                }
            }

            $pdo->commit();

            $successMessage = match ($action) {
                'approve_merge' => 'Suggestion approved and merged successfully.',
                'approve' => 'Suggestion approved successfully.',
                'reject' => 'Suggestion rejected successfully.',
                default => 'Suggestion processed successfully.',
            };
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = 'Failed to process suggestion review.';
        }
    }
}

$pendingStmt = $pdo->query("
    SELECT
        ns.id,
        ns.entry_id,
        ns.suggestion_type,
        ns.created_at,
        ne.name,
        ne.ethnic_group,
        u.full_name AS suggested_by_name
    FROM name_suggestions ns
    INNER JOIN name_entries ne ON ns.entry_id = ne.id
    LEFT JOIN users u ON ns.suggested_by = u.id
    WHERE ns.status = 'pending'
    ORDER BY ns.created_at ASC
");
$pendingSuggestions = $pendingStmt->fetchAll();

$selectedId = isset($_GET['id']) ? (int)($_GET['id'] ?? 0) : 0;

if ($selectedId <= 0 && !empty($pendingSuggestions)) {
    $selectedId = (int)$pendingSuggestions[0]['id'];
}

$selectedSuggestion = null;
$mergeableEntryFields = [];
$mergeableProfileFields = [];

if ($selectedId > 0) {
    $detailStmt = $pdo->prepare("
        SELECT
            ns.id,
            ns.entry_id,
            ns.suggestion_type,
            ns.proposed_meaning,
            ns.proposed_naming_context,
            ns.proposed_cultural_explanation,
            ns.proposed_sources,
            ns.proposed_overview,
            ns.proposed_linguistic_origin,
            ns.proposed_cultural_significance,
            ns.proposed_historical_context,
            ns.proposed_variants,
            ns.proposed_pronunciation,
            ns.proposed_related_names,
            ns.proposed_scholarly_notes,
            ns.proposed_references_text,
            ns.contributor_notes,
            ns.status,
            ns.created_at,
            ne.name,
            ne.meaning,
            ne.ethnic_group,
            ne.region,
            ne.gender,
            ne.naming_context,
            ne.cultural_explanation,
            ne.sources,
            np.overview,
            np.linguistic_origin,
            np.cultural_significance,
            np.historical_context,
            np.variants,
            np.pronunciation,
            np.related_names,
            np.scholarly_notes,
            np.references_text,
            u.full_name AS suggested_by_name
        FROM name_suggestions ns
        INNER JOIN name_entries ne ON ns.entry_id = ne.id
        LEFT JOIN name_profiles np ON np.entry_id = ne.id
        LEFT JOIN users u ON ns.suggested_by = u.id
        WHERE ns.id = :id
        LIMIT 1
    ");
    $detailStmt->execute([':id' => $selectedId]);
    $selectedSuggestion = $detailStmt->fetch();

    if ($selectedSuggestion) {
        $mergeableEntryFields = getMergeableFields($selectedSuggestion, $entryFieldMap);
        $mergeableProfileFields = getMergeableFields($selectedSuggestion, $profileFieldMap);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <section class="detail-hero">
        <h1>Suggestion Review Dashboard</h1>
        <p class="detail-meaning">Review community improvements before applying them to authority content.</p>
    </section>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <div class="review-layout">
        <aside class="review-sidebar detail-card">
            <h2>Pending Suggestions</h2>

            <?php if (!empty($pendingSuggestions)): ?>
                <div class="pending-list">
                    <?php foreach ($pendingSuggestions as $item): ?>
                        <a
                            class="pending-item <?= ((int)$item['id'] === $selectedId) ? 'pending-item-active' : '' ?>"
                            href="review-suggestions.php?id=<?= (int)$item['id'] ?>"
                        >
                            <strong><?= htmlspecialchars($item['name']) ?></strong><br>
                            <span><?= htmlspecialchars($item['ethnic_group']) ?></span><br>
                            <small>Type: <?= htmlspecialchars($item['suggestion_type']) ?></small><br>
                            <small>By: <?= htmlspecialchars($item['suggested_by_name'] ?? 'Unknown contributor') ?></small><br>
                            <small><?= htmlspecialchars($item['created_at']) ?></small><br>
                            <span class="badge badge-pending">Pending</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>No pending suggestions.</p>
            <?php endif; ?>
        </aside>

        <section class="review-main">
            <?php if ($selectedSuggestion): ?>
                <div class="detail-card">
                    <h2><?= htmlspecialchars($selectedSuggestion['name']) ?></h2>

                    <div class="detail-grid">
                        <div>
                            <h3>Ethnic Group</h3>
                            <p><?= htmlspecialchars($selectedSuggestion['ethnic_group']) ?></p>
                        </div>

                        <div>
                            <h3>Region</h3>
                            <p><?= htmlspecialchars($selectedSuggestion['region'] ?: 'Not specified') ?></p>
                        </div>

                        <div>
                            <h3>Gender</h3>
                            <p><?= htmlspecialchars(ucfirst($selectedSuggestion['gender'])) ?></p>
                        </div>

                        <div>
                            <h3>Current Meaning</h3>
                            <p><?= htmlspecialchars($selectedSuggestion['meaning']) ?></p>
                        </div>

                        <div>
                            <h3>Current Naming Context</h3>
                            <p><?= htmlspecialchars($selectedSuggestion['naming_context'] ?: 'Not specified') ?></p>
                        </div>

                        <div>
                            <h3>Suggested By</h3>
                            <p><?= htmlspecialchars($selectedSuggestion['suggested_by_name'] ?? 'Unknown contributor') ?></p>
                        </div>
                    </div>
                </div>

                <div class="detail-card">
                    <h2>Change Review (Diff View)</h2>

                    <?php foreach ($mergeableEntryFields as $config): ?>
                        <?php renderDiffBlock(
                            $config['label'],
                            $selectedSuggestion[$config['current_key']] ?? null,
                            $selectedSuggestion[$config['proposed_key']] ?? null
                        ); ?>
                    <?php endforeach; ?>

                    <?php foreach ($mergeableProfileFields as $config): ?>
                        <?php renderDiffBlock(
                            $config['label'],
                            $selectedSuggestion[$config['current_key']] ?? null,
                            $selectedSuggestion[$config['proposed_key']] ?? null
                        ); ?>
                    <?php endforeach; ?>

                    <?php if (empty($mergeableEntryFields) && empty($mergeableProfileFields)): ?>
                        <p><strong>No mergeable field changes detected.</strong></p>
                    <?php endif; ?>

                    <?php if (hasContent($selectedSuggestion['contributor_notes'] ?? null)): ?>
                        <div class="review-block">
                            <h3>Contributor Notes</h3>
                            <p><?= nl2br(htmlspecialchars($selectedSuggestion['contributor_notes'])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="detail-card">
                    <h2>Current Published Content</h2>
                    <p>
                        <a href="name.php?id=<?= (int)$selectedSuggestion['entry_id'] ?>">View Public Page</a>
                        |
                        <a href="edit-profile.php?entry_id=<?= (int)$selectedSuggestion['entry_id'] ?>">Open Profile Editor</a>
                        |
                        <a href="merge-history.php?entry_id=<?= (int)$selectedSuggestion['entry_id'] ?>">View Merge History</a>
                    </p>

                    <div class="review-block">
                        <h3>Current Cultural Explanation</h3>
                        <p>
                            <?= $selectedSuggestion['cultural_explanation']
                                ? nl2br(htmlspecialchars($selectedSuggestion['cultural_explanation']))
                                : 'No current cultural explanation available.' ?>
                        </p>
                    </div>

                    <div class="review-block">
                        <h3>Current Sources</h3>
                        <p>
                            <?= $selectedSuggestion['sources']
                                ? nl2br(htmlspecialchars($selectedSuggestion['sources']))
                                : 'No current sources available.' ?>
                        </p>
                    </div>

                </div>

                <div class="detail-card">
                    <h2>Editorial Decision</h2>

                    <form method="post" action="review-suggestions.php?id=<?= (int)$selectedSuggestion['id'] ?>">
                        <input type="hidden" name="suggestion_id" value="<?= (int)$selectedSuggestion['id'] ?>">

                        <div class="form-group">
                            <label>Merge Selected Fields</label>
                            <div class="merge-options">

                                <?php foreach ($mergeableEntryFields as $fieldName => $config): ?>
                                    <label>
                                        <input type="checkbox" name="merge_fields[]" value="<?= htmlspecialchars($fieldName) ?>">
                                        <?= htmlspecialchars($config['label']) ?>
                                    </label><br>
                                <?php endforeach; ?>

                                <?php foreach ($mergeableProfileFields as $fieldName => $config): ?>
                                    <label>
                                        <input type="checkbox" name="merge_fields[]" value="<?= htmlspecialchars($fieldName) ?>">
                                        <?= htmlspecialchars($config['label']) ?>
                                    </label><br>
                                <?php endforeach; ?>

                                <?php if (empty($mergeableEntryFields) && empty($mergeableProfileFields)): ?>
                                    <p>No mergeable fields available.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="review_notes">Review Notes</label>
                            <textarea
                                id="review_notes"
                                name="review_notes"
                                rows="5"
                                placeholder="Add editorial notes, acceptance rationale, or rejection reason..."
                            ></textarea>
                        </div>

                        <div class="review-actions">
                            <button type="submit" name="action" value="approve" class="btn-approve">Approve Only</button>
                            <button
                                type="submit"
                                name="action"
                                value="approve_merge"
                                class="btn-approve"
                                <?= (empty($mergeableEntryFields) && empty($mergeableProfileFields)) ? 'disabled' : '' ?>
                            >
                                Approve & Merge
                            </button>
                            <button type="submit" name="action" value="reject" class="btn-reject">Reject</button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="detail-card">
                    <h2>No Suggestion Selected</h2>
                    <p>There are currently no pending suggestions to review.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>