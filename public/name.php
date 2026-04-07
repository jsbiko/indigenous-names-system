<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai_summary.php';

$pageTitle = 'Name Authority Page';

/**
 * Map AI gap-analysis keys to editable anchor targets
 * in edit-name-profile.php.
 */
function gapFieldAnchor(string $key): string
{
    return match ($key) {
        'meaning' => 'meaning_extended',
        'origin_overview' => 'origin_overview',
        'historical_context' => 'historical_context',
        'naming_traditions' => 'naming_traditions',
        'cultural_significance' => 'cultural_significance',
        'variants' => 'variants',
        'pronunciation_notes' => 'pronunciation_notes',
        'sources' => 'sources_extended',
        default => 'profile_status',
    };
}

/**
 * Escape output safely.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Convert plain text into paragraphs.
 */
function nl2p(?string $text): string
{
    $text = trim((string) $text);

    if ($text === '') {
        return '';
    }

    $paragraphs = preg_split("/\\R{2,}/", $text) ?: [];
    $html = '';

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }

        $html .= '<p>' . nl2br(e($paragraph)) . '</p>';
    }

    return $html;
}

/**
 * Status pill style helper.
 */
function badgeClass(string $status): string
{
    return match ($status) {
        'approved', 'published' => 'status-pill status-approved',
        'pending', 'draft' => 'status-pill status-pending',
        'rejected', 'archived' => 'status-pill status-rejected',
        default => 'status-pill status-neutral',
    };
}

/**
 * Redirect helper.
 */
function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

$user = currentUser();
$isEditor = $user && in_array($user['role'] ?? '', ['editor', 'admin'], true);
$currentUserId = (int) ($user['id'] ?? 0);

/**
 * Resolve the record ID from either id or name_id.
 */
$nameId = 0;

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $nameId = (int) $_GET['id'];
} elseif (isset($_GET['name_id']) && is_numeric($_GET['name_id'])) {
    $nameId = (int) $_GET['name_id'];
}

if ($nameId <= 0) {
    http_response_code(404);
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container py-5">
        <section class="card shadow-sm border-0 rounded-4 p-4 p-lg-5 text-center">
            <h1 class="h3 mb-3">Name not found</h1>
            <p class="text-muted mb-0">The requested name record is missing or invalid.</p>
        </section>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/**
 * Load the base entry + authority profile.
 * Public users only see approved + published records.
 */
$sql = "
    SELECT
        ne.*,
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
    FROM name_entries ne
    LEFT JOIN name_profiles np ON np.name_entry_id = ne.id
    WHERE ne.id = :id
";

if (!$isEditor) {
    $sql .= " AND ne.status = 'approved' AND np.profile_status = 'published'";
}

$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $nameId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    http_response_code(404);
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container py-5">
        <section class="card shadow-sm border-0 rounded-4 p-4 p-lg-5 text-center">
            <h1 class="h3 mb-3">Name not available</h1>
            <p class="text-muted mb-0">This authority page is not publicly available yet.</p>
        </section>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/**
 * Session/CSRF setup for editor-only AI actions.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/**
 * Handle AI summary generation/refresh.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isEditor) {
    $postedToken = $_POST['csrf_token'] ?? '';
    $action = trim((string) ($_POST['ai_action'] ?? ''));

    if (hash_equals($csrfToken, (string) $postedToken) && $action === 'generate_ai_summary') {
        $sourceHash = aiSummaryHash($record);
        $summaryText = aiGenerateDraftSummary($record);
        aiUpsertSummary($pdo, (int) $record['id'], $summaryText, $sourceHash, $currentUserId);

        redirectTo('name.php?id=' . (int) $record['id'] . '&ai_summary_updated=1');
    }
}

$pageTitle = trim((string) ($record['name'] ?? 'Name Authority Page')) . ' | Indigenous African Names';

/**
 * Normalize record fields for display.
 */
$name = trim((string) ($record['name'] ?? ''));
$meaning = trim((string) ($record['meaning'] ?? ''));
$meaningExtended = trim((string) ($record['meaning_extended'] ?? ''));
$ethnicGroup = trim((string) ($record['ethnic_group'] ?? ''));
$region = trim((string) ($record['region'] ?? ''));
$gender = trim((string) ($record['gender'] ?? ''));
$namingContext = trim((string) ($record['naming_context'] ?? ''));
$culturalExplanation = trim((string) ($record['cultural_explanation'] ?? ''));
$sources = trim((string) ($record['sources'] ?? ''));
$entryStatus = trim((string) ($record['status'] ?? ''));
$profileStatus = trim((string) ($record['profile_status'] ?? ''));
$profileExists = !empty($record['profile_id']);

$originOverview = trim((string) ($record['origin_overview'] ?? ''));
$historicalContext = trim((string) ($record['historical_context'] ?? ''));
$culturalSignificance = trim((string) ($record['cultural_significance'] ?? ''));
$namingTraditions = trim((string) ($record['naming_traditions'] ?? ''));
$variants = trim((string) ($record['variants'] ?? ''));
$pronunciationNotes = trim((string) ($record['pronunciation_notes'] ?? ''));
$editorialNotes = trim((string) ($record['editorial_notes'] ?? ''));
$sourcesExtended = trim((string) ($record['sources_extended'] ?? ''));

/**
 * Fallbacks so the page remains useful even when
 * the authority profile is still thin.
 */
$finalMeaningBlock = $meaningExtended !== '' ? $meaningExtended : $meaning;

$finalOriginBlock = $originOverview !== '' ? $originOverview : (
    $ethnicGroup !== '' || $region !== ''
        ? "This name is associated with {$ethnicGroup}" . ($region !== '' ? " in {$region}" : '') . '.'
        : ''
);

$finalContextBlock = $namingTraditions !== '' ? $namingTraditions : $namingContext;
$finalCulturalBlock = $culturalSignificance !== '' ? $culturalSignificance : $culturalExplanation;
$finalSourcesBlock = trim($sourcesExtended . ($sourcesExtended !== '' && $sources !== '' ? "\n\n" : '') . $sources);

/**
 * AI summary + gap analysis.
 */
$aiSummary = aiFetchCachedSummary($pdo, (int) $record['id']);
$sourceHash = aiSummaryHash($record);
$aiSummaryText = trim((string) ($aiSummary['summary_text'] ?? ''));
$aiSummaryOutdated = $aiSummary && ($aiSummary['source_hash'] ?? '') !== $sourceHash;
$gapAnalysis = aiRecordGapAnalysis($record);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.authority-shell {
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.10), transparent 28%),
        radial-gradient(circle at top left, rgba(16, 185, 129, 0.07), transparent 20%),
        linear-gradient(180deg, #f8fbff 0%, #f4f7fb 100%);
    min-height: 100vh;
    padding: 28px 0 56px;
}

