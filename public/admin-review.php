<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['editor', 'admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* Fetch current entry */
$stmt = $pdo->prepare("
    SELECT *
    FROM name_entries
    WHERE id = :id AND status = 'pending'
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$entry = $stmt->fetch();

/* Sidebar: pending list */
$listStmt = $pdo->query("
    SELECT id, name, ethnic_group
    FROM name_entries
    WHERE status = 'pending'
    ORDER BY created_at ASC
");
$pendingList = $listStmt->fetchAll();

$pageTitle = 'Review Submissions | Indigenous African Names System';

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <section class="detail-hero">
        <span class="eyebrow">Editorial Review</span>
        <h1>Review Name Submissions</h1>
        <p class="detail-meaning">
            Evaluate submitted names, verify cultural accuracy, and approve or reject entries.
        </p>
    </section>

    <div class="review-layout">

        <!-- LEFT: Pending List -->
        <aside class="review-sidebar detail-card">
            <h2>Pending Entries</h2>

            <?php if (!empty($pendingList)): ?>
                <div class="pending-list">
                    <?php foreach ($pendingList as $item): ?>
                        <a
                            href="admin-review.php?id=<?= (int)$item['id'] ?>"
                            class="pending-item <?= $id === (int)$item['id'] ? 'pending-item-active' : '' ?>"
                        >
                            <strong><?= htmlspecialchars($item['name']) ?></strong>
                            <small><?= htmlspecialchars($item['ethnic_group']) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>No pending submissions.</p>
            <?php endif; ?>
        </aside>

        <!-- RIGHT: Review Content -->
        <section class="review-main">

            <?php if (!$entry): ?>
                <div class="detail-card">
                    <h2>Select an entry</h2>
                    <p>Choose a pending submission from the left to begin reviewing.</p>
                </div>
            <?php else: ?>

                <!-- ENTRY DETAILS -->
                <div class="detail-card">
                    <h2><?= htmlspecialchars($entry['name']) ?></h2>
                    <p class="detail-meaning"><?= htmlspecialchars($entry['meaning']) ?></p>

                    <div class="detail-grid">
                        <div>
                            <strong>Ethnic Group</strong>
                            <p><?= htmlspecialchars($entry['ethnic_group']) ?></p>
                        </div>

                        <div>
                            <strong>Region</strong>
                            <p><?= htmlspecialchars($entry['region'] ?: 'Not specified') ?></p>
                        </div>

                        <div>
                            <strong>Gender</strong>
                            <p><?= htmlspecialchars($entry['gender']) ?></p>
                        </div>

                        <div>
                            <strong>Naming Context</strong>
                            <p><?= htmlspecialchars($entry['naming_context'] ?: 'Not specified') ?></p>
                        </div>
                    </div>

                    <div class="review-block">
                        <h3>Cultural Explanation</h3>
                        <p><?= nl2br(htmlspecialchars($entry['cultural_explanation'] ?? '')) ?></p>
                    </div>

                    <div class="review-block">
                        <h3>Sources</h3>
                        <p><?= nl2br(htmlspecialchars($entry['sources'] ?? '')) ?></p>
                    </div>
                </div>

                <!-- ACTIONS -->
                <div class="detail-card">
                    <h2>Editorial Decision</h2>

                    <form method="post" action="process-review.php">
                        <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">

                        <div class="form-group">
                            <label for="notes">Review Notes (optional)</label>
                            <textarea name="notes" id="notes" rows="4"></textarea>
                        </div>

                        <div class="review-actions">
                            <button class="btn-approve" name="action" value="approved">
                                Approve
                            </button>

                            <button class="btn-reject" name="action" value="rejected">
                                Reject
                            </button>

                            <button class="btn-revision" name="action" value="revision_requested">
                                Request Revision
                            </button>
                        </div>
                    </form>
                </div>

            <?php endif; ?>
        </section>

    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>