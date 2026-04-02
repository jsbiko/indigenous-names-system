<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requireRole(['admin']);

$user = currentUser();
$currentUserId = (int)($user['id'] ?? 0);

$pageTitle = 'Users & Roles';
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

function formatDateTime(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }

    try {
        $dt = new DateTime($datetime);
        return $dt->format('M j, Y \a\t g:i A');
    } catch (Throwable $e) {
        return $datetime;
    }
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND column_name = :column_name
        ");
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function firstExistingColumn(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (tableHasColumn($pdo, $table, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function roleLabel(string $role): string
{
    return ucfirst(trim($role));
}

function buildRoleAuditMessage(string $targetName, string $actorName, string $oldRole, string $newRole): string
{
    $targetName = trim($targetName) !== '' ? $targetName : 'User';
    $actorName = trim($actorName) !== '' ? $actorName : 'System';

    $old = strtolower($oldRole);
    $new = strtolower($newRole);

    if ($old === $new) {
        return "{$actorName} retained {$targetName} as {$new}.";
    }

    if ($new === 'admin') {
        return "🔼 {$targetName} promoted to Admin by {$actorName}";
    }

    if ($new === 'editor' && $old === 'contributor') {
        return "🔼 {$targetName} promoted to Editor by {$actorName}";
    }

    if ($new === 'contributor' && in_array($old, ['admin', 'editor'], true)) {
        return "🔽 {$targetName} demoted to Contributor by {$actorName}";
    }

    return "⚙️ {$actorName} changed {$targetName} from {$old} → {$new}";
}

$allowedRoles = ['contributor', 'editor', 'admin'];
$errors = [];
$success = null;

/**
 * ------------------------------------------------------------
 * Detect schema safely
 * ------------------------------------------------------------
 */
$usersTable = 'users';

$idColumn = firstExistingColumn($pdo, $usersTable, ['id']);
$nameColumn = firstExistingColumn($pdo, $usersTable, ['name', 'full_name', 'username']);
$emailColumn = firstExistingColumn($pdo, $usersTable, ['email']);
$roleColumn = firstExistingColumn($pdo, $usersTable, ['role']);
$createdAtColumn = firstExistingColumn($pdo, $usersTable, ['created_at', 'created_on', 'date_created', 'registered_at']);

if (!$idColumn || !$emailColumn || !$roleColumn) {
    $errors[] = 'The users table is missing one or more required columns: id, email, or role.';
}

/**
 * ------------------------------------------------------------
 * Handle role update
 * ------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'update_role') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newRole = trim((string)($_POST['role'] ?? ''));

        if ($targetUserId <= 0) {
            $errors[] = 'Invalid user selected.';
        }

        if (!in_array($newRole, $allowedRoles, true)) {
            $errors[] = 'Invalid role selected.';
        }

        if ($targetUserId === $currentUserId && $newRole !== 'admin') {
            $errors[] = 'You cannot remove your own admin role from this page.';
        }

        if (!$errors) {
            try {
                $roleFetchStmt = $pdo->prepare("
                    SELECT {$roleColumn}
                    FROM {$usersTable}
                    WHERE {$idColumn} = :id
                    LIMIT 1
                ");
                $roleFetchStmt->execute(['id' => $targetUserId]);
                $oldRole = (string)$roleFetchStmt->fetchColumn();

                if ($oldRole === '') {
                    $errors[] = 'Target user could not be found.';
                } elseif ($oldRole !== $newRole) {
                    $pdo->beginTransaction();

                    $updateStmt = $pdo->prepare("
                        UPDATE {$usersTable}
                        SET {$roleColumn} = :role
                        WHERE {$idColumn} = :id
                        LIMIT 1
                    ");
                    $updateStmt->execute([
                        'role' => $newRole,
                        'id'   => $targetUserId,
                    ]);

                    $auditStmt = $pdo->prepare("
                        INSERT INTO user_role_audit (user_id, changed_by, old_role, new_role)
                        VALUES (:user_id, :changed_by, :old_role, :new_role)
                    ");
                    $auditStmt->execute([
                        'user_id' => $targetUserId,
                        'changed_by' => $currentUserId,
                        'old_role' => $oldRole,
                        'new_role' => $newRole,
                    ]);

                    $pdo->commit();
                    $success = 'User role updated successfully.';
                } else {
                    $success = 'No role change was needed.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to update role right now.';
            }
        }
    }
}

/**
 * ------------------------------------------------------------
 * Filters / search
 * ------------------------------------------------------------
 */
$search = trim((string)($_GET['search'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? ''));

$where = [];
$params = [];

if ($search !== '') {
    if ($nameColumn) {
        $where[] = "({$nameColumn} LIKE :search OR {$emailColumn} LIKE :search)";
    } else {
        $where[] = "{$emailColumn} LIKE :search";
    }
    $params['search'] = '%' . $search . '%';
}

if ($roleFilter !== '' && in_array($roleFilter, $allowedRoles, true)) {
    $where[] = "{$roleColumn} = :role_filter";
    $params['role_filter'] = $roleFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/**
 * ------------------------------------------------------------
 * Summary counts
 * ------------------------------------------------------------
 */
$totalUsers = 0;
$totalContributors = 0;
$totalEditors = 0;
$totalAdmins = 0;

if (!$errors) {
    try {
        $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM {$usersTable}")->fetchColumn();
        $totalContributors = (int)$pdo->query("SELECT COUNT(*) FROM {$usersTable} WHERE {$roleColumn} = 'contributor'")->fetchColumn();
        $totalEditors = (int)$pdo->query("SELECT COUNT(*) FROM {$usersTable} WHERE {$roleColumn} = 'editor'")->fetchColumn();
        $totalAdmins = (int)$pdo->query("SELECT COUNT(*) FROM {$usersTable} WHERE {$roleColumn} = 'admin'")->fetchColumn();
    } catch (Throwable $e) {
        $errors[] = 'Unable to load user statistics.';
    }
}

/**
 * ------------------------------------------------------------
 * Fetch users safely
 * ------------------------------------------------------------
 */
$users = [];

if (!$errors) {
    try {
        $selectName = $nameColumn
            ? "{$nameColumn} AS display_name"
            : "'' AS display_name";

        $selectCreatedAt = $createdAtColumn
            ? "{$createdAtColumn} AS created_at_value"
            : "NULL AS created_at_value";

        $sql = "
            SELECT
                {$idColumn} AS user_id,
                {$selectName},
                {$emailColumn} AS email_value,
                {$roleColumn} AS role_value,
                {$selectCreatedAt}
            FROM {$usersTable}
            {$whereSql}
            ORDER BY " . ($createdAtColumn ? "{$createdAtColumn} DESC," : '') . " {$idColumn} DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $displayName = trim((string)($row['display_name'] ?? ''));
            $emailValue = trim((string)($row['email_value'] ?? ''));

            $users[] = [
                'id' => (int)($row['user_id'] ?? 0),
                'name' => $displayName !== '' ? $displayName : 'Unnamed User',
                'email' => $emailValue,
                'role' => trim((string)($row['role_value'] ?? 'contributor')),
                'created_at' => isset($row['created_at_value']) ? (string)$row['created_at_value'] : '',
            ];
        }
    } catch (Throwable $e) {
        $errors[] = 'Unable to load users.';
    }
}

/**
 * ------------------------------------------------------------
 * Fetch audit trail
 * ------------------------------------------------------------
 */
$roleAuditRows = [];

try {
    $roleAuditRows = $pdo->query("
        SELECT
            a.id,
            a.old_role,
            a.new_role,
            a.created_at,
            COALESCE(u.full_name, u.name, u.username, u.email, 'User') AS target_name,
            COALESCE(ch.full_name, ch.name, ch.username, ch.email, 'Admin') AS actor_name
        FROM user_role_audit a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN users ch ON ch.id = a.changed_by
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // If the audit table does not exist yet, we simply do not render entries.
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-shell">
  <section class="dashboard-hero">
    <div class="dashboard-hero__grid">
      <div>
        <div class="dashboard-hero__eyebrow">Administration</div>
        <h1>Users &amp; Role Management</h1>
        <p>
          Manage platform access, assign editorial responsibility, and keep role governance inside the system.
        </p>

        <div class="dashboard-role-badge">
          Admin-only control area
        </div>

        <div class="quick-actions">
          <a class="quick-action-btn quick-action-btn--primary" href="/dashboard.php">Back to Dashboard</a>
          <a class="quick-action-btn quick-action-btn--secondary" href="/admin-review.php">Open Review Queue</a>
          <a class="quick-action-btn quick-action-btn--secondary" href="/browse.php">Browse Authority Records</a>
        </div>
      </div>

      <div class="dashboard-hero__stats">
        <div class="hero-mini-card">
          <div class="hero-mini-card__label">Total users</div>
          <div class="hero-mini-card__value"><?= $totalUsers ?></div>
        </div>
        <div class="hero-mini-card">
          <div class="hero-mini-card__label">Contributors</div>
          <div class="hero-mini-card__value"><?= $totalContributors ?></div>
        </div>
        <div class="hero-mini-card">
          <div class="hero-mini-card__label">Editors</div>
          <div class="hero-mini-card__value"><?= $totalEditors ?></div>
        </div>
        <div class="hero-mini-card">
          <div class="hero-mini-card__label">Admins</div>
          <div class="hero-mini-card__value"><?= $totalAdmins ?></div>
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
          <h2 class="panel__title">Role Governance</h2>
          <div class="panel__subtle">Access model</div>
        </div>
        <div class="panel__body">
          <div class="insight-grid">
            <div class="insight-box">
              <strong><?= $totalContributors ?></strong>
              <span>Contributors submit new names and suggestions for authority improvement.</span>
            </div>
            <div class="insight-box">
              <strong><?= $totalEditors ?></strong>
              <span>Editors serve as reviewers and moderators of authority-page content.</span>
            </div>
            <div class="insight-box">
              <strong><?= $totalAdmins ?></strong>
              <span>Admins control user governance, system access, and operational oversight.</span>
            </div>
            <div class="insight-box">
              <strong>3 Roles</strong>
              <span>For this MVP, keep the system clean: contributor, editor, and admin.</span>
            </div>
          </div>

          <p class="section-note">
            Recommended governance: only admins should assign roles. Editors should review content, not manage access privileges.
          </p>
        </div>
      </section>

      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">All Users</h2>
          <div class="panel__subtle"><?= count($users) ?> result(s)</div>
        </div>
        <div class="panel__body">
          <?php if (!$users): ?>
            <div class="empty-state">
              No users found for the current filters.
            </div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Joined</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($users as $row): ?>
                    <?php
                      $rowId = (int)$row['id'];
                      $rowRole = (string)($row['role'] ?? 'contributor');
                      $isCurrentUser = $rowId === $currentUserId;

                      $badgeClass = match ($rowRole) {
                          'admin' => 'badge-admin',
                          'editor' => 'badge-editor',
                          default => 'badge-contributor',
                      };
                    ?>
                    <tr>
                      <td>
                        <strong><?= e((string)$row['name']) ?></strong>
                        <?php if ($isCurrentUser): ?>
                          <div style="margin-top:6px;">
                            <span class="badge badge-approved">You</span>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td><?= e((string)$row['email']) ?></td>
                      <td>
                        <span class="badge <?= e($badgeClass) ?>">
                          <?= e(ucfirst($rowRole)) ?>
                        </span>
                      </td>
                      <td><?= e(formatShortDate((string)$row['created_at'])) ?></td>
                      <td>
                        <form method="post" class="inline-form">
                          <input type="hidden" name="action" value="update_role">
                          <input type="hidden" name="user_id" value="<?= $rowId ?>">

                          <select name="role" aria-label="Select role for <?= e((string)$row['name']) ?>">
                            <?php foreach ($allowedRoles as $roleOption): ?>
                              <option value="<?= e($roleOption) ?>" <?= $rowRole === $roleOption ? 'selected' : '' ?>>
                                <?= e(ucfirst($roleOption)) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>

                          <button type="submit">Update</button>
                        </form>
                        <?php if ($isCurrentUser): ?>
                          <div style="margin-top:6px; font-size:0.85rem; color:#64748b;">
                            Self-demotion blocked.
                          </div>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <div class="dashboard-stack">
      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Role Change History</h2>
          <div class="panel__subtle">Latest audit events</div>
        </div>
        <div class="panel__body">
          <?php if (!$roleAuditRows): ?>
            <div class="empty-state">
              No role change history yet. Once an admin promotes, demotes, or changes a role, the action will appear here.
            </div>
          <?php else: ?>
            <div class="activity-list">
              <?php foreach ($roleAuditRows as $audit): ?>
                <div class="activity-item">
                  <div class="activity-item__top">
                    <div class="activity-item__title">
                      <?= e(buildRoleAuditMessage(
                          (string)($audit['target_name'] ?? ''),
                          (string)($audit['actor_name'] ?? ''),
                          (string)($audit['old_role'] ?? ''),
                          (string)($audit['new_role'] ?? '')
                      )) ?>
                    </div>
                    <span class="status-pill status--neutral">role change</span>
                  </div>
                  <div class="activity-item__meta">
                    <strong>From:</strong> <?= e(roleLabel((string)($audit['old_role'] ?? ''))) ?><br>
                    <strong>To:</strong> <?= e(roleLabel((string)($audit['new_role'] ?? ''))) ?><br>
                    <strong>Date:</strong> <?= e(formatDateTime((string)($audit['created_at'] ?? ''))) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Filter Users</h2>
          <div class="panel__subtle">Find faster</div>
        </div>
        <div class="panel__body">
          <form method="get" class="submission-form">
            <div class="form-group">
              <label for="search">Search name or email</label>
              <input
                type="text"
                id="search"
                name="search"
                value="<?= e($search) ?>"
                placeholder="e.g. Achieng or admin@example.com"
              >
            </div>

            <div class="form-group">
              <label for="role">Role</label>
              <select id="role" name="role">
                <option value="">All roles</option>
                <option value="contributor" <?= $roleFilter === 'contributor' ? 'selected' : '' ?>>Contributor</option>
                <option value="editor" <?= $roleFilter === 'editor' ? 'selected' : '' ?>>Editor</option>
                <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
              </select>
            </div>

            <div class="form-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
              <button type="submit">Apply Filters</button>
              <a class="btn-secondary" href="/manage-users.php">Reset</a>
            </div>
          </form>
        </div>
      </section>

      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Admin Notes</h2>
          <div class="panel__subtle">Guardrails</div>
        </div>
        <div class="panel__body">
          <div class="activity-list">
            <div class="activity-item">
              <div class="activity-item__title">Role ownership stays inside the app</div>
              <div class="activity-item__meta">
                Admins should assign contributor, editor, and admin privileges from this page rather than using phpMyAdmin for routine governance.
              </div>
            </div>

            <div class="activity-item">
              <div class="activity-item__title">Editors are your reviewers</div>
              <div class="activity-item__meta">
                For the MVP, keep editor = reviewer. This keeps the permission model strong and simple.
              </div>
            </div>

            <div class="activity-item">
              <div class="activity-item__title">Avoid too many admins</div>
              <div class="activity-item__meta">
                Limit admin accounts to trusted platform governors. Editors can handle most content operations without full admin access.
              </div>
            </div>

            <div class="activity-item">
              <div class="activity-item__title">Protect your own access</div>
              <div class="activity-item__meta">
                This page prevents you from removing your own admin role accidentally.
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>