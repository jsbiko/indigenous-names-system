<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['editor', 'admin']);

$pageTitle = 'Merge History';
$bodyClass = 'dashboard-page';

function e(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function formatDate(?string $dt): string {
    if (!$dt) return '—';
    try {
        return (new DateTime($dt))->format('M j, Y • H:i');
    } catch (Throwable $e) {
        return $dt;
    }
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name = :t
        ");
        $stmt->execute(['t' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function columnExists(PDO $pdo, string $table, string $col): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
            AND table_name = :t
            AND column_name = :c
        ");
        $stmt->execute(['t' => $table, 'c' => $col]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if (!tableExists($pdo, 'suggestion_merge_logs')) {
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="dashboard-shell">
        <section class="panel">
            <div class="panel__body">
                <div class="empty-state">Merge history table not found.</div>
            </div>
        </section>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/**
 * Detect optional columns safely
 */
$hasFullName = columnExists($pdo, 'users', 'full_name');

/**
 * Filters
 */
$q = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(
        ne.name LIKE :q
        OR sml.field_name LIKE :q
        OR sml.old_value LIKE :q
        OR sml.new_value LIKE :q
        " . ($hasFullName ? "OR u.full_name LIKE :q" : "OR u.email LIKE :q") . "
    )";
    $params['q'] = "%{$q}%";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        sml.id,
        sml.entry_id,
        sml.field_name,
        sml.old_value,
        sml.new_value,
        sml.created_at,
        ne.name AS entry_name,
        " . ($hasFullName
            ? "COALESCE(u.full_name, u.email, 'Unknown')"
            : "COALESCE(u.email, 'Unknown')"
        ) . " AS editor_name
    FROM suggestion_merge_logs sml
    LEFT JOIN name_entries ne ON ne.id = sml.entry_id
    LEFT JOIN users u ON u.id = sml.merged_by
    {$whereSql}
    ORDER BY sml.created_at DESC
    LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-shell">

    <!-- HERO -->
    <section class="dashboard-hero">
        <div class="dashboard-hero__grid">
            <div>
                <div class="dashboard-hero__eyebrow">Editorial Governance</div>
                <h1>Merge History</h1>
                <p>Transparent record of all editorial merges into authority profiles.</p>

                <div class="quick-actions">
                    <a href="/dashboard.php" class="quick-action-btn quick-action-btn--primary">Dashboard</a>
                    <a href="/review-suggestions.php" class="quick-action-btn quick-action-btn--secondary">Suggestions</a>
                </div>
            </div>
        </div>
    </section>

    <!-- FILTER -->
    <section class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Search History</h2>
        </div>

        <div class="panel__body">
            <form method="get" class="submission-form">
                <div class="form-group">
                    <label>Search (name, field, editor, content)</label>
                    <input type="text" name="q" value="<?= e($q) ?>">
                </div>

                <div class="form-actions">
                    <button class="btn-primary">Search</button>
                    <a href="/merge-history.php" class="btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </section>

    <!-- RESULTS -->
    <section class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Recent Changes</h2>
            <div class="panel__subtle"><?= count($logs) ?> records</div>
        </div>

        <div class="panel__body">

            <?php if (!$logs): ?>
                <div class="empty-state">No merge history found.</div>
            <?php else: ?>

                <div class="activity-list">

                    <?php foreach ($logs as $log): ?>

                        <div class="activity-item">

                            <div class="activity-item__top">
                                <div class="activity-item__title">
                                    <?= e($log['entry_name'] ?? 'Unknown Entry') ?>
                                </div>
                                <span class="status-pill status--approved">merged</span>
                            </div>

                            <div class="activity-item__meta">
                                <strong>Field:</strong> <?= e(ucwords(str_replace('_', ' ', $log['field_name']))) ?><br>
                                <strong>Editor:</strong> <?= e($log['editor_name']) ?><br>
                                <strong>Date:</strong> <?= e(formatDate($log['created_at'])) ?><br>
                                <a href="/name.php?id=<?= (int)$log['entry_id'] ?>">View Name</a>
                            </div>

                            <div class="authority-content" style="margin-top:10px;">
                                <strong>Before:</strong><br>
                                <div class="section-note"><?= e(substr((string)$log['old_value'], 0, 300)) ?></div>

                                <strong>After:</strong><br>
                                <div class="section-note"><?= e(substr((string)$log['new_value'], 0, 300)) ?></div>
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>
    </section>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>