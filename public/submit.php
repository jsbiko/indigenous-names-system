<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$user = currentUser();
$pageTitle = 'Submit a Name';

function e(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function normalizeName(string $value): string
{
    $value = trim(mb_strtolower($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return $value;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$errors = [];
$nearMatches = [];
$exactDuplicate = null;
$showDuplicateReview = false;

$defaults = [
    'name' => '',
    'meaning' => '',
    'ethnic_group' => '',
    'region' => '',
    'gender' => '',
    'naming_context' => '',
    'cultural_explanation' => '',
    'sources' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, (string) $postedToken)) {
        $errors[] = 'Security check failed. Please refresh the page and try again.';
    }

    $defaults = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'meaning' => trim((string) ($_POST['meaning'] ?? '')),
        'ethnic_group' => trim((string) ($_POST['ethnic_group'] ?? '')),
        'region' => trim((string) ($_POST['region'] ?? '')),
        'gender' => trim((string) ($_POST['gender'] ?? '')),
        'naming_context' => trim((string) ($_POST['naming_context'] ?? '')),
        'cultural_explanation' => trim((string) ($_POST['cultural_explanation'] ?? '')),
        'sources' => trim((string) ($_POST['sources'] ?? '')),
    ];

    $confirmDistinct = isset($_POST['confirm_distinct']) && $_POST['confirm_distinct'] === '1';

    if ($defaults['name'] === '') {
        $errors[] = 'Name is required.';
    }

    if ($defaults['meaning'] === '') {
        $errors[] = 'Meaning is required.';
    }

    if ($defaults['ethnic_group'] === '') {
        $errors[] = 'Ethnic group is required.';
    }

    if ($defaults['gender'] === '') {
        $errors[] = 'Gender is required.';
    }

    if ($defaults['sources'] === '') {
        $errors[] = 'Please provide at least one source or documentation note.';
    }

    $allowedGenders = ['male', 'female', 'unisex', 'unknown'];
    if ($defaults['gender'] !== '' && !in_array($defaults['gender'], $allowedGenders, true)) {
        $errors[] = 'Invalid gender selected.';
    }

    if (!$errors) {
        $normalizedName = normalizeName($defaults['name']);
        $normalizedEthnicGroup = normalizeName($defaults['ethnic_group']);

        $exactStmt = $pdo->prepare("
            SELECT id, name, ethnic_group, region, status, meaning
            FROM name_entries
            WHERE LOWER(TRIM(name)) = :name
              AND LOWER(TRIM(ethnic_group)) = :ethnic_group
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $exactStmt->execute([
            ':name' => $normalizedName,
            ':ethnic_group' => $normalizedEthnicGroup,
        ]);
        $exactDuplicate = $exactStmt->fetch(PDO::FETCH_ASSOC);

        if ($exactDuplicate && !$confirmDistinct) {
            $_SESSION['submit_flash'] = [
                'type' => 'warning',
                'message' => 'A very similar name already exists. Instead of creating a duplicate, consider improving the existing authority record.',
            ];

            redirectTo('suggest-improvement.php?name_id=' . (int) $exactDuplicate['id']);
        }

        if (!$confirmDistinct) {
            $nearStmt = $pdo->prepare("
                SELECT id, name, ethnic_group, region, meaning, status
                FROM name_entries
                WHERE (
                    LOWER(name) LIKE :like_name
                    OR SOUNDEX(name) = SOUNDEX(:sound_name)
                )
                AND LOWER(TRIM(name)) <> :exact_name
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $nearStmt->execute([
                ':like_name' => '%' . $normalizedName . '%',
                ':sound_name' => $defaults['name'],
                ':exact_name' => $normalizedName,
            ]);
            $nearMatches = $nearStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($nearMatches) {
                $showDuplicateReview = true;
            }
        }

        if (!$showDuplicateReview) {
            $insertStmt = $pdo->prepare("
                INSERT INTO name_entries (
                    name,
                    meaning,
                    ethnic_group,
                    region,
                    gender,
                    naming_context,
                    cultural_explanation,
                    sources,
                    status,
                    created_by,
                    created_at,
                    updated_at
                ) VALUES (
                    :name,
                    :meaning,
                    :ethnic_group,
                    :region,
                    :gender,
                    :naming_context,
                    :cultural_explanation,
                    :sources,
                    'pending',
                    :created_by,
                    NOW(),
                    NOW()
                )
            ");

            $insertStmt->execute([
                ':name' => $defaults['name'],
                ':meaning' => $defaults['meaning'],
                ':ethnic_group' => $defaults['ethnic_group'],
                ':region' => $defaults['region'] !== '' ? $defaults['region'] : null,
                ':gender' => strtolower($defaults['gender']),
                ':naming_context' => $defaults['naming_context'] !== '' ? $defaults['naming_context'] : null,
                ':cultural_explanation' => $defaults['cultural_explanation'] !== '' ? $defaults['cultural_explanation'] : null,
                ':sources' => $defaults['sources'],
                ':created_by' => (int) ($user['id'] ?? 0),
            ]);

            $_SESSION['submit_flash'] = [
                'type' => 'success',
                'message' => 'Your name submission has been received and is now pending editorial review.',
            ];

            redirectTo('dashboard.php');
        }
    }
}

$flash = $_SESSION['submit_flash'] ?? null;
unset($_SESSION['submit_flash']);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.submit-shell {
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.10), transparent 26%),
        radial-gradient(circle at top left, rgba(16, 185, 129, 0.06), transparent 20%),
        linear-gradient(180deg, #f8fbff 0%, #f4f7fb 100%);
    min-height: 100vh;
    padding: 28px 0 56px;
}

.submit-wrap {
    max-width: 1120px;
    margin: 0 auto;
    padding: 0 14px;
}

.submit-hero,
.submit-card,
.duplicate-card,
.side-card {
    background: rgba(255, 255, 255, 0.96);
    border: 1px solid rgba(15, 23, 42, 0.07);
    border-radius: 24px;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
}

.submit-hero,
.submit-card,
.duplicate-card {
    padding: 28px;
}

.submit-hero {
    margin-bottom: 24px;
}

.submit-kicker {
    display: inline-flex;
    align-items: center;
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(37, 99, 235, 0.10);
    color: #1d4ed8;
    font-size: 0.84rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    margin-bottom: 12px;
}

.submit-title {
    margin: 0 0 8px;
    font-size: clamp(2rem, 3vw, 2.75rem);
    line-height: 1.05;
    font-weight: 900;
    color: #0f172a;
}

.submit-subtitle {
    margin: 0;
    color: #64748b;
    line-height: 1.8;
    max-width: 820px;
    font-size: 1.02rem;
}

.submit-layout {
    display: grid;
    grid-template-columns: 1.35fr 0.65fr;
    gap: 22px;
}

.submit-card .form-label {
    display: block;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 8px;
}

.submit-card .form-text {
    color: #64748b;
    margin-top: 8px;
}

.submit-card input,
.submit-card textarea,
.submit-card select {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 16px;
    padding: 14px 15px;
    background: #fff;
    color: #0f172a;
    box-sizing: border-box;
}

.submit-card input,
.submit-card select {
    min-height: 54px;
}

.submit-card textarea {
    min-height: 145px;
    resize: vertical;
}

.field-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.form-section {
    margin-bottom: 20px;
}

.form-section.full {
    grid-column: 1 / -1;
}

.section-divider {
    height: 1px;
    background: linear-gradient(90deg, rgba(15,23,42,0.08), rgba(15,23,42,0.02));
    margin: 24px 0 22px;
}

.helper-box {
    border: 1px solid rgba(15, 23, 42, 0.06);
    background: #f8fafc;
    border-radius: 18px;
    padding: 16px 18px;
    color: #475569;
    line-height: 1.75;
    margin-bottom: 20px;
}

.submit-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 10px;
}

.submit-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 48px;
    padding: 0 18px;
    border-radius: 14px;
    border: 1px solid rgba(15, 23, 42, 0.09);
    text-decoration: none;
    font-weight: 800;
    font-size: 0.96rem;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    cursor: pointer;
}

.submit-btn:hover {
    transform: translateY(-1px);
    text-decoration: none;
}

.submit-btn-primary {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 14px 28px rgba(37, 99, 235, 0.20);
}

.submit-btn-primary:hover {
    color: #fff;
}

.submit-btn-secondary {
    background: #fff;
    color: #0f172a;
}

.submit-btn-secondary:hover {
    color: #0f172a;
    border-color: rgba(37, 99, 235, 0.25);
    box-shadow: 0 12px 22px rgba(15, 23, 42, 0.06);
}

.side-card {
    padding: 22px;
    margin-bottom: 18px;
}

.side-label {
    font-size: 0.78rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 800;
    color: #64748b;
    margin-bottom: 10px;
}

.side-copy {
    color: #475569;
    line-height: 1.8;
}

.side-list {
    padding-left: 1rem;
    margin-bottom: 0;
}

.side-list li {
    margin-bottom: 0.65rem;
    color: #475569;
    line-height: 1.65;
}

.duplicate-card {
    margin-top: 24px;
}

.duplicate-kicker {
    display: inline-flex;
    align-items: center;
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(245, 158, 11, 0.12);
    color: #b45309;
    font-size: 0.84rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    margin-bottom: 12px;
}

.duplicate-title {
    margin: 0 0 8px;
    font-size: 2rem;
    line-height: 1.1;
    font-weight: 900;
    color: #0f172a;
}

.duplicate-subtitle {
    color: #64748b;
    line-height: 1.8;
    margin-bottom: 20px;
}

.match-list {
    display: grid;
    gap: 16px;
    margin-bottom: 24px;
}

.match-item {
    border: 1px solid rgba(15, 23, 42, 0.08);
    border-radius: 18px;
    padding: 18px;
    background: linear-gradient(180deg, #ffffff, #f8fbff);
}

.match-title {
    font-size: 1.15rem;
    font-weight: 900;
    color: #0f172a;
    margin-bottom: 6px;
}

.match-copy {
    color: #475569;
    line-height: 1.75;
    margin-bottom: 10px;
}

.match-meta {
    color: #64748b;
    line-height: 1.7;
    font-size: 0.95rem;
}

.match-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 14px;
}

@media (max-width: 1024px) {
    .submit-layout,
    .field-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767.98px) {
    .submit-shell {
        padding: 18px 0 42px;
    }

    .submit-wrap {
        padding: 0 12px;
    }

    .submit-hero,
    .submit-card,
    .duplicate-card,
    .side-card {
        border-radius: 20px;
        padding: 18px;
    }

    .submit-title,
    .duplicate-title {
        font-size: 2rem;
    }

    .submit-btn {
        width: 100%;
    }
}
</style>

<main class="submit-shell">
    <div class="submit-wrap">

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type'] ?? 'info') ?> rounded-4 shadow-sm mb-4">
                <?= e($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <section class="submit-hero">
            <div class="submit-kicker">Contribute Knowledge</div>
            <h1 class="submit-title">Submit a New Indigenous African Name</h1>
            <p class="submit-subtitle">
                Help preserve cultural identity by contributing carefully documented name knowledge. Duplicate detection protects the authority layer by routing overlapping records into the improvement workflow where appropriate.
            </p>
        </section>

        <?php if ($showDuplicateReview): ?>
            <section class="duplicate-card">
                <div class="duplicate-kicker">Possible duplicate detected</div>
                <h2 class="duplicate-title">We found similar existing entries</h2>
                <p class="duplicate-subtitle">
                    To maintain one strong authority record per name context, please review these similar entries before creating a new one.
                </p>

                <div class="match-list">
                    <?php foreach ($nearMatches as $match): ?>
                        <article class="match-item">
                            <div class="match-title"><?= e($match['name'] ?? '') ?></div>
                            <div class="match-copy">
                                <?= e($match['meaning'] ?? 'No meaning summary available.') ?>
                            </div>
                            <div class="match-meta">
                                <strong>Ethnic Group:</strong> <?= e($match['ethnic_group'] ?? 'Not recorded') ?>
                                <?php if (!empty($match['region'])): ?>
                                    · <strong>Region:</strong> <?= e($match['region']) ?>
                                <?php endif; ?>
                                <?php if (!empty($match['status'])): ?>
                                    · <strong>Status:</strong> <?= e(ucfirst((string) $match['status'])) ?>
                                <?php endif; ?>
                            </div>

                            <div class="match-actions">
                                <a href="name.php?id=<?= (int) $match['id'] ?>" class="submit-btn submit-btn-secondary">View Existing Record</a>
                                <a href="suggest-improvement.php?name_id=<?= (int) $match['id'] ?>" class="submit-btn submit-btn-primary">Improve Existing Entry</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="confirm_distinct" value="1">

                    <?php foreach ($defaults as $field => $value): ?>
                        <input type="hidden" name="<?= e($field) ?>" value="<?= e($value) ?>">
                    <?php endforeach; ?>

                    <div class="submit-actions">
                        <button type="submit" class="submit-btn submit-btn-primary">
                            This is a distinct name, continue submission
                        </button>
                        <a href="submit.php" class="submit-btn submit-btn-secondary">
                            Cancel and revise
                        </a>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <div class="submit-layout">
                <section class="submit-card">
                    <div class="helper-box">
                        Strong submissions include cultural precision, clear context, and traceable documentation. Entries without enough detail slow down editorial review and weaken authority quality.
                    </div>

                    <?php if ($errors): ?>
                        <div class="alert alert-danger rounded-4 shadow-sm mb-4">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= e($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                        <div class="field-grid">
                            <div class="form-section full">
                                <label for="name" class="form-label">Name</label>
                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    maxlength="150"
                                    required
                                    value="<?= e($defaults['name']) ?>"
                                >
                            </div>

                            <div class="form-section">
                                <label for="ethnic_group" class="form-label">Ethnic Group</label>
                                <input
                                    type="text"
                                    id="ethnic_group"
                                    name="ethnic_group"
                                    maxlength="150"
                                    required
                                    value="<?= e($defaults['ethnic_group']) ?>"
                                >
                            </div>

                            <div class="form-section">
                                <label for="region" class="form-label">Region</label>
                                <input
                                    type="text"
                                    id="region"
                                    name="region"
                                    maxlength="150"
                                    value="<?= e($defaults['region']) ?>"
                                >
                            </div>

                            <div class="form-section full">
                                <label for="gender" class="form-label">Gender</label>
                                <select id="gender" name="gender" required>
                                    <option value="">Select gender</option>
                                    <option value="male" <?= strtolower($defaults['gender']) === 'male' ? 'selected' : '' ?>>Male</option>
                                    <option value="female" <?= strtolower($defaults['gender']) === 'female' ? 'selected' : '' ?>>Female</option>
                                    <option value="unisex" <?= strtolower($defaults['gender']) === 'unisex' ? 'selected' : '' ?>>Unisex</option>
                                    <option value="unknown" <?= strtolower($defaults['gender']) === 'unknown' ? 'selected' : '' ?>>Unknown / Not Certain</option>
                                </select>
                            </div>
                        </div>

                        <div class="section-divider"></div>

                        <div class="form-section">
                            <label for="meaning" class="form-label">Meaning</label>
                            <textarea id="meaning" name="meaning" required><?= e($defaults['meaning']) ?></textarea>
                            <div class="form-text">Provide the direct meaning or best documented interpretation of the name.</div>
                        </div>

                        <div class="form-section">
                            <label for="naming_context" class="form-label">Naming Context</label>
                            <textarea id="naming_context" name="naming_context"><?= e($defaults['naming_context']) ?></textarea>
                            <div class="form-text">Explain when, why, or under what circumstances the name is typically given.</div>
                        </div>

                        <div class="form-section">
                            <label for="cultural_explanation" class="form-label">Cultural Explanation</label>
                            <textarea id="cultural_explanation" name="cultural_explanation"><?= e($defaults['cultural_explanation']) ?></textarea>
                            <div class="form-text">Add cultural, historical, symbolic, spiritual, or social context where known.</div>
                        </div>

                        <div class="form-section">
                            <label for="sources" class="form-label">Sources / Documentation</label>
                            <textarea id="sources" name="sources" required><?= e($defaults['sources']) ?></textarea>
                            <div class="form-text">Cite books, oral traditions, elders, institutions, academic works, or credible editorial notes.</div>
                        </div>

                        <div class="submit-actions">
                            <button type="submit" class="submit-btn submit-btn-primary">Submit for Review</button>
                            <a href="dashboard.php" class="submit-btn submit-btn-secondary">Cancel</a>
                        </div>
                    </form>
                </section>

                <aside>
                    <section class="side-card">
                        <div class="side-label">Submission Guidance</div>
                        <ul class="side-list">
                            <li>Prefer improving an existing record if the same name already exists in the same community context.</li>
                            <li>Be specific about ethnic, linguistic, and regional context.</li>
                            <li>Short meanings are useful, but richer contextual explanation strengthens review quality.</li>
                            <li>Sources increase credibility and reduce editorial back-and-forth.</li>
                        </ul>
                    </section>

                    <section class="side-card">
                        <div class="side-label">Editorial Standard</div>
                        <div class="side-copy">
                            This platform is designed as an authority system, not just a submission form. Every contribution should help build a traceable, culturally grounded, and editorially governable record.
                        </div>
                    </section>

                    <section class="side-card">
                        <div class="side-label">What happens next</div>
                        <ul class="side-list">
                            <li>Your submission enters the editorial review queue.</li>
                            <li>Editors can approve, reject, or enrich the base entry.</li>
                            <li>Approved entries can grow into full authority pages.</li>
                            <li>Future improvements can be merged field by field into the authority layer.</li>
                        </ul>
                    </section>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>