<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    redirectAfterLogin();
}

$pageTitle = 'Login | Indigenous African Names System';

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id, full_name, email, password_hash, role
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ];

            redirectAfterLogin();
        } else {
            $error = 'Invalid login credentials.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <section class="detail-hero">
        <h1>Login</h1>
        <p class="detail-meaning">
            Access your contributor, editor, or admin workspace.
        </p>
    </section>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <section class="detail-card auth-card">
        <form method="post" action="login.php" class="submission-form">
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

            <div class="form-group">
                <div class="auth-label-row">
                    <label for="password">Password</label>
                    <a class="auth-inline-link" href="forgot-password.php">Forgot password?</a>
                </div>

                <div class="password-wrapper">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                    >
                    <span class="toggle-password" onclick="togglePassword('password')">👁</span>
                </div>

                <small class="password-hint">
                    Enter the password for your account.
                </small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary full-width">Login</button>
            </div>

            <div class="auth-footer">
                <p>Don’t have an account? <a href="register.php">Register</a></p>
            </div>
        </form>
    </section>
</main>

<script>
function togglePassword(id) {
    const input = document.getElementById(id);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>