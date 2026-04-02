<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']);
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireRole(array $allowedRoles): void
{
    requireLogin();

    $user = currentUser();
    if (!$user || !in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

function isAdmin(): bool
{
    $user = currentUser();
    return $user !== null && ($user['role'] ?? null) === 'admin';
}

function isEditor(): bool
{
    $user = currentUser();
    return $user !== null && ($user['role'] ?? null) === 'editor';
}

function isContributor(): bool
{
    $user = currentUser();
    return $user !== null && ($user['role'] ?? null) === 'contributor';
}

function redirectAfterLogin(): void
{
    $user = currentUser();

    if (!$user) {
        header('Location: login.php');
        exit;
    }

    $role = $user['role'] ?? '';

    if ($role === 'admin' || $role === 'editor') {
        header('Location: dashboard.php');
        exit;
    }

    header('Location: dashboard.php');
    exit;
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
            $params['path'],
            $params['domain'],
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }

    session_destroy();
}