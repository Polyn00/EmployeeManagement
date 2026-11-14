<?php
require __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $pageTitle = 'Sign out';
        $activePage = '';
        require __DIR__ . '/includes/header.php';
        require __DIR__ . '/includes/sidebar.php';
        ?>
        <div class="alert alert-error" role="alert">Your session expired. Reload the page and try signing out again.</div>
        <?php
        require __DIR__ . '/includes/footer.php';
        exit;
    }

    $user = auth_current_user();
    if ($user !== null) {
        try {
            require __DIR__ . '/config/db.php';
            require_once __DIR__ . '/includes/audit.php';
            audit_record(
                $pdo,
                'logout',
                'user',
                (int) $user['user_id'],
                ['role' => (string) $user['role']],
                (int) $user['user_id']
            );
        } catch (Throwable $e) {
            error_log('Logout audit record failed: ' . $e->getMessage());
        }
    }
    auth_logout();
    header('Location: login.php');
    exit;
}

if (auth_current_user() === null) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Sign out';
$activePage = '';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">ACCOUNT</p>
        <h1>Sign out</h1>
        <p class="page-description">Are you sure you want to end your PeopleSuite session?</p>
    </div>
</section>
<form method="post" action="logout.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <button class="button button-primary" type="submit">Sign out</button>
    <a class="button button-quiet" href="<?= htmlspecialchars(auth_home_url(auth_current_user()), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>