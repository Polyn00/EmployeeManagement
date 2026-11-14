<?php
require __DIR__ . '/includes/department_helpers.php';
require_once __DIR__ . '/includes/audit.php';
auth_require_roles(['Admin', 'HR']);
require __DIR__ . '/config/db.php';

$pageTitle = 'Add department';
$activePage = 'departments';
$formKey = 'add_department_form';
$values = ['department_name' => '', 'description' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = department_form_values($_POST);
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } else {
        $errors = department_validate($values);
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            department_save($pdo, $values);
            $departmentId = (int) $pdo->lastInsertId();
            audit_record(
                $pdo,
                'department_created',
                'department',
                $departmentId,
                ['department_name' => $values['department_name']],
                (int) auth_current_user()['user_id']
            );
            $pdo->commit();
            department_set_notice('success', 'Department added successfully.');
            header('Location: departments.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Department insert failed: ' . $e->getMessage());
            $errors[] = (int) ($e->errorInfo[1] ?? 0) === 1062
                ? 'A department with that name already exists.'
                : 'The department could not be saved. Please try again.';
        }
    }

    department_set_form_state($formKey, $values, $errors);
    header('Location: add-department.php');
    exit;
}

$form = department_take_form_state($formKey, $values);
$values = $form['values'];
$errors = $form['errors'];

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">WORKSPACE</p>
        <h1>Add department</h1>
        <p class="page-description">Create a department for your organization.</p>
    </div>
    <a class="button button-quiet" href="departments.php">Back to departments</a>
</section>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <p>Please review the following:</p>
        <ul><?php foreach ($errors as $error): ?><li><?= department_escape($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form class="panel employee-form department-form" method="post" action="add-department.php">
    <input type="hidden" name="csrf_token" value="<?= department_escape(auth_csrf_token()) ?>">
    <div class="form-grid">
        <div class="form-field">
            <label for="department_name">Department name <span class="required-mark">*</span></label>
            <input id="department_name" name="department_name" type="text" maxlength="100" value="<?= department_escape($values['department_name']) ?>" required autofocus>
        </div>
        <div class="form-field department-description-field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="5" maxlength="65535"><?= department_escape($values['description']) ?></textarea>
        </div>
    </div>
    <div class="form-actions">
        <a class="button button-quiet" href="departments.php">Cancel</a>
        <button class="button button-primary" type="submit">Save department</button>
    </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
