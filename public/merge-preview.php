<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['editor', 'admin']);

$pageTitle = 'Merge Preview';
$currentUser = currentUser();
$currentUserId = (int) ($currentUser['id'] ?? 0);

function e(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function nl2safe(?string $value): string
{
    return nl2br(e(trim((string) $value)));
}

function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function formatDateTime(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }

    try {
        return (new DateTime($datetime))->format('M j, Y \a\t g:i A');
    } catch (Throwable $e) {
        return (string) $datetime;
    }
}

function normalizeMergeText(string $existing, string $incoming, string $mode): string
{
    $existing = trim($existing);
    $incoming = trim($incoming);

    return match ($mode) {
        'replace' => $incoming,
        'prepend' => trim($incoming . ($existing !== '' ? "\n\n" . $existing : '')),
        default => trim($existing !== '' ? ($existing . "\n\n" . $incoming) : $incoming),
    };
}

function highlightDiff(string $old, string $new): string
{
    $oldWords = preg_split('/\s+/', trim($old)) ?: [];
    $newWords = preg_split('/\s+/', trim($new)) ?: [];

    $diff = [];

    foreach ($newWords as $word) {
        if ($word === '') {
            continue;
        }

        if (!in_array($word, $oldWords, true)) {
            $diff[] = '<span class="diff-added">' . e($word) . '</span>';
        } else {
            $diff[] = e($word);
        }
    }

    return implode(' ', $diff);
}

