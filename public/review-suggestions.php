<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['editor', 'admin']);

$user = currentUser();
$pageTitle = 'Review Suggestions';

function e(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function badgeClass(string $status): string
{
    return match ($status) {
        'approved' => 'bg-success-subtle text-success-emphasis',
        'pending' => 'bg-warning-subtle text-warning-emphasis',
        'rejected' => 'bg-danger-subtle text-danger-emphasis',
        default => 'bg-secondary-subtle text-secondary-emphasis',
    };
}

function suggestionLabel(string $type): string
{
    return match ($type) {
        'meaning' => 'Meaning',
        'cultural_explanation' => 'Cultural Explanation',
        'sources' => 'Sources',
        'authority_enrichment' => 'Authority Enrichment',
        default => ucwords(str_replace('_', ' ', $type)),
    };
}

function renderBlock(string $label, ?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    return '
        <div class="suggestion-block">
            <div class="suggestion-block-label">' . e($label) . '</div>
            <div class="suggestion-block-value">' . nl2br(e($value)) . '</div>
        </div>
    ';
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
    redirectTo('admin-review.php');
}

$entryStmt = $pdo->prepare("
    SELECT id, name, meaning, ethnic_group, region, gender, status
    FROM name_entries
    WHERE id = :id
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
            <p class="text-muted mb-0">The name entry you want to review suggestions for could not be found.</p>
        </section>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$flash = $_SESSION['review_suggestions_flash'] ?? null;
unset($_SESSION['review_suggestions_flash']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $suggestionId = isset($_POST['suggestion_id']) ? (int) $_POST['suggestion_id'] : 0;
    $action = trim((string) ($_POST['action'] ?? ''));
    $reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));

    if (!hash_equals($csrfToken, (string) $postedToken)) {
        $errors[] = 'Security check failed. Please refresh the page and try again.';
    }

    if ($suggestionId <= 0) {
        $errors[] = 'Invalid suggestion selected.';
    }

    if (!in_array($action, ['approve', 'reject'], true)) {
        $errors[] = 'Invalid review action.';
    }

    if (!$errors) {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        $updateStmt = $pdo->prepare("
            UPDATE name_suggestions
            SET
                status = :status,
                reviewed_by = :reviewed_by,
                reviewed_at = NOW(),
                review_notes = :review_notes
            WHERE id = :id
              AND entry_id = :entry_id
            LIMIT 1
        ");

        $updateStmt->execute([
            ':status' => $newStatus,
            ':reviewed_by' => (int) ($user['id'] ?? 0),
            ':review_notes' => $reviewNotes !== '' ? $reviewNotes : null,
            ':id' => $suggestionId,
            ':entry_id' => $entryId,
        ]);

        $_SESSION['review_suggestions_flash'] = [
            'type' => $newStatus === 'approved' ? 'success' : 'warning',
            'message' => $newStatus === 'approved'
                ? 'Suggestion approved successfully.'
                : 'Suggestion rejected successfully.',
        ];

        redirectTo('review-suggestions.php?name_id=' . $entryId);
    }
}

$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : 'pending';
$allowedFilters = ['pending', 'approved', 'rejected', 'all'];

if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'pending';
}

$countStmt = $pdo->prepare("
    SELECT status, COUNT(*) AS total
    FROM name_suggestions
    WHERE entry_id = :entry_id
    GROUP BY status
");
$countStmt->execute([':entry_id' => $entryId]);
$countRows = $countStmt->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];

foreach ($countRows as $row) {
    $statusKey = strtolower((string) ($row['status'] ?? ''));
    if (isset($statusCounts[$statusKey])) {
        $statusCounts[$statusKey] = (int) ($row['total'] ?? 0);
    }
}

$sql = "
    SELECT *
    FROM name_suggestions
    WHERE entry_id = :entry_id
";

$params = [':entry_id' => $entryId];

if ($statusFilter !== 'all') {
    $sql .= " AND status = :status ";
    $params[':status'] = $statusFilter;
}

$sql .= " ORDER BY created_at DESC, id DESC ";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.review-suggestions-shell {
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.10), transparent 28%),
        linear-gradient(180deg, #f8fbff 0%, #f4f7fb 100%);
    min-height: 100vh;
    padding: 28px 0 56px;
}

