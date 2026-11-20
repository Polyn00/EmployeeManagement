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

    $username = $loginUsername;

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Unable to sign in with those credentials.';

    } elseif (
        $loginUsername === '' ||
        strlen($loginUsername) > 50 ||
        $loginPassword === '' ||
        strlen($loginPassword) > 72
    ) {
        $errorMessage = 'Unable to sign in with those credentials.';

    } else {
        try {
            require __DIR__ . '/config/db.php';
            require_once __DIR__ . '/includes/audit.php';

            $statement = $pdo->prepare(
                'SELECT u.user_id, u.employee_id, u.username, u.password,
                        u.role, u.account_status,
                        e.employee_id AS linked_employee_id
                 FROM users AS u
                 LEFT JOIN employees AS e
                    ON e.employee_id = u.employee_id
                 WHERE u.username = :username
                 LIMIT 1'
            );

            $statement->execute([
                ':username' => $loginUsername
            ]);

            $account = $statement->fetch();

            if (
                $account !== false &&
                $account['account_status'] === 'Active' &&
                in_array(
                    $account['role'],
                    ['Admin', 'HR', 'Employee'],
                    true
                ) &&
                password_verify(
                    $loginPassword,
                    $account['password']
                ) &&
                (
                    $account['role'] !== 'Employee' ||
                    $account['linked_employee_id'] !== null
                )
            ) {
                audit_record(
                    $pdo,
                    'login_succeeded',
                    'user',
                    (int)$account['user_id'],
                    ['role' => (string)$account['role']],
                    (int)$account['user_id']
                );

                auth_login($account);

                header(
                    'Location: ' .
                    auth_home_url(auth_current_user())
                );

                exit;

            } else {
                audit_record(
                    $pdo,
                    'login_failed',
                    'user',
                    null,
                    ['reason' => 'invalid_credentials']
                );
            }

        } catch (PDOException $e) {
            error_log(
                'Login query failed: ' . $e->getMessage()
            );
        }

        $errorMessage =
            'Unable to sign in with those credentials.';
    }
}

$pageTitle = 'Sign in';
$activePage = 'login';

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>

<style>
/* ==========================================
   PEOPLESUITE PROFESSIONAL LOGIN
   Uses existing PeopleSuite theme variables
========================================== */

.login-page {
    min-height: calc(100vh - 120px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 35px 20px;
}

.login-container {
    width: 100%;
    max-width: 1000px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 20px 55px rgba(0, 0, 0, .12);
}

/* LEFT BRANDING PANEL */

.login-brand-panel {
    position: relative;
    overflow: hidden;
    min-height: 550px;
    padding: 55px 45px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    background: var(--panel-raised);
    border-right: 1px solid var(--border);
}

.login-brand-panel::before {
    content: "";
    position: absolute;
    width: 280px;
    height: 280px;
    border-radius: 50%;
    background: var(--accent);
    opacity: .07;
    top: -110px;
    right: -100px;
}

.login-brand-panel::after {
    content: "";
    position: absolute;
    width: 220px;
    height: 220px;
    border-radius: 50%;
    background: var(--accent);
    opacity: .05;
    bottom: -90px;
    left: -80px;
}

.login-brand-content,
.login-brand-footer {
    position: relative;
    z-index: 1;
}

.login-brand-logo {
    width: 53px;
    height: 53px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--accent);
    color: #171323;
    font-size: 22px;
    font-weight: 850;
    margin-bottom: 30px;
}

.login-brand-name {
    color: var(--text);
    font-size: 27px;
    font-weight: 850;
    letter-spacing: -.8px;
    margin: 0 0 9px;
}

.login-brand-tagline {
    color: var(--accent);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
}

.login-brand-description {
    color: var(--muted);
    font-size: 14px;
    line-height: 1.9;
    max-width: 340px;
    margin-top: 25px;
}

.login-feature-list {
    display: flex;
    flex-direction: column;
    gap: 17px;
    margin-top: 40px;
}

