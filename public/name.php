<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("
    SELECT id, name, meaning, ethnic_group, region, gender, naming_context, cultural_explanation, sources, created_at
    FROM name_entries
    WHERE id = :id AND status = 'approved'
    LIMIT 1
");
$stmt->execute([':id' => $id]);

$entry = $stmt->fetch();

if (!$entry) {
    http_response_code(404);
}

$pageTitle = $entry
    ? $entry['name'] . ' | Indigenous Names System'
    : 'Name Not Found | Indigenous Names System';

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <?php if (!$entry): ?>
        <h1>Name Not Found</h1>
        <p>The requested name record does not exist or is not publicly available.</p>
        <p><a href="browse.php">Back to Browse</a></p>
    <?php else: ?>
        <div class="breadcrumb">
            <a href="index.php">Home</a> /
            <a href="browse.php">Browse</a> /
            <span><?= htmlspecialchars($entry['name']) ?></span>
        </div>

        <section class="detail-hero">
            <h1><?= htmlspecialchars($entry['name']) ?></h1>
            <p class="detail-meaning"><?= htmlspecialchars($entry['meaning']) ?></p>
        </section>

        <div class="detail-layout">
            <section class="detail-main">
                <div class="detail-card">
                    <h2>Meaning</h2>
                    <p><?= nl2br(htmlspecialchars($entry['meaning'])) ?></p>
                </div>

                <div class="detail-grid">
                    <div class="detail-card">
                        <h3>Ethnic Group</h3>
                        <p><?= htmlspecialchars($entry['ethnic_group']) ?></p>
                    </div>

                    <div class="detail-card">
                        <h3>Region</h3>
                        <p><?= htmlspecialchars($entry['region'] ?: 'Not specified') ?></p>
                    </div>

                    <div class="detail-card">
                        <h3>Gender</h3>
                        <p><?= htmlspecialchars(ucfirst($entry['gender'])) ?></p>
                    </div>

                    <div class="detail-card">
                        <h3>Naming Context</h3>
                        <p><?= htmlspecialchars($entry['naming_context'] ?: 'Not specified') ?></p>
                    </div>
                </div>

                <div class="detail-card">
                    <h2>Cultural Explanation</h2>
                    <p>
                        <?= $entry['cultural_explanation']
                            ? nl2br(htmlspecialchars($entry['cultural_explanation']))
                            : 'No extended cultural explanation available yet.' ?>
                    </p>
                </div>
            </section>

            <aside class="detail-sidebar">
                <div class="detail-card">
                    <h3>Sources / References</h3>
                    <p>
                        <?= $entry['sources']
                            ? nl2br(htmlspecialchars($entry['sources']))
                            : 'No sources provided.' ?>
                    </p>
                </div>

                <div class="detail-card">
                    <h3>Record Info</h3>
                    <p>
                        <strong>Status:</strong>
                        <span class="badge badge-approved">Approved</span>
                    </p>
                    <p><strong>Added:</strong> <?= htmlspecialchars($entry['created_at']) ?></p>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>