.authority-wrap {
    max-width: 1220px;
    margin: 0 auto;
    padding: 0 14px;
}

.authority-alert,
.authority-hero,
.authority-card,
.authority-side-card,
.ai-card,
.gap-card {
    background: rgba(255, 255, 255, 0.96);
    border: 1px solid rgba(15, 23, 42, 0.07);
    border-radius: 24px;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
}

.authority-alert,
.authority-hero,
.ai-card,
.gap-card {
    padding: 24px 28px;
    margin-bottom: 24px;
}

.authority-alert.info {
    border-color: rgba(59, 130, 246, 0.20);
    background: linear-gradient(180deg, #f8fbff, #eff6ff);
}

.authority-alert.warning {
    border-color: rgba(245, 158, 11, 0.20);
    background: linear-gradient(180deg, #fffdf7, #fff7ed);
}

.authority-topbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
}

.authority-kicker,
.ai-kicker,
.gap-kicker {
    display: inline-flex;
    align-items: center;
    padding: 8px 14px;
    border-radius: 999px;
    font-size: 0.84rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    margin-bottom: 12px;
}

.authority-kicker {
    background: rgba(37, 99, 235, 0.10);
    color: #1d4ed8;
}

.ai-kicker {
    background: rgba(124, 58, 237, 0.10);
    color: #6d28d9;
}

.gap-kicker {
    background: rgba(245, 158, 11, 0.12);
    color: #b45309;
}

.authority-title {
    margin: 0 0 10px;
    font-size: clamp(2rem, 3vw, 3rem);
    line-height: 1.02;
    font-weight: 900;
    color: #0f172a;
}

.authority-subtitle {
    margin: 0;
    color: #64748b;
    line-height: 1.8;
    font-size: 1.05rem;
    max-width: 820px;
}

.authority-actions,
.ai-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.authority-btn,
.ai-btn {
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

.authority-btn:hover,
.ai-btn:hover {
    transform: translateY(-1px);
    text-decoration: none;
}

.authority-btn-primary,
.ai-btn-primary {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 14px 28px rgba(37, 99, 235, 0.20);
}

.authority-btn-primary:hover,
.ai-btn-primary:hover {
    color: #fff;
}

.authority-btn-secondary,
.ai-btn-secondary {
    background: #fff;
    color: #0f172a;
}

.authority-btn-secondary:hover,
.ai-btn-secondary:hover {
    color: #0f172a;
    border-color: rgba(37, 99, 235, 0.25);
    box-shadow: 0 12px 22px rgba(15, 23, 42, 0.06);
}

.meta-pills,
.status-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 22px;
}

.meta-pill {
    display: inline-flex;
    align-items: center;
    min-height: 38px;
    padding: 0 14px;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid rgba(15, 23, 42, 0.08);
    color: #0f172a;
    font-size: 0.93rem;
    font-weight: 700;
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

.status-rejected {
    background: rgba(220, 38, 38, 0.11);
    color: #b91c1c;
    border-color: rgba(220, 38, 38, 0.14);
}

.status-neutral {
    background: rgba(100, 116, 139, 0.11);
    color: #475569;
    border-color: rgba(100, 116, 139, 0.14);
}

.authority-layout {
    display: grid;
    grid-template-columns: 1.35fr 0.65fr;
    gap: 22px;
}

.authority-card,
.authority-side-card {
    padding: 24px;
    margin-bottom: 18px;
}

.authority-card h2,
.ai-card h2,
.gap-card h2 {
    margin: 0 0 12px;
    font-size: 1.22rem;
    font-weight: 900;
    color: #0f172a;
}

.authority-card p,
.ai-card p,
.gap-card p {
    color: #334155;
    line-height: 1.85;
    margin-bottom: 1rem;
}

.section-note {
    color: #64748b;
    font-style: italic;
}

.ai-note {
    border-left: 4px solid #8b5cf6;
    background: linear-gradient(180deg, #faf5ff, #f5f3ff);
    border-radius: 16px;
    padding: 16px 18px;
    color: #475569;
    line-height: 1.8;
    margin-top: 16px;
}

.ai-meta {
    color: #64748b;
    font-size: 0.93rem;
    line-height: 1.7;
}

.authority-side-card .side-label {
    font-size: 0.78rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 800;
    color: #64748b;
    margin-bottom: 10px;
}

.authority-side-card .side-value {
    color: #0f172a;
    font-weight: 900;
    font-size: 1.08rem;
    margin-bottom: 12px;
}

.authority-side-card .side-list {
    padding-left: 1rem;
    margin-bottom: 0;
}

.authority-side-card .side-list li {
    margin-bottom: 0.65rem;
    color: #475569;
    line-height: 1.65;
}

.authority-side-card .side-copy {
    color: #475569;
    line-height: 1.8;
}

.gap-score-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 16px;
    margin: 12px 0 18px;
}

.gap-score-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 96px;
    min-height: 44px;
    padding: 0 16px;
    border-radius: 999px;
    font-weight: 900;
    font-size: 1rem;
    background: #fff7ed;
    color: #b45309;
    border: 1px solid rgba(245, 158, 11, 0.18);
}

.gap-summary {
    color: #475569;
    line-height: 1.8;
}

.gap-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-top: 18px;
}

