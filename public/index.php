<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Indigenous African Names Knowledge System';

$stmt = $pdo->prepare("
    SELECT id, name, meaning, ethnic_group
    FROM name_entries
    WHERE status = 'approved'
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute();
$featuredNames = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<main>
    <section class="hero">
        <div class="container">
            <h1>Discover Indigenous African Names</h1>
            <p>Explore meanings, origins, and cultural context.</p>

            <form class="search-bar" action="browse.php" method="get">
                <input type="text" name="q" placeholder="Search names, meanings, or communities...">
                <button type="submit">Search</button>
            </form>
        </div>
    </section>

    <section class="categories container">
        <h2>Browse by Category</h2>
        <div class="category-grid">
            <div class="card">By Ethnic Group</div>
            <div class="card">By Region</div>
            <div class="card">By Naming Context</div>
        </div>
    </section>

    <section class="featured container">
        <h2>Featured & Recently Added Names</h2>

        <?php if (!empty($featuredNames)): ?>
            <div class="name-list">
                <?php foreach ($featuredNames as $entry): ?>
                    <div class="name-row">
                        <div class="name-col strong"><?= htmlspecialchars($entry['name']) ?></div>
                        <div class="name-col"><?= htmlspecialchars($entry['meaning']) ?></div>
                        <div class="name-col"><?= htmlspecialchars($entry['ethnic_group']) ?></div>
                        <div class="name-col">
                            <a href="name.php?id=<?= (int)$entry['id'] ?>">View</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>No approved names available yet.</p>
        <?php endif; ?>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>