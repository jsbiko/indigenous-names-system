<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Submit Name | Indigenous Names System';

$errors = [];
$successMessage = '';

if (isset($_GET['success'])) {
    $successMessage = 'Your name entry has been submitted successfully and is awaiting editorial review.';
}

$name = trim($_POST['name'] ?? '');
$meaning = trim($_POST['meaning'] ?? '');
$ethnicGroup = trim($_POST['ethnic_group'] ?? '');
$region = trim($_POST['region'] ?? '');
$gender = trim($_POST['gender'] ?? 'unisex');
$namingContext = trim($_POST['naming_context'] ?? '');
$culturalExplanation = trim($_POST['cultural_explanation'] ?? '');
$sources = trim($_POST['sources'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($name === '') $errors[] = 'Name is required.';
    if ($meaning === '') $errors[] = 'Meaning is required.';
    if ($ethnicGroup === '') $errors[] = 'Ethnic group is required.';
    if ($namingContext === '') $errors[] = 'Naming context is required.';
    if (strlen($name) > 150) $errors[] = 'Name must not exceed 150 characters.';

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO name_entries
            (name, meaning, ethnic_group, region, gender, naming_context, cultural_explanation, sources, status, created_by)
            VALUES
            (:name, :meaning, :ethnic_group, :region, :gender, :naming_context, :cultural_explanation, :sources, 'pending', :created_by)
        ");

        $createdBy = currentUser()['id'];

        $stmt->execute([
            ':name' => $name,
            ':meaning' => $meaning,
            ':ethnic_group' => $ethnicGroup,
            ':region' => $region !== '' ? $region : null,
            ':gender' => $gender,
            ':naming_context' => $namingContext,
            ':cultural_explanation' => $culturalExplanation !== '' ? $culturalExplanation : null,
            ':sources' => $sources !== '' ? $sources : null,
            ':created_by' => $createdBy,
        ]);

        header('Location: submit.php?success=1');
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <div class="breadcrumb">
        <a href="index.php">Home</a> /
        <span>Submit</span>
    </div>

    <section class="detail-hero">
        <h1>Submit a New Name</h1>
        <p class="detail-meaning">Contribute culturally grounded name information for editorial review.</p>
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
        <form method="post" action="submit.php" class="submission-form">

            <div class="form-group">
                <label>Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($name) ?>" required>
            </div>

            <div class="form-group">
                <label>Meaning *</label>
                <input type="text" name="meaning" value="<?= htmlspecialchars($meaning) ?>" required>
            </div>

            <div class="form-group">
                <label>Ethnic Group *</label>
                <input type="text" name="ethnic_group" value="<?= htmlspecialchars($ethnicGroup) ?>" required>
            </div>

            <div class="form-group">
                <label>Region</label>
                <input type="text" name="region" value="<?= htmlspecialchars($region) ?>">
            </div>

            <div class="form-group">
                <label>Gender</label>
                <select name="gender">
                    <option value="male" <?= $gender === 'male' ? 'selected' : '' ?>>Male</option>
                    <option value="female" <?= $gender === 'female' ? 'selected' : '' ?>>Female</option>
                    <option value="unisex" <?= $gender === 'unisex' ? 'selected' : '' ?>>Unisex</option>
                    <option value="other" <?= $gender === 'other' ? 'selected' : '' ?>>Other</option>
                </select>
            </div>

            <div class="form-group">
                <label>Naming Context *</label>
                <input type="text" name="naming_context" value="<?= htmlspecialchars($namingContext) ?>" required>
            </div>

            <div class="form-group">
                <label>Cultural Explanation</label>
                <textarea name="cultural_explanation"><?= htmlspecialchars($culturalExplanation) ?></textarea>
            </div>

            <div class="form-group">
                <label>Sources</label>
                <textarea name="sources"><?= htmlspecialchars($sources) ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit">Submit for Review</button>
            </div>

        </form>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>