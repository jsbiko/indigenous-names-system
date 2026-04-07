<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['editor', 'admin']);

$user = currentUser();
$pageTitle = 'Edit Authority Page';

/**
 * Escape output safely for HTML.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Simple redirect helper.
 */
function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * UI badge styling helper for statuses.
 */
function badgeClass(string $status): string
{
    return match ($status) {
        'approved', 'published' => 'status-pill status-approved',
        'pending', 'draft' => 'status-pill status-pending',
        'rejected', 'archived' => 'status-pill status-rejected',
        default => 'status-pill status-neutral',
    };
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/**
 * Source name entry ID.
 */
$nameId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($nameId <= 0) {
    redirectTo('dashboard.php');
}

/**
 * Load the base name entry.
 */
$entryStmt = $pdo->prepare("
    SELECT ne.*
    FROM name_entries ne
    WHERE ne.id = :id
    LIMIT 1
");
$entryStmt->execute([':id' => $nameId]);
$entry = $entryStmt->fetch(PDO::FETCH_ASSOC);

if (!$entry) {
    http_response_code(404);
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main class="container py-5">
        <section class="card border-0 rounded-4 shadow-sm p-4 p-lg-5 text-center">
            <h1 class="h3 mb-3">Entry not found</h1>
            <p class="text-muted mb-0">The source name entry could not be loaded.</p>
        </section>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/**
 * Load an existing authority profile if present.
 */
$profileStmt = $pdo->prepare("
    SELECT *
    FROM name_profiles
    WHERE name_entry_id = :name_entry_id
    LIMIT 1
");
$profileStmt->execute([':name_entry_id' => $nameId]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

$profileExists = (bool) $profile;

/**
 * Pre-fill defaults from the profile when available.
 * Fall back to base-entry fields where it improves editor usability.
 */
$defaults = [
    'profile_status' => $profile['profile_status'] ?? 'draft',
    'origin_overview' => $profile['origin_overview'] ?? '',
    'meaning_extended' => $profile['meaning_extended'] ?? '',
    'historical_context' => $profile['historical_context'] ?? '',
    'cultural_significance' => $profile['cultural_significance'] ?? '',
    'naming_traditions' => $profile['naming_traditions'] ?? ($entry['naming_context'] ?? ''),
    'variants' => $profile['variants'] ?? '',
    'pronunciation_notes' => $profile['pronunciation_notes'] ?? '',
    'editorial_notes' => $profile['editorial_notes'] ?? '',
    'sources_extended' => $profile['sources_extended'] ?? ($entry['sources'] ?? ''),
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, (string) $postedToken)) {
        $errors[] = 'Security check failed. Please refresh the page and try again.';
    }

    /**
     * Collect and normalize incoming form data.
     */
    $data = [
        'profile_status' => trim((string) ($_POST['profile_status'] ?? 'draft')),
        'origin_overview' => trim((string) ($_POST['origin_overview'] ?? '')),
        'meaning_extended' => trim((string) ($_POST['meaning_extended'] ?? '')),
        'historical_context' => trim((string) ($_POST['historical_context'] ?? '')),
        'cultural_significance' => trim((string) ($_POST['cultural_significance'] ?? '')),
        'naming_traditions' => trim((string) ($_POST['naming_traditions'] ?? '')),
        'variants' => trim((string) ($_POST['variants'] ?? '')),
        'pronunciation_notes' => trim((string) ($_POST['pronunciation_notes'] ?? '')),
        'editorial_notes' => trim((string) ($_POST['editorial_notes'] ?? '')),
        'sources_extended' => trim((string) ($_POST['sources_extended'] ?? '')),
    ];

    $allowedStatuses = ['draft', 'published', 'archived'];

    if (!in_array($data['profile_status'], $allowedStatuses, true)) {
        $errors[] = 'Invalid profile status selected.';
    }

    if ($data['meaning_extended'] === '' && trim((string) ($entry['meaning'] ?? '')) === '') {
        $errors[] = 'Add at least a meaningful summary in the meaning section or ensure the base entry meaning exists.';
    }

    if ($data['sources_extended'] === '' && trim((string) ($entry['sources'] ?? '')) === '') {
        $errors[] = 'Please attach at least one source or documentation note.';
    }

    /**
     * Publishing guard:
     * only approved base entries should be publicly published.
     */
    if (
        $data['profile_status'] === 'published'
        && strtolower((string) ($entry['status'] ?? '')) !== 'approved'
    ) {
        $errors[] = 'Only approved name entries can have a published authority profile.';
    }

    if (!$errors) {
        if ($profileExists) {
            $sql = "
                UPDATE name_profiles
                SET
                    profile_status = :profile_status,
                    origin_overview = :origin_overview,
                    meaning_extended = :meaning_extended,
                    historical_context = :historical_context,
                    cultural_significance = :cultural_significance,
                    naming_traditions = :naming_traditions,
                    variants = :variants,
                    pronunciation_notes = :pronunciation_notes,
                    editorial_notes = :editorial_notes,
                    sources_extended = :sources_extended,
                    updated_by = :updated_by,
                    updated_at = NOW()
                WHERE name_entry_id = :name_entry_id
                LIMIT 1
            ";
        } else {
            $sql = "
                INSERT INTO name_profiles (
                    name_entry_id,
                    profile_status,
                    origin_overview,
                    meaning_extended,
                    historical_context,
                    cultural_significance,
                    naming_traditions,
                    variants,
                    pronunciation_notes,
                    editorial_notes,
                    sources_extended,
                    created_by,
                    updated_by,
                    created_at,
                    updated_at
                ) VALUES (
                    :name_entry_id,
                    :profile_status,
                    :origin_overview,
                    :meaning_extended,
                    :historical_context,
                    :cultural_significance,
                    :naming_traditions,
                    :variants,
                    :pronunciation_notes,
                    :editorial_notes,
                    :sources_extended,
                    :created_by,
                    :updated_by,
                    NOW(),
                    NOW()
                )
            ";
        }

        $stmt = $pdo->prepare($sql);

        $params = [
            ':name_entry_id' => $nameId,
            ':profile_status' => $data['profile_status'],
            ':origin_overview' => $data['origin_overview'],
            ':meaning_extended' => $data['meaning_extended'],
            ':historical_context' => $data['historical_context'],
            ':cultural_significance' => $data['cultural_significance'],
            ':naming_traditions' => $data['naming_traditions'],
            ':variants' => $data['variants'],
            ':pronunciation_notes' => $data['pronunciation_notes'],
            ':editorial_notes' => $data['editorial_notes'],
            ':sources_extended' => $data['sources_extended'],
            ':updated_by' => (int) ($user['id'] ?? 0),
        ];

        if (!$profileExists) {
            $params[':created_by'] = (int) ($user['id'] ?? 0);
        }

        $stmt->execute($params);

        redirectTo('name.php?id=' . $nameId . '&profile_saved=1');
    }

    /**
     * Keep posted values in the form if validation fails.
     */
    $defaults = $data;
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.profile-shell {
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.10), transparent 26%),
        radial-gradient(circle at top left, rgba(16, 185, 129, 0.06), transparent 18%),
        linear-gradient(180deg, #f8fbff 0%, #f4f7fb 100%);
    min-height: 100vh;
    padding: 28px 0 56px;
}

.profile-wrap {
    max-width: 1220px;
    margin: 0 auto;
    padding: 0 14px;
}

.profile-hero,
.profile-card,
.profile-side-card {
    background: rgba(255, 255, 255, 0.96);
    border: 1px solid rgba(15, 23, 42, 0.07);
    border-radius: 24px;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
}

.profile-hero,
.profile-card {
    padding: 28px;
}

.profile-hero {
    margin-bottom: 24px;
}

.profile-topbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
}

.profile-kicker {
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

.profile-title {
    margin: 0 0 10px;
    font-size: clamp(2rem, 3vw, 2.85rem);
    line-height: 1.04;
    font-weight: 900;
    color: #0f172a;
}

.profile-subtitle {
    margin: 0;
    color: #64748b;
    line-height: 1.8;
    font-size: 1.03rem;
    max-width: 760px;
}

.profile-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.profile-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 46px;
    padding: 0 16px;
    border-radius: 14px;
    border: 1px solid rgba(15, 23, 42, 0.09);
    text-decoration: none;
    font-weight: 800;
    font-size: 0.96rem;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    cursor: pointer;
}

.profile-btn:hover {
    transform: translateY(-1px);
    text-decoration: none;
}

.profile-btn-primary {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 14px 28px rgba(37, 99, 235, 0.20);
}

.profile-btn-primary:hover {
    color: #fff;
}

.profile-btn-secondary {
    background: #fff;
    color: #0f172a;
}

.profile-btn-secondary:hover {
    color: #0f172a;
    border-color: rgba(37, 99, 235, 0.25);
    box-shadow: 0 12px 22px rgba(15, 23, 42, 0.06);
}

.profile-layout {
    display: grid;
    grid-template-columns: 1.3fr 0.7fr;
    gap: 22px;
}

.meta-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-top: 22px;
}

.meta-card {
    border: 1px solid rgba(15, 23, 42, 0.06);
    background: linear-gradient(180deg, #ffffff, #f8fbff);
    border-radius: 18px;
    padding: 16px;
}

.meta-label {
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #64748b;
    font-weight: 800;
    margin-bottom: 8px;
}

.meta-value {
    color: #0f172a;
    font-weight: 900;
    line-height: 1.5;
}

.status-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 18px;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 0 12px;
    border-radius: 999px;
    font-size: 0.84rem;
    font-weight: 800;
    border: 1px solid transparent;
}

.status-approved {
    background: rgba(22, 163, 74, 0.11);
    color: #15803d;
    border-color: rgba(22, 163, 74, 0.14);
}

.status-pending {
    background: rgba(245, 158, 11, 0.13);
    color: #b45309;
    border-color: rgba(245, 158, 11, 0.16);
}

.status-rejected {
    background: rgba(220, 38, 38, 0.11);
    color: #b91c1c;
    border-color: rgba(220, 38, 38, 0.14);
}

.status-neutral {
    background: rgba(100, 116, 139, 0.11);
    color: #475569;
    border-color: rgba(100, 116, 139, 0.14);
}

.profile-card .form-label {
    display: block;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 8px;
}

.profile-card .form-text {
    color: #64748b;
    margin-top: 8px;
}

.profile-card input,
.profile-card textarea,
.profile-card select {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 16px;
    padding: 14px 15px;
    background: #fff;
    color: #0f172a;
    box-sizing: border-box;
}

.profile-card select {
    min-height: 54px;
}

.profile-card textarea {
    min-height: 150px;
    resize: vertical;
}

.form-section {
    margin-bottom: 20px;
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

.profile-actions-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 10px;
}

.profile-side-card {
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

/* Gap-analysis deep-link highlight support */
.field-target {
    scroll-margin-top: 110px;
}

.field-target:target {
    animation: fieldPulse 1.4s ease;
    border-radius: 18px;
}

.field-target:target .form-label {
    color: #1d4ed8;
}

.field-target:target textarea,
.field-target:target select,
.field-target:target input {
    border-color: rgba(37, 99, 235, 0.55);
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.10);
    background: #f8fbff;
}

@keyframes fieldPulse {
    0% {
        background: rgba(37, 99, 235, 0.10);
    }
    100% {
        background: transparent;
    }
}

@media (max-width: 1100px) {
    .profile-layout,
    .meta-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767.98px) {
    .profile-shell {
        padding: 18px 0 42px;
    }

    .profile-wrap {
        padding: 0 12px;
    }

    .profile-hero,
    .profile-card,
    .profile-side-card {
        border-radius: 20px;
        padding: 18px;
    }

    .profile-topbar {
        flex-direction: column;
    }

    .profile-actions {
        width: 100%;
    }

    .profile-btn {
        width: 100%;
    }
}
</style>

<main class="profile-shell">
    <div class="profile-wrap">

        <section class="profile-hero">
            <div class="profile-topbar">
                <div>
                    <div class="profile-kicker">Authority Page Editor</div>
                    <h1 class="profile-title">
                        <?= $profileExists ? 'Edit Authority Profile' : 'Create Authority Profile' ?>
                    </h1>
                    <p class="profile-subtitle">
                        Build a richer, Wikipedia-style authority page for <strong><?= e($entry['name'] ?? '') ?></strong> with structured meaning, origin, traditions, cultural significance, and source-backed documentation.
                    </p>
                </div>

                <div class="profile-actions">
                    <a href="name.php?id=<?= (int) $nameId ?>" class="profile-btn profile-btn-secondary">View Authority Page</a>
                    <a href="dashboard.php" class="profile-btn profile-btn-primary">Back to Dashboard</a>
                </div>
            </div>

            <div class="meta-grid">
                <div class="meta-card">
                    <div class="meta-label">Name</div>
                    <div class="meta-value"><?= e($entry['name'] ?? '') ?></div>
                </div>

                <div class="meta-card">
                    <div class="meta-label">Ethnic Group</div>
                    <div class="meta-value"><?= e($entry['ethnic_group'] ?? 'Not recorded') ?></div>
                </div>

                <div class="meta-card">
                    <div class="meta-label">Region</div>
                    <div class="meta-value"><?= e($entry['region'] ?? 'Not recorded') ?></div>
                </div>

                <div class="meta-card">
                    <div class="meta-label">Entry Status</div>
                    <div class="meta-value"><?= e(ucfirst((string) ($entry['status'] ?? 'unknown'))) ?></div>
                </div>
            </div>

            <div class="status-pills">
                <span class="<?= badgeClass((string) ($entry['status'] ?? 'unknown')) ?>">
                    Entry: <?= e(ucfirst((string) ($entry['status'] ?? 'unknown'))) ?>
                </span>

                <span class="<?= badgeClass((string) ($defaults['profile_status'] ?? 'draft')) ?>">
                    Profile: <?= e(ucfirst((string) ($defaults['profile_status'] ?? 'draft'))) ?>
                </span>
            </div>
        </section>

        <div class="profile-layout">
            <section class="profile-card">
                <div class="helper-box">
                    Only approved base entries should be published as authority pages. Draft mode is safest while building. Use this editor to create one structured, authoritative record rather than fragmented duplicate content.
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

                    <!-- Targetable field for gap-analysis deep links -->
                    <div class="form-section field-target" id="profile_status">
                        <label for="profile_status" class="form-label">Profile Status</label>
                        <select name="profile_status" id="profile_status">
                            <option value="draft" <?= $defaults['profile_status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= $defaults['profile_status'] === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="archived" <?= $defaults['profile_status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                        <div class="form-text">Use draft while building. Publish only after editorial verification is complete.</div>
                    </div>

                    <div class="section-divider"></div>

                    <!-- Targetable field for gap-analysis deep links -->
                    <div class="form-section field-target" id="meaning_extended">
                        <label for="meaning_extended" class="form-label">Meaning and Core Interpretation</label>
                        <textarea name="meaning_extended" id="meaning_extended"><?= e($defaults['meaning_extended']) ?></textarea>
                        <div class="form-text">Write the strongest concise scholarly summary of the name’s meaning and interpretation.</div>
                    </div>

                    <!-- Targetable field for gap-analysis deep links -->
                    <div class="form-section field-target" id="origin_overview">
                        <label for="origin_overview" class="form-label">Origin and Background</label>
                        <textarea name="origin_overview" id="origin_overview"><?= e($defaults['origin_overview']) ?></textarea>
                        <div class="form-text">Document ethnic, linguistic, geographic, clan, lineage, or community background.</div>
                    </div>

                    <!-- Targetable field for gap-analysis deep links -->
                    <div class="form-section field-target" id="historical_context">
                        <label for="historical_context" class="form-label">Historical Context</label>
                        <textarea name="historical_context" id="historical_context"><?= e($defaults['historical_context']) ?></textarea>
                        <div class="form-text">Capture historical relevance, generational use, period context, or inherited naming traditions.</div>
                    </div>

                    <!-- Targetable field for gap-analysis deep links -->
                    <div class="form-section field-target" id="naming_traditions">
                        <label for="naming_traditions" class="form-label">Naming Context and Traditions</label>
                        <textarea name="naming_traditions" id="naming_traditions"><?= e($defaults['naming_traditions']) ?></textarea>
                        <div class="form-text">Explain how, when, why, and by whom the name is typically given.</div>
                    </div>

                    <!-- Targetable field for gap-analysis deep links -->
                    <div class="form-section field-target" id="cultural_significance">
                        <label for="cultural_significance" class="form-label">Cultural Significance</label>
                        <textarea name="cultural_significance" id="cultural_significance"><?= e($defaults['cultural_significance']) ?></textarea>
                        <div class="form-text">Describe symbolic, social, spiritual, historical, or communal significance.</div>
                    </div>

                    <!-- Targetable field for gap-analysis deep links -->
                    <div class="form-section field-target" id="variants">
                        <label for="variants" class="form-label">Variants and Related Forms</label>
                        <textarea name="variants" id="variants"><?= e($defaults['variants']) ?></textarea>
                        <div class="form-text">List spelling variants, dialect forms, regional variations, related names, or gendered forms.</div>
                    </div>

                    <!-- Targetable field for gap-analysis deep links -->
                    <div class="form-section field-target" id="pronunciation_notes">
                        <label for="pronunciation_notes" class="form-label">Pronunciation Notes</label>
                        <textarea name="pronunciation_notes" id="pronunciation_notes"><?= e($defaults['pronunciation_notes']) ?></textarea>
                        <div class="form-text">Add pronunciation guidance, tonal notes, or phonetic clarification where useful.</div>
                    </div>

                    <!-- Targetable field for gap-analysis deep links -->
                    <div class="form-section field-target" id="sources_extended">
                        <label for="sources_extended" class="form-label">Sources and Documentation</label>
                        <textarea name="sources_extended" id="sources_extended"><?= e($defaults['sources_extended']) ?></textarea>
                        <div class="form-text">Cite books, field notes, oral authority, institutions, academic sources, and editorial evidence.</div>
                    </div>

                    <!-- Targetable field for gap-analysis deep links -->
                    <div class="form-section field-target" id="editorial_notes">
                        <label for="editorial_notes" class="form-label">Internal Editorial Notes</label>
                        <textarea name="editorial_notes" id="editorial_notes"><?= e($defaults['editorial_notes']) ?></textarea>
                        <div class="form-text">Internal only. Use this for verification notes, unresolved issues, caution flags, and future editorial tasks.</div>
                    </div>

                    <div class="profile-actions-row">
                        <button type="submit" class="profile-btn profile-btn-primary">Save Authority Profile</button>
                        <a href="name.php?id=<?= (int) $nameId ?>" class="profile-btn profile-btn-secondary">Cancel</a>
                    </div>
                </form>
            </section>

            <div>
                <aside class="profile-side-card">
                    <div class="side-label">Base Entry Summary</div>
                    <div class="side-copy">
                        <p><strong>Meaning:</strong> <?= e($entry['meaning'] ?? 'Not recorded') ?></p>
                        <p><strong>Naming Context:</strong> <?= e($entry['naming_context'] ?? 'Not recorded') ?></p>
                        <p class="mb-0"><strong>Cultural Explanation:</strong> <?= e($entry['cultural_explanation'] ?? 'Not recorded') ?></p>
                    </div>
                </aside>

                <aside class="profile-side-card">
                    <div class="side-label">Editorial Guidance</div>
                    <ul class="side-list">
                        <li>Draft first, publish later. Public release should follow editorial verification.</li>
                        <li>Keep one strong authority page per record rather than scattering information.</li>
                        <li>Use evidence-rich writing, not unsupported claims.</li>
                        <li>Where suggestions exist, merge them thoughtfully field by field.</li>
                    </ul>
                </aside>

                <aside class="profile-side-card">
                    <div class="side-label">Publishing Rule</div>
                    <div class="side-copy">
                        A profile may only be published when the base entry itself is approved. Rejected or pending entries should remain in draft while editorial decisions are still in progress.
                    </div>
                </aside>

                <?php if ($profileExists && !empty($profile['updated_at'])): ?>
                    <aside class="profile-side-card">
                        <div class="side-label">Latest Profile Update</div>
                        <div class="side-copy">
                            <?= e(date('F j, Y, g:i a', strtotime((string) $profile['updated_at']))) ?>
                        </div>
                    </aside>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>