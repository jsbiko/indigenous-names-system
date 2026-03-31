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

            $pdo->prepare("
                UPDATE name_entries
                SET status = :status
                WHERE id = :id
            ")->execute([
                ':status' => $newStatus,
                ':id' => $entryId,
            ]);

            $pdo->prepare("
                INSERT INTO reviews (entry_id, reviewer_id, action, notes)
                VALUES (:entry_id, :reviewer_id, :action, :notes)
            ")->execute([
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

/* Fetch pending */
$pendingEntries = $pdo->query("
    SELECT id, name, ethnic_group, created_at
    FROM name_entries
    WHERE status = 'pending'
    ORDER BY created_at ASC
")->fetchAll();

/* Selected */
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($selectedId <= 0 && !empty($pendingEntries)) {
    $selectedId = (int)$pendingEntries[0]['id'];
}

$selectedEntry = null;

if ($selectedId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM name_entries
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $selectedId]);
    $selectedEntry = $stmt->fetch();
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <section class="detail-hero">
        <h1>Editorial Review Dashboard</h1>
        <p class="detail-meaning">Review pending submissions before publication.</p>
    </section>

    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <div class="review-layout">

        <!-- LEFT: pending list -->
        <aside class="review-sidebar detail-card">
            <h2>Pending</h2>

            <?php if ($pendingEntries): ?>
                <?php foreach ($pendingEntries as $entry): ?>
                    <a class="pending-item <?= $entry['id'] == $selectedId ? 'pending-item-active' : '' ?>"
                       href="admin-review.php?id=<?= (int)$entry['id'] ?>">
                        <strong><?= htmlspecialchars($entry['name']) ?></strong><br>
                        <span><?= htmlspecialchars($entry['ethnic_group']) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No pending submissions.</p>
            <?php endif; ?>
        </aside>

        <!-- RIGHT: details -->
        <section class="review-main">
            <?php if ($selectedEntry): ?>

                <div class="detail-card">
                    <h2><?= htmlspecialchars($selectedEntry['name']) ?></h2>
                    <p><strong>Meaning:</strong> <?= htmlspecialchars($selectedEntry['meaning']) ?></p>
                    <p><strong>Ethnic Group:</strong> <?= htmlspecialchars($selectedEntry['ethnic_group']) ?></p>
                    <p><strong>Context:</strong> <?= htmlspecialchars($selectedEntry['naming_context']) ?></p>
                </div>

                <div class="detail-card">
                    <h2>Review</h2>

                    <form method="post">
                        <input type="hidden" name="entry_id" value="<?= (int)$selectedEntry['id'] ?>">

                        <textarea name="notes" placeholder="Reviewer notes..."></textarea>

                        <div class="review-actions">
                            <button name="action" value="approve">Approve</button>
                            <button name="action" value="reject">Reject</button>
                            <button name="action" value="request_revision">Request Revision</button>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <p>No selection</p>
            <?php endif; ?>
        </section>

    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>