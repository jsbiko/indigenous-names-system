<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['admin']);

$pageTitle = 'Manage Users | Indigenous African Names System';

$success = '';
$error = '';

/* Handle role update */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $newRole = $_POST['role'] ?? '';

    $allowedRoles = ['contributor', 'editor', 'admin'];

    if ($userId <= 0 || !in_array($newRole, $allowedRoles, true)) {
        $error = 'Invalid update request.';
    } else {
        $stmt = $pdo->prepare("
            UPDATE users
            SET role = :role
            WHERE id = :id
        ");
        $stmt->execute([
            ':role' => $newRole,
            ':id' => $userId
        ]);

        $success = 'User role updated successfully.';
    }
}

/* Fetch users */
$stmt = $pdo->query("
    SELECT id, full_name, email, role, created_at
    FROM users
    ORDER BY created_at DESC
");
$users = $stmt->fetchAll();

/* Stats */
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(role = 'contributor') AS contributors,
        SUM(role = 'editor') AS editors,
        SUM(role = 'admin') AS admins
    FROM users
");
$stats = $statsStmt->fetch();

require_once __DIR__ . '/../includes/header.php';
?>

<main class="container page-section">

    <section class="detail-hero">
        <span class="eyebrow">Administration</span>
        <h1>User Management</h1>
        <p class="detail-meaning">
            Manage platform roles and access levels for contributors, editors, and administrators.
        </p>
    </section>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- STATS -->
    <section class="dashboard-section">
        <div class="dashboard-stats-grid">
            <div class="dashboard-stat-card">
                <h3><?= (int)$stats['total'] ?></h3>
                <p>Total Users</p>
            </div>
            <div class="dashboard-stat-card">
                <h3><?= (int)$stats['contributors'] ?></h3>
                <p>Contributors</p>
            </div>
            <div class="dashboard-stat-card">
                <h3><?= (int)$stats['editors'] ?></h3>
                <p>Editors</p>
            </div>
            <div class="dashboard-stat-card">
                <h3><?= (int)$stats['admins'] ?></h3>
                <p>Admins</p>
            </div>
        </div>
    </section>

    <!-- USERS TABLE -->
    <section class="dashboard-section">
        <div class="detail-card">

            <div class="panel-heading">
                <h2>All Users</h2>
                <p>Assign roles and manage access across the system.</p>
            </div>

            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Joined</th>
                            <th>Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= htmlspecialchars($user['full_name']) ?></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td>
                                    <span class="badge badge-<?= htmlspecialchars($user['role']) ?>">
                                        <?= htmlspecialchars(ucfirst($user['role'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($user['created_at']) ?></td>
                                <td>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                                        <select name="role">
                                            <option value="contributor" <?= $user['role'] === 'contributor' ? 'selected' : '' ?>>Contributor</option>
                                            <option value="editor" <?= $user['role'] === 'editor' ? 'selected' : '' ?>>Editor</option>
                                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        </select>

                                        <button type="submit">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>