.review-suggestions-wrap {
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 14px;
}

.rs-panel,
.rs-filter-panel,
.rs-card,
.rs-empty-card {
    background: rgba(255, 255, 255, 0.96);
    border: 1px solid rgba(15, 23, 42, 0.07);
    border-radius: 24px;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
}

.rs-panel {
    padding: 28px;
    margin-bottom: 24px;
}

.rs-topbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 24px;
}

.rs-kicker {
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

.rs-title {
    margin: 0 0 8px;
    font-size: clamp(1.8rem, 3vw, 2.35rem);
    line-height: 1.08;
    font-weight: 900;
    color: #0f172a;
}

.rs-subtitle {
    margin: 0;
    color: #64748b;
    line-height: 1.7;
    max-width: 760px;
}

.rs-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.rs-btn {
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

.rs-btn:hover {
    transform: translateY(-1px);
    text-decoration: none;
}

.rs-btn-primary {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 14px 28px rgba(37, 99, 235, 0.20);
}

.rs-btn-primary:hover {
    color: #fff;
}

.rs-btn-secondary {
    background: #fff;
    color: #0f172a;
}

.rs-btn-secondary:hover {
    color: #0f172a;
    border-color: rgba(37, 99, 235, 0.25);
    box-shadow: 0 12px 22px rgba(15, 23, 42, 0.06);
}

.rs-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.rs-stat {
    border: 1px solid rgba(15, 23, 42, 0.07);
    border-radius: 20px;
    padding: 20px;
    background: linear-gradient(180deg, #ffffff, #f8fbff);
}

.rs-stat-label {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #64748b;
    font-weight: 800;
    margin-bottom: 10px;
}

.rs-stat-value {
    font-size: 2rem;
    line-height: 1;
    font-weight: 900;
    color: #0f172a;
    margin-bottom: 8px;
}

.rs-stat-copy {
    color: #64748b;
    margin: 0;
    font-size: 0.95rem;
}

.rs-filter-panel {
    padding: 22px 24px;
    margin-bottom: 24px;
}

.rs-filter-title {
    margin: 0 0 4px;
    font-size: 1.25rem;
    font-weight: 900;
    color: #0f172a;
}

.rs-filter-copy {
    margin: 0 0 16px;
    color: #64748b;
}

.rs-filter-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.rs-filter-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    padding: 0 18px;
    border-radius: 999px;
    text-decoration: none;
    font-weight: 800;
    border: 1px solid rgba(15, 23, 42, 0.09);
    background: #fff;
    color: #334155;
}

.rs-filter-pill.active {
    background: #0f172a;
    color: #fff;
    border-color: #0f172a;
}

.rs-grid {
    display: grid;
    gap: 18px;
}

.rs-card {
    padding: 22px;
}

.rs-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 16px;
}

.rs-card-title {
    margin: 0 0 8px;
    font-size: 1.25rem;
    line-height: 1.15;
    font-weight: 900;
    color: #0f172a;
}

.rs-meta {
    color: #64748b;
    font-size: 0.95rem;
}

.rs-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 10px;
}

.suggestion-block {
    border: 1px solid rgba(15, 23, 42, 0.06);
    background: linear-gradient(180deg, #ffffff, #f8fbff);
    border-radius: 16px;
    padding: 14px 15px;
    margin-bottom: 12px;
}

.suggestion-block-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #64748b;
    font-weight: 800;
    margin-bottom: 6px;
}

.suggestion-block-value {
    color: #0f172a;
    line-height: 1.75;
    white-space: normal;
    word-break: break-word;
}

.rs-review-form {
    margin-top: 14px;
    border-top: 1px solid rgba(15, 23, 42, 0.07);
    padding-top: 16px;
}

.rs-review-form label {
    display: block;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 8px;
}

.rs-review-form textarea {
    width: 100%;
    min-height: 110px;
    padding: 12px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 14px;
    resize: vertical;
    margin-bottom: 12px;
}

.rs-review-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.rs-empty-card {
    padding: 38px 24px;
    text-align: center;
}

