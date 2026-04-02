<?php
declare(strict_types=1);
?>
<footer class="site-footer site-footer-modern">
    <div class="container footer-grid">
        <div class="footer-brand">
            <a href="index.php" class="footer-brand-link">
                <span class="footer-brand-mark">IA</span>
                <span>
                    <strong>Indigenous African Names System</strong>
                    <small>Knowledge &amp; Authority Platform</small>
                </span>
            </a>
            <p class="footer-mission">
                Preserving indigenous African naming knowledge through contribution,
                editorial review, and authority-based cultural documentation.
            </p>
        </div>

        <div class="footer-column">
            <h3>Platform</h3>
            <a href="index.php">Home</a>
            <a href="browse.php">Browse</a>
            <a href="submit.php">Submit</a>
            <a href="dashboard.php">Dashboard</a>
        </div>

        <div class="footer-column">
            <h3>Access</h3>
            <?php if (isLoggedIn()): ?>
                <a href="dashboard.php">My Dashboard</a>

                <?php if (in_array(currentUser()['role'] ?? '', ['editor', 'admin'], true)): ?>
                    <a href="admin-review.php">Review Queue</a>
                    <a href="review-suggestions.php">Suggestion Review</a>
                <?php endif; ?>

                <?php if ((currentUser()['role'] ?? '') === 'admin'): ?>
                    <a href="manage-users.php">Manage Users</a>
                <?php endif; ?>

                <a href="logout.php">Logout</a>
            <?php else: ?>
                <a href="login.php">Login</a>
                <a href="register.php">Register</a>
            <?php endif; ?>
        </div>

        <div class="footer-column">
            <h3>Information</h3>
            <a href="#faq">FAQ</a>
            <a href="#">About</a>
            <a href="#">Contact</a>
            <a href="#">Terms</a>
            <a href="#">Privacy</a>
        </div>
    </div>

    <div class="container footer-bottom">
        <p>&copy; <?= date('Y') ?> Indigenous African Names Knowledge System. All rights reserved.</p>
        <p class="footer-note">Built for cultural preservation, trusted contribution, and long-term knowledge stewardship.</p>
    </div>
</footer>
</body>
</html>