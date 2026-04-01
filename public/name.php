<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("
    SELECT 
        ne.id,
        ne.name,
        ne.meaning,
        ne.ethnic_group,
        ne.region,
        ne.gender,
        ne.naming_context,
        ne.cultural_explanation,
        ne.sources,
        ne.created_at,
        np.overview,
        np.linguistic_origin,
        np.cultural_significance,
        np.historical_context,
        np.variants,
        np.pronunciation,
        np.related_names,
        np.scholarly_notes,
        np.references_text,
        np.ai_summary,
        np.updated_at AS profile_updated_at
    FROM name_entries ne
    LEFT JOIN name_profiles np ON np.entry_id = ne.id
    WHERE ne.id = :id AND ne.status = 'approved'
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

$isEditorialUser = false;

if (function_exists('isLoggedIn') && function_exists('currentUser') && isLoggedIn()) {
    $currentUser = currentUser();
    $isEditorialUser = isset($currentUser['role']) && in_array($currentUser['role'], ['editor', 'admin'], true);
}

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

        <?php if (isLoggedIn()): ?>
    <div class="detail-card">
        <a class="btn-revision" href="suggest-improvement.php?entry_id=<?= (int)$entry['id'] ?>">
            Suggest Improvement
        </a>
    </div><?php endif; ?>

        <?php if ($isEditorialUser): ?>
            <div class="detail-card">
                <h2>Editorial Tools</h2>
                <p>Manage the authority profile and editorial content for this name entry.</p>
                <p>
                    <a class="btn-approve" href="edit-profile.php?entry_id=<?= (int)$entry['id'] ?>">
                        Edit Authority Profile
                    </a>
                </p>
                <p><a class="btn-revision" href="merge-history.php?entry_id=<?= (int)$entry['id'] ?>">
                    View Merge History
                </a>
                </p>
            </div>
        <?php endif; ?>

        <div class="detail-layout">
            <section class="detail-main">

                <?php if (!empty($entry['overview'])): ?>
                    <div class="detail-card">
                        <h2>Overview</h2>
                        <p><?= nl2br(htmlspecialchars($entry['overview'])) ?></p>
                    </div>
                <?php endif; ?>

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

                    <?php if (!empty($entry['pronunciation'])): ?>
                        <div class="detail-card">
                            <h3>Pronunciation</h3>
                            <p><?= htmlspecialchars($entry['pronunciation']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="detail-card">
                    <h2>Cultural Explanation</h2>
                    <p>
                        <?= $entry['cultural_explanation']
                            ? nl2br(htmlspecialchars($entry['cultural_explanation']))
                            : 'No extended cultural explanation available yet.' ?>
                    </p>
                </div>

                <?php if (!empty($entry['linguistic_origin'])): ?>
                    <div class="detail-card">
                        <h2>Linguistic Origin</h2>
                        <p><?= nl2br(htmlspecialchars($entry['linguistic_origin'])) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($entry['cultural_significance'])): ?>
                    <div class="detail-card">
                        <h2>Cultural Significance</h2>
                        <p><?= nl2br(htmlspecialchars($entry['cultural_significance'])) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($entry['historical_context'])): ?>
                    <div class="detail-card">
                        <h2>Historical Context</h2>
                        <p><?= nl2br(htmlspecialchars($entry['historical_context'])) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($entry['variants'])): ?>
                    <div class="detail-card">
                        <h2>Variants</h2>
                        <p><?= nl2br(htmlspecialchars($entry['variants'])) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($entry['related_names'])): ?>
                    <div class="detail-card">
                        <h2>Related Names</h2>
                        <p><?= nl2br(htmlspecialchars($entry['related_names'])) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($entry['scholarly_notes'])): ?>
                    <div class="detail-card">
                        <h2>Scholarly Notes</h2>
                        <p><?= nl2br(htmlspecialchars($entry['scholarly_notes'])) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($entry['ai_summary'])): ?>
                    <div class="detail-card">
                        <h2>AI Summary</h2>
                        <p><?= nl2br(htmlspecialchars($entry['ai_summary'])) ?></p>
                    </div>
                <?php endif; ?>

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

                <?php if (!empty($entry['references_text'])): ?>
                    <div class="detail-card">
                        <h3>Additional References</h3>
                        <p><?= nl2br(htmlspecialchars($entry['references_text'])) ?></p>
                    </div>
                <?php endif; ?>

                <div class="detail-card">
                    <h3>Record Info</h3>
                    <p>
                        <strong>Status:</strong>
                        <span class="badge badge-approved">Approved</span>
                    </p>
                    <p><strong>Added:</strong> <?= htmlspecialchars($entry['created_at']) ?></p>

                    <?php if (!empty($entry['profile_updated_at'])): ?>
                        <p><strong>Profile Updated:</strong> <?= htmlspecialchars($entry['profile_updated_at']) ?></p>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>