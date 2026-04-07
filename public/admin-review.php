<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['editor', 'admin']);

$pageTitle = 'Admin Review';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function statusBadgeClass(string $status): string
{
    return match ($status) {
        'approved', 'published' => 'status-badge status-success',
        'pending', 'draft' => 'status-badge status-warning',
        'rejected', 'archived' => 'status-badge status-danger',
        default => 'status-badge status-neutral',
    };
}

function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$allowedFilters = ['pending', 'approved', 'rejected', 'all'];
$statusFilter = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : 'pending';

if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $entryId = isset($_POST['entry_id']) ? (int) $_POST['entry_id'] : 0;
    $action = trim((string) ($_POST['entry_action'] ?? ''));

    if (!hash_equals($csrfToken, (string) $postedToken)) {
        $_SESSION['admin_review_flash'] = [
            'type' => 'danger',
            'message' => 'Security check failed. Please refresh the page and try again.',
        ];
        redirectTo('admin-review.php?status=' . urlencode($statusFilter));
    }

    if ($entryId <= 0) {
        $_SESSION['admin_review_flash'] = [
            'type' => 'danger',
            'message' => 'Invalid entry selected.',
        ];
        redirectTo('admin-review.php?status=' . urlencode($statusFilter));
    }

    if ($action === 'approve') {
        $stmt = $pdo->prepare("
            UPDATE name_entries
            SET status = 'approved', updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $entryId]);

        $_SESSION['admin_review_flash'] = [
            'type' => 'success',
            'message' => 'Entry approved successfully.',
        ];

        redirectTo('admin-review.php?status=approved');
    }

    if ($action === 'reject') {
        $stmt = $pdo->prepare("
            UPDATE name_entries
            SET status = 'rejected', updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $entryId]);

        $_SESSION['admin_review_flash'] = [
            'type' => 'warning',
            'message' => 'Entry rejected successfully.',
        ];

        redirectTo('admin-review.php?status=rejected');
    }
}

$flash = $_SESSION['admin_review_flash'] ?? null;
unset($_SESSION['admin_review_flash']);

$statusCounts = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];

$entries = [];
$pageError = null;

try {
    $countStmt = $pdo->query("
        SELECT status, COUNT(*) AS total
        FROM name_entries
        GROUP BY status
    ");
    $countRows = $countStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($countRows as $row) {
        $key = strtolower((string) ($row['status'] ?? ''));
        if (isset($statusCounts[$key])) {
            $statusCounts[$key] = (int) ($row['total'] ?? 0);
        }
    }

    $sql = "
        SELECT
            ne.id,
            ne.name,
            ne.meaning,
            ne.ethnic_group,
            ne.region,
            ne.gender,
            ne.status,
            ne.created_at,
            ne.created_by,
            np.id AS profile_id,
            np.profile_status
        FROM name_entries ne
        LEFT JOIN name_profiles np ON np.name_entry_id = ne.id
    ";

    $params = [];

    if ($statusFilter !== 'all') {
        $sql .= " WHERE ne.status = :status ";
        $params[':status'] = $statusFilter;
    }

    $sql .= "
        ORDER BY
            CASE ne.status
                WHEN 'pending' THEN 1
                WHEN 'approved' THEN 2
                WHEN 'rejected' THEN 3
                ELSE 4
            END,
            ne.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.review-shell {
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.10), transparent 26%),
        radial-gradient(circle at top left, rgba(16, 185, 129, 0.07), transparent 20%),
        linear-gradient(180deg, #f8fbff 0%, #f4f7fb 100%);
    min-height: 100vh;
    padding: 28px 0 56px;
}

.review-wrap {
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 14px;
}

.review-panel,
.queue-card,
.empty-card {
    background: rgba(255, 255, 255, 0.96);
    border: 1px solid rgba(15, 23, 42, 0.07);
    border-radius: 24px;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
}

.review-panel {
    padding: 28px;
    margin-bottom: 24px;
}

.review-topbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 24px;
}

.review-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(37, 99, 235, 0.10);
    color: #1d4ed8;
    font-size: 0.84rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    margin-bottom: 12px;
}

.review-title {
    margin: 0 0 8px;
    font-size: clamp(1.9rem, 3vw, 2.5rem);
    line-height: 1.08;
    font-weight: 900;
    color: #0f172a;
}

