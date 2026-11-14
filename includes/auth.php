<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function auth_current_user(): ?array
{
    if (!isset($_SESSION['auth_user']) || !is_array($_SESSION['auth_user'])) {
        return null;
    }

    $user = $_SESSION['auth_user'];
    if (!isset($user['user_id'], $user['username'], $user['role'])
        || !in_array($user['role'], ['Admin', 'HR', 'Employee'], true)
    ) {
        return null;
    }

    $lastActivity = (int) ($_SESSION['auth_last_activity'] ?? 0);
    if ($lastActivity > 0 && time() - $lastActivity > 1800) {
        auth_logout();
        return null;
    }

    $_SESSION['auth_last_activity'] = time();
    return $user;
}

function auth_login(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['auth_user'] = [
        'user_id' => (int) $user['user_id'],
        'employee_id' => isset($user['employee_id']) ? (int) $user['employee_id'] : null,
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
    ];
    $_SESSION['auth_last_activity'] = time();
    $_SESSION['auth_session_regenerated'] = time();
}

function auth_logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];
    $parameters = session_get_cookie_params();
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function auth_csrf_token(): string
{
    if (empty($_SESSION['auth_csrf_token'])) {
        $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['auth_csrf_token'];
}

function auth_csrf_is_valid($submittedToken): bool
{
    return is_string($submittedToken)
        && isset($_SESSION['auth_csrf_token'])
        && hash_equals($_SESSION['auth_csrf_token'], $submittedToken);
}

function auth_home_url(array $user): string
{
    return $user['role'] === 'Employee' ? 'my-account.php' : 'dashboard.php';
}

function auth_require_roles(array $allowedRoles): void
{
    $user = auth_current_user();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }

    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        $pageTitle = 'Access denied';
        $activePage = '';
        require __DIR__ . '/header.php';
        require __DIR__ . '/sidebar.php';
        ?>
        <section class="page-heading">
            <div>
                <p class="eyebrow">ACCESS CONTROL</p>
                <h1>Access denied</h1>
                <p class="page-description">Your account does not have permission to access this page.</p>
            </div>
            <a class="button button-quiet" href="<?= htmlspecialchars(auth_home_url($user), ENT_QUOTES, 'UTF-8') ?>">Return to your workspace</a>
        </section>
        <?php
        require __DIR__ . '/footer.php';
        exit;
    }
}
