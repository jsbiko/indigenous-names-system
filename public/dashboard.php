<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$user = currentUser();
$role = $user['role'] ?? 'contributor';
$userId = (int)($user['id'] ?? 0);
$userName = trim((string)($user['name'] ?? 'User'));

$pageTitle = 'Dashboard';
$bodyClass = 'dashboard-page';

/**
 * ------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------
 */
function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table
        ");
        $stmt->execute(['table' => $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function firstExistingTable(PDO $pdo, array $candidates): ?string
{
    foreach ($candidates as $table) {
        if (tableExists($pdo, $table)) {
            return $table;
        }
    }
    return null;
}

function scalar(PDO $pdo, string $sql, array $params = [], int $default = 0): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value !== false ? (int)$value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function fetchAllSafe(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatDateTime(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }

    try {
        $dt = new DateTime($datetime);
        return $dt->format('M j, Y \a\t g:i A');
    } catch (Throwable $e) {
        return $datetime;
    }
}

function formatShortDate(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }

    try {
        $dt = new DateTime($datetime);
        return $dt->format('M j, Y');
    } catch (Throwable $e) {
        return $datetime;
    }
}

function statusClass(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'approved', 'published', 'merged', 'accepted' => 'status--approved',
        'pending', 'under review', 'submitted' => 'status--pending',
        'rejected', 'declined' => 'status--rejected',
        default => 'status--neutral',
    };
}

function safePercent(int $part, int $total): int
{
    if ($total <= 0) {
        return 0;
    }
    return (int)round(($part / $total) * 100);
}

/**
 * ------------------------------------------------------------
 * Optional tables
 * ------------------------------------------------------------
 */
$suggestionTable = firstExistingTable($pdo, [
    'suggestions',
    'improvements',
    'name_suggestions',
    'entry_suggestions'
]);

$mergeAuditTable = firstExistingTable($pdo, [
    'merge_audit_history',
    'merge_audit_logs',
    'merge_history',
    'merge_logs',
    'suggestion_merge_logs'
]);

$nameProfilesTable = firstExistingTable($pdo, [
    'name_profiles'
]);

$reviewsTable = firstExistingTable($pdo, [
    'reviews'
]);

$userRoleAuditTable = firstExistingTable($pdo, [
    'user_role_audit'
]);

/**
 * ------------------------------------------------------------
 * Core counts
 * ------------------------------------------------------------
 */
$totalEntries = scalar($pdo, "SELECT COUNT(*) FROM name_entries");
$totalApproved = scalar($pdo, "SELECT COUNT(*) FROM name_entries WHERE status = 'approved'");
$totalPending = scalar($pdo, "SELECT COUNT(*) FROM name_entries WHERE status = 'pending'");
$totalRejected = scalar($pdo, "SELECT COUNT(*) FROM name_entries WHERE status = 'rejected'");

