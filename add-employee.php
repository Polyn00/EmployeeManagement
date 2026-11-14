<?php
require __DIR__ . '/includes/employee_helpers.php';
require_once __DIR__ . '/includes/audit.php';
auth_require_roles(['Admin', 'HR']);
require __DIR__ . '/config/db.php';

$pageTitle = 'Add employee';
$activePage = 'employees';
$formKey = 'add_employee_form';
$values = employee_form_defaults();
$errors = [];
$databaseError = null;

try {
    $departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')
        ->fetchAll();
} catch (PDOException $e) {
    error_log('Department list failed: ' . $e->getMessage());
    $departments = [];
    $databaseError = 'Departments could not be loaded. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = employee_normalize_form($_POST);
    if (!employee_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } else {
        $departmentIds = array_map('intval', array_column($departments, 'department_id'));
        $errors = employee_validate_form($values, $departmentIds);
    }

    if ($errors === [] && $databaseError === null) {
        try {
            $pdo->beginTransaction();
            employee_save($pdo, $values);
            $employeeId = (int) $pdo->lastInsertId();
            audit_record(
                $pdo,
                'employee_created',
                'employee',
                $employeeId,
                ['changed_fields' => 'employee_record'],
                (int) auth_current_user()['user_id']
            );
            $pdo->commit();
            employee_set_notice('success', 'Employee added successfully.');
            header('Location: employees.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Employee insert failed: ' . $e->getMessage());
            $errors[] = (int) ($e->errorInfo[1] ?? 0) === 1062
                ? 'Employee code or email is already in use.'
                : 'The employee could not be saved. Please try again.';
        }
    }

    employee_set_form_state($formKey, $values, $errors);
    header('Location: add-employee.php');
    exit;
}

$formState = employee_take_form_state($formKey, $values);
$values = $formState['values'];
$errors = $formState['errors'];

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">PEOPLE</p>
        <h1>Add employee</h1>
        <p class="page-description">Enter the employee details to create a record.</p>
    </div>
    <a class="button button-quiet" href="employees.php">Back to employees</a>
</section>

<?php if ($databaseError !== null): ?>
    <div class="alert alert-error" role="alert"><?= employee_escape($databaseError) ?></div>
<?php endif; ?>
<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <p>Please review the following:</p>
        <ul><?php foreach ($errors as $error): ?><li><?= employee_escape($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form class="panel employee-form" method="post" action="add-employee.php">
    <input type="hidden" name="csrf_token" value="<?= employee_escape(employee_csrf_token()) ?>">
    <section class="form-section">
        <h2>Personal information</h2>
        <div class="form-grid">
            <?php employee_form_field('employee_code', 'Employee code', $values, 'text', true, 'maxlength="20" autocomplete="off"'); ?>
            <?php employee_form_field('first_name', 'First name', $values, 'text', true, 'maxlength="100" autocomplete="given-name"'); ?>
            <?php employee_form_field('middle_name', 'Middle name', $values, 'text', false, 'maxlength="100" autocomplete="additional-name"'); ?>
            <?php employee_form_field('last_name', 'Last name', $values, 'text', true, 'maxlength="100" autocomplete="family-name"'); ?>
            <?php employee_form_select('gender', 'Gender', $values, ['Male' => 'Male', 'Female' => 'Female', 'Other' => 'Other'], true); ?>
            <?php employee_form_field('birth_date', 'Birth date', $values, 'date'); ?>
            <?php employee_form_field('email', 'Email', $values, 'email', true, 'maxlength="150" autocomplete="email"'); ?>
            <?php employee_form_field('phone', 'Phone', $values, 'tel', false, 'maxlength="30" autocomplete="tel"'); ?>
            <?php employee_form_field('address', 'Address', $values, 'textarea', false, 'maxlength="5000" autocomplete="street-address"'); ?>
        </div>
    </section>

    <section class="form-section">
        <h2>Employment details</h2>
        <div class="form-grid">
            <div class="form-field">
                <label for="department_id">Department</label>
                <select id="department_id" name="department_id">
                    <option value="">Unassigned</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= employee_escape($department['department_id']) ?>" <?= (string) $values['department_id'] === (string) $department['department_id'] ? 'selected' : '' ?>>
                            <?= employee_escape($department['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php employee_form_field('position', 'Position', $values, 'text', false, 'maxlength="100"'); ?>
            <?php employee_form_field('hire_date', 'Hire date', $values, 'date', true); ?>
            <?php employee_form_select('employment_type', 'Employment type', $values, [
                'Regular' => 'Regular',
                'Probationary' => 'Probationary',
                'Contractual' => 'Contractual',
                'Part-time' => 'Part-time',
            ], true); ?>
            <?php employee_form_select('employment_status', 'Employment status', $values, [
                'Active' => 'Active',
                'On Leave' => 'On Leave',
                'Resigned' => 'Resigned',
                'Terminated' => 'Terminated',
                'Retired' => 'Retired',
            ], true); ?>
            <?php employee_form_field('basic_salary', 'Basic salary', $values, 'number', false, 'min="0" max="9999999999.99" step="0.01" inputmode="decimal"'); ?>
        </div>
    </section>

    <div class="form-actions">
        <a class="button button-quiet" href="employees.php">Cancel</a>
        <button class="button button-primary" type="submit" <?= $databaseError !== null ? 'disabled' : '' ?>>Save employee</button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>