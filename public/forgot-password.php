<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Forgot Password | Indigenous African Names System';

$message = '';
$resetLink = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email !== '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);

        if ($stmt->fetch()) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare("DELETE FROM password_resets WHERE email = :email")
                ->execute([':email' => $email]);

            $pdo->prepare("
                INSERT INTO password_resets (email, token, expires_at)
                VALUES (:email, :token, :expires)
            ")->execute([
                ':email' => $email,
                ':token' => $token,
                ':expires' => $expires,
            ]);

            $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8888';
            $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
            $resetLink = 'http://' . $host . $baseDir . '/reset-password.php?token=' . urlencode($token);

            $message = 'Reset link generated successfully.';
        } else {
            $message = 'If an account exists for that email, a reset link has been generated.';
        }
    } else {
        $message = 'Please enter your email address.';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <section class="auth-hero-block">
        <span class="eyebrow">Account Recovery</span>
        <h1>Forgot Password</h1>
        <p class="detail-meaning">
            Enter your email address and generate a secure password reset link.
        </p>
    </section>

    <section class="auth-shell auth-shell-single">
        <div class="detail-card auth-card auth-card-premium">
            <?php if ($message !== ''): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($resetLink !== ''): ?>
                <div class="alert alert-info">
                    <strong>Development Reset Link:</strong><br>
                    <a href="<?= htmlspecialchars($resetLink) ?>"><?= htmlspecialchars($resetLink) ?></a>
                </div>
            <?php endif; ?>

            <form method="post" action="forgot-password.php" class="submission-form">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= htmlspecialchars($email) ?>"
                        required
                        autocomplete="email"
                    >
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary full-width">Generate Reset Link</button>
                </div>

                <div class="auth-footer">
                    <p>Remembered your password? <a href="login.php">Back to login</a></p>
                </div>
            </form>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>