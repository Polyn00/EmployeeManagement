<?php
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
auth_require_roles(['Admin']);
require __DIR__ . '/config/db.php';

$pageTitle = 'User access';
$activePage = 'users';
$notice = $_SESSION['user_access_notice'] ?? null;
unset($_SESSION['user_access_notice']);
$errors = $_SESSION['user_access_errors'] ?? [];
$values = $_SESSION['user_access_values'] ?? ['username' => '', 'role' => 'Employee', 'employee_id' => ''];
unset($_SESSION['user_access_errors'], $_SESSION['user_access_values']);
$employees = [];
$databaseError = null;

try {
    $employees = $pdo->query(
        'SELECT e.employee_id, e.employee_code, e.first_name, e.last_name
         FROM employees AS e
         WHERE NOT EXISTS (
             SELECT 1 FROM users AS u WHERE u.employee_id = e.employee_id
         )
         ORDER BY e.last_name, e.first_name'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('User access employee list failed: ' . $e->getMessage());
    $databaseError = 'Employee accounts could not be loaded. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
    $role = isset($_POST['role']) && is_string($_POST['role']) ? $_POST['role'] : '';
    $employeeId = isset($_POST['employee_id']) && is_string($_POST['employee_id']) ? $_POST['employee_id'] : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $passwordConfirmation = isset($_POST['password_confirmation']) && is_string($_POST['password_confirmation'])
        ? $_POST['password_confirmation']
        : '';
    $formErrors = [];

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $formErrors[] = 'Your session expired. Please submit the form again.';
    }
    if (!preg_match('/\A[a-zA-Z0-9._-]{3,50}\z/D', $username)) {
        $formErrors[] = 'Username must be 3–50 characters using letters, numbers, dots, underscores, or hyphens.';
    }
    if (!in_array($role, ['Admin', 'HR', 'Employee'], true)) {
        $formErrors[] = 'Select a valid role.';
    }
    if (strlen($password) < 12 || strlen($password) > 72) {
        $formErrors[] = 'Password must be between 12 and 72 bytes.';
    }
    if ($password !== $passwordConfirmation) {
        $formErrors[] = 'Password confirmation does not match.';
    }

    $linkedEmployeeId = null;
    if ($employeeId !== '') {
        $parsedEmployeeId = filter_var($employeeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $availableIds = array_map('intval', array_column($employees, 'employee_id'));
        if ($parsedEmployeeId === false || !in_array($parsedEmployeeId, $availableIds, true)) {
            $formErrors[] = 'Select an employee who does not already have a linked account.';
        } else {
            $linkedEmployeeId = $parsedEmployeeId;
        }
    }
    if ($role === 'Employee' && $linkedEmployeeId === null) {
        $formErrors[] = 'Employee accounts must be linked to an employee record.';
    }

    if ($formErrors === [] && $databaseError === null) {
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO users (employee_id, username, password, role, account_status)
                 VALUES (:employee_id, :username, :password, :role, :account_status)'
            );
            $insert->execute([
                ':employee_id' => $linkedEmployeeId,
                ':username' => $username,
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => $role,
                ':account_status' => 'Active',
            ]);
            $newUserId = (int) $pdo->lastInsertId();
            audit_record(
                $pdo,
                'user_created',
                'user',
                $newUserId,
                [
                    'username' => $username,
                    'role' => $role,
                    'employee_id' => $linkedEmployeeId,
                ],
                (int) auth_current_user()['user_id']
            );
            $pdo->commit();
            $_SESSION['user_access_notice'] = 'User account created successfully.';
            header('Location: users.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('User account creation failed: ' . $e->getMessage());
            $formErrors[] = (int) ($e->errorInfo[1] ?? 0) === 1062
                ? 'That username or employee already has an account.'
                : 'The account could not be created. Please try again.';
        }
    }

    $_SESSION['user_access_errors'] = $formErrors;
    $_SESSION['user_access_values'] = [
        'username' => $username,
        'role' => $role,
        'employee_id' => $employeeId,
    ];
    header('Location: users.php');
    exit;
}

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">ADMINISTRATION</p>
        <h1>User access</h1>
        <p class="page-description">Create accounts and assign a PeopleSuite role.</p>
    </div>
</section>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status"><?= htmlspecialchars((string) $notice, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($databaseError !== null): ?>
    <div class="alert alert-error" role="alert"><?= htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <p>Please review the following:</p>
        <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<section class="panel employee-form">
    <form method="post" action="users.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-grid">
            <div class="form-field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" autocomplete="off" value="<?= htmlspecialchars((string) ($values['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-field">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <?php foreach (['Admin', 'HR', 'Employee'] as $role): ?>
                        <option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" <?= ($values['role'] ?? '') === $role ? 'selected' : '' ?>><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="employee_id">Linked employee (required for Employee role)</label>
                <select id="employee_id" name="employee_id">
                    <option value="">No employee link</option>
                    <?php foreach ($employees as $employee): ?>
                        <?php $fullName = $employee['first_name'] . ' ' . $employee['last_name']; ?>
                        <option value="<?= (int) $employee['employee_id'] ?>" <?= (string) ($values['employee_id'] ?? '') === (string) $employee['employee_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($employee['employee_code'] . ' — ' . $fullName, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="password">Password (12–72 bytes)</label>
                <input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
            </div>
            <div class="form-field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
            </div>
        </div>
        <div class="form-actions">
            <button class="button button-primary" type="submit" <?= $databaseError !== null ? 'disabled' : '' ?>>Create account</button>
        </div>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
