<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Suggest Improvement';

$errors = [];
$successMessage = '';

$entryId = isset($_GET['entry_id']) ? (int)$_GET['entry_id'] : 0;

/* Fetch base entry */
$stmt = $pdo->prepare("
    SELECT id, name, ethnic_group, meaning
    FROM name_entries
    WHERE id = :id AND status = 'approved'
    LIMIT 1
");
$stmt->execute([':id' => $entryId]);
$entry = $stmt->fetch();

if (!$entry) {
    http_response_code(404);
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container page-section">
        <h1>Entry Not Found</h1>
        <p>This name entry does not exist or is not publicly available.</p>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Form values */
$proposedMeaning = trim($_POST['proposed_meaning'] ?? '');
$proposedCulturalExplanation = trim($_POST['proposed_cultural_explanation'] ?? '');
$proposedSources = trim($_POST['proposed_sources'] ?? '');
$contributorNotes = trim($_POST['contributor_notes'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($proposedMeaning === '' && $proposedCulturalExplanation === '' && $proposedSources === '') {
        $errors[] = 'Please provide at least one improvement (meaning, cultural explanation, or sources).';
    }

    if (empty($errors)) {

        $stmt = $pdo->prepare("
            INSERT INTO name_suggestions
            (
                entry_id,
                suggested_by,
                suggestion_type,
                proposed_meaning,
                proposed_cultural_explanation,
                proposed_sources,
                contributor_notes
            )
            VALUES
            (
                :entry_id,
                :suggested_by,
                'general',
                :proposed_meaning,
                :proposed_cultural_explanation,
                :proposed_sources,
                :contributor_notes
            )
        ");

        $stmt->execute([
            ':entry_id' => $entryId,
            ':suggested_by' => currentUser()['id'],
            ':proposed_meaning' => $proposedMeaning !== '' ? $proposedMeaning : null,
            ':proposed_cultural_explanation' => $proposedCulturalExplanation !== '' ? $proposedCulturalExplanation : null,
            ':proposed_sources' => $proposedSources !== '' ? $proposedSources : null,
            ':contributor_notes' => $contributorNotes !== '' ? $contributorNotes : null,
        ]);

        header('Location: suggest-improvement.php?entry_id=' . $entryId . '&success=1');
        exit;
    }
}

if (isset($_GET['success'])) {
    $successMessage = 'Your suggestion has been submitted for editorial review.';
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">

    <div class="breadcrumb">
        <a href="index.php">Home</a> /
        <a href="name.php?id=<?= (int)$entry['id'] ?>"><?= htmlspecialchars($entry['name']) ?></a> /
        <span>Suggest Improvement</span>
    </div>

    <section class="detail-hero">
        <h1>Suggest Improvement</h1>
        <p class="detail-meaning">
            Help improve the knowledge quality for <strong><?= htmlspecialchars($entry['name']) ?></strong>.
        </p>
    </section>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($successMessage) ?>
        </div>
    <?php endif; ?>

    <section class="detail-card">

        <h2>Current Meaning</h2>
        <p><?= htmlspecialchars($entry['meaning']) ?></p>

        <form method="post" action="suggest-improvement.php?entry_id=<?= (int)$entry['id'] ?>">

            <div class="form-group">
                <label>Proposed Meaning</label>
                <input type="text" name="proposed_meaning" value="<?= htmlspecialchars($proposedMeaning) ?>">
            </div>

            <div class="form-group">
                <label>Proposed Cultural Explanation</label>
                <textarea name="proposed_cultural_explanation"><?= htmlspecialchars($proposedCulturalExplanation) ?></textarea>
            </div>

            <div class="form-group">
                <label>Proposed Sources / References</label>
                <textarea name="proposed_sources"><?= htmlspecialchars($proposedSources) ?></textarea>
            </div>

            <div class="form-group">
                <label>Additional Notes (Optional)</label>
                <textarea name="contributor_notes"><?= htmlspecialchars($contributorNotes) ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit">Submit Suggestion</button>
            </div>

        </form>

    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>