.gap-list-card {
    border: 1px solid rgba(15, 23, 42, 0.06);
    background: linear-gradient(180deg, #ffffff, #f8fbff);
    border-radius: 18px;
    padding: 18px;
}

.gap-list-card h3 {
    margin: 0 0 12px;
    font-size: 1rem;
    font-weight: 900;
    color: #0f172a;
}

.gap-list {
    padding-left: 1rem;
    margin: 0;
}

.gap-list li {
    margin-bottom: 0.8rem;
    color: #475569;
    line-height: 1.7;
}

.gap-priority {
    display: inline-block;
    margin-top: 4px;
    font-size: 0.8rem;
    font-weight: 800;
    color: #1d4ed8;
}

.gap-action-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-top: 8px;
    min-height: 36px;
    padding: 0 12px;
    border-radius: 10px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid rgba(37, 99, 235, 0.14);
    text-decoration: none;
    font-size: 0.86rem;
    font-weight: 800;
    transition: all 0.18s ease;
}

.gap-action-link:hover {
    text-decoration: none;
    color: #1d4ed8;
    transform: translateY(-1px);
    box-shadow: 0 10px 18px rgba(37, 99, 235, 0.08);
}

@media (max-width: 1024px) {
    .authority-layout,
    .gap-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767.98px) {
    .authority-shell {
        padding: 18px 0 42px;
    }

    .authority-wrap {
        padding: 0 12px;
    }

    .authority-alert,
    .authority-hero,
    .authority-card,
    .authority-side-card,
    .ai-card,
    .gap-card {
        border-radius: 20px;
        padding: 18px;
    }

    .authority-topbar {
        flex-direction: column;
    }

    .authority-actions,
    .ai-actions {
        width: 100%;
    }

    .authority-btn,
    .ai-btn {
        width: 100%;
    }
}
</style>

