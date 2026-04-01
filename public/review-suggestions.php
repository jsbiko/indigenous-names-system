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

$pageTitle = 'Suggestion Review Dashboard';
$successMessage = '';
$errorMessage = '';

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

            $stmt = $pdo->prepare("
                SELECT *
                FROM name_suggestions
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $suggestionId]);
            $suggestion = $stmt->fetch();

            if (!$suggestion) {
                throw new RuntimeException('Suggestion not found.');
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
                $entryFields = [];
                $entryParams = [
                    ':entry_id' => $suggestion['entry_id'],
                ];

                if (in_array('meaning', $mergeFields, true)) {
                    $entryFields[] = 'meaning = :meaning';
                    $entryParams[':meaning'] = $suggestion['proposed_meaning'];
                }

                if (in_array('naming_context', $mergeFields, true)) {
                    $entryFields[] = 'naming_context = :naming_context';
                    $entryParams[':naming_context'] = $suggestion['proposed_naming_context'];
                }

                if (in_array('cultural_explanation', $mergeFields, true)) {
                    $entryFields[] = 'cultural_explanation = :cultural_explanation';
                    $entryParams[':cultural_explanation'] = $suggestion['proposed_cultural_explanation'];
                }

                if (in_array('sources', $mergeFields, true)) {
                    $entryFields[] = 'sources = :sources';
                    $entryParams[':sources'] = $suggestion['proposed_sources'];
                }

                if (!empty($entryFields)) {
                    $updateEntryStmt = $pdo->prepare("
                        UPDATE name_entries
                        SET " . implode(', ', $entryFields) . "
                        WHERE id = :entry_id
                    ");
                    $updateEntryStmt->execute($entryParams);
                }

                $profileFieldKeys = [
                    'overview',
                    'linguistic_origin',
                    'cultural_significance',
                    'historical_context',
                    'variants',
                    'pronunciation',
                    'related_names',
                    'scholarly_notes',
                    'references_text',
                ];

                $selectedProfileFields = array_values(array_intersect($profileFieldKeys, $mergeFields));

                if (!empty($selectedProfileFields)) {
                    $profileCheckStmt = $pdo->prepare("
                        SELECT id
                        FROM name_profiles
                        WHERE entry_id = :entry_id
                        LIMIT 1
                    ");
                    $profileCheckStmt->execute([':entry_id' => $suggestion['entry_id']]);
                    $profile = $profileCheckStmt->fetch();

                    if (!$profile) {
                        $insertProfileStmt = $pdo->prepare("
                            INSERT INTO name_profiles (entry_id, last_edited_by)
                            VALUES (:entry_id, :editor)
                        ");
                        $insertProfileStmt->execute([
                            ':entry_id' => $suggestion['entry_id'],
                            ':editor' => currentUser()['id'],
                        ]);
                    }

                    $profileMap = [
                        'overview' => 'proposed_overview',
                        'linguistic_origin' => 'proposed_linguistic_origin',
                        'cultural_significance' => 'proposed_cultural_significance',
                        'historical_context' => 'proposed_historical_context',
                        'variants' => 'proposed_variants',
                        'pronunciation' => 'proposed_pronunciation',
                        'related_names' => 'proposed_related_names',
                        'scholarly_notes' => 'proposed_scholarly_notes',
                        'references_text' => 'proposed_references_text',
                    ];

                    $profileFields = [];
                    $profileParams = [
                        ':entry_id' => $suggestion['entry_id'],
                        ':editor' => currentUser()['id'],
                    ];

                    foreach ($selectedProfileFields as $field) {
                        $profileFields[] = $field . ' = :' . $field;
                        $profileParams[':' . $field] = $suggestion[$profileMap[$field]];
                    }

                    $profileFields[] = 'last_edited_by = :editor';

                    $updateProfileStmt = $pdo->prepare("
                        UPDATE name_profiles
                        SET " . implode(', ', $profileFields) . "
                        WHERE entry_id = :entry_id
                    ");
                    $updateProfileStmt->execute($profileParams);
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
            u.full_name AS suggested_by_name
        FROM name_suggestions ns
        INNER JOIN name_entries ne ON ns.entry_id = ne.id
        LEFT JOIN users u ON ns.suggested_by = u.id
        WHERE ns.id = :id
        LIMIT 1
    ");
    $detailStmt->execute([':id' => $selectedId]);
    $selectedSuggestion = $detailStmt->fetch();
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

                    <?php
                    renderDiffBlock('Meaning', $selectedSuggestion['meaning'], $selectedSuggestion['proposed_meaning']);
                    renderDiffBlock('Naming Context', $selectedSuggestion['naming_context'], $selectedSuggestion['proposed_naming_context']);
                    renderDiffBlock('Cultural Explanation', $selectedSuggestion['cultural_explanation'], $selectedSuggestion['proposed_cultural_explanation']);
                    renderDiffBlock('Sources', $selectedSuggestion['sources'], $selectedSuggestion['proposed_sources']);
                    renderDiffBlock('Overview', null, $selectedSuggestion['proposed_overview']);
                    renderDiffBlock('Linguistic Origin', null, $selectedSuggestion['proposed_linguistic_origin']);
                    renderDiffBlock('Cultural Significance', null, $selectedSuggestion['proposed_cultural_significance']);
                    renderDiffBlock('Historical Context', null, $selectedSuggestion['proposed_historical_context']);
                    renderDiffBlock('Variants', null, $selectedSuggestion['proposed_variants']);
                    renderDiffBlock('Pronunciation', null, $selectedSuggestion['proposed_pronunciation']);
                    renderDiffBlock('Related Names', null, $selectedSuggestion['proposed_related_names']);
                    renderDiffBlock('Scholarly Notes', null, $selectedSuggestion['proposed_scholarly_notes']);
                    renderDiffBlock('References', null, $selectedSuggestion['proposed_references_text']);
                    ?>
                </div>

                <div class="detail-card">
                    <h2>Current Published Content</h2>

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

                    <p>
                        <a href="name.php?id=<?= (int)$selectedSuggestion['entry_id'] ?>">View Public Page</a>
                        |
                        <a href="edit-profile.php?entry_id=<?= (int)$selectedSuggestion['entry_id'] ?>">Open Profile Editor</a>
                    </p>
                </div>

                <div class="detail-card">
                    <h2>Editorial Decision</h2>

                    <form method="post" action="review-suggestions.php?id=<?= (int)$selectedSuggestion['id'] ?>">
                        <input type="hidden" name="suggestion_id" value="<?= (int)$selectedSuggestion['id'] ?>">

                        <div class="form-group">
                            <label>Merge Selected Fields</label>
                            <div class="merge-options">
                                <?php if (!empty($selectedSuggestion['proposed_meaning']) && trim((string)$selectedSuggestion['proposed_meaning']) !== trim((string)$selectedSuggestion['meaning'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="meaning"> Meaning</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_naming_context']) && trim((string)$selectedSuggestion['proposed_naming_context']) !== trim((string)$selectedSuggestion['naming_context'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="naming_context"> Naming Context</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_cultural_explanation']) && trim((string)$selectedSuggestion['proposed_cultural_explanation']) !== trim((string)$selectedSuggestion['cultural_explanation'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="cultural_explanation"> Cultural Explanation</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_sources']) && trim((string)$selectedSuggestion['proposed_sources']) !== trim((string)$selectedSuggestion['sources'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="sources"> Sources</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_overview'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="overview"> Overview</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_linguistic_origin'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="linguistic_origin"> Linguistic Origin</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_cultural_significance'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="cultural_significance"> Cultural Significance</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_historical_context'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="historical_context"> Historical Context</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_variants'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="variants"> Variants</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_pronunciation'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="pronunciation"> Pronunciation</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_related_names'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="related_names"> Related Names</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_scholarly_notes'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="scholarly_notes"> Scholarly Notes</label><br>
                                <?php endif; ?>

                                <?php if (!empty($selectedSuggestion['proposed_references_text'])): ?>
                                    <label><input type="checkbox" name="merge_fields[]" value="references_text"> References</label><br>
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
                            <button type="submit" name="action" value="approve_merge" class="btn-approve">Approve & Merge</button>
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