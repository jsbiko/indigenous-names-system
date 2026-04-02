<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$currentUser = currentUser();
$currentUserId = (int)($currentUser['id'] ?? 0);

$pageTitle = 'My Profile';
$bodyClass = 'dashboard-page';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatShortDate(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }

    try {
        $dt = new DateTime($datetime);
        return $dt->format('M j, Y');
    } catch (Throwable $e) {
        return $datetime;
    }
}

function safePercent(int $part, int $total): int
{
    if ($total <= 0) {
        return 0;
    }

    return (int)round(($part / $total) * 100);
}

/**
 * ------------------------------------------------------------
 * Load current user record
 * ------------------------------------------------------------
 */
$userStmt = $pdo->prepare("
    SELECT id, full_name, email, role, created_at, password_hash
    FROM users
    WHERE id = :id
    LIMIT 1
");
$userStmt->execute(['id' => $currentUserId]);
$userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$userRow) {
    logoutUser();
    header('Location: /login.php');
    exit;
}

$fullName = trim((string)($userRow['full_name'] ?? ''));
$email = trim((string)($userRow['email'] ?? ''));
$role = trim((string)($userRow['role'] ?? 'contributor'));
$joinedAt = isset($userRow['created_at']) ? (string)$userRow['created_at'] : '';
$currentPasswordHash = (string)($userRow['password_hash'] ?? '');

$errors = [];
$success = null;

/**
 * ------------------------------------------------------------
 * Contribution stats
 * ------------------------------------------------------------
 */
$myEntries = 0;
$myApprovedEntries = 0;
$myPendingEntries = 0;
$myRejectedEntries = 0;
$mySuggestions = 0;

/* Use proper prepared statements individually */
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM name_entries WHERE created_by = :user_id");
    $stmt->execute(['user_id' => $currentUserId]);
    $myEntries = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM name_entries WHERE created_by = :user_id AND status = 'approved'");
    $stmt->execute(['user_id' => $currentUserId]);
    $myApprovedEntries = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM name_entries WHERE created_by = :user_id AND status = 'pending'");
    $stmt->execute(['user_id' => $currentUserId]);
    $myPendingEntries = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM name_entries WHERE created_by = :user_id AND status = 'rejected'");
    $stmt->execute(['user_id' => $currentUserId]);
    $myRejectedEntries = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    // ignored
}

try {
    $suggestionTableCandidates = ['suggestions', 'improvements', 'name_suggestions', 'entry_suggestions'];
    $suggestionTable = null;

    foreach ($suggestionTableCandidates as $candidate) {
        $check = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
        ");
        $check->execute(['table_name' => $candidate]);
        if ((int)$check->fetchColumn() > 0) {
            $suggestionTable = $candidate;
            break;
        }
    }

    if ($suggestionTable) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM {$suggestionTable}
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $currentUserId]);
        $mySuggestions = (int)$stmt->fetchColumn();
    }
} catch (Throwable $e) {
    // ignored
}

$approvalRate = safePercent($myApprovedEntries, $myEntries);

