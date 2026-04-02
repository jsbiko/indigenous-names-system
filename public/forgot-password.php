<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/header.php';

$message = '';
$resetLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
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
                ':expires' => $expires
            ]);

            // LOCAL DEV: show link instead of email
            $resetLink = "http://localhost/reset-password.php?token=$token";

            $message = "Reset link generated (dev mode).";
        } else {
            $message = "If account exists, a reset link will be sent.";
        }
    }
}
?>

<main class="container page-section">
    <h1>Forgot Password</h1>
    <p>Enter your email to reset your password.</p>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($resetLink): ?>
        <div class="alert">
            <strong>Dev Reset Link:</strong><br>
            <a href="<?= $resetLink ?>"><?= $resetLink ?></a>
        </div>
    <?php endif; ?>

    <form method="post" class="submission-form">
        <input type="email" name="email" placeholder="Enter your email" required>
        <button type="submit">Send Reset Link</button>
    </form>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>