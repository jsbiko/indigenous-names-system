<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/header.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if ($token === '') {
    $error = 'Invalid token.';
} else {
    $stmt = $pdo->prepare("
        SELECT email, expires_at
        FROM password_resets
        WHERE token = :token
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $reset = $stmt->fetch();

    if (!$reset || strtotime($reset['expires_at']) < time()) {
        $error = 'Token expired or invalid.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';

        if ($password === '' || strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $pdo->prepare("
                UPDATE users
                SET password_hash = :hash
                WHERE email = :email
            ")->execute([
                ':hash' => $hash,
                ':email' => $reset['email']
            ]);

            $pdo->prepare("DELETE FROM password_resets WHERE email = :email")
                ->execute([':email' => $reset['email']]);

            $success = 'Password updated successfully.';
        }
    }
}
?>

<main class="container page-section">
    <h1>Reset Password</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
        <a href="login.php">Go to Login</a>
    <?php else: ?>

        <form method="post" class="submission-form">
            <input type="password" name="password" placeholder="New password" required>
            <input type="password" name="confirm" placeholder="Confirm password" required>
            <button type="submit">Reset Password</button>
        </form>

    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>