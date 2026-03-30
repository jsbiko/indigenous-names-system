<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

$errors = [];
$successMessage = '';

$name = trim($_POST['name'] ?? '');
$meaning = trim($_POST['meaning'] ?? '');
$ethnicGroup = trim($_POST['ethnic_group'] ?? '');
$region = trim($_POST['region'] ?? '');
$gender = trim($_POST['gender'] ?? 'unisex');
$namingContext = trim($_POST['naming_context'] ?? '');
$culturalExplanation = trim($_POST['cultural_explanation'] ?? '');
$sources = trim($_POST['sources'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($name === '') {
        $errors[] = 'Name is required.';
    }

    if ($meaning === '') {
        $errors[] = 'Meaning is required.';
    }

    if ($ethnicGroup === '') {
        $errors[] = 'Ethnic group is required.';
    }

    if ($namingContext === '') {
        $errors[] = 'Naming context is required.';
    }

    if (strlen($name) > 150) {
        $errors[] = 'Name must not exceed 150 characters.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO name_entries
            (name, meaning, ethnic_group, region, gender, naming_context, cultural_explanation, sources, status, created_by)
            VALUES
            (:name, :meaning, :ethnic_group, :region, :gender, :naming_context, :cultural_explanation, :sources, 'pending', NULL)
        ");

        $stmt->execute([
            ':name' => $name,
            ':meaning' => $meaning,
            ':ethnic_group' => $ethnicGroup,
            ':region' => $region !== '' ? $region : null,
            ':gender' => $gender,
            ':naming_context' => $namingContext,
            ':cultural_explanation' => $culturalExplanation !== '' ? $culturalExplanation : null,
            ':sources' => $sources !== '' ? $sources : null,
        ]);

        $successMessage = 'Your name entry has been submitted successfully and is awaiting editorial review.';

        $name = '';
        $meaning = '';
        $ethnicGroup = '';
        $region = '';
        $gender = 'unisex';
        $namingContext = '';
        $culturalExplanation = '';
        $sources = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Name | Indigenous Names System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container nav">
            <div class="logo">Indigenous Names System</div>
            <nav>
                <a href="index.php">Home</a>
                <a href="browse.php">Browse</a>
                <a href="submit.php">Submit</a>
                <a href="login.php">Login</a>
            </nav>
        </div>
    </header>

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
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
                </div>

                <div class="form-group">
                    <label for="meaning">Meaning *</label>
                    <input type="text" id="meaning" name="meaning" value="<?= htmlspecialchars($meaning) ?>" required>
                </div>

                <div class="form-group">
                    <label for="ethnic_group">Ethnic Group *</label>
                    <input type="text" id="ethnic_group" name="ethnic_group" value="<?= htmlspecialchars($ethnicGroup) ?>" required>
                </div>

                <div class="form-group">
                    <label for="region">Region / Country</label>
                    <input type="text" id="region" name="region" value="<?= htmlspecialchars($region) ?>">
                </div>

                <div class="form-group">
                    <label for="gender">Gender</label>
                    <select id="gender" name="gender">
                        <option value="male" <?= $gender === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= $gender === 'female' ? 'selected' : '' ?>>Female</option>
                        <option value="unisex" <?= $gender === 'unisex' ? 'selected' : '' ?>>Unisex</option>
                        <option value="other" <?= $gender === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="naming_context">Naming Context *</label>
                    <input type="text" id="naming_context" name="naming_context" value="<?= htmlspecialchars($namingContext) ?>" placeholder="e.g. Birth time, lineage, event" required>
                </div>

                <div class="form-group">
                    <label for="cultural_explanation">Cultural Explanation</label>
                    <textarea id="cultural_explanation" name="cultural_explanation" rows="6" placeholder="Provide detailed cultural context, background, and explanation."><?= htmlspecialchars($culturalExplanation) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="sources">Sources / References</label>
                    <textarea id="sources" name="sources" rows="4" placeholder="Optional references, oral sources, citations, or knowledge holders."><?= htmlspecialchars($sources) ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit">Submit Entry</button>
                </div>
            </form>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container">
            <a href="#">About</a>
            <a href="#">Contact</a>
            <a href="#">Terms</a>
            <a href="#">Privacy</a>
        </div>
    </footer>
</body>
</html>