<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Browse Indigenous Names';
$bodyClass = 'dashboard-page';

$user = currentUser();
$role = strtolower(trim((string)($user['role'] ?? '')));
$isEditorOrAdmin = in_array($role, ['editor', 'admin'], true);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

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

function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND column_name = :column
        ");
        $stmt->execute([
            'table' => $tableName,
            'column' => $columnName,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
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

function excerpt(?string $text, int $limit = 180): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/\s+/', ' ', $text) ?? $text;

    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
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

/**
 * ------------------------------------------------------------
 * Schema detection
 * ------------------------------------------------------------
 */
$hasProfiles = tableExists($pdo, 'name_profiles');

$profileColumns = [
    'overview' => $hasProfiles && tableHasColumn($pdo, 'name_profiles', 'overview'),
    'ai_summary' => $hasProfiles && tableHasColumn($pdo, 'name_profiles', 'ai_summary'),
    'pronunciation' => $hasProfiles && tableHasColumn($pdo, 'name_profiles', 'pronunciation'),
    'variants' => $hasProfiles && tableHasColumn($pdo, 'name_profiles', 'variants'),
    'cultural_significance' => $hasProfiles && tableHasColumn($pdo, 'name_profiles', 'cultural_significance'),
    'linguistic_origin' => $hasProfiles && tableHasColumn($pdo, 'name_profiles', 'linguistic_origin'),
];

$profileJoin = $hasProfiles ? "LEFT JOIN name_profiles np ON np.entry_id = ne.id" : "";

/**
 * ------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------
 */
$q = trim((string)($_GET['q'] ?? ''));
$ethnicGroup = trim((string)($_GET['ethnic_group'] ?? ''));
$gender = trim((string)($_GET['gender'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'recent'));

$allowedSorts = ['recent', 'oldest', 'name_asc', 'name_desc'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'recent';
}

$allowedStatuses = ['approved', 'pending', 'rejected'];
if (!$isEditorOrAdmin) {
    $status = 'approved';
} elseif ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$allowedGenders = ['male', 'female', 'unisex', 'unknown'];
if ($gender !== '' && !in_array($gender, $allowedGenders, true)) {
    $gender = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

/**
 * ------------------------------------------------------------
 * Filter options
 * ------------------------------------------------------------
 */
$ethnicGroups = fetchAllSafe($pdo, "
    SELECT DISTINCT ethnic_group
    FROM name_entries
    WHERE ethnic_group IS NOT NULL
      AND TRIM(ethnic_group) <> ''
    ORDER BY ethnic_group ASC
");

/**
 * ------------------------------------------------------------
 * Build query
 * ------------------------------------------------------------
 */
$where = [];
$params = [];

if ($q !== '') {
    $searchClauses = [
        "ne.name LIKE :q",
        "ne.meaning LIKE :q",
        "ne.ethnic_group LIKE :q",
        "ne.region LIKE :q",
        "ne.naming_context LIKE :q",
        "ne.cultural_explanation LIKE :q",
    ];

    if ($hasProfiles && $profileColumns['overview']) {
        $searchClauses[] = "np.overview LIKE :q";
    }
    if ($hasProfiles && $profileColumns['cultural_significance']) {
        $searchClauses[] = "np.cultural_significance LIKE :q";
    }
    if ($hasProfiles && $profileColumns['linguistic_origin']) {
        $searchClauses[] = "np.linguistic_origin LIKE :q";
    }

    $where[] = '(' . implode(' OR ', $searchClauses) . ')';
    $params['q'] = '%' . $q . '%';
}

if ($ethnicGroup !== '') {
    $where[] = "ne.ethnic_group = :ethnic_group";
    $params['ethnic_group'] = $ethnicGroup;
}

if ($gender !== '') {
    $where[] = "ne.gender = :gender";
    $params['gender'] = $gender;
}

if ($status !== '') {
    $where[] = "ne.status = :status";
    $params['status'] = $status;
} elseif (!$isEditorOrAdmin) {
    $where[] = "ne.status = 'approved'";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$orderSql = match ($sort) {
    'oldest' => 'ORDER BY ne.created_at ASC, ne.id ASC',
    'name_asc' => 'ORDER BY ne.name ASC, ne.id ASC',
    'name_desc' => 'ORDER BY ne.name DESC, ne.id DESC',
    default => 'ORDER BY ne.created_at DESC, ne.id DESC',
};

$profileSelectParts = [];
$profileSelectParts[] = ($hasProfiles ? "CASE WHEN np.id IS NULL THEN 0 ELSE 1 END AS has_profile" : "0 AS has_profile");
$profileSelectParts[] = ($hasProfiles && $profileColumns['overview']) ? "np.overview AS overview" : "NULL AS overview";
$profileSelectParts[] = ($hasProfiles && $profileColumns['ai_summary']) ? "np.ai_summary AS ai_summary" : "NULL AS ai_summary";
$profileSelectParts[] = ($hasProfiles && $profileColumns['pronunciation']) ? "np.pronunciation AS pronunciation" : "NULL AS pronunciation";
$profileSelectParts[] = ($hasProfiles && $profileColumns['variants']) ? "np.variants AS variants" : "NULL AS variants";
$profileSelect = implode(",\n        ", $profileSelectParts);

/**
 * ------------------------------------------------------------
 * Totals
 * ------------------------------------------------------------
 */
$totalResults = scalar($pdo, "
    SELECT COUNT(DISTINCT ne.id)
    FROM name_entries ne
    {$profileJoin}
    {$whereSql}
", $params);

$totalPages = max(1, (int)ceil($totalResults / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/**
 * ------------------------------------------------------------
 * Fetch results
 * ------------------------------------------------------------
 */
$sql = "
    SELECT
        ne.id,
        ne.name,
        ne.meaning,
        ne.ethnic_group,
        ne.region,
        ne.gender,
        ne.naming_context,
        ne.cultural_explanation,
        ne.status,
        ne.created_at,
        {$profileSelect},
        COALESCE(u.full_name, u.email, 'Unknown User') AS contributor_name
    FROM name_entries ne
    LEFT JOIN users u ON u.id = ne.created_by
    {$profileJoin}
    {$whereSql}
    GROUP BY ne.id
    {$orderSql}
    LIMIT {$perPage} OFFSET {$offset}
";

$results = fetchAllSafe($pdo, $sql, $params);

/**
 * ------------------------------------------------------------
 * Summary metrics
 * ------------------------------------------------------------
 */
$totalApproved = scalar($pdo, "SELECT COUNT(*) FROM name_entries WHERE status = 'approved'");
$totalProfiles = $hasProfiles ? scalar($pdo, "SELECT COUNT(*) FROM name_profiles") : 0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-shell browse-page">
    <section class="dashboard-hero">
        <div class="dashboard-hero__grid">
            <div>
                <div class="dashboard-hero__eyebrow">Authority Directory</div>
                <h1>Browse Indigenous African Names</h1>
                <p>
                    Explore structured name records, authority profiles, linguistic context, and culturally grounded meanings across the archive.
                </p>

                <div class="quick-actions">
                    <a class="quick-action-btn quick-action-btn--primary" href="/submit.php">Submit a Name</a>
                    <a class="quick-action-btn quick-action-btn--secondary" href="/dashboard.php">Dashboard</a>
                    <?php if ($isEditorOrAdmin): ?>
                        <a class="quick-action-btn quick-action-btn--secondary" href="/admin-review.php">Review Queue</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dashboard-hero__stats">
                <div class="hero-mini-card">
                    <div class="hero-mini-card__label">Approved records</div>
                    <div class="hero-mini-card__value"><?= $totalApproved ?></div>
                </div>
                <div class="hero-mini-card">
                    <div class="hero-mini-card__label">Authority profiles</div>
                    <div class="hero-mini-card__value"><?= $totalProfiles ?></div>
                </div>
                <div class="hero-mini-card">
                    <div class="hero-mini-card__label">Current results</div>
                    <div class="hero-mini-card__value"><?= $totalResults ?></div>
                </div>
                <div class="hero-mini-card">
                    <div class="hero-mini-card__label">Search scope</div>
                    <div class="hero-mini-card__value" style="font-size:1.2rem;">
                        <?= $isEditorOrAdmin ? 'All Records' : 'Published Only' ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Search & Filters</h2>
            <div class="panel__subtle">Refine discovery</div>
        </div>
        <div class="panel__body">
            <form method="get" action="/browse.php" class="submission-form browse-filters-form">
                <div class="browse-filters-grid">
                    <div class="form-group">
                        <label for="q">Search names or context</label>
                        <input
                            type="text"
                            id="q"
                            name="q"
                            value="<?= e($q) ?>"
                            placeholder="e.g. Achieng, rain, Luo, naming context"
                        >
                    </div>

                    <div class="form-group">
                        <label for="ethnic_group">Ethnic Group</label>
                        <select id="ethnic_group" name="ethnic_group">
                            <option value="">All groups</option>
                            <?php foreach ($ethnicGroups as $groupRow): ?>
                                <?php $groupValue = trim((string)($groupRow['ethnic_group'] ?? '')); ?>
                                <?php if ($groupValue === '') continue; ?>
                                <option value="<?= e($groupValue) ?>" <?= $ethnicGroup === $groupValue ? 'selected' : '' ?>>
                                    <?= e($groupValue) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender">
                            <option value="">All</option>
                            <option value="male" <?= $gender === 'male' ? 'selected' : '' ?>>Male</option>
                            <option value="female" <?= $gender === 'female' ? 'selected' : '' ?>>Female</option>
                            <option value="unisex" <?= $gender === 'unisex' ? 'selected' : '' ?>>Unisex</option>
                            <option value="unknown" <?= $gender === 'unknown' ? 'selected' : '' ?>>Unknown</option>
                        </select>
                    </div>

                    <?php if ($isEditorOrAdmin): ?>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="">All statuses</option>
                                <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="sort">Sort</label>
                        <select id="sort" name="sort">
                            <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Most Recent</option>
                            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                            <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option>
                            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option>
                        </select>
                    </div>
                </div>

                <div class="form-actions browse-filter-actions">
                    <button type="submit" class="btn-primary">Apply Filters</button>
                    <a class="btn-secondary" href="/browse.php">Reset</a>
                </div>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Results</h2>
            <div class="panel__subtle">
                <?= $totalResults ?> result<?= $totalResults === 1 ? '' : 's' ?> found
            </div>
        </div>
        <div class="panel__body">
            <?php if (!$results): ?>
                <div class="empty-state">
                    No names matched your current filters. Try broadening your search, changing the ethnic group filter, or resetting all filters.
                </div>
            <?php else: ?>
                <div class="browse-results-grid">
                    <?php foreach ($results as $row): ?>
                        <?php
                            $meaning = trim((string)($row['meaning'] ?? ''));
                            $overview = trim((string)($row['overview'] ?? ''));
                            $aiSummary = trim((string)($row['ai_summary'] ?? ''));
                            $fallback = trim((string)($row['cultural_explanation'] ?? ''));
                            $summary = excerpt($aiSummary !== '' ? $aiSummary : ($overview !== '' ? $overview : ($fallback !== '' ? $fallback : $meaning)));
                        ?>
                        <article class="browse-card">
                            <div class="browse-card__top">
                                <div>
                                    <h3 class="browse-card__title">
                                        <a href="/name.php?id=<?= (int)$row['id'] ?>">
                                            <?= e((string)$row['name']) ?>
                                        </a>
                                    </h3>
                                    <div class="browse-card__meta-inline">
                                        <?php if (!empty($row['ethnic_group'])): ?>
                                            <span class="dashboard-role-badge browse-badge"><?= e((string)$row['ethnic_group']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($row['gender'])): ?>
                                            <span class="dashboard-role-badge browse-badge"><?= e(ucfirst((string)$row['gender'])) ?></span>
                                        <?php endif; ?>
                                        <?php if ($isEditorOrAdmin): ?>
                                            <span class="status-pill <?= e(statusClass((string)($row['status'] ?? ''))) ?>">
                                                <?= e((string)($row['status'] ?? 'unknown')) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ((int)($row['has_profile'] ?? 0) === 1): ?>
                                            <span class="status-pill status--approved">authority profile</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($summary !== ''): ?>
                                <p class="browse-card__summary"><?= e($summary) ?></p>
                            <?php endif; ?>

                            <div class="browse-card__details">
                                <?php if (!empty($row['region'])): ?>
                                    <div><strong>Region:</strong> <?= e((string)$row['region']) ?></div>
                                <?php endif; ?>

                                <?php if (!empty($row['naming_context'])): ?>
                                    <div><strong>Context:</strong> <?= e(excerpt((string)$row['naming_context'], 80)) ?></div>
                                <?php endif; ?>

                                <?php if (!empty($row['pronunciation'])): ?>
                                    <div><strong>Pronunciation:</strong> <?= e((string)$row['pronunciation']) ?></div>
                                <?php endif; ?>

                                <div><strong>Added:</strong> <?= e(formatShortDate((string)$row['created_at'])) ?></div>
                            </div>

                            <div class="browse-card__footer">
                                <a class="quick-action-btn quick-action-btn--primary" href="/name.php?id=<?= (int)$row['id'] ?>">
                                    View Authority Page
                                </a>

                                <?php if ($isEditorOrAdmin): ?>
                                    <a class="quick-action-btn quick-action-btn--secondary" href="/edit-name-profile.php?entry_id=<?= (int)$row['id'] ?>">
                                        Edit Profile
                                    </a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="browse-pagination" aria-label="Pagination">
                        <?php $queryBase = $_GET; ?>

                        <?php if ($page > 1): ?>
                            <?php $prev = $queryBase; $prev['page'] = $page - 1; ?>
                            <a class="browse-page-link" href="/browse.php?<?= e(http_build_query($prev)) ?>">← Previous</a>
                        <?php endif; ?>

                        <span class="browse-page-current">
                            Page <?= $page ?> of <?= $totalPages ?>
                        </span>

                        <?php if ($page < $totalPages): ?>
                            <?php $next = $queryBase; $next['page'] = $page + 1; ?>
                            <a class="browse-page-link" href="/browse.php?<?= e(http_build_query($next)) ?>">Next →</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>