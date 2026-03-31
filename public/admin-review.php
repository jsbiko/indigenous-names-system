<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['editor', 'admin']);

$pageTitle = 'Editorial Review Dashboard';

$successMessage = '';
$errorMessage = '';

/* Handle review action */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entryId = (int)($_POST['entry_id'] ?? 0);
    $action = trim($_POST['action'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $validActions = [
        'approve' => 'approved',
        'reject' => 'rejected',
        'request_revision' => 'revision_requested',
    ];

    if ($entryId <= 0 || !isset($validActions[$action])) {
        $errorMessage = 'Invalid review request.';
    } else {
        $newStatus = match ($action) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'request_revision' => 'pending',
        };

        try {
            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare("
                UPDATE name_entries
                SET status = :status
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':status' => $newStatus,
                ':id' => $entryId,
            ]);

            $reviewStmt = $pdo->prepare("
                INSERT INTO reviews (entry_id, reviewer_id, action, notes)
                VALUES (:entry_id, :reviewer_id, :action, :notes)
            ");
            $reviewStmt->execute([
                ':entry_id' => $entryId,
                ':reviewer_id' => currentUser()['id'],
                ':action' => $validActions[$action],
                ':notes' => $notes !== '' ? $notes : null,
            ]);

            $pdo->commit();
            $successMessage = 'Review action completed successfully.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errorMessage = 'Failed to process review action.';
        }
    }
}

/* Fetch pending submissions */
$pendingStmt = $pdo->query("
    SELECT id, name, ethnic_group, created_at
    FROM name_entries
    WHERE status = 'pending'
    ORDER BY created_at ASC
");
$pendingEntries = $pendingStmt->fetchAll();

/* Determine selected entry */
$selectedId = isset($_GET['id']) ? (int)($_GET['id'] ?? 0) : 0;

if ($selectedId <= 0 && !empty($pendingEntries)) {
    $selectedId = (int)$pendingEntries[0]['id'];
}

$selectedEntry = null;
$reviewHistory = [];

if ($selectedId > 0) {
    $detailStmt = $pdo->prepare("
        SELECT id, name, meaning, ethnic_group, region, gender, naming_context,
               cultural_explanation, sources, created_at, status
        FROM name_entries
        WHERE id = :id
        LIMIT 1
    ");
    $detailStmt->execute([':id' => $selectedId]);
    $selectedEntry = $detailStmt->fetch();

    if ($selectedEntry) {
        $historyStmt = $pdo->prepare("
            SELECT r.action, r.notes, r.created_at, u.full_name
            FROM reviews r
            LEFT JOIN users u ON r.reviewer_id = u.id
            WHERE r.entry_id = :entry_id
            ORDER BY r.created_at DESC
        ");
        $historyStmt->execute([':entry_id' => $selectedEntry['id']]);
        $reviewHistory = $historyStmt->fetchAll();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <section class="detail-hero">
        <h1>Editorial Review Dashboard</h1>
        <p class="detail-meaning">Review pending submissions before publication.</p>
    </section>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <div class="review-layout">

        <aside class="review-sidebar detail-card">
            <h2>Pending</h2>

            <?php if (!empty($pendingEntries)): ?>
                <div class="pending-list">
                    <?php foreach ($pendingEntries as $entry): ?>
                        <a
                            class="pending-item <?= ((int)$entry['id'] === $selectedId) ? 'pending-item-active' : '' ?>"
                            href="admin-review.php?id=<?= (int)$entry['id'] ?>"
                        >
                            <strong><?= htmlspecialchars($entry['name']) ?></strong><br>
                            <span><?= htmlspecialchars($entry['ethnic_group']) ?></span><br>
                            <small><?= htmlspecialchars($entry['created_at']) ?></small><br>
                            <span class="badge badge-pending">Pending</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>No pending submissions.</p>
            <?php endif; ?>
        </aside>

        <section class="review-main">
            <?php if ($selectedEntry): ?>

                <div class="detail-card">
                    <h2><?= htmlspecialchars($selectedEntry['name']) ?></h2>

                    <div class="detail-grid">
                        <div>
                            <h3>Meaning</h3>
                            <p><?= htmlspecialchars($selectedEntry['meaning']) ?></p>
                        </div>

                        <div>
                            <h3>Ethnic Group</h3>
                            <p><?= htmlspecialchars($selectedEntry['ethnic_group']) ?></p>
                        </div>

                        <div>
                            <h3>Region</h3>
                            <p><?= htmlspecialchars($selectedEntry['region'] ?: 'Not specified') ?></p>
                        </div>

                        <div>
                            <h3>Gender</h3>
                            <p><?= htmlspecialchars(ucfirst($selectedEntry['gender'])) ?></p>
                        </div>

                        <div>
                            <h3>Naming Context</h3>
                            <p><?= htmlspecialchars($selectedEntry['naming_context'] ?: 'Not specified') ?></p>
                        </div>

                        <div>
                            <h3>Status</h3>
                            <p><span class="badge badge-pending">Pending</span></p>
                        </div>
                    </div>

                    <div class="review-block">
                        <h3>Cultural Explanation</h3>
                        <p>
                            <?= $selectedEntry['cultural_explanation']
                                ? nl2br(htmlspecialchars($selectedEntry['cultural_explanation']))
                                : 'No cultural explanation provided.' ?>
                        </p>
                    </div>

                    <div class="review-block">
                        <h3>Sources / References</h3>
                        <p>
                            <?= $selectedEntry['sources']
                                ? nl2br(htmlspecialchars($selectedEntry['sources']))
                                : 'No sources provided.' ?>
                        </p>
                    </div>
                </div>

                <div class="detail-card">
                    <h2>Review</h2>

                    <form method="post" action="admin-review.php?id=<?= (int)$selectedEntry['id'] ?>">
                        <input type="hidden" name="entry_id" value="<?= (int)$selectedEntry['id'] ?>">

                        <div class="form-group">
                            <label for="notes">Reviewer Notes / Revision Message</label>
                            <textarea
                                id="notes"
                                name="notes"
                                rows="5"
                                placeholder="Add review comments, rejection reasons, or revision guidance..."
                            ></textarea>
                        </div>

                        <div class="review-actions">
                            <button type="submit" name="action" value="approve" class="btn-approve">Approve Entry</button>
                            <button type="submit" name="action" value="reject" class="btn-reject">Reject Entry</button>
                            <button type="submit" name="action" value="request_revision" class="btn-revision">Request Revision</button>
                        </div>
                    </form>
                </div>

                <div class="detail-card">
                    <h2>Review History</h2>

                    <?php if (!empty($reviewHistory)): ?>
                        <div class="history-list">
                            <?php foreach ($reviewHistory as $item): ?>
                                <div class="history-item">
                                    <p><strong>Action:</strong> <?= htmlspecialchars($item['action']) ?></p>
                                    <p><strong>Reviewer:</strong> <?= htmlspecialchars($item['full_name'] ?? 'Unknown') ?></p>
                                    <p><strong>Date:</strong> <?= htmlspecialchars($item['created_at']) ?></p>
                                    <p>
                                        <strong>Notes:</strong>
                                        <?= $item['notes']
                                            ? nl2br(htmlspecialchars($item['notes']))
                                            : 'No notes provided.' ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p>No review history available yet.</p>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div class="detail-card">
                    <h2>No Submission Selected</h2>
                    <p>There are currently no pending submissions to review.</p>
                </div>
            <?php endif; ?>
        </section>

    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>