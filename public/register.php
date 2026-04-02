<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    redirectAfterLogin();
}

$pageTitle = 'Register | Indigenous African Names System';

$errors = [];
$fullName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullName === '') $errors[] = 'Full name is required.';
    if ($email === '') $errors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';

    if ($password === '') $errors[] = 'Password is required.';
    elseif (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

    if ($confirmPassword === '') $errors[] = 'Confirm your password.';
    elseif ($password !== $confirmPassword) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $checkStmt->execute([':email' => $email]);

        if ($checkStmt->fetch()) {
            $errors[] = 'Account already exists.';
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $insertStmt = $pdo->prepare("
                    INSERT INTO users (full_name, email, password_hash, role)
                    VALUES (:full_name, :email, :password_hash, 'contributor')
                ");

                $insertStmt->execute([
                    ':full_name' => $fullName,
                    ':email' => $email,
                    ':password_hash' => $passwordHash
                ]);

                $_SESSION['user'] = [
                    'id' => (int)$pdo->lastInsertId(),
                    'full_name' => $fullName,
                    'email' => $email,
                    'role' => 'contributor'
                ];

                redirectAfterLogin();

            } catch (Throwable $e) {
                $errors[] = 'Registration failed.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">

    <section class="detail-hero">
        <h1>Create Your Account</h1>
        <p class="detail-meaning">
            Join the Indigenous African Names System and start contributing cultural knowledge.
        </p>
    </section>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="detail-card auth-card">

        <form method="post" class="submission-form">

            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name"
                       value="<?= htmlspecialchars($fullName) ?>" required>
            </div>

            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email"
                       value="<?= htmlspecialchars($email) ?>" required>
            </div>

            <div class="form-group">
                <label>Password</label>

                <div class="password-wrapper">
                    <input type="password" id="password" name="password" required>
                    <span class="toggle-password" onclick="togglePassword('password')">👁</span>
                </div>

                <div class="password-strength" id="strengthText"></div>
                <small class="password-hint">
                    At least 8 characters, include uppercase, lowercase & number
                </small>
            </div>

            <div class="form-group">
                <label>Confirm Password</label>

                <div class="password-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" required>
                    <span class="toggle-password" onclick="togglePassword('confirm_password')">👁</span>
                </div>

                <div class="password-match" id="matchText"></div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary full-width">Create Account</button>
            </div>

            <div class="auth-footer">
                <p>Already have an account? <a href="login.php">Login</a></p>
            </div>

        </form>

    </section>

</main>

<script>
function togglePassword(id) {
    const input = document.getElementById(id);
    input.type = input.type === "password" ? "text" : "password";
}

const password = document.getElementById('password');
const confirm = document.getElementById('confirm_password');

password.addEventListener('input', () => {
    const val = password.value;
    const strength = document.getElementById('strengthText');

    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[a-z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;

    if (score <= 1) {
        strength.textContent = "Weak password";
        strength.style.color = "red";
    } else if (score === 2 || score === 3) {
        strength.textContent = "Medium strength";
        strength.style.color = "orange";
    } else {
        strength.textContent = "Strong password";
        strength.style.color = "green";
    }
});

confirm.addEventListener('input', () => {
    const match = document.getElementById('matchText');
    if (confirm.value === password.value) {
        match.textContent = "Passwords match";
        match.style.color = "green";
    } else {
        match.textContent = "Passwords do not match";
        match.style.color = "red";
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>