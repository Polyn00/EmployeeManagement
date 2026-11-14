<?php
require __DIR__ . '/includes/department_helpers.php';
require_once __DIR__ . '/includes/audit.php';
auth_require_roles(['Admin', 'HR']);
require __DIR__ . '/config/db.php';

$pageTitle = 'Edit department';
$activePage = 'departments';
$departmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$department = null;
$databaseError = null;
$errors = [];

if ($departmentId === false) {
    http_response_code(400);
    $databaseError = 'A valid department ID is required.';
} else {
    try {
        $lookup = $pdo->prepare('SELECT department_id, department_name, description FROM departments WHERE department_id = :department_id');
        $lookup->execute([':department_id' => $departmentId]);
        $department = $lookup->fetch();
        if ($department === false) {
            $department = null;
            http_response_code(404);
            $databaseError = 'Department not found.';
        }
    } catch (PDOException $e) {
        error_log('Department edit load failed: ' . $e->getMessage());
        $databaseError = 'Department details could not be loaded. Please try again later.';
    }
}

$formKey = $department !== null ? 'edit_department_form_' . $departmentId : '';
$values = [
    'department_name' => $department['department_name'] ?? '',
    'description' => $department['description'] ?? '',
];

if ($department !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = department_form_values($_POST);
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } else {
        $errors = department_validate($values);
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            department_save($pdo, $values, (int) $departmentId);
            audit_record(
                $pdo,
                'department_updated',
                'department',
                (int) $departmentId,
                ['department_name' => $values['department_name']],
                (int) auth_current_user()['user_id']
            );
            $pdo->commit();
            department_set_notice('success', 'Department updated successfully.');
            header('Location: departments.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Department update failed: ' . $e->getMessage());
            $errors[] = (int) ($e->errorInfo[1] ?? 0) === 1062
                ? 'A department with that name already exists.'
                : 'The department could not be updated. Please try again.';
        }
    }

    department_set_form_state($formKey, $values, $errors);
    header('Location: edit-department.php?id=' . (int) $departmentId);
    exit;
}

if ($department !== null) {
    $form = department_take_form_state($formKey, $values);
    $values = $form['values'];
    $errors = $form['errors'];
}

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">WORKSPACE</p>
        <h1><?= $department !== null ? 'Edit department' : 'Department unavailable' ?></h1>
        <p class="page-description"><?= $department !== null ? 'Update department details.' : 'The requested department could not be opened.' ?></p>
    </div>
    <a class="button button-quiet" href="departments.php">Back to departments</a>
</section>

<?php if ($databaseError !== null): ?>
    <div class="alert alert-error" role="alert"><?= department_escape($databaseError) ?></div>
<?php elseif ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <p>Please review the following:</p>
        <ul><?php foreach ($errors as $error): ?><li><?= department_escape($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($department !== null): ?>
    <form class="panel employee-form department-form" method="post" action="edit-department.php?id=<?= (int) $departmentId ?>">
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
            <button class="button button-primary" type="submit">Save changes</button>
        </div>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
