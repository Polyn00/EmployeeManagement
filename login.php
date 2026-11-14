<?php
require __DIR__ . '/includes/auth.php';

$user = auth_current_user();
if ($user !== null) {
    header('Location: ' . auth_home_url($user));
    exit;
}

$errorMessage = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginUsername = isset($_POST['username']) && is_string($_POST['username'])
        ? trim($_POST['username'])
        : '';
    $loginPassword = isset($_POST['password']) && is_string($_POST['password'])
        ? $_POST['password']
        : '';

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Unable to sign in with those credentials.';
    } elseif ($loginUsername === '' || strlen($loginUsername) > 50 || $loginPassword === '' || strlen($loginPassword) > 72) {
        $errorMessage = 'Unable to sign in with those credentials.';
    } else {
        try {
            require __DIR__ . '/config/db.php';
            require_once __DIR__ . '/includes/audit.php';
            $statement = $pdo->prepare(
                'SELECT u.user_id, u.employee_id, u.username, u.password,
                        u.role, u.account_status, e.employee_id AS linked_employee_id
                 FROM users AS u
                 LEFT JOIN employees AS e ON e.employee_id = u.employee_id
                 WHERE u.username = :username
                 LIMIT 1'
            );
            $statement->execute([':username' => $loginUsername]);
            $account = $statement->fetch();

            if ($account !== false
                && $account['account_status'] === 'Active'
                && in_array($account['role'], ['Admin', 'HR', 'Employee'], true)
                && password_verify($loginPassword, $account['password'])
                && ($account['role'] !== 'Employee' || $account['linked_employee_id'] !== null)
            ) {
                audit_record(
                    $pdo,
                    'login_succeeded',
                    'user',
                    (int) $account['user_id'],
                    ['role' => (string) $account['role']],
                    (int) $account['user_id']
                );
                auth_login($account);
                header('Location: ' . auth_home_url(auth_current_user()));
                exit;
            } else {
                audit_record($pdo, 'login_failed', 'user', null, ['reason' => 'invalid_credentials']);
            }
        } catch (PDOException $e) {
            error_log('Login query failed: ' . $e->getMessage());
        }

        $errorMessage = 'Unable to sign in with those credentials.';
    }
}

$pageTitle = 'Sign in';
$activePage = 'login';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">WELCOME BACK</p>
        <h1>Sign in</h1>
        <p class="page-description">Sign in to your PeopleSuite workspace.</p>
    </div>
</section>

<section class="auth-panel panel">
    <div class="auth-panel-heading">
        <h2>Account access</h2>
        <p>Use your administrator-provided account credentials.</p>
    </div>
    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" action="login.php" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" maxlength="50" autocomplete="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" required autofocus>
        </div>
        <div class="form-field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" maxlength="72" autocomplete="current-password" required>
        </div>
        <button class="button button-primary auth-submit" type="submit">Sign in</button>
    </form>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>