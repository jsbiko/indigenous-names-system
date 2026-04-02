<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Reset Password | Indigenous African Names System';

$token = trim($_GET['token'] ?? '');
$error = '';
$success = '';

$reset = null;

if ($token === '') {
    $error = 'Invalid password reset token.';
} else {
    $stmt = $pdo->prepare("
        SELECT email, expires_at
        FROM password_resets
        WHERE token = :token
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $reset = $stmt->fetch();

    if (!$reset || strtotime((string)$reset['expires_at']) < time()) {
        $error = 'This reset link is invalid or has expired.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && $reset) {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if ($password === '' || strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
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
            ':email' => $reset['email'],
        ]);

        $pdo->prepare("
            DELETE FROM password_resets
            WHERE email = :email
        ")->execute([
            ':email' => $reset['email'],
        ]);

        $success = 'Password updated successfully.';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">
    <section class="auth-hero-block">
        <span class="eyebrow">Secure Reset</span>
        <h1>Reset Password</h1>
        <p class="detail-meaning">
            Choose a new password for your account.
        </p>
    </section>

    <section class="auth-shell auth-shell-single">
        <div class="detail-card auth-card auth-card-premium">
            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php elseif ($success !== ''): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <div class="auth-footer">
                    <p><a href="login.php">Go to login</a></p>
                </div>
            <?php else: ?>
                <form method="post" action="reset-password.php?token=<?= urlencode($token) ?>" class="submission-form">
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <div class="password-wrapper">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                required
                                minlength="8"
                                autocomplete="new-password"
                            >
                            <button type="button" class="toggle-password" onclick="togglePassword('password')" aria-label="Show or hide password">
                                👁
                            </button>
                        </div>
                        <div class="password-strength" id="strengthText"></div>
                    </div>

                    <div class="form-group">
                        <label for="confirm">Confirm Password</label>
                        <div class="password-wrapper">
                            <input
                                type="password"
                                id="confirm"
                                name="confirm"
                                required
                                minlength="8"
                                autocomplete="new-password"
                            >
                            <button type="button" class="toggle-password" onclick="togglePassword('confirm')" aria-label="Show or hide password">
                                👁
                            </button>
                        </div>
                        <div class="password-match" id="matchText"></div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary full-width">Reset Password</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>
</main>

<script>
function togglePassword(id) {
    const input = document.getElementById(id);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
}

const password = document.getElementById('password');
const confirmPassword = document.getElementById('confirm');

if (password) {
    password.addEventListener('input', () => {
        const val = password.value;
        const strength = document.getElementById('strengthText');
        if (!strength) return;

        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[a-z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;

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

if (confirmPassword && password) {
    confirmPassword.addEventListener('input', () => {
        const match = document.getElementById('matchText');
        if (!match) return;

        if (confirmPassword.value === '') {
            match.textContent = '';
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