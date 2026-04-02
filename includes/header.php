<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pageTitle = $pageTitle ?? 'Indigenous African Names System';
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

function navIsActive(array $pages, string $currentPage): string
{
    return in_array($currentPage, $pages, true) ? 'nav-link active' : 'nav-link';
}

$cssFile = __DIR__ . '/../public/assets/css/style.css';
$cssVersion = file_exists($cssFile) ? (string)filemtime($cssFile) : (string)time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= htmlspecialchars($cssVersion) ?>">
</head>
<body>
<header class="site-header site-header-modern">
    <div class="container nav nav-modern">
        <a href="index.php" class="brand">
            <span class="brand-mark">IA</span>
            <span class="brand-text">
                <strong>Indigenous African Names System</strong>
                <small>Knowledge &amp; Authority Platform</small>
            </span>
        </a>

        <button
            class="nav-toggle"
            type="button"
            aria-label="Toggle navigation"
            aria-expanded="false"
            aria-controls="site-nav"
        >
            <span></span>
            <span></span>
            <span></span>
        </button>

        <nav id="site-nav" class="site-nav">
            <a class="<?= navIsActive(['index.php'], $currentPage) ?>" href="index.php">Home</a>
            <a class="<?= navIsActive(['browse.php', 'name.php'], $currentPage) ?>" href="browse.php">Browse</a>

            <?php if (isLoggedIn()): ?>
                <a class="<?= navIsActive(['dashboard.php'], $currentPage) ?>" href="dashboard.php">Dashboard</a>
                <a class="<?= navIsActive(['submit.php', 'suggest-improvement.php'], $currentPage) ?>" href="submit.php">Submit</a>

                <?php if (in_array(currentUser()['role'] ?? '', ['editor', 'admin'], true)): ?>
                    <a class="<?= navIsActive(['admin-review.php', 'review-suggestions.php', 'merge-history.php'], $currentPage) ?>" href="admin-review.php">Review</a>
                <?php endif; ?>

                <?php if ((currentUser()['role'] ?? '') === 'admin'): ?>
                    <a class="<?= navIsActive(['manage-users.php'], $currentPage) ?>" href="manage-users.php">Users</a>
                <?php endif; ?>

                <span class="nav-user">
                    <?= htmlspecialchars(currentUser()['full_name'] ?? 'User') ?>
                    <small><?= htmlspecialchars(ucfirst(currentUser()['role'] ?? '')) ?></small>
                </span>

                <a class="nav-link nav-link-button" href="logout.php">Logout</a>
            <?php else: ?>
                <a class="<?= navIsActive(['submit.php'], $currentPage) ?>" href="submit.php">Submit</a>
                <a class="<?= navIsActive(['login.php'], $currentPage) ?>" href="login.php">Login</a>
                <a class="nav-link nav-link-button" href="register.php">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.querySelector('.nav-toggle');
    const nav = document.getElementById('site-nav');

    if (!toggle || !nav) return;

    toggle.addEventListener('click', function () {
        const isOpen = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
});
</script>