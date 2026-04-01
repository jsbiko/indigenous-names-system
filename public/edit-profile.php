<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['editor', 'admin']);

$pageTitle = 'Edit Name Authority Profile';

$successMessage = '';
$errorMessage = '';

$entryId = isset($_GET['entry_id']) ? (int)$_GET['entry_id'] : 0;

if ($entryId <= 0) {
    http_response_code(400);
    $pageTitle = 'Invalid Profile Request';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container page-section">
        <section class="detail-card">
            <h1>Invalid Request</h1>
            <p>A valid name entry was not specified.</p>
            <p><a href="admin-review.php">Return to review dashboard</a></p>
        </section>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Fetch entry */
$entryStmt = $pdo->prepare("
    SELECT id, name, meaning, ethnic_group, region, gender, naming_context,
           cultural_explanation, sources, status
    FROM name_entries
    WHERE id = :id
    LIMIT 1
");
$entryStmt->execute([':id' => $entryId]);
$entry = $entryStmt->fetch();

if (!$entry) {
    http_response_code(404);
    $pageTitle = 'Name Entry Not Found';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container page-section">
        <section class="detail-card">
            <h1>Entry Not Found</h1>
            <p>The requested name entry does not exist.</p>
            <p><a href="admin-review.php">Return to review dashboard</a></p>
        </section>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Fetch existing profile if present */
$profileStmt = $pdo->prepare("
    SELECT id, overview, linguistic_origin, cultural_significance,
           historical_context, variants, pronunciation, related_names,
           scholarly_notes, references_text, ai_summary, updated_at
    FROM name_profiles
    WHERE entry_id = :entry_id
    LIMIT 1
");
$profileStmt->execute([':entry_id' => $entryId]);
$profile = $profileStmt->fetch();

/* Defaults for form repopulation */
$formData = [
    'overview' => $profile['overview'] ?? '',
    'linguistic_origin' => $profile['linguistic_origin'] ?? '',
    'cultural_significance' => $profile['cultural_significance'] ?? '',
    'historical_context' => $profile['historical_context'] ?? '',
    'variants' => $profile['variants'] ?? '',
    'pronunciation' => $profile['pronunciation'] ?? '',
    'related_names' => $profile['related_names'] ?? '',
    'scholarly_notes' => $profile['scholarly_notes'] ?? '',
    'references_text' => $profile['references_text'] ?? '',
    'ai_summary' => $profile['ai_summary'] ?? '',
];

/* Handle submit */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'overview' => trim($_POST['overview'] ?? ''),
        'linguistic_origin' => trim($_POST['linguistic_origin'] ?? ''),
        'cultural_significance' => trim($_POST['cultural_significance'] ?? ''),
        'historical_context' => trim($_POST['historical_context'] ?? ''),
        'variants' => trim($_POST['variants'] ?? ''),
        'pronunciation' => trim($_POST['pronunciation'] ?? ''),
        'related_names' => trim($_POST['related_names'] ?? ''),
        'scholarly_notes' => trim($_POST['scholarly_notes'] ?? ''),
        'references_text' => trim($_POST['references_text'] ?? ''),
        'ai_summary' => trim($_POST['ai_summary'] ?? ''),
    ];

    try {
        if ($profile) {
            $updateStmt = $pdo->prepare("
                UPDATE name_profiles
                SET overview = :overview,
                    linguistic_origin = :linguistic_origin,
                    cultural_significance = :cultural_significance,
                    historical_context = :historical_context,
                    variants = :variants,
                    pronunciation = :pronunciation,
                    related_names = :related_names,
                    scholarly_notes = :scholarly_notes,
                    references_text = :references_text,
                    ai_summary = :ai_summary,
                    last_edited_by = :last_edited_by
                WHERE entry_id = :entry_id
            ");

            $updateStmt->execute([
                ':overview' => $formData['overview'] !== '' ? $formData['overview'] : null,
                ':linguistic_origin' => $formData['linguistic_origin'] !== '' ? $formData['linguistic_origin'] : null,
                ':cultural_significance' => $formData['cultural_significance'] !== '' ? $formData['cultural_significance'] : null,
                ':historical_context' => $formData['historical_context'] !== '' ? $formData['historical_context'] : null,
                ':variants' => $formData['variants'] !== '' ? $formData['variants'] : null,
                ':pronunciation' => $formData['pronunciation'] !== '' ? $formData['pronunciation'] : null,
                ':related_names' => $formData['related_names'] !== '' ? $formData['related_names'] : null,
                ':scholarly_notes' => $formData['scholarly_notes'] !== '' ? $formData['scholarly_notes'] : null,
                ':references_text' => $formData['references_text'] !== '' ? $formData['references_text'] : null,
                ':ai_summary' => $formData['ai_summary'] !== '' ? $formData['ai_summary'] : null,
                ':last_edited_by' => (int)currentUser()['id'],
                ':entry_id' => $entryId,
            ]);
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO name_profiles (
                    entry_id,
                    overview,
                    linguistic_origin,
                    cultural_significance,
                    historical_context,
                    variants,
                    pronunciation,
                    related_names,
                    scholarly_notes,
                    references_text,
                    ai_summary,
                    last_edited_by
                ) VALUES (
                    :entry_id,
                    :overview,
                    :linguistic_origin,
                    :cultural_significance,
                    :historical_context,
                    :variants,
                    :pronunciation,
                    :related_names,
                    :scholarly_notes,
                    :references_text,
                    :ai_summary,
                    :last_edited_by
                )
            ");

            $insertStmt->execute([
                ':entry_id' => $entryId,
                ':overview' => $formData['overview'] !== '' ? $formData['overview'] : null,
                ':linguistic_origin' => $formData['linguistic_origin'] !== '' ? $formData['linguistic_origin'] : null,
                ':cultural_significance' => $formData['cultural_significance'] !== '' ? $formData['cultural_significance'] : null,
                ':historical_context' => $formData['historical_context'] !== '' ? $formData['historical_context'] : null,
                ':variants' => $formData['variants'] !== '' ? $formData['variants'] : null,
                ':pronunciation' => $formData['pronunciation'] !== '' ? $formData['pronunciation'] : null,
                ':related_names' => $formData['related_names'] !== '' ? $formData['related_names'] : null,
                ':scholarly_notes' => $formData['scholarly_notes'] !== '' ? $formData['scholarly_notes'] : null,
                ':references_text' => $formData['references_text'] !== '' ? $formData['references_text'] : null,
                ':ai_summary' => $formData['ai_summary'] !== '' ? $formData['ai_summary'] : null,
                ':last_edited_by' => (int)currentUser()['id'],
            ]);
        }

        header('Location: edit-profile.php?entry_id=' . $entryId . '&saved=1');
        exit;
    } catch (Throwable $e) {
        $errorMessage = 'Failed to save the authority profile.';
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $successMessage = 'Authority profile saved successfully.';
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <section class="detail-hero">
        <h1>Edit Authority Profile</h1>
        <p class="detail-meaning">
            Build the richer knowledge page for <strong><?= htmlspecialchars($entry['name']) ?></strong>.
        </p>
    </section>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <div class="review-layout">
        <aside class="review-sidebar detail-card">
            <h2>Base Entry</h2>

            <p><strong>Name:</strong><br><?= htmlspecialchars($entry['name']) ?></p>
            <p><strong>Meaning:</strong><br><?= htmlspecialchars($entry['meaning']) ?></p>
            <p><strong>Ethnic Group:</strong><br><?= htmlspecialchars($entry['ethnic_group']) ?></p>
            <p><strong>Region:</strong><br><?= htmlspecialchars($entry['region'] ?: 'Not specified') ?></p>
            <p><strong>Gender:</strong><br><?= htmlspecialchars(ucfirst($entry['gender'])) ?></p>
            <p><strong>Status:</strong><br><?= htmlspecialchars(ucfirst($entry['status'])) ?></p>

            <hr>

            <p>
                <a href="name.php?id=<?= (int)$entry['id'] ?>">View public page</a>
            </p>
            <p>
                <a href="admin-review.php?id=<?= (int)$entry['id'] ?>">Back to review dashboard</a>
            </p>
        </aside>

        <section class="review-main">
            <div class="detail-card">
                <h2>Authority Profile Content</h2>

                <form method="post" action="edit-profile.php?entry_id=<?= (int)$entry['id'] ?>">
                    <div class="form-group">
                        <label for="overview">Overview</label>
                        <textarea id="overview" name="overview" rows="5"><?= htmlspecialchars($formData['overview']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="linguistic_origin">Linguistic Origin</label>
                        <textarea id="linguistic_origin" name="linguistic_origin" rows="4"><?= htmlspecialchars($formData['linguistic_origin']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="cultural_significance">Cultural Significance</label>
                        <textarea id="cultural_significance" name="cultural_significance" rows="5"><?= htmlspecialchars($formData['cultural_significance']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="historical_context">Historical Context</label>
                        <textarea id="historical_context" name="historical_context" rows="5"><?= htmlspecialchars($formData['historical_context']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="variants">Variants</label>
                        <textarea id="variants" name="variants" rows="3" placeholder="Alternative spellings, dialectal forms, regional variants..."><?= htmlspecialchars($formData['variants']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="pronunciation">Pronunciation</label>
                        <input
                            type="text"
                            id="pronunciation"
                            name="pronunciation"
                            value="<?= htmlspecialchars($formData['pronunciation']) ?>"
                            maxlength="255"
                            placeholder="Example: ah-KEEN-yee"
                        >
                    </div>

                    <div class="form-group">
                        <label for="related_names">Related Names</label>
                        <textarea id="related_names" name="related_names" rows="3"><?= htmlspecialchars($formData['related_names']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="scholarly_notes">Scholarly Notes</label>
                        <textarea id="scholarly_notes" name="scholarly_notes" rows="5"><?= htmlspecialchars($formData['scholarly_notes']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="references_text">References / Citations</label>
                        <textarea id="references_text" name="references_text" rows="5"><?= htmlspecialchars($formData['references_text']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="ai_summary">AI Summary</label>
                        <textarea id="ai_summary" name="ai_summary" rows="4" placeholder="Optional short AI-assisted summary for the lower section of the profile page."><?= htmlspecialchars($formData['ai_summary']) ?></textarea>
                    </div>

                    <div class="review-actions">
                        <button type="submit" class="btn-approve">Save Authority Profile</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>