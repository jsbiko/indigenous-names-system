<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

/**
 * ------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirectAfterLoginByRole(array $user): void
{
    $role = strtolower(trim((string)($user['role'] ?? 'contributor')));

    switch ($role) {
        case 'admin':
        case 'editor':
        case 'contributor':
        default:
            header('Location: /dashboard.php');
            exit;
    }
}

/**
 * ------------------------------------------------------------
 * Redirect already logged-in users
 * ------------------------------------------------------------
 */
if (isLoggedIn()) {
    $existingUser = currentUser();
    redirectAfterLoginByRole($existingUser ?? []);
}

$pageTitle = 'Login | Indigenous African Names System';

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, full_name, email, password_hash, role
                FROM users
                WHERE email = :email
                LIMIT 1
            ");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && isset($user['password_hash']) && password_verify($password, (string)$user['password_hash'])) {
                loginUser([
                    'id' => (int)$user['id'],
                    'name' => (string)($user['full_name'] ?? ''),
                    'email' => (string)($user['email'] ?? ''),
                    'role' => (string)($user['role'] ?? 'contributor'),
                ]);

                redirectAfterLoginByRole($user);
            } else {
                $error = 'Invalid login credentials.';
            }
        } catch (Throwable $e) {
            $error = 'Unable to log you in right now. Please try again.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <section class="auth-hero-block">
        <span class="eyebrow">Secure Access</span>
        <h1>Welcome Back</h1>
        <p class="detail-meaning">
            Access your contributor, editor, or admin workspace.
        </p>
    </section>

    <section class="auth-shell">
        <div class="auth-panel auth-panel-intro">
            <h2>Sign in to continue</h2>
            <p>
                Return to your dashboard, manage submissions, review suggestions,
                and continue building trusted cultural knowledge.
            </p>

            <div class="auth-feature-list">
                <div class="auth-feature-item">
                    <strong>Contributors</strong>
                    <span>Track submissions and suggest improvements.</span>
                </div>
                <div class="auth-feature-item">
                    <strong>Editors</strong>
                    <span>Review entries, merge changes, and maintain authority pages.</span>
                </div>
                <div class="auth-feature-item">
                    <strong>Admins</strong>
                    <span>Manage users, oversee governance, and monitor platform activity.</span>
                </div>
            </div>
        </div>

        <div class="detail-card auth-card auth-card-premium">
            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="/login.php" class="submission-form" novalidate>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= e($email) ?>"
                        required
                        autocomplete="email"
                        inputmode="email"
                    >
                </div>

                <div class="form-group">
                    <div class="auth-label-row">
                        <label for="password">Password</label>
                        <a class="auth-inline-link" href="/forgot-password.php">Forgot password?</a>
                    </div>

                    <div class="password-wrapper">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            autocomplete="current-password"
                        >
                        <button
                            type="button"
                            class="toggle-password"
                            onclick="togglePassword('password', this)"
                            aria-label="Show or hide password"
                            aria-pressed="false"
                        >
                            👁
                        </button>
                    </div>

                    <small class="password-hint">Enter the password associated with your account.</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary full-width">Login</button>
                </div>

                <div class="auth-footer">
                    <p>Don’t have an account? <a href="/register.php">Register</a></p>
                </div>
            </form>
        </div>
    </section>
</main>

<script>
function togglePassword(id, button) {
    const input = document.getElementById(id);
    if (!input) return;

    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';

    if (button) {
        button.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
        button.textContent = isPassword ? '🙈' : '👁';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>