<main class="authority-shell">
    <div class="authority-wrap">

        <?php if (isset($_GET['profile_saved'])): ?>
            <section class="authority-alert info">
                Authority profile saved successfully.
            </section>
        <?php endif; ?>

        <?php if (isset($_GET['ai_summary_updated'])): ?>
            <section class="authority-alert info">
                AI summary updated successfully.
            </section>
        <?php endif; ?>

        <?php if ($isEditor && !$profileExists): ?>
            <section class="authority-alert info">
                This name does not yet have an authority profile. Create one to unlock full structured documentation.
            </section>
        <?php endif; ?>

        <?php if ($isEditor && $profileExists && $profileStatus !== 'published'): ?>
            <section class="authority-alert warning">
                This authority page is currently in <strong><?= e($profileStatus) ?></strong> state and is not publicly visible yet.
            </section>
        <?php endif; ?>

        <!-- Hero / above-the-fold authority header -->
        <section class="authority-hero">
            <div class="authority-topbar">
                <div>
                    <div class="authority-kicker">Name Authority Page</div>
                    <h1 class="authority-title"><?= e($name) ?></h1>
                    <p class="authority-subtitle">
                        <?= e($meaning !== '' ? $meaning : 'A documented Indigenous African personal name within the authority knowledge system.') ?>
                    </p>

                    <div class="meta-pills">
                        <?php if ($ethnicGroup !== ''): ?>
                            <span class="meta-pill"><?= e($ethnicGroup) ?></span>
                        <?php endif; ?>

                        <?php if ($region !== ''): ?>
                            <span class="meta-pill"><?= e($region) ?></span>
                        <?php endif; ?>

                        <?php if ($gender !== ''): ?>
                            <span class="meta-pill"><?= e($gender) ?></span>
                        <?php endif; ?>

                        <?php if ($isEditor && $entryStatus !== ''): ?>
                            <span class="<?= badgeClass($entryStatus) ?>">Entry: <?= e(ucfirst($entryStatus)) ?></span>
                        <?php endif; ?>

                        <?php if ($isEditor && $profileExists): ?>
                            <span class="<?= badgeClass($profileStatus) ?>">Profile: <?= e(ucfirst($profileStatus)) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isEditor): ?>
                    <div class="authority-actions">
                        <a href="edit-name-profile.php?id=<?= (int) $record['id'] ?>" class="authority-btn authority-btn-primary">
                            <?= $profileExists ? 'Edit Authority Page' : 'Create Authority Page' ?>
                        </a>
                        <a href="review-suggestions.php?name_id=<?= (int) $record['id'] ?>" class="authority-btn authority-btn-secondary">
                            Review Suggestions
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- AI summary card -->
        <section class="ai-card">
            <div class="authority-topbar">
                <div>
                    <div class="ai-kicker">AI-assisted editorial insight</div>
                    <h2>Draft AI Summary</h2>
                    <p class="ai-meta">Generated from current authority-page fields and base record context.</p>
                    <p class="mb-0">
                        This summary is an assistive draft generated from the currently documented record. It supports interpretation and editorial review, but it is not a source of truth and should never replace citations or cultural verification.
                    </p>
                </div>

                <?php if ($isEditor): ?>
                    <form method="post" class="ai-actions">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="ai_action" value="generate_ai_summary">
                        <button type="submit" class="ai-btn ai-btn-primary">
                            <?= $aiSummaryText !== '' ? 'Refresh AI Summary' : 'Generate AI Summary' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($aiSummaryText !== ''): ?>
                <div class="ai-note">
                    <?= nl2p($aiSummaryText) ?>
                </div>

                <div class="ai-meta mt-3">
                    Generated model: <?= e($aiSummary['model_name'] ?? 'internal-ai-draft-v1') ?>
                    <?php if (!empty($aiSummary['generated_at'])): ?>
                        · Generated: <?= e(date('F j, Y, g:i a', strtotime((string) $aiSummary['generated_at']))) ?>
                    <?php endif; ?>
                    <?php if ($aiSummaryOutdated): ?>
                        · <strong>Outdated:</strong> source content has changed since the last generation
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="section-note mb-0">
                    No AI draft summary has been generated for this record yet.
                </p>
            <?php endif; ?>
        </section>

        <!-- AI gap analysis is its own separate card -->
        <?php if ($isEditor): ?>
            <section class="gap-card">
                <div class="gap-kicker">AI gap analysis</div>
                <h2>Editorial Gap Analysis</h2>
                <p class="mb-0">
                    This panel highlights missing or weak sections in the current authority record and suggests the next strongest editorial improvements.
                </p>

                <div class="gap-score-row">
                    <div class="gap-score-pill">Score: <?= (int) $gapAnalysis['score'] ?>/100</div>
                    <div class="gap-summary"><?= e($gapAnalysis['summary']) ?></div>
                </div>

                <div class="gap-grid">
                    <div class="gap-list-card">
                        <h3>Missing Sections</h3>
                        <?php if (!empty($gapAnalysis['missing'])): ?>
                            <ul class="gap-list">
                                <?php foreach ($gapAnalysis['missing'] as $gap): ?>
                                    <li>
                                        <strong><?= e($gap['label']) ?></strong><br>
                                        <?= e($gap['advice']) ?><br>
                                        <span class="gap-priority">Priority: <?= e(ucfirst($gap['priority'])) ?></span><br>
                                        <a
                                            class="gap-action-link"
                                            href="edit-name-profile.php?id=<?= (int) $record['id'] ?>#<?= e(gapFieldAnchor((string) $gap['key'])) ?>"
                                        >
                                            Fix this section
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="section-note mb-0">No fully missing major sections were detected.</p>
                        <?php endif; ?>
                    </div>

                    <div class="gap-list-card">
                        <h3>Weak or Thin Sections</h3>
                        <?php if (!empty($gapAnalysis['weak'])): ?>
                            <ul class="gap-list">
                                <?php foreach ($gapAnalysis['weak'] as $gap): ?>
                                    <li>
                                        <strong><?= e($gap['label']) ?></strong><br>
                                        <?= e($gap['advice']) ?><br>
                                        <span class="gap-priority">Priority: <?= e(ucfirst($gap['priority'])) ?></span><br>
                                        <a
                                            class="gap-action-link"
                                            href="edit-name-profile.php?id=<?= (int) $record['id'] ?>#<?= e(gapFieldAnchor((string) $gap['key'])) ?>"
                                        >
                                            Improve this section
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="section-note mb-0">No thin high-value sections were detected.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($gapAnalysis['priority_actions'])): ?>
                    <div class="ai-note mt-4">
                        <strong>Recommended next editorial actions:</strong>
                        <ul class="gap-list mt-2">
                            <?php foreach ($gapAnalysis['priority_actions'] as $action): ?>
                                <li><?= e($action) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- Main content layout -->
        <div class="authority-layout">
            <div>
                <section class="authority-card">
                    <h2>Meaning</h2>
                    <?php if ($finalMeaningBlock !== ''): ?>
                        <?= nl2p($finalMeaningBlock) ?>
                    <?php else: ?>
                        <p class="section-note">This section has not yet been enriched by the editorial team.</p>
                    <?php endif; ?>
                </section>

                <section class="authority-card">
                    <h2>Origin and Background</h2>
                    <?php if ($finalOriginBlock !== ''): ?>
                        <?= nl2p($finalOriginBlock) ?>
                    <?php else: ?>
                        <p class="section-note">Origin details will appear here once the authority profile is expanded.</p>
                    <?php endif; ?>
                </section>

                <section class="authority-card">
                    <h2>Historical Context</h2>
                    <?php if ($historicalContext !== ''): ?>
                        <?= nl2p($historicalContext) ?>
                    <?php else: ?>
                        <p class="section-note">Historical context has not yet been documented for this name.</p>
                    <?php endif; ?>
                </section>

                <section class="authority-card">
                    <h2>Naming Context and Traditions</h2>
                    <?php if ($finalContextBlock !== ''): ?>
                        <?= nl2p($finalContextBlock) ?>
                    <?php else: ?>
                        <p class="section-note">Editorial naming-context notes will appear here when available.</p>
                    <?php endif; ?>
                </section>

                <section class="authority-card">
                    <h2>Cultural Significance</h2>
                    <?php if ($finalCulturalBlock !== ''): ?>
                        <?= nl2p($finalCulturalBlock) ?>
                    <?php else: ?>
                        <p class="section-note">Cultural significance notes have not yet been added.</p>
                    <?php endif; ?>
                </section>

                <section class="authority-card">
                    <h2>Variants and Related Forms</h2>
                    <?php if ($variants !== ''): ?>
                        <?= nl2p($variants) ?>
                    <?php else: ?>
                        <p class="section-note">Known variants or related forms have not yet been listed.</p>
                    <?php endif; ?>
                </section>

                <section class="authority-card">
                    <h2>Pronunciation Notes</h2>
                    <?php if ($pronunciationNotes !== ''): ?>
                        <?= nl2p($pronunciationNotes) ?>
                    <?php else: ?>
                        <p class="section-note">Pronunciation guidance has not yet been added.</p>
                    <?php endif; ?>
                </section>

                <section class="authority-card">
                    <h2>Sources and Documentation</h2>
                    <?php if ($finalSourcesBlock !== ''): ?>
                        <?= nl2p($finalSourcesBlock) ?>
                    <?php else: ?>
                        <p class="section-note">No sources have yet been attached to this authority page.</p>
                    <?php endif; ?>
                </section>

                <?php if ($isEditor): ?>
                    <section class="authority-card">
                        <h2>Editorial Notes</h2>
                        <?php if ($editorialNotes !== ''): ?>
                            <?= nl2p($editorialNotes) ?>
                        <?php else: ?>
                            <p class="section-note">Internal editorial notes are currently empty.</p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>

            <div>
                <aside class="authority-side-card">
                    <div class="side-label">Core Record</div>
                    <div class="side-value"><?= e($name) ?></div>

                    <ul class="side-list">
                        <li><strong>Ethnic Group:</strong> <?= e($ethnicGroup !== '' ? $ethnicGroup : 'Not yet recorded') ?></li>
                        <li><strong>Region:</strong> <?= e($region !== '' ? $region : 'Not yet recorded') ?></li>
                        <li><strong>Gender:</strong> <?= e($gender !== '' ? $gender : 'Not specified') ?></li>
                        <li><strong>Created By:</strong> <?= e(!empty($record['created_by']) ? 'Record #' . (string) $record['created_by'] : 'Unknown') ?></li>
                    </ul>
                </aside>

                <aside class="authority-side-card">
                    <div class="side-label">Verification Status</div>
                    <div class="status-pills mb-3">
                        <span class="<?= badgeClass($entryStatus !== '' ? $entryStatus : 'unknown') ?>">
                            Entry: <?= e(ucfirst($entryStatus !== '' ? $entryStatus : 'unknown')) ?>
                        </span>

                        <span class="<?= badgeClass($profileExists ? $profileStatus : 'draft') ?>">
                            Profile: <?= e($profileExists ? ucfirst($profileStatus) : 'Not created') ?>
                        </span>
                    </div>

                    <div class="side-copy">
                        <?= $profileExists && $profileStatus === 'published'
                            ? 'This authority page has completed the full editorial publication path.'
                            : 'This record is still moving through the editorial authority workflow.'
                        ?>
                    </div>
                </aside>

                <aside class="authority-side-card">
                    <div class="side-label">Authority Profile</div>
                    <div class="side-copy">
                        <?= $profileExists
                            ? 'A structured authority profile exists for this record and can continue to be enriched, reviewed, or published.'
                            : 'No authority profile has been created yet. Editors can open the authority editor to build the structured reference page.'
                        ?>
                    </div>
                </aside>

                <aside class="authority-side-card">
                    <div class="side-label">Editorial Guidance</div>
                    <ul class="side-list">
                        <li>This page serves as the structured authority surface for this name.</li>
                        <li>Editors should document sources, variants, context, and significance carefully.</li>
                        <li>Suggestions should strengthen the canonical record rather than create duplicates.</li>
                        <li>AI summaries are assistive drafts only and must not replace evidence-based editorial judgment.</li>
                    </ul>
                </aside>

                <?php if ($profileExists && !empty($record['profile_updated_at'])): ?>
                    <aside class="authority-side-card">
                        <div class="side-label">Latest Profile Update</div>
                        <div class="side-value">
                            <?= e(date('F j, Y, g:i a', strtotime((string) $record['profile_updated_at']))) ?>
                        </div>
                    </aside>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>