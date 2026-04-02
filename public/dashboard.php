<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$user = currentUser();
$role = $user['role'] ?? 'contributor';
$userId = (int)($user['id'] ?? 0);

$pageTitle = 'Dashboard | Indigenous African Names System';

$stats = [
    'total_users' => 0,
    'contributors' => 0,
    'editors' => 0,
    'admins' => 0,

    'total_entries' => 0,
    'approved_entries' => 0,
    'pending_entries' => 0,
    'rejected_entries' => 0,

    'total_suggestions' => 0,
    'pending_suggestions' => 0,
    'approved_suggestions' => 0,
    'rejected_suggestions' => 0,

    'total_merges' => 0,
    'total_rollbacks' => 0,

    'my_entries' => 0,
    'my_pending_entries' => 0,
    'my_approved_entries' => 0,
    'my_suggestions' => 0,
    'my_pending_suggestions' => 0,
    'my_approved_suggestions' => 0,
];

$recentEntries = [];
$recentSuggestions = [];
$recentMergeActivity = [];
$myRecentEntries = [];
$myRecentSuggestions = [];

/* ------------------------------------------------- */
/* Contributor-specific data */
/* ------------------------------------------------- */
if ($role === 'contributor') {
    $myEntryStatsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS my_entries,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS my_pending_entries,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS my_approved_entries
        FROM name_entries
        WHERE created_by = :user_id
    ");
    $myEntryStatsStmt->execute([':user_id' => $userId]);
    $myEntryStats = $myEntryStatsStmt->fetch();

    $mySuggestionStatsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS my_suggestions,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS my_pending_suggestions,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS my_approved_suggestions
        FROM name_suggestions
        WHERE suggested_by = :user_id
    ");
    $mySuggestionStatsStmt->execute([':user_id' => $userId]);
    $mySuggestionStats = $mySuggestionStatsStmt->fetch();

    $stats['my_entries'] = (int)($myEntryStats['my_entries'] ?? 0);
    $stats['my_pending_entries'] = (int)($myEntryStats['my_pending_entries'] ?? 0);
    $stats['my_approved_entries'] = (int)($myEntryStats['my_approved_entries'] ?? 0);

    $stats['my_suggestions'] = (int)($mySuggestionStats['my_suggestions'] ?? 0);
    $stats['my_pending_suggestions'] = (int)($mySuggestionStats['my_pending_suggestions'] ?? 0);
    $stats['my_approved_suggestions'] = (int)($mySuggestionStats['my_approved_suggestions'] ?? 0);

    $myRecentEntriesStmt = $pdo->prepare("
        SELECT id, name, ethnic_group, status, created_at
        FROM name_entries
        WHERE created_by = :user_id
        ORDER BY created_at DESC
        LIMIT 6
    ");
    $myRecentEntriesStmt->execute([':user_id' => $userId]);
    $myRecentEntries = $myRecentEntriesStmt->fetchAll();

    $myRecentSuggestionsStmt = $pdo->prepare("
        SELECT
            ns.id,
            ns.entry_id,
            ns.status,
            ns.created_at,
            ne.name,
            ne.ethnic_group
        FROM name_suggestions ns
        INNER JOIN name_entries ne ON ns.entry_id = ne.id
        WHERE ns.suggested_by = :user_id
        ORDER BY ns.created_at DESC
        LIMIT 6
    ");
    $myRecentSuggestionsStmt->execute([':user_id' => $userId]);
    $myRecentSuggestions = $myRecentSuggestionsStmt->fetchAll();
}

/* ------------------------------------------------- */
/* Editor/Admin shared editorial data */
/* ------------------------------------------------- */
if (in_array($role, ['editor', 'admin'], true)) {
    $entryStatsStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_entries,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_entries,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_entries,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_entries
        FROM name_entries
    ");
    $entryStats = $entryStatsStmt->fetch();

    $suggestionStatsStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_suggestions,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_suggestions,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_suggestions,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_suggestions
        FROM name_suggestions
    ");
    $suggestionStats = $suggestionStatsStmt->fetch();

    $mergeStatsStmt = $pdo->query("
        SELECT
            SUM(CASE WHEN merge_status = 'merged' AND action_type = 'merge' THEN 1 ELSE 0 END) AS total_merges,
            SUM(CASE WHEN action_type = 'rollback' THEN 1 ELSE 0 END) AS total_rollbacks
        FROM suggestion_merge_logs
    ");
    $mergeStats = $mergeStatsStmt->fetch();

    $stats['total_entries'] = (int)($entryStats['total_entries'] ?? 0);
    $stats['approved_entries'] = (int)($entryStats['approved_entries'] ?? 0);
    $stats['pending_entries'] = (int)($entryStats['pending_entries'] ?? 0);
    $stats['rejected_entries'] = (int)($entryStats['rejected_entries'] ?? 0);

    $stats['total_suggestions'] = (int)($suggestionStats['total_suggestions'] ?? 0);
    $stats['pending_suggestions'] = (int)($suggestionStats['pending_suggestions'] ?? 0);
    $stats['approved_suggestions'] = (int)($suggestionStats['approved_suggestions'] ?? 0);
    $stats['rejected_suggestions'] = (int)($suggestionStats['rejected_suggestions'] ?? 0);

    $stats['total_merges'] = (int)($mergeStats['total_merges'] ?? 0);
    $stats['total_rollbacks'] = (int)($mergeStats['total_rollbacks'] ?? 0);

    $recentEntriesStmt = $pdo->query("
        SELECT id, name, ethnic_group, status, created_at
        FROM name_entries
        ORDER BY created_at DESC
        LIMIT 6
    ");
    $recentEntries = $recentEntriesStmt->fetchAll();

    $recentSuggestionsStmt = $pdo->query("
        SELECT
            ns.id,
            ns.entry_id,
            ns.status,
            ns.created_at,
            ne.name,
            ne.ethnic_group
        FROM name_suggestions ns
        INNER JOIN name_entries ne ON ns.entry_id = ne.id
        ORDER BY ns.created_at DESC
        LIMIT 6
    ");
    $recentSuggestions = $recentSuggestionsStmt->fetchAll();

    $recentMergeActivityStmt = $pdo->query("
        SELECT
            sml.entry_id,
            sml.field_name,
            sml.merge_status,
            sml.action_type,
            sml.created_at,
            ne.name,
            u.full_name AS editor_name
        FROM suggestion_merge_logs sml
        INNER JOIN name_entries ne ON sml.entry_id = ne.id
        LEFT JOIN users u ON sml.merged_by = u.id
        ORDER BY sml.created_at DESC, sml.id DESC
        LIMIT 8
    ");
    $recentMergeActivity = $recentMergeActivityStmt->fetchAll();
}

/* ------------------------------------------------- */
/* Admin-specific user analytics */
/* ------------------------------------------------- */
if ($role === 'admin') {
    $userStatsStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_users,
            SUM(CASE WHEN role = 'contributor' THEN 1 ELSE 0 END) AS contributors,
            SUM(CASE WHEN role = 'editor' THEN 1 ELSE 0 END) AS editors,
            SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins
        FROM users
    ");
    $userStats = $userStatsStmt->fetch();

    $stats['total_users'] = (int)($userStats['total_users'] ?? 0);
    $stats['contributors'] = (int)($userStats['contributors'] ?? 0);
    $stats['editors'] = (int)($userStats['editors'] ?? 0);
    $stats['admins'] = (int)($userStats['admins'] ?? 0);
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <section class="detail-hero dashboard-hero">
        <span class="eyebrow">Role-Aware Workspace</span>
        <h1>Dashboard</h1>
        <p class="detail-meaning">
            Welcome back, <?= htmlspecialchars($user['full_name'] ?? 'User') ?>.
            <?php if ($role === 'admin'): ?>
                You have full oversight of users, reviews, suggestions, merges, and rollbacks.
            <?php elseif ($role === 'editor'): ?>
                You can review submissions, manage suggestions, and maintain content quality.
            <?php else: ?>
                Track your entries, suggestions, and contribution progress from one place.
            <?php endif; ?>
        </p>
    </section>

    <?php if ($role === 'admin'): ?>
        <section class="dashboard-section">
            <div class="section-heading">
                <h2>System Access Overview</h2>
                <p>User role distribution across the platform.</p>
            </div>

            <div class="dashboard-stats-grid">
                <div class="dashboard-stat-card">
                    <h3><?= $stats['total_users'] ?></h3>
                    <p>Total Users</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['contributors'] ?></h3>
                    <p>Contributors</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['editors'] ?></h3>
                    <p>Editors</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['admins'] ?></h3>
                    <p>Admins</p>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if (in_array($role, ['editor', 'admin'], true)): ?>
        <section class="dashboard-section">
            <div class="section-heading">
                <h2>Editorial Analytics</h2>
                <p>Live metrics for entries, suggestions, merges, and review workload.</p>
            </div>

            <div class="dashboard-stats-grid">
                <div class="dashboard-stat-card">
                    <h3><?= $stats['total_entries'] ?></h3>
                    <p>Total Entries</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['approved_entries'] ?></h3>
                    <p>Approved Entries</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['pending_entries'] ?></h3>
                    <p>Pending Entries</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['rejected_entries'] ?></h3>
                    <p>Rejected Entries</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['total_suggestions'] ?></h3>
                    <p>Total Suggestions</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['pending_suggestions'] ?></h3>
                    <p>Pending Suggestions</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['total_merges'] ?></h3>
                    <p>Merged Fields</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['total_rollbacks'] ?></h3>
                    <p>Rollbacks</p>
                </div>
            </div>
        </section>

        <section class="dashboard-section">
            <div class="dashboard-quick-actions">
                <a class="dashboard-action-card" href="admin-review.php">
                    <h3>Review Name Submissions</h3>
                    <p>Open the editorial queue for new name entries.</p>
                </a>

                <a class="dashboard-action-card" href="review-suggestions.php">
                    <h3>Review Suggestions</h3>
                    <p>Compare contributor improvements and merge approved changes.</p>
                </a>

                <a class="dashboard-action-card" href="browse.php">
                    <h3>Browse Published Names</h3>
                    <p>Explore approved entries and authority pages.</p>
                </a>

                <?php if ($role === 'admin'): ?>
                    <a class="dashboard-action-card" href="manage-users.php">
                        <h3>Manage Users</h3>
                        <p>Administer contributors, editors, and admin access levels.</p>
                    </a>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($role === 'contributor'): ?>
        <section class="dashboard-section">
            <div class="section-heading">
                <h2>Your Contribution Overview</h2>
                <p>A quick view of your activity in the platform.</p>
            </div>

            <div class="dashboard-stats-grid">
                <div class="dashboard-stat-card">
                    <h3><?= $stats['my_entries'] ?></h3>
                    <p>Your Entries</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['my_pending_entries'] ?></h3>
                    <p>Pending Entries</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['my_approved_entries'] ?></h3>
                    <p>Approved Entries</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['my_suggestions'] ?></h3>
                    <p>Your Suggestions</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['my_pending_suggestions'] ?></h3>
                    <p>Pending Suggestions</p>
                </div>
                <div class="dashboard-stat-card">
                    <h3><?= $stats['my_approved_suggestions'] ?></h3>
                    <p>Approved Suggestions</p>
                </div>
            </div>
        </section>

        <section class="dashboard-section">
            <div class="dashboard-quick-actions">
                <a class="dashboard-action-card" href="submit.php">
                    <h3>Submit a New Name</h3>
                    <p>Add a new name entry for editorial review.</p>
                </a>

                <a class="dashboard-action-card" href="browse.php">
                    <h3>Browse Names</h3>
                    <p>Explore meanings, communities, and naming traditions.</p>
                </a>

                <a class="dashboard-action-card" href="register.php" style="display:none;"></a>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($role === 'contributor'): ?>
        <section class="dashboard-section">
            <div class="dashboard-two-column">
                <div class="detail-card">
                    <h2>Your Recent Entries</h2>

                    <?php if (!empty($myRecentEntries)): ?>
                        <div class="history-list">
                            <?php foreach ($myRecentEntries as $item): ?>
                                <div class="history-item">
                                    <p><strong>Name:</strong> <?= htmlspecialchars($item['name']) ?></p>
                                    <p><strong>Ethnic Group:</strong> <?= htmlspecialchars($item['ethnic_group']) ?></p>
                                    <p><strong>Status:</strong> <?= htmlspecialchars(ucfirst($item['status'])) ?></p>
                                    <p><strong>Date:</strong> <?= htmlspecialchars($item['created_at']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p>You have not submitted any entries yet.</p>
                    <?php endif; ?>
                </div>

                <div class="detail-card">
                    <h2>Your Recent Suggestions</h2>

                    <?php if (!empty($myRecentSuggestions)): ?>
                        <div class="history-list">
                            <?php foreach ($myRecentSuggestions as $item): ?>
                                <div class="history-item">
                                    <p><strong>Name:</strong> <?= htmlspecialchars($item['name']) ?></p>
                                    <p><strong>Ethnic Group:</strong> <?= htmlspecialchars($item['ethnic_group']) ?></p>
                                    <p><strong>Status:</strong> <?= htmlspecialchars(ucfirst($item['status'])) ?></p>
                                    <p><strong>Date:</strong> <?= htmlspecialchars($item['created_at']) ?></p>
                                    <p><a href="name.php?id=<?= (int)$item['entry_id'] ?>">View Name Page</a></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p>You have not submitted any suggestions yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if (in_array($role, ['editor', 'admin'], true)): ?>
        <section class="dashboard-section">
            <div class="dashboard-two-column">
                <div class="detail-card">
                    <h2>Recent Entries</h2>

                    <?php if (!empty($recentEntries)): ?>
                        <div class="history-list">
                            <?php foreach ($recentEntries as $item): ?>
                                <div class="history-item">
                                    <p><strong>Name:</strong> <?= htmlspecialchars($item['name']) ?></p>
                                    <p><strong>Ethnic Group:</strong> <?= htmlspecialchars($item['ethnic_group']) ?></p>
                                    <p><strong>Status:</strong> <?= htmlspecialchars(ucfirst($item['status'])) ?></p>
                                    <p><strong>Date:</strong> <?= htmlspecialchars($item['created_at']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p>No entries found.</p>
                    <?php endif; ?>
                </div>

                <div class="detail-card">
                    <h2>Recent Suggestions</h2>

                    <?php if (!empty($recentSuggestions)): ?>
                        <div class="history-list">
                            <?php foreach ($recentSuggestions as $item): ?>
                                <div class="history-item">
                                    <p><strong>Name:</strong> <?= htmlspecialchars($item['name']) ?></p>
                                    <p><strong>Ethnic Group:</strong> <?= htmlspecialchars($item['ethnic_group']) ?></p>
                                    <p><strong>Status:</strong> <?= htmlspecialchars(ucfirst($item['status'])) ?></p>
                                    <p><strong>Date:</strong> <?= htmlspecialchars($item['created_at']) ?></p>
                                    <p><a href="name.php?id=<?= (int)$item['entry_id'] ?>">View Name Page</a></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p>No suggestions found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="dashboard-section">
            <div class="detail-card">
                <h2>Recent Merge Activity</h2>

                <?php if (!empty($recentMergeActivity)): ?>
                    <div class="history-list">
                        <?php foreach ($recentMergeActivity as $item): ?>
                            <div class="history-item">
                                <p><strong>Name:</strong> <?= htmlspecialchars($item['name']) ?></p>
                                <p><strong>Field:</strong> <?= htmlspecialchars($item['field_name']) ?></p>
                                <p><strong>Status:</strong> <?= htmlspecialchars(ucfirst($item['merge_status'])) ?></p>
                                <p><strong>Action:</strong> <?= htmlspecialchars(ucfirst($item['action_type'])) ?></p>
                                <p><strong>Editor:</strong> <?= htmlspecialchars($item['editor_name'] ?? 'Unknown') ?></p>
                                <p><strong>Date:</strong> <?= htmlspecialchars($item['created_at']) ?></p>
                                <p><a href="merge-history.php?entry_id=<?= (int)$item['entry_id'] ?>">View Merge History</a></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>No merge activity found.</p>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>