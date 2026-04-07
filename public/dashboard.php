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

function roleChangeMessage(array $change): string
{
    $target = trim((string)($change['target_name'] ?? '')) ?: 'User';
    $actor = trim((string)($change['actor_name'] ?? '')) ?: 'Admin';

    $old = strtolower(trim((string)($change['old_role'] ?? '')));
    $new = strtolower(trim((string)($change['new_role'] ?? '')));

    if ($old === $new && $new !== '') {
        return "{$actor} retained {$target} as {$new}";
    }

    if ($new === 'admin') {
        return "🔼 {$target} promoted to Admin by {$actor}";
    }

    if ($new === 'editor') {
        return "🔼 {$target} promoted to Editor by {$actor}";
    }

    if ($new === 'contributor') {
        return "🔽 {$target} moved to Contributor by {$actor}";
    }

    return "⚙️ {$actor} changed {$target} from {$old} to {$new}";
}

/**
 * ------------------------------------------------------------
 * Optional tables
 * ------------------------------------------------------------
 */
$suggestionTable = firstExistingTable($pdo, [
    'name_suggestions',
    'entry_suggestions',
    'suggestions',
    'improvements'
]);

$mergeAuditTable = firstExistingTable($pdo, [
    'suggestion_merge_logs',
    'merge_audit_history',
    'merge_audit_logs',
    'merge_history',
    'merge_logs'
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
    SELECT
        ne.id,
        ne.name,
        ne.ethnic_group,
        ne.region,
        ne.created_at,
        TIMESTAMPDIFF(HOUR, ne.created_at, NOW()) AS hours_waiting,
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
$dashboardIntro = match ($role) {
    'admin' => 'Oversee governance, quality, and activity.',
    'editor' => 'Review submissions and maintain quality.',
    default => 'Track contributions and improve authority pages.',
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
  <section
  class="dashboard-hero"
  style="
  background: #ffffff;
  border: 1px solid #dbeafe;
  border-radius: 30px;
  padding: 22px;
  box-shadow: 0 10px 24px rgba(148, 163, 184, 0.10);
  margin-bottom: 22px;
"
>
  <div
    class="dashboard-hero-top"
    style="
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:16px;
      margin-bottom:18px;
    "
  >
    <div style="flex:1 1 auto; min-width:0;">
      <h1
        style="
          margin-bottom:8px;
          font-size:clamp(2rem, 2.8vw, 2.9rem);
          line-height:1.02;
          letter-spacing:-0.035em;
          color:#000;
        "
      >
        Welcome back, <?= e($userName) ?>
      </h1>

      <p
        class="dashboard-hero__lead"
        style="
          margin:0;
          max-width:720px;
          color:#000;
          font-size:0.98rem;
          line-height:1.5;
        "
      >
        <?= e($dashboardIntro) ?>
      </p>
    </div>

    <div
      class="dashboard-hero-top-actions"
      style="
        display:flex;
        flex-direction:column;
        align-items:flex-end;
        gap:10px;
        flex:0 0 auto;
      "
    >
      <div class="quick-actions" style="margin-top:0; gap:10px; display:flex; flex-wrap:wrap;">
        <?php foreach ($primaryItems as $index => $item): ?>
          <?php
            $buttonStyle = match ($index) {
              0 => 'background: linear-gradient(135deg, #004dab, #0553fb); color: #ffffff; border: 1px solid transparent; box-shadow: 0 14px 28px rgba(59, 130, 246, 0.22);',
              1 => 'background: linear-gradient(135deg, #5429d4, #5d00ff); color: #ffffff; border: 1px solid transparent; box-shadow: 0 14px 28px rgba(124, 58, 237, 0.18);',
              default => 'background: linear-gradient(135deg, #5f3400, #000000); color: #ffffff; border: 1px solid transparent; box-shadow: 0 14px 28px rgba(16, 185, 129, 0.18);',
            };
          ?>
          <a
            class="quick-action-btn"
            href="<?= e($item['href']) ?>"
            style="
              display:inline-flex;
              align-items:center;
              justify-content:center;
              min-height:44px;
              padding:0 18px;
              border-radius:14px;
              font-weight:700;
              font-size:0.9rem;
              text-decoration:none;
              white-space:nowrap;
              <?= $buttonStyle ?>
            "
          >
            <?= e($item['label']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div
    class="dashboard-hero-bottom"
    style="
      display:grid;
      grid-template-columns:minmax(0,1.72fr) minmax(340px,0.95fr);
      gap:22px;
      align-items:stretch;
    "
  >
    <section
      class="dashboard-analytics-card"
      aria-labelledby="dashboard-analytics-title"
      style="
  margin:0;
  height:100%;
  position:relative;
  background:#ffffff;
  border:1px solid #dbeafe;
  border-radius:24px;
  padding:18px;
  box-shadow:0 8px 20px rgba(148, 163, 184, 0.08);
  overflow:hidden;
"
    >
      <div
        style="
  position:absolute;
  inset:0 0 auto 0;
  height:4px;
  background:linear-gradient(90deg, #60a5fa, #002d77, #34d399);
"
      ></div>

      <div class="dashboard-analytics-header" style="display:flex; justify-content:space-between; gap:12px; margin-bottom:12px; flex-wrap:wrap;">
        <div>
          <p
            class="dashboard-section-kicker"
            style="
              margin:0 0 6px;
              font-size:10px;
              font-weight:800;
              letter-spacing:0.14em;
              text-transform:uppercase;
              color:#002d77;
            "
          >
            Platform analytics
          </p>
          <h2
            id="dashboard-analytics-title"
            style="
              margin:0;
              font-size:1.28rem;
              line-height:1.08;
              color:#000;
              letter-spacing:-0.02em;
            "
          >
            Activity Overview
          </h2>
        </div>

        <div
          class="dashboard-analytics-actions"
          role="group"
          aria-label="Analytics date range"
          style="
            display:inline-flex;
            align-items:center;
            gap:4px;
            background:rgba(239, 246, 255, 0.95);
            border:1px solid #dbeafe;
            border-radius:999px;
            padding:4px;
          "
        >
          <button type="button" class="analytics-range-btn" data-days="7" aria-pressed="false" style="appearance:none; border:none; background:transparent; color:#64748b; font-weight:700; font-size:0.78rem; border-radius:999px; padding:7px 10px; cursor:pointer;">7D</button>
          <button type="button" class="analytics-range-btn active" data-days="30" aria-pressed="true" style="appearance:none; border:none; background:linear-gradient(135deg, #60a5fa, #2563eb); color:#ffffff; font-weight:700; font-size:0.78rem; border-radius:999px; padding:7px 10px; cursor:pointer; box-shadow:0 8px 16px rgba(59, 130, 246, 0.18);">30D</button>
          <button type="button" class="analytics-range-btn" data-days="90" aria-pressed="false" style="appearance:none; border:none; background:transparent; color:#64748b; font-weight:700; font-size:0.78rem; border-radius:999px; padding:7px 10px; cursor:pointer;">90D</button>
        </div>
      </div>

      <div class="dashboard-analytics-summary" style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:10px; margin-bottom:12px;">
        <div
          class="dashboard-analytics-stat"
          style="
            background:linear-gradient(180deg, #ffffff, #f8fbff);
            border:1px solid #dbeafe;
            border-radius:16px;
            padding:12px 14px;
            box-shadow:0 6px 14px rgba(148, 163, 184, 0.08);
          "
        >
          <span class="label" style="display:block; margin-bottom:4px; color:#64748b; font-size:0.74rem; font-weight:700;">Submissions</span>
          <strong id="analyticsTotalSubmissions" style="display:block; font-size:1.22rem; line-height:1; color:#2563eb;">0</strong>
        </div>

        <div
          class="dashboard-analytics-stat"
          style="
            background:linear-gradient(180deg, #ffffff, #f8fbff);
            border:1px solid #dbeafe;
            border-radius:16px;
            padding:12px 14px;
            box-shadow:0 6px 14px rgba(148, 163, 184, 0.08);
          "
        >
          <span class="label" style="display:block; margin-bottom:4px; color:#64748b; font-size:0.74rem; font-weight:700;">Suggestions</span>
          <strong id="analyticsTotalSuggestions" style="display:block; font-size:1.22rem; line-height:1; color:#7c3aed;">0</strong>
        </div>

        <div
          class="dashboard-analytics-stat"
          style="
            background:linear-gradient(180deg, #ffffff, #f8fbff);
            border:1px solid #dbeafe;
            border-radius:16px;
            padding:12px 14px;
            box-shadow:0 6px 14px rgba(148, 163, 184, 0.08);
          "
        >
          <span class="label" style="display:block; margin-bottom:4px; color:#64748b; font-size:0.74rem; font-weight:700;">Approvals</span>
          <strong id="analyticsTotalApprovals" style="display:block; font-size:1.22rem; line-height:1; color:#059669;">0</strong>
        </div>
      </div>

      <div
        class="dashboard-chart-wrap"
        style="
  min-height:225px;
  position:relative;
  background:#ffffff;
  border:1px solid #dbeafe;
  border-radius:18px;
  padding:12px;
"
      >
        <canvas id="dashboardAnalyticsChart" height="120"></canvas>
      </div>

      <div id="dashboardAnalyticsState" class="dashboard-analytics-state" aria-live="polite" style="min-height:18px; margin-top:8px; color:#64748b; font-size:0.8rem;"></div>
    </section>

    <section
      class="panel dashboard-status-panel"
      style="
  margin:0;
  height:100%;
  position:relative;
  border-radius:24px;
  overflow:hidden;
  border:1px solid #dbeafe;
  box-shadow:0 8px 20px rgba(148, 163, 184, 0.08);
  background:#ffffff;
"
    >
      <div class="panel__head" style="padding:16px 18px; background:#ffffff; border-bottom:1px solid #e5edf8;">
        <h2 class="panel__title" style="color:#000; font-size:1.18rem; letter-spacing:-0.02em;">Status Distribution</h2>
        <div class="panel__subtle" style="color:#94a3b8; font-size:0.9rem;">Live content health</div>
      </div>

      <div class="panel__body" style="padding:18px; display:flex; flex-direction:column; justify-content:center;">
        <div class="status-distribution-chart" style="min-height:290px; margin-bottom:16px;">
          <canvas id="dashboardStatusChart" height="170"></canvas>
        </div>

        <div class="status-distribution-legend" style="display:flex; flex-direction:column; gap:12px;">
          <div
            class="status-distribution-item"
            style="
              display:grid;
              grid-template-columns:12px 1fr auto;
              align-items:center;
              gap:10px;
              padding:10px 12px;
              border-radius:14px;
              background:linear-gradient(180deg, #fffaf0 0%, #fff7ed 100%);
              border:1px solid #fde68a;
              box-shadow:0 6px 14px rgba(148, 163, 184, 0.08);
            "
          >
            <span class="status-dot status-dot--pending" style="width:10px; height:10px; border-radius:999px; display:inline-block; background:#fbbf24;"></span>
            <span style="color:#d97706; font-weight:700; font-size:0.88rem;">Pending</span>
            <strong id="statusPendingTotal" style="color:#d97706; font-size:0.98rem; font-weight:800;">0</strong>
          </div>

          <div
            class="status-distribution-item"
            style="
              display:grid;
              grid-template-columns:12px 1fr auto;
              align-items:center;
              gap:10px;
              padding:10px 12px;
              border-radius:14px;
              background:linear-gradient(180deg, #f0fdf4 0%, #ecfdf5 100%);
              border:1px solid #a7f3d0;
              box-shadow:0 6px 14px rgba(148, 163, 184, 0.08);
            "
          >
            <span class="status-dot status-dot--approved" style="width:10px; height:10px; border-radius:999px; display:inline-block; background:#34d399;"></span>
            <span style="color:#059669; font-weight:700; font-size:0.88rem;">Approved</span>
            <strong id="statusApprovedTotal" style="color:#059669; font-size:0.98rem; font-weight:800;">0</strong>
          </div>

          <div
            class="status-distribution-item"
            style="
              display:grid;
              grid-template-columns:12px 1fr auto;
              align-items:center;
              gap:10px;
              padding:10px 12px;
              border-radius:14px;
              background:linear-gradient(180deg, #fff5f5 0%, #fef2f2 100%);
              border:1px solid #fecaca;
              box-shadow:0 6px 14px rgba(148, 163, 184, 0.08);
            "
          >
            <span class="status-dot status-dot--rejected" style="width:10px; height:10px; border-radius:999px; display:inline-block; background:#f87171;"></span>
            <span style="color:#dc2626; font-weight:700; font-size:0.88rem;">Rejected</span>
            <strong id="statusRejectedTotal" style="color:#dc2626; font-size:0.98rem; font-weight:800;">0</strong>
          </div>
        </div>
      </div>
    </section>
  </div>
</section>

  <section class="panel panel--priority">
    <div class="panel__head">
      <h2 class="panel__title">Action Required</h2>
      <div class="panel__subtle">Immediate priorities</div>
    </div>
    <div class="panel__body">
      <div class="insight-grid">
        <?php if ($role === 'editor' || $role === 'admin'): ?>
          <?php if ($totalPending > 0): ?>
            <div class="insight-box insight-box--alert">
              <strong><?= $totalPending ?></strong>
              <span>Pending entries require review.</span>
              <span><a href="/admin-review.php">Review now →</a></span>
            </div>
          <?php endif; ?>

          <?php if ($pendingSuggestions > 0): ?>
            <div class="insight-box insight-box--warning">
              <strong><?= $pendingSuggestions ?></strong>
              <span>Suggestions are waiting for editorial action.</span>
            </div>
          <?php endif; ?>

          <?php if ($role === 'admin' && $totalRoleChanges > 0): ?>
            <div class="insight-box">
              <strong><?= $totalRoleChanges ?></strong>
              <span>Recent governance changes have been logged.</span>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($role === 'contributor'): ?>
          <?php if ($myPendingEntries > 0): ?>
            <div class="insight-box insight-box--warning">
              <strong><?= $myPendingEntries ?></strong>
              <span>Your submissions are currently under review.</span>
            </div>
          <?php endif; ?>

          <?php if ($myRejectedEntries > 0): ?>
            <div class="insight-box insight-box--alert">
              <strong><?= $myRejectedEntries ?></strong>
              <span>Some entries need revision and resubmission.</span>
            </div>
          <?php endif; ?>

          <?php if ($mySuggestions > 0): ?>
            <div class="insight-box">
              <strong><?= $mySuggestions ?></strong>
              <span>You have active improvement participation in the system.</span>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (
          ($role === 'contributor' && $myPendingEntries === 0 && $myRejectedEntries === 0 && $mySuggestions === 0) ||
          (($role === 'editor' || $role === 'admin') && $totalPending === 0 && $pendingSuggestions === 0)
        ): ?>
          <div class="insight-box">
            <strong>All clear</strong>
            <span>No urgent workflow items need immediate attention right now.</span>
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

                    <?php if ($role === 'editor' && isset($item['hours_waiting'])): ?>
                      <strong>Waiting:</strong> <?= (int)$item['hours_waiting'] ?> hrs<br>
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
                      <div class="activity-item__title"><?= e(roleChangeMessage($change)) ?></div>
                      <span class="status-pill status--neutral">governance</span>
                    </div>
                    <div class="activity-item__meta">
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

<!-- Analytics scripts -->
 <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
(function () {
    const lineCanvas = document.getElementById('dashboardAnalyticsChart');
    const doughnutCanvas = document.getElementById('dashboardStatusChart');
    const stateEl = document.getElementById('dashboardAnalyticsState');

    const totalSubmissionsEl = document.getElementById('analyticsTotalSubmissions');
    const totalSuggestionsEl = document.getElementById('analyticsTotalSuggestions');
    const totalApprovalsEl = document.getElementById('analyticsTotalApprovals');

    const statusPendingEl = document.getElementById('statusPendingTotal');
    const statusApprovedEl = document.getElementById('statusApprovedTotal');
    const statusRejectedEl = document.getElementById('statusRejectedTotal');

    const buttons = document.querySelectorAll('.analytics-range-btn');

    if (!lineCanvas || !stateEl) return;

    let analyticsChart = null;
    let statusChart = null;
    let currentDays = 30;

    function setState(message, type = '') {
        stateEl.textContent = message || '';
        stateEl.className = 'dashboard-analytics-state';
        if (type) {
            stateEl.classList.add(type);
        }
    }

    function updateSummary(totals) {
        totalSubmissionsEl.textContent = Number(totals.submissions || 0).toLocaleString();
        totalSuggestionsEl.textContent = Number(totals.suggestions || 0).toLocaleString();
        totalApprovalsEl.textContent = Number(totals.approvals || 0).toLocaleString();
    }

    function updateStatusSummary(statusTotals) {
        if (statusPendingEl) {
            statusPendingEl.textContent = Number(statusTotals.pending || 0).toLocaleString();
        }
        if (statusApprovedEl) {
            statusApprovedEl.textContent = Number(statusTotals.approved || 0).toLocaleString();
        }
        if (statusRejectedEl) {
            statusRejectedEl.textContent = Number(statusTotals.rejected || 0).toLocaleString();
        }
    }

    function setActiveButton(days) {
        buttons.forEach((button) => {
            const isActive = Number(button.dataset.days) === Number(days);
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function renderLineChart(payload) {
        const config = {
            type: 'line',
            data: {
                labels: payload.labels,
                datasets: [
                    {
                        label: 'Submissions',
                        data: payload.datasets.submissions,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.10)',
                        borderWidth: 3,
                        tension: 0.35,
                        fill: false,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#2563eb'
                    },
                    {
                        label: 'Suggestions',
                        data: payload.datasets.suggestions,
                        borderColor: '#7c3aed',
                        backgroundColor: 'rgba(124, 58, 237, 0.10)',
                        borderWidth: 3,
                        tension: 0.35,
                        fill: false,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#7c3aed'
                    },
                    {
                        label: 'Approvals',
                        data: payload.datasets.approvals,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.10)',
                        borderWidth: 3,
                        tension: 0.35,
                        fill: false,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#059669'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 500
                },
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'start',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 10,
                            boxHeight: 10,
                            padding: 16,
                            color: '#334155',
                            font: {
                                size: 12,
                                weight: '600'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#ffffff',
                        bodyColor: '#e2e8f0',
                        padding: 12,
                        displayColors: true,
                        cornerRadius: 12
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#64748b',
                            maxRotation: 45,
                            minRotation: 45,
                            autoSkip: true,
                            maxTicksLimit: 8
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grace: '10%',
                        ticks: {
                            precision: 0,
                            color: '#64748b',
                            stepSize: 1
                        },
                        grid: {
                            color: 'rgba(148, 163, 184, 0.18)'
                        }
                    }
                }
            }
        };

        if (analyticsChart) {
            analyticsChart.destroy();
        }

        analyticsChart = new Chart(lineCanvas, config);
    }

    function renderStatusChart(statusDistribution) {
        if (!doughnutCanvas) return;

        const config = {
            type: 'doughnut',
            data: {
                labels: statusDistribution.labels,
                datasets: [
                    {
                        data: statusDistribution.values,
                        backgroundColor: [
                            '#f59e0b',
                            '#10b981',
                            '#ef4444'
                        ],
                        borderColor: '#ffffff',
                        borderWidth: 4,
                        hoverOffset: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '66%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#ffffff',
                        bodyColor: '#e2e8f0',
                        padding: 12,
                        cornerRadius: 12
                    }
                }
            }
        };

        if (statusChart) {
            statusChart.destroy();
        }

        statusChart = new Chart(doughnutCanvas, config);
    }

    async function loadAnalytics(days = 30) {
        setActiveButton(days);
        setState('Loading analytics...', 'is-loading');

        try {
            const response = await fetch('./dashboard-analytics.php?days=' + encodeURIComponent(days), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });

            const rawText = await response.text();
            let payload = null;

            try {
                payload = JSON.parse(rawText);
            } catch (jsonError) {
                console.error('Non-JSON response from analytics endpoint:', rawText);
                throw new Error('Endpoint did not return valid JSON.');
            }

            if (!response.ok || !payload.success) {
                console.error('Analytics payload error:', payload);
                throw new Error(payload.debug || payload.message || 'Failed to load analytics.');
            }

            renderLineChart(payload);
            updateSummary(payload.totals);

            if (payload.status_distribution) {
                renderStatusChart(payload.status_distribution);
                updateStatusSummary(payload.status_distribution.totals || {});
            }

            setState('');
        } catch (error) {
            console.error('Analytics load failed:', error);
            setState(error.message || 'Failed to load analytics.', 'is-error');
        }
    }

    buttons.forEach((button) => {
        button.addEventListener('click', function () {
            const days = Number(this.dataset.days || 30);
            currentDays = days;
            loadAnalytics(currentDays);
        });
    });

    loadAnalytics(currentDays);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>