.login-feature {
    display: flex;
    align-items: center;
    gap: 13px;
    color: var(--text);
    font-size: 12px;
    font-weight: 600;
}

.login-feature-icon {
    width: 32px;
    height: 32px;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    background: rgba(156, 140, 255, .12);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--accent);
    font-size: 13px;
}

.login-brand-footer {
    color: var(--muted);
    font-size: 10px;
    line-height: 1.7;
}

/* RIGHT LOGIN PANEL */

.login-form-panel {
    padding: 55px 48px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
}

.login-welcome {
    margin-bottom: 32px;
}

.login-eyebrow {
    color: var(--accent);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 2px;
    margin-bottom: 12px;
}

.login-welcome h1 {
    color: var(--text);
    font-size: 29px;
    font-weight: 800;
    letter-spacing: -.8px;
    margin: 0 0 10px;
}

.login-welcome p {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.7;
    margin: 0;
}

.login-form {
    display: flex;
    flex-direction: column;
    gap: 21px;
}

.login-input-group {
    display: flex;
    flex-direction: column;
    gap: 9px;
}

.login-input-group label {
    color: var(--text);
    font-size: 12px;
    font-weight: 700;
}

.login-input-wrapper {
    position: relative;
}

.login-input-wrapper input {
    width: 100%;
    height: 49px;
    padding: 0 15px;
    background: var(--panel-raised);
    border: 1px solid var(--border);
    border-radius: 11px;
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
    outline: none;
    transition: .2s ease;
}

.login-input-wrapper input::placeholder {
    color: var(--muted);
    opacity: .75;
}

.login-input-wrapper input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(156, 140, 255, .12);
}

.login-password-input {
    padding-right: 65px !important;
}

.login-password-toggle {
    position: absolute;
    top: 50%;
    right: 13px;
    transform: translateY(-50%);
    border: none;
    background: transparent;
    color: var(--accent);
    font-family: inherit;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    padding: 5px;
}

.login-password-toggle:hover {
    opacity: .75;
}

/* SIGN IN BUTTON */

.login-submit {
    width: 100%;
    min-height: 49px;
    margin-top: 5px;
    border: none;
    border-radius: 11px;
    background: var(--accent);
    color: #171323;
    font-family: inherit;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    transition: .2s ease;
}

.login-submit:hover {
    filter: brightness(1.08);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(156, 140, 255, .15);
}

.login-submit:focus-visible,
.login-password-toggle:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 3px;
}

/* ERROR MESSAGE */

.login-error {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 13px 15px;
    margin-bottom: 22px;
    border: 1px solid rgba(255, 105, 125, .25);
    border-radius: 11px;
    background: rgba(255, 105, 125, .09);
    color: #ff8d9c;
    font-size: 12px;
    line-height: 1.6;
}

.login-error-icon {
    font-weight: 800;
}

/* FOOTER */

.login-form-footer {
    margin-top: 27px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
    color: var(--muted);
    font-size: 11px;
    text-align: center;
    line-height: 1.7;
}

.login-form-footer strong {
    color: var(--text);
}

/* RESPONSIVE */

@media (max-width: 850px) {
    .login-container {
        max-width: 540px;
        grid-template-columns: 1fr;
    }

    .login-brand-panel {
        min-height: auto;
        padding: 35px;
        border-right: none;
        border-bottom: 1px solid var(--border);
    }

    .login-feature-list {
        display: grid;
        grid-template-columns: 1fr 1fr;
        margin-top: 25px;
    }

    .login-brand-footer {
        margin-top: 30px;
    }

    .login-form-panel {
        padding: 40px 35px;
    }
}

@media (max-width: 520px) {
    .login-page {
        padding: 20px 10px;
    }

    .login-brand-panel {
        padding: 28px 24px;
    }

    .login-form-panel {
        padding: 32px 24px;
    }

    .login-feature-list {
        grid-template-columns: 1fr;
    }

    .login-brand-name {
        font-size: 24px;
    }

    .login-welcome h1 {
        font-size: 25px;
    }
}
</style>