/**
 * ------------------------------------------------------------
 * Handle forms
 * ------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'update_profile') {
        $newFullName = trim((string)($_POST['full_name'] ?? ''));
        $newEmail = trim((string)($_POST['email'] ?? ''));

        if ($newFullName === '') {
            $errors[] = 'Full name is required.';
        } elseif (mb_strlen($newFullName) > 150) {
            $errors[] = 'Full name must not exceed 150 characters.';
        }

        if ($newEmail === '') {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (mb_strlen($newEmail) > 150) {
            $errors[] = 'Email must not exceed 150 characters.';
        }

        if (!$errors) {
            try {
                $checkStmt = $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE email = :email
                      AND id <> :id
                    LIMIT 1
                ");
                $checkStmt->execute([
                    'email' => $newEmail,
                    'id' => $currentUserId,
                ]);

                if ($checkStmt->fetch()) {
                    $errors[] = 'That email address is already in use by another account.';
                } else {
                    $updateStmt = $pdo->prepare("
                        UPDATE users
                        SET full_name = :full_name,
                            email = :email
                        WHERE id = :id
                        LIMIT 1
                    ");
                    $updateStmt->execute([
                        'full_name' => $newFullName,
                        'email' => $newEmail,
                        'id' => $currentUserId,
                    ]);

                    loginUser([
                        'id' => $currentUserId,
                        'name' => $newFullName,
                        'email' => $newEmail,
                        'role' => $role,
                    ]);

                    $fullName = $newFullName;
                    $email = $newEmail;
                    $success = 'Profile details updated successfully.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Unable to update your profile right now.';
            }
        }
    }

    if ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($currentPassword === '') {
            $errors[] = 'Current password is required.';
        }

        if ($newPassword === '') {
            $errors[] = 'New password is required.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        } elseif (
            !preg_match('/[A-Z]/', $newPassword) ||
            !preg_match('/[a-z]/', $newPassword) ||
            !preg_match('/[0-9]/', $newPassword)
        ) {
            $errors[] = 'New password must include at least one uppercase letter, one lowercase letter, and one number.';
        }

        if ($confirmPassword === '') {
            $errors[] = 'Please confirm your new password.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match.';
        }

        if ($newPassword !== '' && $currentPassword !== '' && $newPassword === $currentPassword) {
            $errors[] = 'Your new password must be different from your current password.';
        }

        if (!$errors) {
            if (!password_verify($currentPassword, $currentPasswordHash)) {
                $errors[] = 'Current password is incorrect.';
            } else {
                try {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                    $updatePasswordStmt = $pdo->prepare("
                        UPDATE users
                        SET password_hash = :password_hash
                        WHERE id = :id
                        LIMIT 1
                    ");
                    $updatePasswordStmt->execute([
                        'password_hash' => $newHash,
                        'id' => $currentUserId,
                    ]);

                    $currentPasswordHash = $newHash;
                    $success = 'Password updated successfully.';
                } catch (Throwable $e) {
                    $errors[] = 'Unable to change your password right now.';
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-shell">
  <section class="dashboard-hero">
    <div class="dashboard-hero__grid">
      <div>
        <div class="dashboard-hero__eyebrow">Account Settings</div>
        <h1>My Profile</h1>
        <p>
          Manage your account details, review your contribution footprint, and keep your access secure.
        </p>

        <div class="dashboard-role-badge">
          Role: <?= e(ucfirst($role)) ?>
        </div>

        <div class="quick-actions">
          <a class="quick-action-btn quick-action-btn--primary" href="/dashboard.php">Back to Dashboard</a>
          <a class="quick-action-btn quick-action-btn--secondary" href="/browse.php">Browse Records</a>
          <a class="quick-action-btn quick-action-btn--secondary" href="/submit.php">Submit a Name</a>
        </div>
      </div>

      <div class="dashboard-hero__stats">
        <div class="hero-mini-card">
          <div class="hero-mini-card__label">My submissions</div>
          <div class="hero-mini-card__value"><?= $myEntries ?></div>
        </div>
        <div class="hero-mini-card">
          <div class="hero-mini-card__label">Approval rate</div>
          <div class="hero-mini-card__value"><?= $approvalRate ?>%</div>
        </div>
        <div class="hero-mini-card">
          <div class="hero-mini-card__label">Pending review</div>
          <div class="hero-mini-card__value"><?= $myPendingEntries ?></div>
        </div>
        <div class="hero-mini-card">
          <div class="hero-mini-card__label">Suggestions made</div>
          <div class="hero-mini-card__value"><?= $mySuggestions ?></div>
        </div>
      </div>
    </div>
  </section>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <ul>
        <?php foreach ($errors as $error): ?>
          <li><?= e($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success">
      <?= e($success) ?>
    </div>
  <?php endif; ?>

  <div class="dashboard-grid">
    <div class="dashboard-stack">
      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Profile Information</h2>
          <div class="panel__subtle">Update your basic account details</div>
        </div>
        <div class="panel__body">
          <form method="post" class="submission-form" novalidate>
            <input type="hidden" name="action" value="update_profile">

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
              <label for="email">Email Address</label>
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

            <div class="form-actions">
              <button type="submit" class="btn-primary">Save Profile Changes</button>
            </div>
          </form>
        </div>
      </section>

      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Change Password</h2>
          <div class="panel__subtle">Keep your account secure</div>
        </div>
        <div class="panel__body">
          <form method="post" class="submission-form" novalidate>
            <input type="hidden" name="action" value="change_password">

            <div class="form-group">
              <label for="current_password">Current Password</label>
              <div class="password-wrapper">
                <input
                  type="password"
                  id="current_password"
                  name="current_password"
                  required
                  autocomplete="current-password"
                >
                <button
                  type="button"
                  class="toggle-password"
                  onclick="togglePassword('current_password', this)"
                  aria-label="Show or hide current password"
                  aria-pressed="false"
                >
                  👁
                </button>
              </div>
            </div>

            <div class="form-group">
              <label for="new_password">New Password</label>
              <div class="password-wrapper">
                <input
                  type="password"
                  id="new_password"
                  name="new_password"
                  required
                  minlength="8"
                  autocomplete="new-password"
                >
                <button
                  type="button"
                  class="toggle-password"
                  onclick="togglePassword('new_password', this)"
                  aria-label="Show or hide new password"
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
              <label for="confirm_password">Confirm New Password</label>
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
                  aria-label="Show or hide confirm password"
                  aria-pressed="false"
                >
                  👁
                </button>
              </div>
              <div class="password-match" id="matchText"></div>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn-primary">Update Password</button>
            </div>
          </form>
        </div>
      </section>
    </div>

    <div class="dashboard-stack">
      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Account Summary</h2>
          <div class="panel__subtle">Read-only account information</div>
        </div>
        <div class="panel__body">
          <div class="activity-list">
            <div class="activity-item">
              <div class="activity-item__title">Current role</div>
              <div class="activity-item__meta"><?= e(ucfirst($role)) ?></div>
            </div>

            <div class="activity-item">
              <div class="activity-item__title">Joined</div>
              <div class="activity-item__meta"><?= e(formatShortDate($joinedAt)) ?></div>
            </div>

            <div class="activity-item">
              <div class="activity-item__title">Email address</div>
              <div class="activity-item__meta"><?= e($email) ?></div>
            </div>
          </div>

          <p class="section-note">
            Role changes are controlled by administrators. This page is for profile maintenance and account security only.
          </p>
        </div>
      </section>

      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Contribution Snapshot</h2>
          <div class="panel__subtle">Your publishing footprint</div>
        </div>
        <div class="panel__body">
          <div class="insight-grid">
            <div class="insight-box">
              <strong><?= $myApprovedEntries ?></strong>
              <span>Approved entries currently published in the system.</span>
            </div>
            <div class="insight-box">
              <strong><?= $myPendingEntries ?></strong>
              <span>Records currently waiting for editorial review.</span>
            </div>
            <div class="insight-box">
              <strong><?= $myRejectedEntries ?></strong>
              <span>Entries that may need revision and resubmission.</span>
            </div>
            <div class="insight-box">
              <strong><?= $mySuggestions ?></strong>
              <span>Improvement suggestions contributed to authority pages.</span>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</div>

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

const newPassword = document.getElementById('new_password');
const confirmPassword = document.getElementById('confirm_password');
const strength = document.getElementById('strengthText');
const match = document.getElementById('matchText');

if (newPassword && strength) {
    newPassword.addEventListener('input', () => {
        const val = newPassword.value;
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

if (confirmPassword && newPassword && match) {
    confirmPassword.addEventListener('input', () => {
        if (confirmPassword.value === '') {
            match.textContent = '';
            match.style.color = '';
            return;
        }

        if (confirmPassword.value === newPassword.value) {
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