function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
        ");
        $stmt->execute([':table_name' => $tableName]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND column_name = :column_name
        ");
        $stmt->execute([
            ':table_name' => $tableName,
            ':column_name' => $columnName,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

if (!tableExists($pdo, 'name_suggestions') || !tableExists($pdo, 'name_profiles')) {
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container py-5">
        <section class="card border-0 rounded-4 shadow-sm p-4 p-lg-5">
            <h1 class="h3 mb-3">Merge Preview Unavailable</h1>
            <p class="text-muted mb-4">The suggestions or authority profile table is not available.</p>
            <a href="review-suggestions.php" class="btn btn-primary">Back to Suggestions</a>
        </section>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$suggestionId = isset($_GET['suggestion_id']) ? (int) $_GET['suggestion_id'] : (int) ($_POST['suggestion_id'] ?? 0);

if ($suggestionId <= 0) {
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container py-5">
        <section class="card border-0 rounded-4 shadow-sm p-4 p-lg-5">
            <h1 class="h3 mb-3">Invalid Suggestion</h1>
            <p class="text-muted mb-4">A valid suggestion was not specified.</p>
            <a href="review-suggestions.php" class="btn btn-primary">Back to Suggestions</a>
        </section>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        ns.*,
        ne.id AS entry_id_resolved,
        ne.name AS entry_name,
        ne.ethnic_group,
        ne.meaning AS entry_meaning,
        ne.naming_context AS entry_naming_context,
        ne.cultural_explanation AS entry_cultural_explanation,
        ne.sources AS entry_sources,
        np.id AS profile_id,
        np.profile_status,
        np.origin_overview,
        np.meaning_extended,
        np.historical_context,
        np.cultural_significance,
        np.naming_traditions,
        np.variants,
        np.pronunciation_notes,
        np.editorial_notes,
        np.sources_extended,
        np.updated_at AS profile_updated_at
    FROM name_suggestions ns
    INNER JOIN name_entries ne ON ne.id = ns.entry_id
    LEFT JOIN name_profiles np ON np.name_entry_id = ne.id
    WHERE ns.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $suggestionId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container py-5">
        <section class="card border-0 rounded-4 shadow-sm p-4 p-lg-5">
            <h1 class="h3 mb-3">Suggestion Not Found</h1>
            <p class="text-muted mb-4">The requested suggestion does not exist.</p>
            <a href="review-suggestions.php" class="btn btn-primary">Back to Suggestions</a>
        </section>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$entryId = (int) ($record['entry_id_resolved'] ?? 0);

$mergeableFields = [
    'meaning_extended' => 'Meaning',
    'origin_overview' => 'Origin and Background',
    'historical_context' => 'Historical Context',
    'cultural_significance' => 'Cultural Significance',
    'naming_traditions' => 'Naming Context and Traditions',
    'variants' => 'Variants',
    'pronunciation_notes' => 'Pronunciation Notes',
    'editorial_notes' => 'Editorial Notes',
    'sources_extended' => 'Sources and Documentation',
];

$suggestionSources = [
    'proposed_meaning' => [
        'label' => 'Proposed Meaning',
        'value' => trim((string) ($record['proposed_meaning'] ?? '')),
        'default_target' => 'meaning_extended',
    ],
    'proposed_naming_context' => [
        'label' => 'Proposed Naming Context',
        'value' => trim((string) ($record['proposed_naming_context'] ?? '')),
        'default_target' => 'naming_traditions',
    ],
    'proposed_cultural_explanation' => [
        'label' => 'Proposed Cultural Explanation',
        'value' => trim((string) ($record['proposed_cultural_explanation'] ?? '')),
        'default_target' => 'cultural_significance',
    ],
    'proposed_sources' => [
        'label' => 'Proposed Sources',
        'value' => trim((string) ($record['proposed_sources'] ?? '')),
        'default_target' => 'sources_extended',
    ],
    'proposed_overview' => [
        'label' => 'Proposed Overview',
        'value' => trim((string) ($record['proposed_overview'] ?? '')),
        'default_target' => 'origin_overview',
    ],
    'proposed_linguistic_origin' => [
        'label' => 'Proposed Linguistic Origin',
        'value' => trim((string) ($record['proposed_linguistic_origin'] ?? '')),
        'default_target' => 'origin_overview',
    ],
    'proposed_cultural_significance' => [
        'label' => 'Proposed Cultural Significance',
        'value' => trim((string) ($record['proposed_cultural_significance'] ?? '')),
        'default_target' => 'cultural_significance',
    ],
    'proposed_historical_context' => [
        'label' => 'Proposed Historical Context',
        'value' => trim((string) ($record['proposed_historical_context'] ?? '')),
        'default_target' => 'historical_context',
    ],
    'proposed_variants' => [
        'label' => 'Proposed Variants',
        'value' => trim((string) ($record['proposed_variants'] ?? '')),
        'default_target' => 'variants',
    ],
    'proposed_pronunciation' => [
        'label' => 'Proposed Pronunciation',
        'value' => trim((string) ($record['proposed_pronunciation'] ?? '')),
        'default_target' => 'pronunciation_notes',
    ],
    'proposed_related_names' => [
        'label' => 'Proposed Related Names',
        'value' => trim((string) ($record['proposed_related_names'] ?? '')),
        'default_target' => 'variants',
    ],
    'proposed_scholarly_notes' => [
        'label' => 'Proposed Scholarly Notes',
        'value' => trim((string) ($record['proposed_scholarly_notes'] ?? '')),
        'default_target' => 'editorial_notes',
    ],
    'proposed_references_text' => [
        'label' => 'Proposed References Text',
        'value' => trim((string) ($record['proposed_references_text'] ?? '')),
        'default_target' => 'sources_extended',
    ],
    'contributor_notes' => [
        'label' => 'Contributor Notes',
        'value' => trim((string) ($record['contributor_notes'] ?? '')),
        'default_target' => 'editorial_notes',
    ],
];

$availableSuggestionSources = array_filter(
    $suggestionSources,
    static fn(array $item): bool => $item['value'] !== ''
);

$defaultSourceField = array_key_first($availableSuggestionSources);
if ($defaultSourceField === null) {
    $defaultSourceField = 'proposed_meaning';
}

$selectedSourceField = trim((string) ($_POST['source_field'] ?? $_GET['source_field'] ?? $defaultSourceField));
if (!isset($suggestionSources[$selectedSourceField]) || $suggestionSources[$selectedSourceField]['value'] === '') {
    $selectedSourceField = $defaultSourceField;
}

$selectedField = trim((string) ($_POST['target_field'] ?? $_GET['target_field'] ?? $suggestionSources[$selectedSourceField]['default_target']));
if (!array_key_exists($selectedField, $mergeableFields)) {
    $selectedField = 'meaning_extended';
}

$mergeMode = trim((string) ($_POST['merge_mode'] ?? $_GET['merge_mode'] ?? 'append'));
if (!in_array($mergeMode, ['append', 'prepend', 'replace'], true)) {
    $mergeMode = 'append';
}

$suggestionText = trim((string) ($suggestionSources[$selectedSourceField]['value'] ?? ''));
$currentValue = trim((string) ($record[$selectedField] ?? ''));
$previewValue = normalizeMergeText($currentValue, $suggestionText, $mergeMode);
$finalMergedText = trim((string) ($_POST['final_merged_text'] ?? $previewValue));

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $decisionNotes = trim((string) ($_POST['decision_notes'] ?? ''));

    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Security check failed. Please refresh the page and try again.';
    }

    if ($action === 'apply_merge') {
        if ($suggestionText === '') {
            $errors[] = 'The selected suggestion field is empty and cannot be merged.';
        }

        if (!array_key_exists($selectedField, $mergeableFields)) {
            $errors[] = 'Invalid target authority field selected.';
        }

        if (mb_strlen($finalMergedText) > 20000) {
            $errors[] = 'Final merged text is too long.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                if ((int) ($record['profile_id'] ?? 0) > 0) {
                    $updateProfile = $pdo->prepare("
                        UPDATE name_profiles
                        SET
                            {$selectedField} = :merged_value,
                            updated_by = :updated_by,
                            updated_at = NOW()
                        WHERE name_entry_id = :entry_id
                        LIMIT 1
                    ");
                    $updateProfile->execute([
                        ':merged_value' => $finalMergedText !== '' ? $finalMergedText : null,
                        ':updated_by' => $currentUserId,
                        ':entry_id' => $entryId,
                    ]);
                } else {
                    $insertProfile = $pdo->prepare("
                        INSERT INTO name_profiles (
                            name_entry_id,
                            profile_status,
                            {$selectedField},
                            created_by,
                            updated_by,
                            created_at,
                            updated_at
                        ) VALUES (
                            :entry_id,
                            'draft',
                            :merged_value,
                            :created_by,
                            :updated_by,
                            NOW(),
                            NOW()
                        )
                    ");
                    $insertProfile->execute([
                        ':entry_id' => $entryId,
                        ':merged_value' => $finalMergedText !== '' ? $finalMergedText : null,
                        ':created_by' => $currentUserId,
                        ':updated_by' => $currentUserId,
                    ]);
                }

                $newReviewNotes = trim(
                    ($record['review_notes'] ?? '') .
                    ($record['review_notes'] ? "\n\n" : '') .
                    '[Merged ' . date('Y-m-d H:i:s') . '] ' .
                    $suggestionSources[$selectedSourceField]['label'] .
                    ' → ' . $mergeableFields[$selectedField] .
                    ' (' . $mergeMode . ')' .
                    ($decisionNotes !== '' ? "\n" . $decisionNotes : '')
                );

                $updateSuggestion = $pdo->prepare("
                    UPDATE name_suggestions
                    SET
                        review_notes = :review_notes,
                        reviewed_by = :reviewed_by,
                        reviewed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");
                $updateSuggestion->execute([
                    ':review_notes' => $newReviewNotes,
                    ':reviewed_by' => $currentUserId,
                    ':id' => $suggestionId,
                ]);

                if (tableExists($pdo, 'suggestion_merge_logs')) {
                    $logColumns = [];
                    $logValues = [];
                    $logParams = [];

                    $candidateLogCols = [
                        'suggestion_id' => $suggestionId,
                        'entry_id' => $entryId,
                        'field_name' => $selectedField,
                        'old_value' => $currentValue !== '' ? $currentValue : null,
                        'new_value' => $finalMergedText !== '' ? $finalMergedText : null,
                        'merged_by' => $currentUserId,
                    ];

                    foreach ($candidateLogCols as $column => $value) {
                        if (tableHasColumn($pdo, 'suggestion_merge_logs', $column)) {
                            $logColumns[] = $column;
                            $logValues[] = ':' . $column;
                            $logParams[':' . $column] = $value;
                        }
                    }

                    if ($logColumns) {
                        $logSql = "
                            INSERT INTO suggestion_merge_logs (" . implode(', ', $logColumns) . ")
                            VALUES (" . implode(', ', $logValues) . ")
                        ";
                        $logStmt = $pdo->prepare($logSql);
                        $logStmt->execute($logParams);
                    }
                }

                $pdo->commit();

                $_SESSION['review_suggestions_flash'] = [
                    'type' => 'success',
                    'message' => 'Suggestion merged into the authority profile successfully.',
                ];

                redirectTo('review-suggestions.php?name_id=' . $entryId . '&status=approved');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to apply the merge right now.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.merge-shell {
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.10), transparent 26%),
        radial-gradient(circle at top left, rgba(16, 185, 129, 0.07), transparent 20%),
        linear-gradient(180deg, #f8fbff 0%, #f4f7fb 100%);
    min-height: 100vh;
    padding: 28px 0 56px;
}

.merge-wrap {
    max-width: 1240px;
    margin: 0 auto;
    padding: 0 14px;
}

.merge-panel,
.merge-card {
    background: rgba(255, 255, 255, 0.96);
    border: 1px solid rgba(15, 23, 42, 0.07);
    border-radius: 24px;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
}

.merge-panel {
    padding: 28px;
    margin-bottom: 24px;
}

.merge-topbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 24px;
}

.merge-kicker {
    display: inline-flex;
    align-items: center;
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(37, 99, 235, 0.10);
    color: #1d4ed8;
    font-size: 0.84rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    margin-bottom: 12px;
}

.merge-title {
    margin: 0 0 8px;
    font-size: clamp(1.8rem, 3vw, 2.35rem);
    line-height: 1.08;
    font-weight: 900;
    color: #0f172a;
}

.merge-subtitle {
    margin: 0;
    color: #64748b;
    line-height: 1.7;
    max-width: 760px;
}

.merge-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.merge-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 46px;
    padding: 0 16px;
    border-radius: 14px;
    border: 1px solid rgba(15, 23, 42, 0.09);
    text-decoration: none;
    font-weight: 800;
    font-size: 0.96rem;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
}

.merge-btn:hover {
    transform: translateY(-1px);
    text-decoration: none;
}

.merge-btn-primary {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 14px 28px rgba(37, 99, 235, 0.20);
}

.merge-btn-primary:hover {
    color: #fff;
}

.merge-btn-secondary {
    background: #fff;
    color: #0f172a;
}

.merge-btn-secondary:hover {
    color: #0f172a;
    border-color: rgba(37, 99, 235, 0.25);
    box-shadow: 0 12px 22px rgba(15, 23, 42, 0.06);
}

.merge-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.merge-stat {
    border: 1px solid rgba(15, 23, 42, 0.07);
    border-radius: 20px;
    padding: 20px;
    background: linear-gradient(180deg, #ffffff, #f8fbff);
}

.merge-stat-label {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #64748b;
    font-weight: 800;
    margin-bottom: 10px;
}

.merge-stat-value {
    font-size: 1.1rem;
    line-height: 1.35;
    font-weight: 900;
    color: #0f172a;
}

.merge-controls {
    margin-bottom: 24px;
}

.merge-controls-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 16px;
}

.merge-form-group label {
    display: block;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 8px;
}

.merge-form-group select,
.merge-form-group textarea {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 14px;
    padding: 12px 14px;
    background: #fff;
}

.merge-form-group textarea {
    min-height: 110px;
    resize: vertical;
}

.merge-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.merge-card {
    padding: 22px;
}

.merge-card-title {
    margin: 0 0 8px;
    font-size: 1.25rem;
    line-height: 1.15;
    font-weight: 900;
    color: #0f172a;
}

.merge-card-subtitle {
    color: #64748b;
    font-size: 0.95rem;
    margin-bottom: 14px;
}

.merge-content {
    border: 1px solid rgba(15, 23, 42, 0.06);
    background: linear-gradient(180deg, #ffffff, #f8fbff);
    border-radius: 16px;
    padding: 14px 15px;
    color: #0f172a;
    line-height: 1.8;
    min-height: 120px;
}

.merge-empty {
    color: #64748b;
    font-style: italic;
}

.merge-final textarea {
    width: 100%;
    min-height: 220px;
    border: 1px solid #cbd5e1;
    border-radius: 16px;
    padding: 14px 15px;
    resize: vertical;
    margin-bottom: 14px;
}

.diff-added {
    background: #dcfce7;
    color: #166534;
    padding: 1px 4px;
    border-radius: 6px;
}

.merge-apply-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
}

@media (max-width: 991.98px) {
    .merge-stats,
    .merge-controls-grid,
    .merge-grid {
        grid-template-columns: 1fr;
    }

    .merge-topbar {
        flex-direction: column;
    }
}

@media (max-width: 767.98px) {
    .merge-shell {
        padding: 18px 0 42px;
    }

    .merge-wrap {
        padding: 0 12px;
    }

    .merge-panel,
    .merge-card {
        border-radius: 20px;
        padding: 18px;
    }

    .merge-actions,
    .merge-apply-row {
        width: 100%;
    }

    .merge-btn {
        width: 100%;
    }
}
</style>

<main class="merge-shell">
    <div class="merge-wrap">

        <?php if ($errors): ?>
            <div class="alert alert-danger rounded-4 shadow-sm mb-4">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="merge-panel">
            <div class="merge-topbar">
                <div>
                    <div class="merge-kicker">Editorial Merge Workflow</div>
                    <h1 class="merge-title">Merge Preview</h1>
                    <p class="merge-subtitle">
                        Compare a specific approved suggestion against the current authority profile before applying any editorial change.
                    </p>
                </div>

                <div class="merge-actions">
                    <a href="review-suggestions.php?name_id=<?= $entryId ?>" class="merge-btn merge-btn-secondary">Back to Suggestions</a>
                    <a href="name.php?id=<?= $entryId ?>" class="merge-btn merge-btn-secondary">View Name Page</a>
                    <a href="edit-name-profile.php?id=<?= $entryId ?>" class="merge-btn merge-btn-primary">Open Authority Editor</a>
                </div>
            </div>

            <div class="merge-stats">
                <div class="merge-stat">
                    <div class="merge-stat-label">Name</div>
                    <div class="merge-stat-value"><?= e((string) ($record['entry_name'] ?? '—')) ?></div>
                </div>
                <div class="merge-stat">
                    <div class="merge-stat-label">Ethnic Group</div>
                    <div class="merge-stat-value"><?= e((string) ($record['ethnic_group'] ?? '—')) ?></div>
                </div>
                <div class="merge-stat">
                    <div class="merge-stat-label">Suggestion Type</div>
                    <div class="merge-stat-value"><?= e(ucwords(str_replace('_', ' ', (string) ($record['suggestion_type'] ?? 'general')))) ?></div>
                </div>
                <div class="merge-stat">
                    <div class="merge-stat-label">Suggestion Date</div>
                    <div class="merge-stat-value"><?= e(formatDateTime($record['created_at'] ?? null)) ?></div>
                </div>
            </div>
        </section>

        <section class="merge-panel merge-controls">
            <form method="post" id="merge-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="suggestion_id" value="<?= (int) $suggestionId ?>">

                <div class="merge-controls-grid">
                    <div class="merge-form-group">
                        <label for="source_field">Suggestion Field</label>
                        <select id="source_field" name="source_field" onchange="this.form.submit()">
                            <?php foreach ($availableSuggestionSources as $fieldKey => $fieldMeta): ?>
                                <option value="<?= e($fieldKey) ?>" <?= $selectedSourceField === $fieldKey ? 'selected' : '' ?>>
                                    <?= e($fieldMeta['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="merge-form-group">
                        <label for="target_field">Target Authority Field</label>
                        <select id="target_field" name="target_field" onchange="this.form.submit()">
                            <?php foreach ($mergeableFields as $fieldKey => $fieldLabel): ?>
                                <option value="<?= e($fieldKey) ?>" <?= $selectedField === $fieldKey ? 'selected' : '' ?>>
                                    <?= e($fieldLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="merge-form-group">
                        <label for="merge_mode">Merge Strategy</label>
                        <select id="merge_mode" name="merge_mode" onchange="this.form.submit()">
                            <option value="append" <?= $mergeMode === 'append' ? 'selected' : '' ?>>Append to existing text</option>
                            <option value="prepend" <?= $mergeMode === 'prepend' ? 'selected' : '' ?>>Place before existing text</option>
                            <option value="replace" <?= $mergeMode === 'replace' ? 'selected' : '' ?>>Replace existing text</option>
                        </select>
                    </div>
                </div>

                <div class="merge-form-group mt-3">
                    <label for="decision_notes">Merge Notes</label>
                    <textarea
                        id="decision_notes"
                        name="decision_notes"
                        rows="3"
                        placeholder="Optional editorial note about why this suggestion was merged..."
                    ></textarea>
                </div>

                <div class="merge-grid mt-4">
                    <section class="merge-card">
                        <h2 class="merge-card-title">Incoming Suggestion</h2>
                        <div class="merge-card-subtitle"><?= e($suggestionSources[$selectedSourceField]['label']) ?></div>
                        <div class="merge-content">
                            <?php if ($suggestionText !== ''): ?>
                                <?= nl2safe($suggestionText) ?>
                            <?php else: ?>
                                <div class="merge-empty">No content in this suggestion field.</div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="merge-card">
                        <h2 class="merge-card-title">Current Target Content</h2>
                        <div class="merge-card-subtitle"><?= e($mergeableFields[$selectedField]) ?></div>
                        <div class="merge-content">
                            <?php if ($currentValue !== ''): ?>
                                <?= nl2safe($currentValue) ?>
                            <?php else: ?>
                                <div class="merge-empty">This authority field is currently empty.</div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <section class="merge-card merge-final mt-4">
                    <h2 class="merge-card-title">Editable Merged Result</h2>
                    <div class="merge-card-subtitle">Review and refine the final authority text before saving.</div>

                    <textarea name="final_merged_text" id="final_merged_text"><?= e($finalMergedText) ?></textarea>

                    <div class="merge-card-subtitle">Diff Preview</div>
                    <div class="merge-content">
                        <?php if (trim($finalMergedText) !== ''): ?>
                            <?= highlightDiff($currentValue, $finalMergedText) ?>
                        <?php else: ?>
                            <div class="merge-empty">No merged content available.</div>
                        <?php endif; ?>
                    </div>

                    <div class="merge-apply-row">
                        <button type="submit" name="action" value="apply_merge" class="merge-btn merge-btn-primary">
                            Apply Merge
                        </button>
                    </div>
                </section>
            </form>
        </section>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>