<main class="login-page">

    <div class="login-container">

        <!-- LEFT BRANDING SECTION -->

        <section class="login-brand-panel">

            <div class="login-brand-content">

                <div class="login-brand-logo">
                    P
                </div>

                <p class="login-brand-tagline">
                    Employee Management System
                </p>

                <h2 class="login-brand-name">
                    PeopleSuite
                </h2>

                <p class="login-brand-description">
                    A centralized workspace designed to simplify
                    employee management, organize information,
                    and support efficient workplace operations.
                </p>

                <div class="login-feature-list">

                    <div class="login-feature">
                        <span class="login-feature-icon">01</span>
                        <span>Employee Information Management</span>
                    </div>

                    <div class="login-feature">
                        <span class="login-feature-icon">02</span>
                        <span>Payroll and Compensation</span>
                    </div>

                    <div class="login-feature">
                        <span class="login-feature-icon">03</span>
                        <span>Secure Account Access</span>
                    </div>

                </div>

            </div>

            <div class="login-brand-footer">
                PeopleSuite · Employee Management System
                <br>
                Organized. Efficient. Connected.
            </div>

        </section>

        <!-- RIGHT LOGIN SECTION -->

        <section class="login-form-panel">

            <div class="login-welcome">

                <p class="login-eyebrow">
                    WELCOME BACK
                </p>

                <h1>Sign in to your account</h1>

                <p>
                    Enter your account credentials to access
                    your PeopleSuite workspace.
                </p>

            </div>

            <?php if ($errorMessage !== null): ?>

                <div class="login-error" role="alert">

                    <span class="login-error-icon">!</span>

                    <span>
                        <?= htmlspecialchars(
                            $errorMessage,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </span>

                </div>

            <?php endif; ?>

            <form
                method="post"
                action="login.php"
                class="login-form"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        auth_csrf_token(),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <!-- USERNAME -->

                <div class="login-input-group">

                    <label for="username">
                        Username
                    </label>

                    <div class="login-input-wrapper">

                        <input
                            id="username"
                            name="username"
                            type="text"
                            maxlength="50"
                            autocomplete="username"
                            placeholder="Enter your username"
                            value="<?= htmlspecialchars(
                                $username,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            required
                            autofocus
                        >

                    </div>

                </div>

                <!-- PASSWORD -->

                <div class="login-input-group">

                    <label for="password">
                        Password
                    </label>

                    <div class="login-input-wrapper">

                        <input
                            id="password"
                            name="password"
                            type="password"
                            maxlength="72"
                            autocomplete="current-password"
                            placeholder="Enter your password"
                            class="login-password-input"
                            required
                        >

                        <button
                            type="button"
                            class="login-password-toggle"
                            id="togglePassword"
                            aria-label="Show password"
                            aria-pressed="false"
                        >
                            Show
                        </button>

                    </div>

                </div>

                <!-- SUBMIT -->

                <button
                    class="login-submit"
                    type="submit"
                >
                    Sign in to PeopleSuite
                </button>

            </form>

            <div class="login-form-footer">
                <strong>Secure account access</strong>
                <br>
                Use the credentials provided by your administrator.
            </div>

        </section>

    </div>

</main>

<script>
/* ==========================================
   PASSWORD VISIBILITY TOGGLE
========================================== */

const passwordInput = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');

if (passwordInput && togglePassword) {

    togglePassword.addEventListener('click', function () {

        const isHidden = passwordInput.type === 'password';

        passwordInput.type = isHidden ? 'text' : 'password';

        togglePassword.textContent = isHidden ? 'Hide' : 'Show';

        togglePassword.setAttribute(
            'aria-label',
            isHidden ? 'Hide password' : 'Show password'
        );

        togglePassword.setAttribute(
            'aria-pressed',
            isHidden ? 'true' : 'false'
        );

    });

}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
