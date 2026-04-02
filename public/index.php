<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Indigenous African Names Knowledge System';

/* Featured names */
$featuredStmt = $pdo->prepare("
    SELECT id, name, meaning, ethnic_group, cultural_explanation
    FROM name_entries
    WHERE status = 'approved'
    ORDER BY created_at DESC
    LIMIT 6
");
$featuredStmt->execute();
$featuredNames = $featuredStmt->fetchAll();

/* Homepage stats */
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS approved_names,
        COUNT(DISTINCT ethnic_group) AS ethnic_groups
    FROM name_entries
    WHERE status = 'approved'
");
$stats = $statsStmt->fetch();

$profileStatsStmt = $pdo->query("
    SELECT COUNT(*) AS authority_profiles
    FROM name_profiles
");
$profileStats = $profileStatsStmt->fetch();

$pendingStatsStmt = $pdo->query("
    SELECT COUNT(*) AS pending_reviews
    FROM name_entries
    WHERE status = 'pending'
");
$pendingStats = $pendingStatsStmt->fetch();

$approvedNamesCount = (int)($stats['approved_names'] ?? 0);
$ethnicGroupsCount = (int)($stats['ethnic_groups'] ?? 0);
$authorityProfilesCount = (int)($profileStats['authority_profiles'] ?? 0);
$pendingReviewsCount = (int)($pendingStats['pending_reviews'] ?? 0);

require_once __DIR__ . '/../includes/header.php';
?>

<main>
    <section class="hero hero-enhanced">
        <div class="container hero-grid">
            <div class="hero-copy">
                <span class="eyebrow">Trusted Cultural Knowledge Platform</span>
                <h1>Preserving Indigenous African Names Through Trusted Cultural Knowledge</h1>
                <p class="hero-lead">
                    Explore indigenous African names, their meanings, origins, and cultural context through a structured,
                    authority-driven knowledge system. The platform begins with Kenyan naming traditions and will expand
                    across Africa through community and scholarly contributions.
                </p>

                <form class="search-bar hero-search" action="browse.php" method="get">
                    <input
                        type="text"
                        name="q"
                        placeholder="Search names, meanings, ethnic groups, or naming traditions..."
                    >
                    <button type="submit">Search</button>
                </form>

                <div class="hero-actions">
                    <a class="btn-primary" href="browse.php">Browse Names</a>
                    <a class="btn-secondary btn-contrast" href="<?= isLoggedIn() ? 'submit.php' : 'register.php' ?>">
                        <?= isLoggedIn() ? 'Contribute a Name' : 'Create Account & Contribute' ?>
                    </a>
                </div>

                <p class="hero-trust">
                    Designed for contributors, editors, researchers, and future institutional collaboration.
                </p>
            </div>

            <div class="hero-panel">
                <div class="hero-preview-card">
                    <div class="hero-preview-top">
                        <span class="preview-badge">Authority Profile</span>
                        <span class="preview-meta">Editorially maintained</span>
                    </div>

                    <h3>Akinyi</h3>
                    <p class="preview-meaning">Born in the morning</p>

                    <div class="preview-grid">
                        <div>
                            <strong>Ethnic Group</strong>
                            <p>Luo</p>
                        </div>
                        <div>
                            <strong>Naming Context</strong>
                            <p>Birth time</p>
                        </div>
                        <div>
                            <strong>Profile Type</strong>
                            <p>Cultural authority page</p>
                        </div>
                        <div>
                            <strong>Use Case</strong>
                            <p>Research, preservation, discovery</p>
                        </div>
                    </div>

                    <p class="preview-note">
                        Rich pages can include overview, linguistic origin, cultural significance,
                        historical context, references, and editorial merge history.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="trust-strip">
        <div class="container trust-grid">
            <div class="trust-item">
                <h3>Editorial Review</h3>
                <p>Submissions and improvements are moderated before becoming trusted public knowledge.</p>
            </div>
            <div class="trust-item">
                <h3>Authority Profiles</h3>
                <p>Names can evolve into richer, research-oriented pages with structured cultural context.</p>
            </div>
            <div class="trust-item">
                <h3>Cultural Preservation</h3>
                <p>The platform supports long-term preservation of indigenous naming traditions and meanings.</p>
            </div>
            <div class="trust-item">
                <h3>Scholarly Collaboration</h3>
                <p>Built to support contributors, editors, researchers, and future institutional participation.</p>
            </div>
        </div>
    </section>

    <section class="stats-section container">
        <div class="section-heading">
            <h2>Knowledge System Snapshot</h2>
            <p>A living cultural archive strengthened through contribution, review, and authority building.</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3><?= $approvedNamesCount ?></h3>
                <p>Approved Names</p>
            </div>
            <div class="stat-card">
                <h3><?= $ethnicGroupsCount ?></h3>
                <p>Ethnic Groups Represented</p>
            </div>
            <div class="stat-card">
                <h3><?= $pendingReviewsCount ?></h3>
                <p>Pending Reviews</p>
            </div>
            <div class="stat-card">
                <h3><?= $authorityProfilesCount ?></h3>
                <p>Authority Profiles Published</p>
            </div>
        </div>
    </section>

    <section class="browse-pathways container">
        <div class="section-heading">
            <h2>Explore the Knowledge System</h2>
            <p>Use structured discovery pathways to navigate names through cultural and contextual lenses.</p>
        </div>

        <div class="pathway-grid">
            <a class="pathway-card" href="browse.php">
                <h3>Browse by Ethnic Group</h3>
                <p>Explore names through community and linguistic identity.</p>
            </a>

            <a class="pathway-card" href="browse.php">
                <h3>Browse by Region</h3>
                <p>Discover geographical variation and naming traditions across locations.</p>
            </a>

            <a class="pathway-card" href="browse.php">
                <h3>Browse by Naming Context</h3>
                <p>Find names linked to birth time, circumstance, virtue, ancestry, and more.</p>
            </a>

            <a class="pathway-card" href="browse.php">
                <h3>Browse by Gender</h3>
                <p>Filter names by male, female, unisex, and other recorded usage categories.</p>
            </a>

            <a class="pathway-card" href="browse.php">
                <h3>Browse by Meaning Theme</h3>
                <p>Search for names connected to joy, weather, lineage, spirituality, or aspiration.</p>
            </a>

            <a class="pathway-card" href="browse.php">
                <h3>Recently Updated Profiles</h3>
                <p>See names that have been recently expanded through editorial and scholarly review.</p>
            </a>
        </div>
    </section>

    <section class="featured-section container">
        <div class="section-heading">
            <h2>Featured & Recently Added Names</h2>
            <p>Start with a curated sample of approved names already available in the system.</p>
        </div>

        <?php if (!empty($featuredNames)): ?>
            <div class="featured-grid">
                <?php foreach ($featuredNames as $entry): ?>
                    <article class="featured-card">
                        <div class="featured-card-top">
                            <span class="featured-badge">Approved</span>
                        </div>

                        <h3><?= htmlspecialchars($entry['name']) ?></h3>
                        <p class="featured-meaning"><?= htmlspecialchars($entry['meaning']) ?></p>

                        <div class="featured-meta">
                            <span><strong>Group:</strong> <?= htmlspecialchars($entry['ethnic_group']) ?></span>
                        </div>

                        <p class="featured-summary">
                            <?= htmlspecialchars(
                                mb_strimwidth(
                                    trim((string)($entry['cultural_explanation'] ?? 'Cultural context available on the profile page.')),
                                    0,
                                    120,
                                    '...'
                                )
                            ) ?>
                        </p>

                        <a class="featured-link" href="name.php?id=<?= (int)$entry['id'] ?>">View Profile</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="detail-card">
                <p>No approved names are available yet.</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="how-it-works container">
        <div class="section-heading">
            <h2>How the System Works</h2>
            <p>The platform combines community contribution with editorial oversight to produce reliable cultural knowledge.</p>
        </div>

        <div class="steps-grid">
            <div class="step-card">
                <span class="step-number">01</span>
                <h3>Discover or Contribute</h3>
                <p>Users can browse existing names or submit new names and cultural improvements for review.</p>
            </div>

            <div class="step-card">
                <span class="step-number">02</span>
                <h3>Editorial Review</h3>
                <p>Editors assess submissions, compare suggestions, and merge accepted improvements into the system.</p>
            </div>

            <div class="step-card">
                <span class="step-number">03</span>
                <h3>Publish Authority Content</h3>
                <p>Approved names can grow into authority profiles with references, context, and merge history.</p>
            </div>
        </div>
    </section>

    <section class="mission-section container">
        <div class="mission-card">
            <div class="section-heading">
                <h2>Why This Matters</h2>
            </div>
            <p>
                Indigenous African names carry memory, identity, philosophy, ancestry, circumstance, and social meaning.
                This system is designed to help preserve that knowledge in a structured, trustworthy form for future generations,
                researchers, communities, and institutions.
            </p>
        </div>
    </section>

    <section class="contribution-pathways container">
        <div class="section-heading">
            <h2>Who the Platform Serves</h2>
            <p>The knowledge system is designed for contribution, review, and long-term cultural stewardship.</p>
        </div>

        <div class="pathway-grid">
            <div class="pathway-card">
                <h3>Contributors</h3>
                <p>Share names, meanings, contexts, and references to help expand the archive.</p>
            </div>

            <div class="pathway-card">
                <h3>Editors</h3>
                <p>Review submissions, validate suggestions, and maintain authority-grade public content.</p>
            </div>

            <div class="pathway-card">
                <h3>Institutions & Researchers</h3>
                <p>Use the platform as a foundation for deeper collaboration, preservation, and cultural research.</p>
            </div>
        </div>
    </section>

    <section id="faq" class="faq-section container">
        <div class="section-heading">
            <h2>Frequently Asked Questions</h2>
            <p>Quick answers about contribution, review, and authority content.</p>
        </div>

        <div class="faq-list">
            <details class="faq-item">
                <summary>What is the Indigenous African Names Knowledge System?</summary>
                <p>
                    It is a full-stack cultural knowledge platform for preserving, exploring, and editorially reviewing
                    indigenous African names and their meanings, origins, and contexts.
                </p>
            </details>

            <details class="faq-item">
                <summary>Who can contribute a name?</summary>
                <p>
                    Registered contributors can submit new names or suggest improvements to existing records for editorial review.
                </p>
            </details>

            <details class="faq-item">
                <summary>How are submissions reviewed?</summary>
                <p>
                    Editors and admins review incoming entries and suggestions, compare changes, and merge accepted improvements
                    into published content.
                </p>
            </details>

            <details class="faq-item">
                <summary>What is an authority profile?</summary>
                <p>
                    An authority profile is a richer page for a name that can include overview, linguistic origin,
                    cultural significance, historical context, notes, and references.
                </p>
            </details>

            <details class="faq-item">
                <summary>Can institutions collaborate with the platform?</summary>
                <p>
                    Yes. The system is being structured to support future collaboration with scholars, researchers,
                    cultural custodians, and institutions.
                </p>
            </details>
        </div>
    </section>

    <section class="final-cta container">
        <div class="cta-card">
            <h2>Start Exploring or Contributing Today</h2>
            <p>
                Browse trusted name records, discover cultural meaning, or create an account to contribute to the archive.
            </p>
            <div class="hero-actions">
                <a class="btn-primary" href="browse.php">Start Exploring</a>
                <a class="btn-secondary btn-contrast-light" href="<?= isLoggedIn() ? 'submit.php' : 'register.php' ?>">
                    <?= isLoggedIn() ? 'Submit a Name' : 'Register as Contributor' ?>
                </a>
            </div>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>