<?php
declare(strict_types=1);

/**
 * ------------------------------------------------------------
 * Session bootstrap
 * ------------------------------------------------------------
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * ------------------------------------------------------------
 * Authentication helpers
 * ------------------------------------------------------------
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']);
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    $user = $_SESSION['user'];

    return [
        'id'    => isset($user['id']) ? (int)$user['id'] : 0,
        'name'  => isset($user['name']) ? trim((string)$user['name']) : '',
        'email' => isset($user['email']) ? trim((string)$user['email']) : '',
        'role'  => isset($user['role']) ? trim((string)$user['role']) : 'contributor',
    ];
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        $_SESSION['flash_error'] = 'Please log in to continue.';
        header('Location: /login.php');
        exit;
    }
}

/**
 * ------------------------------------------------------------
 * Role helpers
 * ------------------------------------------------------------
 */
function hasRole(array $allowedRoles): bool
{
    $user = currentUser();

    if (!$user) {
        return false;
    }

    $userRole = strtolower(trim((string)($user['role'] ?? 'contributor')));
    $normalizedAllowedRoles = array_map(
        static fn ($role) => strtolower(trim((string)$role)),
        $allowedRoles
    );

    return in_array($userRole, $normalizedAllowedRoles, true);
}

function requireRole(array $allowedRoles): void
{
    requireLogin();

    if (!hasRole($allowedRoles)) {
        http_response_code(403);
        $_SESSION['flash_error'] = 'You do not have permission to access that page.';
        header('Location: /dashboard.php');
        exit;
    }
}

/**
 * ------------------------------------------------------------
 * Session write helpers
 * ------------------------------------------------------------
 */
function loginUser(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'    => isset($user['id']) ? (int)$user['id'] : 0,
        'name'  => isset($user['name']) ? trim((string)$user['name']) : '',
        'email' => isset($user['email']) ? trim((string)$user['email']) : '',
        'role'  => isset($user['role']) ? trim((string)$user['role']) : 'contributor',
    ];
}

function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}