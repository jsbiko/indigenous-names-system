<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$user = currentUser();
$pageTitle = 'Suggest an Improvement';

function e(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function suggestionTypeLabel(string $type): string
{
    return match ($type) {
        'meaning' => 'Meaning',
        'cultural_explanation' => 'Cultural Explanation',
        'sources' => 'Sources',
        'language_origin' => 'Language / Origin',
        'general' => 'General',
        default => ucwords(str_replace('_', ' ', $type)),
    };
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$entryId = isset($_GET['name_id']) && is_numeric($_GET['name_id'])
    ? (int) $_GET['name_id']
    : (isset($_GET['entry_id']) && is_numeric($_GET['entry_id']) ? (int) $_GET['entry_id'] : 0);

if ($entryId <= 0) {
    redirectTo('browse.php');
}

$entryStmt = $pdo->prepare("
    SELECT
        ne.id,
        ne.name,
        ne.meaning,
        ne.ethnic_group,
        ne.region,
        ne.gender,
        ne.naming_context,
        ne.cultural_explanation,
        ne.sources,
        ne.status,
        np.id AS profile_id,
        np.profile_status,
        np.origin_overview,
        np.meaning_extended,
        np.historical_context,
        np.cultural_significance,
        np.naming_traditions,
        np.variants,
        np.pronunciation_notes,
        np.sources_extended
    FROM name_entries ne
    LEFT JOIN name_profiles np ON np.name_entry_id = ne.id
    WHERE ne.id = :id
    LIMIT 1
");
$entryStmt->execute([':id' => $entryId]);
$entry = $entryStmt->fetch(PDO::FETCH_ASSOC);

if (!$entry) {
    http_response_code(404);
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container py-5">
        <section class="card border-0 rounded-4 shadow-sm p-4 p-lg-5 text-center">
            <h1 class="h3 mb-3">Entry not found</h1>
            <p class="text-muted mb-0">The name you want to improve could not be found.</p>
        </section>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$defaults = [
    'suggestion_type' => 'general',
    'proposed_meaning' => '',
    'proposed_naming_context' => '',
    'proposed_cultural_explanation' => '',
    'proposed_sources' => '',
    'proposed_overview' => '',
    'proposed_linguistic_origin' => '',
    'proposed_cultural_significance' => '',
    'proposed_historical_context' => '',
    'proposed_variants' => '',
    'proposed_pronunciation' => '',
    'proposed_related_names' => '',
    'proposed_scholarly_notes' => '',
    'proposed_references_text' => '',
    'contributor_notes' => '',
];

$errors = [];
$flash = $_SESSION['submit_flash'] ?? null;
unset($_SESSION['submit_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, (string) $postedToken)) {
        $errors[] = 'Security check failed. Please refresh the page and try again.';
    }

    $defaults = [
        'suggestion_type' => trim((string) ($_POST['suggestion_type'] ?? 'general')),
        'proposed_meaning' => trim((string) ($_POST['proposed_meaning'] ?? '')),
        'proposed_naming_context' => trim((string) ($_POST['proposed_naming_context'] ?? '')),
        'proposed_cultural_explanation' => trim((string) ($_POST['proposed_cultural_explanation'] ?? '')),
        'proposed_sources' => trim((string) ($_POST['proposed_sources'] ?? '')),
        'proposed_overview' => trim((string) ($_POST['proposed_overview'] ?? '')),
        'proposed_linguistic_origin' => trim((string) ($_POST['proposed_linguistic_origin'] ?? '')),
        'proposed_cultural_significance' => trim((string) ($_POST['proposed_cultural_significance'] ?? '')),
        'proposed_historical_context' => trim((string) ($_POST['proposed_historical_context'] ?? '')),
        'proposed_variants' => trim((string) ($_POST['proposed_variants'] ?? '')),
        'proposed_pronunciation' => trim((string) ($_POST['proposed_pronunciation'] ?? '')),
        'proposed_related_names' => trim((string) ($_POST['proposed_related_names'] ?? '')),
        'proposed_scholarly_notes' => trim((string) ($_POST['proposed_scholarly_notes'] ?? '')),
        'proposed_references_text' => trim((string) ($_POST['proposed_references_text'] ?? '')),
        'contributor_notes' => trim((string) ($_POST['contributor_notes'] ?? '')),
    ];

    $allowedTypes = ['meaning', 'cultural_explanation', 'sources', 'language_origin', 'general'];
    if (!in_array($defaults['suggestion_type'], $allowedTypes, true)) {
        $errors[] = 'Invalid suggestion type selected.';
    }

    $hasContent =
        $defaults['proposed_meaning'] !== '' ||
        $defaults['proposed_naming_context'] !== '' ||
        $defaults['proposed_cultural_explanation'] !== '' ||
        $defaults['proposed_sources'] !== '' ||
        $defaults['proposed_overview'] !== '' ||
        $defaults['proposed_linguistic_origin'] !== '' ||
        $defaults['proposed_cultural_significance'] !== '' ||
        $defaults['proposed_historical_context'] !== '' ||
        $defaults['proposed_variants'] !== '' ||
        $defaults['proposed_pronunciation'] !== '' ||
        $defaults['proposed_related_names'] !== '' ||
        $defaults['proposed_scholarly_notes'] !== '' ||
        $defaults['proposed_references_text'] !== '' ||
        $defaults['contributor_notes'] !== '';

    if (!$hasContent) {
        $errors[] = 'Add at least one proposed improvement before submitting.';
    }

    if (
        $defaults['proposed_sources'] === '' &&
        $defaults['proposed_references_text'] === '' &&
        $defaults['contributor_notes'] === ''
    ) {
        $errors[] = 'Please provide sources, references text, or contributor notes to support the suggestion.';
    }

    if (!$errors) {
        $insertStmt = $pdo->prepare("
            INSERT INTO name_suggestions (
                entry_id,
                suggested_by,
                suggestion_type,
                proposed_meaning,
                proposed_naming_context,
                proposed_cultural_explanation,
                proposed_sources,
                proposed_overview,
                proposed_linguistic_origin,
                proposed_cultural_significance,
                proposed_historical_context,
                proposed_variants,
                proposed_pronunciation,
                proposed_related_names,
                proposed_scholarly_notes,
                proposed_references_text,
                contributor_notes,
                status,
                created_at,
                updated_at
            ) VALUES (
                :entry_id,
                :suggested_by,
                :suggestion_type,
                :proposed_meaning,
                :proposed_naming_context,
                :proposed_cultural_explanation,
                :proposed_sources,
                :proposed_overview,
                :proposed_linguistic_origin,
                :proposed_cultural_significance,
                :proposed_historical_context,
                :proposed_variants,
                :proposed_pronunciation,
                :proposed_related_names,
                :proposed_scholarly_notes,
                :proposed_references_text,
                :contributor_notes,
                'pending',
                NOW(),
                NOW()
            )
        ");

        $insertStmt->execute([
            ':entry_id' => $entryId,
            ':suggested_by' => (int) ($user['id'] ?? 0),
            ':suggestion_type' => $defaults['suggestion_type'],
            ':proposed_meaning' => $defaults['proposed_meaning'] !== '' ? $defaults['proposed_meaning'] : null,
            ':proposed_naming_context' => $defaults['proposed_naming_context'] !== '' ? $defaults['proposed_naming_context'] : null,
            ':proposed_cultural_explanation' => $defaults['proposed_cultural_explanation'] !== '' ? $defaults['proposed_cultural_explanation'] : null,
            ':proposed_sources' => $defaults['proposed_sources'] !== '' ? $defaults['proposed_sources'] : null,
            ':proposed_overview' => $defaults['proposed_overview'] !== '' ? $defaults['proposed_overview'] : null,
            ':proposed_linguistic_origin' => $defaults['proposed_linguistic_origin'] !== '' ? $defaults['proposed_linguistic_origin'] : null,
            ':proposed_cultural_significance' => $defaults['proposed_cultural_significance'] !== '' ? $defaults['proposed_cultural_significance'] : null,
            ':proposed_historical_context' => $defaults['proposed_historical_context'] !== '' ? $defaults['proposed_historical_context'] : null,
            ':proposed_variants' => $defaults['proposed_variants'] !== '' ? $defaults['proposed_variants'] : null,
            ':proposed_pronunciation' => $defaults['proposed_pronunciation'] !== '' ? $defaults['proposed_pronunciation'] : null,
            ':proposed_related_names' => $defaults['proposed_related_names'] !== '' ? $defaults['proposed_related_names'] : null,
            ':proposed_scholarly_notes' => $defaults['proposed_scholarly_notes'] !== '' ? $defaults['proposed_scholarly_notes'] : null,
            ':proposed_references_text' => $defaults['proposed_references_text'] !== '' ? $defaults['proposed_references_text'] : null,
            ':contributor_notes' => $defaults['contributor_notes'] !== '' ? $defaults['contributor_notes'] : null,
        ]);

        $_SESSION['submit_flash'] = [
            'type' => 'success',
            'message' => 'Your improvement suggestion has been submitted for editorial review.',
        ];

        redirectTo('name.php?id=' . $entryId);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.suggest-shell {
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.10), transparent 26%),
        radial-gradient(circle at top left, rgba(16, 185, 129, 0.06), transparent 20%),
        linear-gradient(180deg, #f8fbff 0%, #f4f7fb 100%);
    min-height: 100vh;
    padding: 28px 0 56px;
}

.suggest-wrap {
    max-width: 1220px;
    margin: 0 auto;
    padding: 0 14px;
}

.suggest-hero,
.suggest-card,
.reference-card,
.record-card {
    background: rgba(255, 255, 255, 0.96);
    border: 1px solid rgba(15, 23, 42, 0.07);
    border-radius: 24px;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
}

.suggest-hero,
.suggest-card {
    padding: 28px;
}

.suggest-hero {
    margin-bottom: 24px;
}

.suggest-topbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 22px;
}

.suggest-kicker {
    display: inline-flex;
    align-items: center;
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(245, 158, 11, 0.12);
    color: #b45309;
    font-size: 0.84rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    margin-bottom: 12px;
}

.suggest-title {
    margin: 0 0 8px;
    font-size: clamp(2rem, 3vw, 2.75rem);
    line-height: 1.05;
    font-weight: 900;
    color: #0f172a;
}

.suggest-subtitle {
    margin: 0;
    color: #64748b;
    line-height: 1.8;
    max-width: 780px;
    font-size: 1.02rem;
}

.suggest-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.suggest-btn {
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
    cursor: pointer;
}

.suggest-btn:hover {
    transform: translateY(-1px);
    text-decoration: none;
}

.suggest-btn-primary {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 14px 28px rgba(37, 99, 235, 0.20);
}

.suggest-btn-primary:hover {
    color: #fff;
}

.suggest-btn-secondary {
    background: #fff;
    color: #0f172a;
}

.suggest-btn-secondary:hover {
    color: #0f172a;
    border-color: rgba(37, 99, 235, 0.25);
    box-shadow: 0 12px 22px rgba(15, 23, 42, 0.06);
}

.duplicate-warning {
    display: grid;
    grid-template-columns: 1.3fr 0.7fr;
    gap: 18px;
}

.duplicate-panel {
    border: 1px solid rgba(245, 158, 11, 0.18);
    background: linear-gradient(180deg, #fffdf7, #fffaf0);
    border-radius: 20px;
    padding: 20px;
}

.duplicate-panel h2 {
    margin: 0 0 10px;
    font-size: 1.25rem;
    font-weight: 900;
    color: #0f172a;
}

.duplicate-panel p {
    margin: 0;
    color: #475569;
    line-height: 1.8;
}

.duplicate-steps {
    list-style: none;
    padding: 0;
    margin: 0;
}

.duplicate-steps li {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 12px;
    color: #475569;
    line-height: 1.6;
}

.duplicate-steps li:last-child {
    margin-bottom: 0;
}

.step-badge {
    flex: 0 0 auto;
    width: 28px;
    height: 28px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(37, 99, 235, 0.12);
    color: #1d4ed8;
    font-weight: 900;
    font-size: 0.85rem;
    margin-top: 1px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-top: 20px;
}

.summary-card {
    border: 1px solid rgba(15, 23, 42, 0.06);
    background: linear-gradient(180deg, #ffffff, #f8fbff);
    border-radius: 18px;
    padding: 16px;
}

.summary-label {
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #64748b;
    font-weight: 800;
    margin-bottom: 8px;
}

.summary-value {
    color: #0f172a;
    font-weight: 900;
    line-height: 1.5;
}

.suggest-layout {
    display: grid;
    grid-template-columns: 1.35fr 0.65fr;
    gap: 22px;
}

.suggest-card .form-label {
    display: block;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 8px;
}

.suggest-card .form-text {
    color: #64748b;
    margin-top: 8px;
}

.suggest-card textarea,
.suggest-card select {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 16px;
    padding: 14px 15px;
    background: #fff;
    color: #0f172a;
    box-sizing: border-box;
}

.suggest-card select {
    min-height: 52px;
}

.suggest-card textarea {
    min-height: 150px;
    resize: vertical;
}

.form-section {
    margin-bottom: 22px;
}

.section-divider {
    height: 1px;
    background: linear-gradient(90deg, rgba(15,23,42,0.08), rgba(15,23,42,0.02));
    margin: 24px 0 22px;
}

.field-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.field-grid .form-section.full {
    grid-column: 1 / -1;
}

.helper-box {
    border: 1px solid rgba(15, 23, 42, 0.06);
    background: #f8fafc;
    border-radius: 18px;
    padding: 16px 18px;
    color: #475569;
    line-height: 1.75;
    margin-top: 18px;
}

.record-card,
.reference-card {
    padding: 22px;
    margin-bottom: 18px;
}

.context-label {
    font-size: 0.78rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 800;
    color: #64748b;
    margin-bottom: 8px;
}

.context-value {
    color: #0f172a;
    font-weight: 900;
    font-size: 1.15rem;
    margin-bottom: 10px;
}

.context-copy {
    color: #475569;
    line-height: 1.8;
}

.info-list {
    padding-left: 1rem;
    margin-bottom: 0;
}

.info-list li {
    margin-bottom: 0.65rem;
    color: #475569;
    line-height: 1.65;
}

.status-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 0 12px;
    border-radius: 999px;
    font-size: 0.84rem;
    font-weight: 800;
    border: 1px solid transparent;
}

.status-approved {
    background: rgba(22, 163, 74, 0.11);
    color: #15803d;
    border-color: rgba(22, 163, 74, 0.14);
}

.status-pending {
    background: rgba(245, 158, 11, 0.13);
    color: #b45309;
    border-color: rgba(245, 158, 11, 0.16);
}

.status-draft {
    background: rgba(59, 130, 246, 0.10);
    color: #1d4ed8;
    border-color: rgba(59, 130, 246, 0.14);
}

.status-neutral {
    background: rgba(100, 116, 139, 0.11);
    color: #475569;
    border-color: rgba(100, 116, 139, 0.14);
}

@media (max-width: 1100px) {
    .duplicate-warning,
    .suggest-layout,
    .summary-grid,
    .field-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767.98px) {
    .suggest-shell {
        padding: 18px 0 42px;
    }

    .suggest-wrap {
        padding: 0 12px;
    }

    .suggest-hero,
    .suggest-card,
    .record-card,
    .reference-card {
        border-radius: 20px;
        padding: 18px;
    }

    .suggest-topbar {
        flex-direction: column;
    }

    .suggest-actions {
        width: 100%;
    }

    .suggest-btn {
        width: 100%;
    }
}
</style>

<main class="suggest-shell">
    <div class="suggest-wrap">
        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type'] ?? 'info') ?> rounded-4 shadow-sm mb-4">
                <?= e($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <section class="suggest-hero">
            <div class="suggest-topbar">
                <div>
                    <div class="suggest-kicker">Duplicate detected — improve existing record</div>
                    <h1 class="suggest-title">Suggest an Improvement</h1>
                    <p class="suggest-subtitle">
                        We found an existing record for <strong><?= e($entry['name'] ?? '') ?></strong>. Instead of creating a duplicate, strengthen the authority knowledge base by improving the current entry.
                    </p>
                </div>

                <div class="suggest-actions">
                    <a href="name.php?id=<?= (int) $entryId ?>" class="suggest-btn suggest-btn-secondary">View Existing Record</a>
                    <a href="browse.php" class="suggest-btn suggest-btn-primary">Back to Browse</a>
                </div>
            </div>

            <div class="duplicate-warning">
                <div class="duplicate-panel">
                    <h2>Why you were redirected here</h2>
                    <p>
                        This platform protects cultural data quality by preventing duplicate name entries. When a very similar name already exists, contributors are guided into an editorial improvement workflow instead of creating fragmented records.
                    </p>
                </div>

                <div class="duplicate-panel">
                    <h2>What to do next</h2>
                    <ul class="duplicate-steps">
                        <li><span class="step-badge">1</span><span>Review the existing record summary.</span></li>
                        <li><span class="step-badge">2</span><span>Add only the fields that genuinely improve accuracy, depth, or evidence.</span></li>
                        <li><span class="step-badge">3</span><span>Submit your suggestion for editorial review and merge.</span></li>
                    </ul>
                </div>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-label">Name</div>
                    <div class="summary-value"><?= e($entry['name'] ?? '') ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Ethnic Group</div>
                    <div class="summary-value"><?= e($entry['ethnic_group'] ?? 'Not recorded') ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Region</div>
                    <div class="summary-value"><?= e($entry['region'] ?? 'Not recorded') ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Gender</div>
                    <div class="summary-value"><?= e($entry['gender'] ?? 'Not specified') ?></div>
                </div>
            </div>
        </section>

        <?php if ($errors): ?>
            <div class="alert alert-danger rounded-4 shadow-sm mb-4">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="suggest-layout">
            <section class="suggest-card">
                <div class="status-pills">
                    <span class="status-pill status-pending">Improvement Workflow</span>
                    <span class="status-pill status-neutral"><?= e(suggestionTypeLabel($defaults['suggestion_type'])) ?></span>
                </div>

                <h2 class="mb-2" style="font-weight:900;color:#0f172a;">Editorial Improvement Form</h2>
                <p class="text-secondary mb-0" style="line-height:1.8;">
                    Use this form to enrich the existing record with better meaning, stronger cultural context, improved references, and more complete authority content.
                </p>

                <div class="helper-box">
                    Suggestions are reviewed editorially and may be merged field by field. Strong references, precise cultural framing, and clear contributor notes make approval much easier.
                </div>

                <form method="post" action="" class="mt-4">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                    <div class="form-section">
                        <label for="suggestion_type" class="form-label">Suggestion Type</label>
                        <select name="suggestion_type" id="suggestion_type" required>
                            <option value="general" <?= $defaults['suggestion_type'] === 'general' ? 'selected' : '' ?>>General improvement</option>
                            <option value="meaning" <?= $defaults['suggestion_type'] === 'meaning' ? 'selected' : '' ?>>Meaning refinement</option>
                            <option value="cultural_explanation" <?= $defaults['suggestion_type'] === 'cultural_explanation' ? 'selected' : '' ?>>Cultural explanation</option>
                            <option value="sources" <?= $defaults['suggestion_type'] === 'sources' ? 'selected' : '' ?>>Sources and references</option>
                            <option value="language_origin" <?= $defaults['suggestion_type'] === 'language_origin' ? 'selected' : '' ?>>Language or origin</option>
                        </select>
                        <div class="form-text">Choose the editorial category that best matches your contribution.</div>
                    </div>

                    <div class="section-divider"></div>

                    <div class="field-grid">
                        <div class="form-section">
                            <label for="proposed_meaning" class="form-label">Proposed Meaning</label>
                            <textarea name="proposed_meaning" id="proposed_meaning"><?= e($defaults['proposed_meaning']) ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="proposed_naming_context" class="form-label">Proposed Naming Context</label>
                            <textarea name="proposed_naming_context" id="proposed_naming_context"><?= e($defaults['proposed_naming_context']) ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="proposed_cultural_explanation" class="form-label">Proposed Cultural Explanation</label>
                            <textarea name="proposed_cultural_explanation" id="proposed_cultural_explanation"><?= e($defaults['proposed_cultural_explanation']) ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="proposed_overview" class="form-label">Proposed Overview</label>
                            <textarea name="proposed_overview" id="proposed_overview"><?= e($defaults['proposed_overview']) ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="proposed_linguistic_origin" class="form-label">Proposed Linguistic Origin</label>
                            <textarea name="proposed_linguistic_origin" id="proposed_linguistic_origin"><?= e($defaults['proposed_linguistic_origin']) ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="proposed_cultural_significance" class="form-label">Proposed Cultural Significance</label>
                            <textarea name="proposed_cultural_significance" id="proposed_cultural_significance"><?= e($defaults['proposed_cultural_significance']) ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="proposed_historical_context" class="form-label">Proposed Historical Context</label>
                            <textarea name="proposed_historical_context" id="proposed_historical_context"><?= e($defaults['proposed_historical_context']) ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="proposed_variants" class="form-label">Proposed Variants</label>
                            <textarea name="proposed_variants" id="proposed_variants"><?= e($defaults['proposed_variants']) ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="proposed_pronunciation" class="form-label">Proposed Pronunciation</label>
                            <textarea name="proposed_pronunciation" id="proposed_pronunciation"><?= e($defaults['proposed_pronunciation']) ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="proposed_related_names" class="form-label">Proposed Related Names</label>
                            <textarea name="proposed_related_names" id="proposed_related_names"><?= e($defaults['proposed_related_names']) ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="proposed_scholarly_notes" class="form-label">Proposed Scholarly Notes</label>
                            <textarea name="proposed_scholarly_notes" id="proposed_scholarly_notes"><?= e($defaults['proposed_scholarly_notes']) ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="proposed_references_text" class="form-label">Proposed References Text</label>
                            <textarea name="proposed_references_text" id="proposed_references_text"><?= e($defaults['proposed_references_text']) ?></textarea>
                        </div>

                        <div class="form-section full">
                            <label for="proposed_sources" class="form-label">Proposed Sources</label>
                            <textarea name="proposed_sources" id="proposed_sources"><?= e($defaults['proposed_sources']) ?></textarea>
                            <div class="form-text">Cite books, oral authority, academic sources, cultural institutions, elders, or trusted field notes.</div>
                        </div>

                        <div class="form-section full">
                            <label for="contributor_notes" class="form-label">Contributor Notes</label>
                            <textarea name="contributor_notes" id="contributor_notes"><?= e($defaults['contributor_notes']) ?></textarea>
                            <div class="form-text">Explain why this improvement matters, what authority supports it, and how it strengthens the record.</div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-3 pt-2">
                        <button type="submit" class="suggest-btn suggest-btn-primary">
                            Submit Improvement
                        </button>
                        <a href="name.php?id=<?= (int) $entryId ?>" class="suggest-btn suggest-btn-secondary">
                            Cancel
                        </a>
                    </div>
                </form>
            </section>

            <div>
                <section class="record-card">
                    <div class="context-label">Existing Record Summary</div>
                    <div class="context-value"><?= e($entry['name'] ?? '') ?></div>

                    <div class="status-pills">
                        <span class="status-pill <?= strtolower((string) ($entry['status'] ?? '')) === 'approved' ? 'status-approved' : 'status-pending' ?>">
                            Entry: <?= e(ucfirst((string) ($entry['status'] ?? 'unknown'))) ?>
                        </span>
                        <span class="status-pill <?= !empty($entry['profile_id']) ? 'status-draft' : 'status-neutral' ?>">
                            Profile: <?= e(!empty($entry['profile_id']) ? ucfirst((string) ($entry['profile_status'] ?? 'draft')) : 'Not created') ?>
                        </span>
                    </div>

                    <div class="context-copy mb-3">
                        <?= e($entry['meaning'] ?? 'No meaning currently recorded.') ?>
                    </div>

                    <ul class="info-list">
                        <li><strong>Ethnic Group:</strong> <?= e($entry['ethnic_group'] ?? 'Not recorded') ?></li>
                        <li><strong>Region:</strong> <?= e($entry['region'] ?? 'Not recorded') ?></li>
                        <li><strong>Gender:</strong> <?= e($entry['gender'] ?? 'Not specified') ?></li>
                    </ul>
                </section>

                <section class="reference-card">
                    <div class="context-label">Current Entry Context</div>
                    <div class="context-copy">
                        <?php if (!empty($entry['naming_context'])): ?>
                            <p><strong>Naming Context:</strong> <?= e($entry['naming_context']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($entry['cultural_explanation'])): ?>
                            <p><strong>Cultural Explanation:</strong> <?= e($entry['cultural_explanation']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($entry['sources'])): ?>
                            <p class="mb-0"><strong>Sources:</strong> <?= e($entry['sources']) ?></p>
                        <?php endif; ?>

                        <?php if (
                            empty($entry['naming_context']) &&
                            empty($entry['cultural_explanation']) &&
                            empty($entry['sources'])
                        ): ?>
                            <p class="mb-0 text-muted">This record still needs stronger context and documentation.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="reference-card">
                    <div class="context-label">Existing Authority Profile</div>
                    <div class="context-copy">
                        <?php if (!empty($entry['meaning_extended'])): ?>
                            <p><strong>Meaning:</strong> <?= e($entry['meaning_extended']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($entry['origin_overview'])): ?>
                            <p><strong>Origin:</strong> <?= e($entry['origin_overview']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($entry['naming_traditions'])): ?>
                            <p><strong>Traditions:</strong> <?= e($entry['naming_traditions']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($entry['cultural_significance'])): ?>
                            <p><strong>Cultural Significance:</strong> <?= e($entry['cultural_significance']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($entry['variants'])): ?>
                            <p><strong>Variants:</strong> <?= e($entry['variants']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($entry['pronunciation_notes'])): ?>
                            <p><strong>Pronunciation:</strong> <?= e($entry['pronunciation_notes']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($entry['sources_extended'])): ?>
                            <p class="mb-0"><strong>Profile Sources:</strong> <?= e($entry['sources_extended']) ?></p>
                        <?php endif; ?>

                        <?php if (
                            empty($entry['meaning_extended']) &&
                            empty($entry['origin_overview']) &&
                            empty($entry['naming_traditions']) &&
                            empty($entry['cultural_significance']) &&
                            empty($entry['variants']) &&
                            empty($entry['pronunciation_notes']) &&
                            empty($entry['sources_extended'])
                        ): ?>
                            <p class="mb-0 text-muted">No authority profile content has been added yet.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="reference-card">
                    <div class="context-label">Editorial Guidance</div>
                    <ul class="info-list">
                        <li>Do not duplicate existing entries when the same name already exists in the knowledge base.</li>
                        <li>Focus on accuracy, cultural specificity, and verifiable supporting information.</li>
                        <li>Editors review suggestions and merge them field by field into the authority layer.</li>
                        <li>Investor-grade credibility depends on fewer duplicates and stronger editorial traceability.</li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>