$myEntries = scalar($pdo, "
    SELECT COUNT(*)
    FROM name_entries
    WHERE created_by = :user_id
", ['user_id' => $userId]);

$myPendingEntries = scalar($pdo, "
    SELECT COUNT(*)
    FROM name_entries
    WHERE created_by = :user_id
      AND status = 'pending'
", ['user_id' => $userId]);

$myApprovedEntries = scalar($pdo, "
    SELECT COUNT(*)
    FROM name_entries
    WHERE created_by = :user_id
      AND status = 'approved'
", ['user_id' => $userId]);

$myRejectedEntries = scalar($pdo, "
    SELECT COUNT(*)
    FROM name_entries
    WHERE created_by = :user_id
      AND status = 'rejected'
", ['user_id' => $userId]);

$totalUsers = scalar($pdo, "SELECT COUNT(*) FROM users");
$totalContributors = scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'contributor'");
$totalEditors = scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'editor'");
$totalAdmins = scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'admin'");

$totalProfiles = $nameProfilesTable
    ? scalar($pdo, "SELECT COUNT(*) FROM {$nameProfilesTable}")
    : 0;

$totalSuggestions = 0;
$mySuggestions = 0;
$pendingSuggestions = 0;

if ($suggestionTable) {
    $totalSuggestions = scalar($pdo, "SELECT COUNT(*) FROM {$suggestionTable}");
    $mySuggestions = scalar($pdo, "
        SELECT COUNT(*)
        FROM {$suggestionTable}
        WHERE user_id = :user_id
    ", ['user_id' => $userId]);

    $pendingSuggestions = scalar($pdo, "
        SELECT COUNT(*)
        FROM {$suggestionTable}
        WHERE status = 'pending'
    ");
}

$totalMergeAudits = $mergeAuditTable
    ? scalar($pdo, "SELECT COUNT(*) FROM {$mergeAuditTable}")
    : 0;

$totalRoleChanges = $userRoleAuditTable
    ? scalar($pdo, "SELECT COUNT(*) FROM {$userRoleAuditTable}")
    : 0;

$myReviews = 0;
if ($reviewsTable) {
    $myReviews = scalar($pdo, "
        SELECT COUNT(*)
        FROM {$reviewsTable}
        WHERE reviewer_id = :user_id
    ", ['user_id' => $userId]);
}

$myApprovalRate = safePercent($myApprovedEntries, $myEntries);

/**
 * ------------------------------------------------------------
 * Recent content
 * ------------------------------------------------------------
 */
$recentMyEntries = fetchAllSafe($pdo, "
    SELECT id, name, ethnic_group, region, status, created_at
    FROM name_entries
    WHERE created_by = :user_id
    ORDER BY created_at DESC
    LIMIT 6
", ['user_id' => $userId]);

$recentGlobalEntries = fetchAllSafe($pdo, "
    SELECT ne.id, ne.name, ne.ethnic_group, ne.region, ne.status, ne.created_at,
           COALESCE(u.full_name, u.name, u.email, 'Unknown User') AS author_name
    FROM name_entries ne
    LEFT JOIN users u ON u.id = ne.created_by
    ORDER BY ne.created_at DESC
    LIMIT 8
");

$recentPendingEntries = fetchAllSafe($pdo, "
    SELECT ne.id, ne.name, ne.ethnic_group, ne.region, ne.created_at,
           COALESCE(u.full_name, u.name, u.email, 'Unknown User') AS author_name
    FROM name_entries ne
    LEFT JOIN users u ON u.id = ne.created_by
    WHERE ne.status = 'pending'
    ORDER BY ne.created_at ASC
    LIMIT 8
");

$recentUsers = fetchAllSafe($pdo, "
    SELECT id,
           COALESCE(full_name, name, email, 'Unnamed User') AS display_name,
           email,
           role,
           created_at
    FROM users
    ORDER BY created_at DESC, id DESC
    LIMIT 6
");

$recentSuggestions = [];
if ($suggestionTable) {
    $recentSuggestions = fetchAllSafe($pdo, "
        SELECT s.id, s.status, s.created_at,
               COALESCE(u.full_name, u.name, u.email, 'Unknown User') AS author_name,
               COALESCE(ne.name, 'Name Entry') AS entry_name
        FROM {$suggestionTable} s
        LEFT JOIN users u ON u.id = s.user_id
        LEFT JOIN name_entries ne ON ne.id = s.name_entry_id
        ORDER BY s.created_at DESC
        LIMIT 8
    ");
}

$recentMySuggestions = [];
if ($suggestionTable) {
    $recentMySuggestions = fetchAllSafe($pdo, "
        SELECT s.id, s.status, s.created_at,
               COALESCE(ne.name, 'Name Entry') AS entry_name
        FROM {$suggestionTable} s
        LEFT JOIN name_entries ne ON ne.id = s.name_entry_id
        WHERE s.user_id = :user_id
        ORDER BY s.created_at DESC
        LIMIT 5
    ", ['user_id' => $userId]);
}

$recentMerges = [];
if ($mergeAuditTable) {
    $recentMerges = fetchAllSafe($pdo, "
        SELECT id, created_at
        FROM {$mergeAuditTable}
        ORDER BY created_at DESC
        LIMIT 6
    ");
}

$recentRoleChanges = [];
if ($userRoleAuditTable) {
    $recentRoleChanges = fetchAllSafe($pdo, "
        SELECT a.old_role, a.new_role, a.created_at,
               COALESCE(u.full_name, u.name, u.email, 'User') AS target_name,
               COALESCE(ch.full_name, ch.name, ch.email, 'Admin') AS actor_name
        FROM {$userRoleAuditTable} a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN users ch ON ch.id = a.changed_by
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT 6
    ");
}

/**
 * ------------------------------------------------------------
 * Role-aware copy
 * ------------------------------------------------------------
 */
$dashboardHeading = match ($role) {
    'admin' => 'System Command Center',
    'editor' => 'Editorial Review Workspace',
    default => 'Contributor Dashboard',
};

$dashboardIntro = match ($role) {
    'admin' => 'Oversee growth, user governance, authority quality, and platform integrity from one place.',
    'editor' => 'Review submissions, process improvements, and maintain editorial quality across authority pages.',
    default => 'Track your contributions, improve authority pages, and grow your publishing footprint in the knowledge system.',
};

$primaryItems = match ($role) {
    'admin' => [
        ['label' => 'Manage Users', 'href' => '/manage-users.php'],
        ['label' => 'Review Queue', 'href' => '/admin-review.php'],
        ['label' => 'Browse Records', 'href' => '/browse.php'],
    ],
    'editor' => [
        ['label' => 'Open Review Queue', 'href' => '/admin-review.php'],
        ['label' => 'Browse Records', 'href' => '/browse.php'],
        ['label' => 'Submit Name', 'href' => '/submit.php'],
    ],
    default => [
        ['label' => 'Submit Name', 'href' => '/submit.php'],
        ['label' => 'Browse Records', 'href' => '/browse.php'],
        ['label' => 'My Account', 'href' => '/profile.php'],
    ],
};

require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-shell">
  <section class="dashboard-hero">
    <div class="dashboard-hero__grid">
      <div>
        <div class="dashboard-hero__eyebrow">Indigenous African Names Knowledge System</div>
        <h1>Welcome back, <?= e($userName) ?></h1>
        <p><?= e($dashboardIntro) ?></p>

        <div class="dashboard-role-badge">
          Role: <?= e(ucfirst($role)) ?>
        </div>

        <div class="quick-actions">
          <?php foreach ($primaryItems as $item): ?>
            <a
              class="quick-action-btn <?= $item === $primaryItems[0] ? 'quick-action-btn--primary' : 'quick-action-btn--secondary' ?>"
              href="<?= e($item['href']) ?>"
            >
              <?= e($item['label']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="dashboard-hero__stats">
        <?php if ($role === 'contributor'): ?>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">My submissions</div>
            <div class="hero-mini-card__value"><?= $myEntries ?></div>
          </div>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">Approval rate</div>
            <div class="hero-mini-card__value"><?= $myApprovalRate ?>%</div>
          </div>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">Pending review</div>
            <div class="hero-mini-card__value"><?= $myPendingEntries ?></div>
          </div>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">Suggestions made</div>
            <div class="hero-mini-card__value"><?= $mySuggestions ?></div>
          </div>
        <?php elseif ($role === 'editor'): ?>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">Pending entries</div>
            <div class="hero-mini-card__value"><?= $totalPending ?></div>
          </div>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">Pending suggestions</div>
            <div class="hero-mini-card__value"><?= $pendingSuggestions ?></div>
          </div>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">My reviews</div>
            <div class="hero-mini-card__value"><?= $myReviews ?></div>
          </div>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">Merge logs</div>
            <div class="hero-mini-card__value"><?= $totalMergeAudits ?></div>
          </div>
        <?php else: ?>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">Total records</div>
            <div class="hero-mini-card__value"><?= $totalEntries ?></div>
          </div>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">Platform users</div>
            <div class="hero-mini-card__value"><?= $totalUsers ?></div>
          </div>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">Pending queue</div>
            <div class="hero-mini-card__value"><?= $totalPending ?></div>
          </div>
          <div class="hero-mini-card">
            <div class="hero-mini-card__label">Role changes</div>
            <div class="hero-mini-card__value"><?= $totalRoleChanges ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <div class="dashboard-grid">
    <div class="dashboard-stack">
      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Performance Snapshot</h2>
          <div class="panel__subtle">Role-aware metrics</div>
        </div>
        <div class="panel__body">
          <?php if ($role === 'contributor'): ?>
            <div class="stats-grid">
              <div class="stat-card">
                <div class="stat-card__label">Approved</div>
                <div class="stat-card__value"><?= $myApprovedEntries ?></div>
                <div class="stat-card__hint">Accepted entries strengthening the authority archive.</div>
              </div>
              <div class="stat-card">
                <div class="stat-card__label">Pending</div>
                <div class="stat-card__value"><?= $myPendingEntries ?></div>
                <div class="stat-card__hint">Submissions currently waiting for editorial review.</div>
              </div>
              <div class="stat-card">
                <div class="stat-card__label">Rejected</div>
                <div class="stat-card__value"><?= $myRejectedEntries ?></div>
                <div class="stat-card__hint">Entries that may need revision and resubmission.</div>
              </div>
              <div class="stat-card">
                <div class="stat-card__label">Suggestions</div>
                <div class="stat-card__value"><?= $mySuggestions ?></div>
                <div class="stat-card__hint">Improvement proposals contributed to existing records.</div>
              </div>
            </div>
          <?php elseif ($role === 'editor'): ?>
            <div class="stats-grid">
              <div class="stat-card">
                <div class="stat-card__label">Pending submissions</div>
                <div class="stat-card__value"><?= $totalPending ?></div>
                <div class="stat-card__hint">Current editorial queue waiting for action.</div>
              </div>
              <div class="stat-card">
                <div class="stat-card__label">Pending suggestions</div>
                <div class="stat-card__value"><?= $pendingSuggestions ?></div>
                <div class="stat-card__hint">Contributor improvements needing evaluation.</div>
              </div>
              <div class="stat-card">
                <div class="stat-card__label">Approved records</div>
                <div class="stat-card__value"><?= $totalApproved ?></div>
                <div class="stat-card__hint">Published authority entries currently visible in the system.</div>
              </div>
              <div class="stat-card">
                <div class="stat-card__label">Merge activity</div>
                <div class="stat-card__value"><?= $totalMergeAudits ?></div>
                <div class="stat-card__hint">Tracked merge actions supporting transparency and quality.</div>
              </div>
            </div>
          <?php else: ?>
            <div class="stats-grid">
              <div class="stat-card">
                <div class="stat-card__label">Approved records</div>
                <div class="stat-card__value"><?= $totalApproved ?></div>
                <div class="stat-card__hint">Authority content that has passed review and is live.</div>
              </div>
              <div class="stat-card">
                <div class="stat-card__label">Authority profiles</div>
                <div class="stat-card__value"><?= $totalProfiles ?></div>
                <div class="stat-card__hint">Enriched name pages available to readers and editors.</div>
              </div>
              <div class="stat-card">
                <div class="stat-card__label">Total suggestions</div>
                <div class="stat-card__value"><?= $totalSuggestions ?></div>
                <div class="stat-card__hint">Contributor-driven improvement activity across the platform.</div>
              </div>
              <div class="stat-card">
                <div class="stat-card__label">Admins</div>
                <div class="stat-card__value"><?= $totalAdmins ?></div>
                <div class="stat-card__hint">Governance accounts with platform-wide privilege.</div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Needs Attention</h2>
          <div class="panel__subtle">What matters next</div>
        </div>
        <div class="panel__body">
          <div class="insight-grid">
            <?php if ($role === 'contributor'): ?>
              <div class="insight-box">
                <strong><?= $myPendingEntries ?></strong>
                <span>Your entries waiting for editorial review.</span>
              </div>
              <div class="insight-box">
                <strong><?= $myRejectedEntries ?></strong>
                <span>Records that may benefit from revision and resubmission.</span>
              </div>
              <div class="insight-box">
                <strong><?= $myApprovalRate ?>%</strong>
                <span>Your current contribution approval rate.</span>
              </div>
              <div class="insight-box">
                <strong><?= $mySuggestions ?></strong>
                <span>Total suggestions contributed to improve existing authority pages.</span>
              </div>
            <?php elseif ($role === 'editor'): ?>
              <div class="insight-box">
                <strong><?= $totalPending ?></strong>
                <span>Entries currently building editorial workload.</span>
              </div>
              <div class="insight-box">
                <strong><?= $pendingSuggestions ?></strong>
                <span>Open suggestions requiring editorial action.</span>
              </div>
              <div class="insight-box">
                <strong><?= $myReviews ?></strong>
                <span>Your own recorded review count so far.</span>
              </div>
              <div class="insight-box">
                <strong><?= $totalMergeAudits ?></strong>
                <span>Merge activity available for editorial audit visibility.</span>
              </div>
            <?php else: ?>
              <div class="insight-box">
                <strong><?= $totalPending ?></strong>
                <span>Pending records affecting system publishing throughput.</span>
              </div>
              <div class="insight-box">
                <strong><?= $pendingSuggestions ?></strong>
                <span>Suggestion backlog affecting authority-page freshness.</span>
              </div>
              <div class="insight-box">
                <strong><?= $totalUsers ?></strong>
                <span>Total governed users across contributor, editor, and admin roles.</span>
              </div>
              <div class="insight-box">
                <strong><?= $totalRoleChanges ?></strong>
                <span>Logged governance actions across user role assignments.</span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">
            <?php if ($role === 'contributor'): ?>
              My Recent Submissions
            <?php elseif ($role === 'editor'): ?>
              Pending Review Queue
            <?php else: ?>
              Latest Platform Entries
            <?php endif; ?>
          </h2>
          <div class="panel__subtle">
            <?php if ($role === 'contributor'): ?>
              Last 6 entries
            <?php elseif ($role === 'editor'): ?>
              Oldest pending first
            <?php else: ?>
              Most recent records
            <?php endif; ?>
          </div>
        </div>

        <div class="panel__body">
          <?php
            $items = match ($role) {
              'contributor' => $recentMyEntries,
              'editor' => $recentPendingEntries,
              default => $recentGlobalEntries,
            };
          ?>

          <div class="activity-list">
            <?php if (!$items): ?>
              <div class="empty-state">
                No records yet. This area will become more useful as content and activity grow.
              </div>
            <?php else: ?>
              <?php foreach ($items as $item): ?>
                <a class="activity-item" href="/name.php?id=<?= (int)$item['id'] ?>">
                  <div class="activity-item__top">
                    <div class="activity-item__title"><?= e((string)$item['name']) ?></div>
                    <?php if (isset($item['status'])): ?>
                      <span class="status-pill <?= e(statusClass((string)$item['status'])) ?>">
                        <?= e((string)$item['status']) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="activity-item__meta">
                    <?php if (!empty($item['ethnic_group'])): ?>
                      <strong>Ethnic Group:</strong> <?= e((string)$item['ethnic_group']) ?><br>
                    <?php endif; ?>

                    <?php if (!empty($item['region'])): ?>
                      <strong>Region:</strong> <?= e((string)$item['region']) ?><br>
                    <?php endif; ?>

                    <?php if (!empty($item['author_name']) && $role !== 'contributor'): ?>
                      <strong>Submitted by:</strong> <?= e((string)$item['author_name']) ?><br>
                    <?php endif; ?>

                    <strong>Date:</strong> <?= e(formatDateTime((string)($item['created_at'] ?? ''))) ?>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </section>
    </div>

    <div class="dashboard-stack">
      <?php if ($role === 'contributor'): ?>
        <section class="panel">
          <div class="panel__head">
            <h2 class="panel__title">My Suggestion Activity</h2>
            <div class="panel__subtle">Recent contribution improvements</div>
          </div>
          <div class="panel__body">
            <?php if (!$recentMySuggestions): ?>
              <div class="empty-state">
                You have not submitted any improvement suggestions yet.
              </div>
            <?php else: ?>
              <div class="activity-list">
                <?php foreach ($recentMySuggestions as $suggestion): ?>
                  <div class="activity-item">
                    <div class="activity-item__top">
                      <div class="activity-item__title"><?= e((string)$suggestion['entry_name']) ?></div>
                      <span class="status-pill <?= e(statusClass((string)$suggestion['status'])) ?>">
                        <?= e((string)$suggestion['status']) ?>
                      </span>
                    </div>
                    <div class="activity-item__meta">
                      <strong>Date:</strong> <?= e(formatDateTime((string)$suggestion['created_at'])) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($role === 'editor' || $role === 'admin'): ?>
        <section class="panel">
          <div class="panel__head">
            <h2 class="panel__title">Recent Suggestions</h2>
            <div class="panel__subtle">Improvement workflow</div>
          </div>
          <div class="panel__body">
            <?php if (!$recentSuggestions): ?>
              <div class="empty-state">
                No recent suggestion activity found.
              </div>
            <?php else: ?>
              <div class="activity-list">
                <?php foreach ($recentSuggestions as $suggestion): ?>
                  <div class="activity-item">
                    <div class="activity-item__top">
                      <div class="activity-item__title"><?= e((string)$suggestion['entry_name']) ?></div>
                      <span class="status-pill <?= e(statusClass((string)$suggestion['status'])) ?>">
                        <?= e((string)$suggestion['status']) ?>
                      </span>
                    </div>
                    <div class="activity-item__meta">
                      <strong>By:</strong> <?= e((string)$suggestion['author_name']) ?><br>
                      <strong>Date:</strong> <?= e(formatDateTime((string)$suggestion['created_at'])) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($role === 'admin'): ?>
        <section class="panel">
          <div class="panel__head">
            <h2 class="panel__title">User Governance</h2>
            <div class="panel__subtle">Recent role activity</div>
          </div>
          <div class="panel__body">
            <?php if (!$recentRoleChanges): ?>
              <div class="empty-state">
                No recent role changes have been logged yet.
              </div>
            <?php else: ?>
              <div class="activity-list">
                <?php foreach ($recentRoleChanges as $change): ?>
                  <div class="activity-item">
                    <div class="activity-item__top">
                      <div class="activity-item__title">
                        <?= e((string)$change['target_name']) ?>
                      </div>
                      <span class="status-pill status--neutral">
                        <?= e(ucfirst((string)$change['old_role'])) ?> → <?= e(ucfirst((string)$change['new_role'])) ?>
                      </span>
                    </div>
                    <div class="activity-item__meta">
                      <strong>Changed by:</strong> <?= e((string)$change['actor_name']) ?><br>
                      <strong>Date:</strong> <?= e(formatDateTime((string)$change['created_at'])) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Quick Navigation</h2>
          <div class="panel__subtle">Common destinations</div>
        </div>
        <div class="panel__body">
          <div class="link-list">
            <a class="link-item" href="/browse.php">Browse indigenous names and authority pages</a>
            <a class="link-item" href="/submit.php">Submit a new indigenous name record</a>

            <?php if ($role === 'editor' || $role === 'admin'): ?>
              <a class="link-item" href="/admin-review.php">Open editorial review queue</a>
            <?php endif; ?>

            <?php if ($role === 'admin'): ?>
              <a class="link-item" href="/manage-users.php">Manage users and assign roles</a>
            <?php endif; ?>

            <a class="link-item" href="/profile.php">Manage your account</a>
          </div>
        </div>
      </section>

      <?php if ($role === 'admin'): ?>
        <section class="panel">
          <div class="panel__head">
            <h2 class="panel__title">Recent User Growth</h2>
            <div class="panel__subtle">Latest registrations</div>
          </div>
          <div class="panel__body">
            <?php if (!$recentUsers): ?>
              <div class="empty-state">No recent users found.</div>
            <?php else: ?>
              <div class="users-mini-list">
                <?php foreach ($recentUsers as $recentUser): ?>
                  <div class="user-mini">
                    <div>
                      <div class="user-mini__name"><?= e((string)$recentUser['display_name']) ?></div>
                      <div class="user-mini__meta"><?= e((string)$recentUser['email']) ?></div>
                    </div>
                    <div class="user-mini__meta">
                      <strong><?= e(ucfirst((string)$recentUser['role'])) ?></strong><br>
                      Joined <?= e(formatShortDate((string)$recentUser['created_at'])) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if (($role === 'editor' || $role === 'admin') && !empty($recentMerges)): ?>
        <section class="panel">
          <div class="panel__head">
            <h2 class="panel__title">Recent Merge Activity</h2>
            <div class="panel__subtle">Audit visibility</div>
          </div>
          <div class="panel__body">
            <div class="activity-list">
              <?php foreach ($recentMerges as $merge): ?>
                <div class="activity-item">
                  <div class="activity-item__top">
                    <div class="activity-item__title">Merge record #<?= (int)$merge['id'] ?></div>
                    <span class="status-pill status--approved">logged</span>
                  </div>
                  <div class="activity-item__meta">
                    <strong>Date:</strong> <?= e(formatDateTime((string)$merge['created_at'])) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>