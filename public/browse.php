<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Browse Names | Indigenous Names System';

$search = trim($_GET['q'] ?? '');
$ethnicGroup = trim($_GET['ethnic_group'] ?? '');
$region = trim($_GET['region'] ?? '');
$gender = trim($_GET['gender'] ?? '');
$namingContext = trim($_GET['naming_context'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 6;
$offset = ($page - 1) * $perPage;

$where = ["status = 'approved'"];
$params = [];

if ($search !== '') {
    $where[] = "(name LIKE :search OR meaning LIKE :search OR ethnic_group LIKE :search OR region LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if ($ethnicGroup !== '') {
    $where[] = "ethnic_group = :ethnic_group";
    $params[':ethnic_group'] = $ethnicGroup;
}

if ($region !== '') {
    $where[] = "region = :region";
    $params[':region'] = $region;
}

if ($gender !== '') {
    $where[] = "gender = :gender";
    $params[':gender'] = $gender;
}

if ($namingContext !== '') {
    $where[] = "naming_context = :naming_context";
    $params[':naming_context'] = $namingContext;
}

$whereSql = implode(' AND ', $where);

/* Count total */
$countSql = "SELECT COUNT(*) FROM name_entries WHERE {$whereSql}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalResults = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalResults / $perPage));

/* Fetch results */
$sql = "
    SELECT id, name, meaning, ethnic_group, region, gender, naming_context
    FROM name_entries
    WHERE {$whereSql}
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$names = $stmt->fetchAll();

/* Filter dropdown data */
$ethnicGroups = $pdo->query("SELECT DISTINCT ethnic_group FROM name_entries WHERE status = 'approved' AND ethnic_group IS NOT NULL AND ethnic_group != '' ORDER BY ethnic_group")->fetchAll(PDO::FETCH_COLUMN);
$regions = $pdo->query("SELECT DISTINCT region FROM name_entries WHERE status = 'approved' AND region IS NOT NULL AND region != '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);
$genders = $pdo->query("SELECT DISTINCT gender FROM name_entries WHERE status = 'approved' AND gender IS NOT NULL AND gender != '' ORDER BY gender")->fetchAll(PDO::FETCH_COLUMN);
$contexts = $pdo->query("SELECT DISTINCT naming_context FROM name_entries WHERE status = 'approved' AND naming_context IS NOT NULL AND naming_context != '' ORDER BY naming_context")->fetchAll(PDO::FETCH_COLUMN);

function buildPageUrl(int $pageNum): string
{
    $query = $_GET;
    $query['page'] = $pageNum;
    return 'browse.php?' . http_build_query($query);
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <h1>Browse Names</h1>

    <form method="get" class="browse-layout">
        <aside class="filters-panel">
            <h2>Filters</h2>

            <div class="form-group">
                <label for="q">Search</label>
                <input type="text" id="q" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search names, meanings, communities">
            </div>

            <div class="form-group">
                <label for="ethnic_group">Ethnic Group</label>
                <select id="ethnic_group" name="ethnic_group">
                    <option value="">All</option>
                    <?php foreach ($ethnicGroups as $group): ?>
                        <option value="<?= htmlspecialchars($group) ?>" <?= $ethnicGroup === $group ? 'selected' : '' ?>>
                            <?= htmlspecialchars($group) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="region">Region</label>
                <select id="region" name="region">
                    <option value="">All</option>
                    <?php foreach ($regions as $item): ?>
                        <option value="<?= htmlspecialchars($item) ?>" <?= $region === $item ? 'selected' : '' ?>>
                            <?= htmlspecialchars($item) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="gender">Gender</label>
                <select id="gender" name="gender">
                    <option value="">All</option>
                    <?php foreach ($genders as $item): ?>
                        <option value="<?= htmlspecialchars($item) ?>" <?= $gender === $item ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst($item)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="naming_context">Naming Context</label>
                <select id="naming_context" name="naming_context">
                    <option value="">All</option>
                    <?php foreach ($contexts as $item): ?>
                        <option value="<?= htmlspecialchars($item) ?>" <?= $namingContext === $item ? 'selected' : '' ?>>
                            <?= htmlspecialchars($item) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit">Apply Filters</button>
                <a class="button-link" href="browse.php">Reset</a>
            </div>
        </aside>

        <section class="results-panel">
            <div class="results-header">
                <h2>Results</h2>
                <p><?= $totalResults ?> name(s) found</p>
            </div>

            <?php if (!empty($names)): ?>
                <div class="name-list">
                    <?php foreach ($names as $entry): ?>
                        <div class="name-row">
                            <div class="name-col strong"><?= htmlspecialchars($entry['name']) ?></div>
                            <div class="name-col"><?= htmlspecialchars($entry['meaning']) ?></div>
                            <div class="name-col"><?= htmlspecialchars($entry['ethnic_group']) ?></div>
                            <div class="name-col">
                                <a href="name.php?id=<?= (int) $entry['id'] ?>">View</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="<?= htmlspecialchars(buildPageUrl($page - 1)) ?>">Prev</a>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="<?= htmlspecialchars(buildPageUrl($i)) ?>" class="<?= $i === $page ? 'active-page' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= htmlspecialchars(buildPageUrl($page + 1)) ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p>No names matched your search.</p>
            <?php endif; ?>
        </section>
    </form>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>