.review-subtitle {
    margin: 0;
    color: #64748b;
    line-height: 1.7;
    max-width: 760px;
}

.review-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.review-btn {
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

.review-btn:hover {
    transform: translateY(-1px);
    text-decoration: none;
}

.review-btn-primary {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 14px 28px rgba(37, 99, 235, 0.20);
}

.review-btn-primary:hover {
    color: #fff;
}

.review-btn-secondary {
    background: #fff;
    color: #0f172a;
}

.review-btn-secondary:hover {
    color: #0f172a;
    border-color: rgba(37, 99, 235, 0.25);
    box-shadow: 0 12px 22px rgba(15, 23, 42, 0.06);
}

.review-btn-success {
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 14px 28px rgba(22, 163, 74, 0.18);
}

.review-btn-success:hover {
    color: #fff;
}

.review-btn-danger {
    background: #fff5f5;
    color: #b91c1c;
    border-color: rgba(220, 38, 38, 0.18);
}

.review-btn-danger:hover {
    color: #991b1b;
    box-shadow: 0 12px 22px rgba(220, 38, 38, 0.08);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.stat-card {
    position: relative;
    overflow: hidden;
    background: linear-gradient(180deg, #ffffff, #f8fbff);
    border: 1px solid rgba(15, 23, 42, 0.07);
    border-radius: 20px;
    padding: 20px;
}

.stat-card::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 5px;
    border-radius: 20px;
    background: linear-gradient(180deg, #2563eb, #60a5fa);
}

.stat-card.pending::before {
    background: linear-gradient(180deg, #f59e0b, #fbbf24);
}

.stat-card.approved::before {
    background: linear-gradient(180deg, #16a34a, #4ade80);
}

.stat-card.rejected::before {
    background: linear-gradient(180deg, #dc2626, #f87171);
}

.stat-label {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #64748b;
    font-weight: 800;
    margin-bottom: 10px;
}

.stat-value {
    font-size: 2rem;
    line-height: 1;
    font-weight: 900;
    color: #0f172a;
    margin-bottom: 8px;
}

.stat-copy {
    color: #64748b;
    margin: 0;
    font-size: 0.95rem;
}

.filter-panel {
    background: rgba(255, 255, 255, 0.96);
    border: 1px solid rgba(15, 23, 42, 0.07);
    border-radius: 24px;
    box-shadow: 0 16px 34px rgba(15, 23, 42, 0.05);
    padding: 22px 24px;
    margin-bottom: 24px;
}

.filter-title {
    margin: 0 0 4px;
    font-size: 1.25rem;
    font-weight: 900;
    color: #0f172a;
}

.filter-copy {
    margin: 0 0 16px;
    color: #64748b;
}

.filter-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.filter-pill {
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
    transition: all 0.18s ease;
}

.filter-pill:hover {
    color: #0f172a;
    text-decoration: none;
    transform: translateY(-1px);
}

.filter-pill.active {
    background: #0f172a;
    color: #fff;
    border-color: #0f172a;
    box-shadow: 0 14px 24px rgba(15, 23, 42, 0.14);
}

.queue-grid {
    display: grid;
    gap: 18px;
}

.queue-card {
    padding: 22px;
}

.queue-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 16px;
}

.queue-title-block {
    min-width: 0;
}

.queue-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 0 12px;
    border-radius: 999px;
    font-size: 0.84rem;
    font-weight: 800;
    border: 1px solid transparent;
}

.status-success {
    background: rgba(22, 163, 74, 0.11);
    color: #15803d;
    border-color: rgba(22, 163, 74, 0.14);
}

.status-warning {
    background: rgba(245, 158, 11, 0.13);
    color: #b45309;
    border-color: rgba(245, 158, 11, 0.16);
}

.status-danger {
    background: rgba(220, 38, 38, 0.11);
    color: #b91c1c;
    border-color: rgba(220, 38, 38, 0.14);
}

.status-neutral {
    background: rgba(100, 116, 139, 0.11);
    color: #475569;
    border-color: rgba(100, 116, 139, 0.14);
}

.queue-name {
    margin: 0 0 6px;
    font-size: 1.4rem;
    line-height: 1.15;
    font-weight: 900;
    color: #0f172a;
}

.queue-meta {
    color: #64748b;
    font-size: 0.95rem;
}

.queue-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: flex-end;
}

.queue-summary {
    font-size: 1.04rem;
    line-height: 1.8;
    color: #334155;
    margin-bottom: 18px;
}

.queue-details {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.detail-card {
    border: 1px solid rgba(15, 23, 42, 0.06);
    background: linear-gradient(180deg, #ffffff, #f8fbff);
    border-radius: 16px;
    padding: 14px 15px;
}

.detail-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #64748b;
    font-weight: 800;
    margin-bottom: 6px;
}

.detail-value {
    color: #0f172a;
    font-weight: 800;
    line-height: 1.5;
}

.readiness-note {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
    padding: 8px 12px;
    border-radius: 12px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 0.9rem;
    font-weight: 700;
}

.empty-card {
    padding: 38px 24px;
    text-align: center;
}

.empty-title {
    margin: 0 0 8px;
    font-size: 1.3rem;
    font-weight: 900;
    color: #0f172a;
}

.empty-copy {
    margin: 0;
    color: #64748b;
    line-height: 1.7;
}

@media (max-width: 991.98px) {
    .stats-grid,
    .queue-details {
        grid-template-columns: 1fr;
    }

    .queue-head {
        flex-direction: column;
    }

    .queue-actions {
        justify-content: flex-start;
    }
}

@media (max-width: 767.98px) {
    .review-shell {
        padding: 18px 0 42px;
    }

    .review-wrap {
        padding: 0 12px;
    }

    .review-panel,
    .filter-panel,
    .queue-card,
    .empty-card {
        border-radius: 20px;
        padding: 18px;
    }

    .review-topbar {
        flex-direction: column;
    }

    .review-actions,
    .queue-actions {
        width: 100%;
    }

    .review-btn {
        width: 100%;
    }

    .filter-pills {
        gap: 8px;
    }

    .filter-pill {
        flex: 1 1 calc(50% - 8px);
    }
}
</style>

<main class="review-shell">
    <div class="review-wrap">

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type'] ?? 'info') ?> rounded-4 shadow-sm mb-4">
                <?= e($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <section class="review-panel">
            <div class="review-topbar">
                <div>
                    <div class="review-kicker">Editorial Review Console</div>
                    <h1 class="review-title">Admin Review</h1>
                    <p class="review-subtitle">
                        Review submitted names, approve or reject base records, and move approved records into structured authority development.
                    </p>
                </div>

                <div class="review-actions">
                    <a href="dashboard.php" class="review-btn review-btn-secondary">Back to Dashboard</a>
                    <a href="merge-history.php" class="review-btn review-btn-primary">Merge History</a>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card pending">
                    <div class="stat-label">Pending Entries</div>
                    <div class="stat-value"><?= (int) $statusCounts['pending'] ?></div>
                    <p class="stat-copy">Awaiting editorial review or first-pass approval.</p>
                </div>

                <div class="stat-card approved">
                    <div class="stat-label">Approved Entries</div>
                    <div class="stat-value"><?= (int) $statusCounts['approved'] ?></div>
                    <p class="stat-copy">Eligible for authority-page enrichment and publication workflow.</p>
                </div>

                <div class="stat-card rejected">
                    <div class="stat-label">Rejected Entries</div>
                    <div class="stat-value"><?= (int) $statusCounts['rejected'] ?></div>
                    <p class="stat-copy">Set aside due to duplication, insufficient evidence, or quality issues.</p>
                </div>
            </div>
        </section>

        <section class="filter-panel">
            <h2 class="filter-title">Filter by Review Status</h2>
            <p class="filter-copy">Switch between queues and focus the editorial workflow.</p>

            <div class="filter-pills">
                <a class="filter-pill <?= $statusFilter === 'pending' ? 'active' : '' ?>" href="admin-review.php?status=pending">Pending</a>
                <a class="filter-pill <?= $statusFilter === 'approved' ? 'active' : '' ?>" href="admin-review.php?status=approved">Approved</a>
                <a class="filter-pill <?= $statusFilter === 'rejected' ? 'active' : '' ?>" href="admin-review.php?status=rejected">Rejected</a>
                <a class="filter-pill <?= $statusFilter === 'all' ? 'active' : '' ?>" href="admin-review.php?status=all">All</a>
            </div>
        </section>

        <?php if ($pageError !== null): ?>
            <section class="empty-card">
                <h2 class="empty-title">Review queue unavailable</h2>
                <p class="empty-copy"><?= e($pageError) ?></p>
            </section>
        <?php elseif (!$entries): ?>
            <section class="empty-card">
                <h2 class="empty-title">No entries found</h2>
                <p class="empty-copy">There are no name entries in this review state right now.</p>
            </section>
        <?php else: ?>
            <section class="queue-grid">
                <?php foreach ($entries as $entry): ?>
                    <?php
                    $entryId = (int) ($entry['id'] ?? 0);
                    $entryStatusValue = trim((string) ($entry['status'] ?? ''));
                    $profileExists = !empty($entry['profile_id']);
                    $profileStatus = trim((string) ($entry['profile_status'] ?? ''));
                    $createdAt = !empty($entry['created_at']) ? date('F j, Y, g:i a', strtotime((string) $entry['created_at'])) : 'Unknown date';
                    ?>
                    <article class="queue-card">
                        <div class="queue-head">
                            <div class="queue-title-block">
                                <div class="queue-badges">
                                    <span class="<?= statusBadgeClass($entryStatusValue) ?>">
                                        Entry: <?= e(ucfirst($entryStatusValue !== '' ? $entryStatusValue : 'unknown')) ?>
                                    </span>

                                    <span class="<?= statusBadgeClass($profileExists ? $profileStatus : 'draft') ?>">
                                        Profile: <?= e($profileExists ? ucfirst($profileStatus) : 'Not created') ?>
                                    </span>
                                </div>

                                <h3 class="queue-name"><?= e($entry['name'] ?? 'Untitled Name') ?></h3>

                                <div class="queue-meta">
                                    Submitted record #<?= (int) ($entry['created_by'] ?? 0) ?> · <?= e($createdAt) ?>
                                </div>
                            </div>

                            <div class="queue-actions">
                                <a href="name.php?id=<?= $entryId ?>" class="review-btn review-btn-secondary">View Page</a>

                                <a href="edit-name-profile.php?id=<?= $entryId ?>" class="review-btn review-btn-primary">
                                    <?= $profileExists ? 'Edit Authority Page' : 'Create Authority Page' ?>
                                </a>

                                <a href="review-suggestions.php?name_id=<?= $entryId ?>" class="review-btn review-btn-secondary">Review Suggestions</a>

                                <?php if ($entryStatusValue === 'pending'): ?>
                                    <form method="post" style="display:inline-flex;">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="entry_id" value="<?= $entryId ?>">
                                        <button type="submit" name="entry_action" value="approve" class="review-btn review-btn-success">
                                            Approve Entry
                                        </button>
                                    </form>

                                    <form method="post" style="display:inline-flex;">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="entry_id" value="<?= $entryId ?>">
                                        <button type="submit" name="entry_action" value="reject" class="review-btn review-btn-danger">
                                            Reject Entry
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($entry['meaning'])): ?>
                            <div class="queue-summary">
                                <?= e($entry['meaning']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="queue-details">
                            <div class="detail-card">
                                <div class="detail-label">Ethnic Group</div>
                                <div class="detail-value"><?= e($entry['ethnic_group'] ?? 'Not recorded') ?></div>
                            </div>

                            <div class="detail-card">
                                <div class="detail-label">Region</div>
                                <div class="detail-value"><?= e($entry['region'] ?? 'Not recorded') ?></div>
                            </div>

                            <div class="detail-card">
                                <div class="detail-label">Gender</div>
                                <div class="detail-value"><?= e($entry['gender'] ?? 'Not specified') ?></div>
                            </div>
                        </div>

                        <?php if ($entryStatusValue === 'approved' && !$profileExists): ?>
                            <div class="readiness-note">
                                Ready for authority-page creation.
                            </div>
                        <?php elseif ($entryStatusValue === 'approved' && $profileExists && $profileStatus === 'draft'): ?>
                            <div class="readiness-note">
                                Authority page exists in draft and can be refined for publication.
                            </div>
                        <?php elseif ($entryStatusValue === 'approved' && $profileExists && $profileStatus === 'published'): ?>
                            <div class="readiness-note">
                                This record is fully approved and publicly publishable.
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>