.rs-empty-title {
    margin: 0 0 8px;
    font-size: 1.3rem;
    font-weight: 900;
    color: #0f172a;
}

.rs-empty-copy {
    margin: 0;
    color: #64748b;
    line-height: 1.7;
}

@media (max-width: 991.98px) {
    .rs-stats {
        grid-template-columns: 1fr;
    }

    .rs-card-head {
        flex-direction: column;
    }
}

@media (max-width: 767.98px) {
    .review-suggestions-shell {
        padding: 18px 0 42px;
    }

    .review-suggestions-wrap {
        padding: 0 12px;
    }

    .rs-panel,
    .rs-filter-panel,
    .rs-card,
    .rs-empty-card {
        border-radius: 20px;
        padding: 18px;
    }

    .rs-topbar {
        flex-direction: column;
    }

    .rs-actions,
    .rs-review-actions {
        width: 100%;
    }

    .rs-btn {
        width: 100%;
    }

    .rs-filter-pill {
        flex: 1 1 calc(50% - 8px);
    }
}
</style>

<main class="review-suggestions-shell">
    <div class="review-suggestions-wrap">

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type'] ?? 'info') ?> rounded-4 shadow-sm mb-4">
                <?= e($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger rounded-4 shadow-sm mb-4">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="rs-panel">
            <div class="rs-topbar">
                <div>
                    <div class="rs-kicker">Suggestion Review Queue</div>
                    <h1 class="rs-title">Review Suggestions for <?= e($entry['name'] ?? 'Entry') ?></h1>
                    <p class="rs-subtitle">
                        Evaluate community improvements, approve or reject them, and route approved changes into your editorial merge workflow.
                    </p>
                </div>

                <div class="rs-actions">
                    <a href="name.php?id=<?= (int) $entryId ?>" class="rs-btn rs-btn-secondary">View Authority Page</a>
                    <a href="admin-review.php" class="rs-btn rs-btn-primary">Back to Review</a>
                </div>
            </div>

            <div class="rs-stats">
                <div class="rs-stat">
                    <div class="rs-stat-label">Pending Suggestions</div>
                    <div class="rs-stat-value"><?= (int) $statusCounts['pending'] ?></div>
                    <p class="rs-stat-copy">Awaiting editorial review and field-by-field consideration.</p>
                </div>

                <div class="rs-stat">
                    <div class="rs-stat-label">Approved Suggestions</div>
                    <div class="rs-stat-value"><?= (int) $statusCounts['approved'] ?></div>
                    <p class="rs-stat-copy">Ready for merge preview, editorial integration, or archive tracking.</p>
                </div>

                <div class="rs-stat">
                    <div class="rs-stat-label">Rejected Suggestions</div>
                    <div class="rs-stat-value"><?= (int) $statusCounts['rejected'] ?></div>
                    <p class="rs-stat-copy">Set aside because of duplication, weak evidence, or editorial mismatch.</p>
                </div>
            </div>
        </section>

        <section class="rs-filter-panel">
            <h2 class="rs-filter-title">Filter by Suggestion Status</h2>
            <p class="rs-filter-copy">Focus the queue by current editorial state.</p>

            <div class="rs-filter-pills">
                <a class="rs-filter-pill <?= $statusFilter === 'pending' ? 'active' : '' ?>" href="review-suggestions.php?name_id=<?= $entryId ?>&status=pending">Pending</a>
                <a class="rs-filter-pill <?= $statusFilter === 'approved' ? 'active' : '' ?>" href="review-suggestions.php?name_id=<?= $entryId ?>&status=approved">Approved</a>
                <a class="rs-filter-pill <?= $statusFilter === 'rejected' ? 'active' : '' ?>" href="review-suggestions.php?name_id=<?= $entryId ?>&status=rejected">Rejected</a>
                <a class="rs-filter-pill <?= $statusFilter === 'all' ? 'active' : '' ?>" href="review-suggestions.php?name_id=<?= $entryId ?>&status=all">All</a>
            </div>
        </section>

        <?php if (!$suggestions): ?>
            <section class="rs-empty-card">
                <h2 class="rs-empty-title">No suggestions found</h2>
                <p class="rs-empty-copy">There are no suggestions for this entry in the selected review state.</p>
            </section>
        <?php else: ?>
            <section class="rs-grid">
                <?php foreach ($suggestions as $suggestion): ?>
                    <article class="rs-card">
                        <div class="rs-card-head">
                            <div>
                                <div class="rs-badges">
                                    <span class="badge rounded-pill px-3 py-2 <?= badgeClass((string) ($suggestion['status'] ?? 'pending')) ?>">
                                        <?= e(ucfirst((string) ($suggestion['status'] ?? 'pending'))) ?>
                                    </span>

                                    <span class="badge rounded-pill px-3 py-2 bg-primary-subtle text-primary-emphasis">
                                        <?= e(suggestionLabel((string) ($suggestion['suggestion_type'] ?? 'general'))) ?>
                                    </span>
                                </div>

                                <h3 class="rs-card-title">Suggestion #<?= (int) ($suggestion['id'] ?? 0) ?></h3>

                                <div class="rs-meta">
                                    Suggested by record #<?= (int) ($suggestion['suggested_by'] ?? 0) ?>
                                    <?php if (!empty($suggestion['created_at'])): ?>
                                        · <?= e(date('F j, Y, g:i a', strtotime((string) $suggestion['created_at']))) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($suggestion['reviewed_at'])): ?>
                                        · Reviewed <?= e(date('F j, Y, g:i a', strtotime((string) $suggestion['reviewed_at']))) ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (($suggestion['status'] ?? '') === 'approved'): ?>
                                <div class="rs-actions">
                                    <a href="merge-preview.php?suggestion_id=<?= (int) $suggestion['id'] ?>" class="rs-btn rs-btn-primary">Open Merge Preview</a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?= renderBlock('Proposed Meaning', $suggestion['proposed_meaning'] ?? null) ?>
                        <?= renderBlock('Proposed Naming Context', $suggestion['proposed_naming_context'] ?? null) ?>
                        <?= renderBlock('Proposed Cultural Explanation', $suggestion['proposed_cultural_explanation'] ?? null) ?>
                        <?= renderBlock('Proposed Sources', $suggestion['proposed_sources'] ?? null) ?>
                        <?= renderBlock('Proposed Overview', $suggestion['proposed_overview'] ?? null) ?>
                        <?= renderBlock('Proposed Linguistic Origin', $suggestion['proposed_linguistic_origin'] ?? null) ?>
                        <?= renderBlock('Proposed Cultural Significance', $suggestion['proposed_cultural_significance'] ?? null) ?>
                        <?= renderBlock('Proposed Historical Context', $suggestion['proposed_historical_context'] ?? null) ?>
                        <?= renderBlock('Proposed Variants', $suggestion['proposed_variants'] ?? null) ?>
                        <?= renderBlock('Proposed Pronunciation', $suggestion['proposed_pronunciation'] ?? null) ?>
                        <?= renderBlock('Proposed Related Names', $suggestion['proposed_related_names'] ?? null) ?>
                        <?= renderBlock('Proposed Scholarly Notes', $suggestion['proposed_scholarly_notes'] ?? null) ?>
                        <?= renderBlock('Proposed References Text', $suggestion['proposed_references_text'] ?? null) ?>
                        <?= renderBlock('Contributor Notes', $suggestion['contributor_notes'] ?? null) ?>
                        <?= renderBlock('Review Notes', $suggestion['review_notes'] ?? null) ?>

                        <?php if (($suggestion['status'] ?? '') === 'pending'): ?>
                            <form method="post" action="" class="rs-review-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="suggestion_id" value="<?= (int) $suggestion['id'] ?>">

                                <label for="review_notes_<?= (int) $suggestion['id'] ?>">Review Notes</label>
                                <textarea
                                    name="review_notes"
                                    id="review_notes_<?= (int) $suggestion['id'] ?>"
                                    placeholder="Add an editorial note explaining your decision..."
                                ></textarea>

                                <div class="rs-review-actions">
                                    <button type="submit" name="action" value="approve" class="rs-btn rs-btn-primary">
                                        Approve Suggestion
                                    </button>
                                    <button type="submit" name="action" value="reject" class="rs-btn rs-btn-secondary">
                                        Reject Suggestion
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>