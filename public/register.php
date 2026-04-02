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

$pageTitle = 'Register | Indigenous African Names System';

$errors = [];
$fullName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    } elseif (mb_strlen($fullName) > 150) {
        $errors[] = 'Full name must not exceed 150 characters.';
    }

    if ($email === '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (mb_strlen($email) > 150) {
        $errors[] = 'Email must not exceed 150 characters.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif (
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password)
    ) {
        $errors[] = 'Password must include at least one uppercase letter, one lowercase letter, and one number.';
    }

    if ($confirmPassword === '') {
        $errors[] = 'Please confirm your password.';
    } elseif ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $checkStmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = :email
                LIMIT 1
            ");
            $checkStmt->execute(['email' => $email]);
            $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                $errors[] = 'An account with that email already exists.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $insertStmt = $pdo->prepare("
                    INSERT INTO users (full_name, email, password_hash, role)
                    VALUES (:full_name, :email, :password_hash, 'contributor')
                ");
                $insertStmt->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                ]);

                $userId = (int)$pdo->lastInsertId();

                $newUser = [
                    'id' => $userId,
                    'name' => $fullName,
                    'email' => $email,
                    'role' => 'contributor',
                ];

                loginUser($newUser);
                redirectAfterLoginByRole($newUser);
            }
        } catch (Throwable $e) {
            $errors[] = 'Registration failed. Please try again.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <section class="auth-hero-block">
        <span class="eyebrow">Join the Platform</span>
        <h1>Create Your Account</h1>
        <p class="detail-meaning">
            Start contributing to the Indigenous African Names System.
        </p>
    </section>

    <section class="auth-shell">
        <div class="auth-panel auth-panel-intro">
            <h2>Become a contributor</h2>
            <p>
                Create an account to submit names, suggest improvements, and participate
                in the preservation of indigenous African naming knowledge.
            </p>

            <div class="auth-feature-list">
                <div class="auth-feature-item">
                    <strong>Submit names</strong>
                    <span>Add names, meanings, and cultural context for editorial review.</span>
                </div>
                <div class="auth-feature-item">
                    <strong>Suggest improvements</strong>
                    <span>Help strengthen existing entries through better context and references.</span>
                </div>
                <div class="auth-feature-item">
                    <strong>Grow the archive</strong>
                    <span>Support a system designed to scale from Kenya to the wider African continent.</span>
                </div>
            </div>
        </div>

        <div class="detail-card auth-card auth-card-premium">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="/register.php" class="submission-form" novalidate>
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        value="<?= e($fullName) ?>"
                        required
                        maxlength="150"
                        autocomplete="name"
                    >
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= e($email) ?>"
                        required
                        maxlength="150"
                        autocomplete="email"
                        inputmode="email"
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>

                    <div class="password-wrapper">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            minlength="8"
                            autocomplete="new-password"
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

                    <div class="password-strength" id="strengthText"></div>
                    <small class="password-hint">
                        At least 8 characters, including uppercase, lowercase, and a number.
                    </small>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>

                    <div class="password-wrapper">
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                        >
                        <button
                            type="button"
                            class="toggle-password"
                            onclick="togglePassword('confirm_password', this)"
                            aria-label="Show or hide password"
                            aria-pressed="false"
                        >
                            👁
                        </button>
                    </div>

                    <div class="password-match" id="matchText"></div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary full-width">Create Account</button>
                </div>

                <div class="auth-footer">
                    <p>Already have an account? <a href="/login.php">Login</a></p>
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

const password = document.getElementById('password');
const confirmPassword = document.getElementById('confirm_password');
const strength = document.getElementById('strengthText');
const match = document.getElementById('matchText');

if (password && strength) {
    password.addEventListener('input', () => {
        const val = password.value;
        let score = 0;

        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[a-z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;

        if (val.length === 0) {
            strength.textContent = '';
            strength.style.color = '';
            return;
        }

        if (score <= 1) {
            strength.textContent = 'Weak password';
            strength.style.color = '#b91c1c';
        } else if (score <= 3) {
            strength.textContent = 'Medium strength';
            strength.style.color = '#c2410c';
        } else {
            strength.textContent = 'Strong password';
            strength.style.color = '#166534';
        }
    });
}

if (confirmPassword && password && match) {
    confirmPassword.addEventListener('input', () => {
        if (confirmPassword.value === '') {
            match.textContent = '';
            match.style.color = '';
            return;
        }

        if (confirmPassword.value === password.value) {
            match.textContent = 'Passwords match';
            match.style.color = '#166534';
        } else {
            match.textContent = 'Passwords do not match';
            match.style.color = '#b91c1c';
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>