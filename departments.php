<?php
require __DIR__ . '/includes/department_helpers.php';
require_once __DIR__ . '/includes/audit.php';
auth_require_roles(['Admin', 'HR']);
require __DIR__ . '/config/db.php';

$pageTitle = 'Departments';
$activePage = 'departments';
$notice = department_take_notice();
$errorMessage = null;
$departments = [];
$totalDepartments = 0;
$totalPages = 1;
$perPage = 10;
$search = isset($_GET['search']) && is_string($_GET['search']) ? substr(trim($_GET['search']), 0, 100) : '';
$page = isset($_GET['page']) && is_scalar($_GET['page'])
    ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : 1;
$page = $page === false ? 1 : $page;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        department_set_notice('error', 'Your session expired. Please try again.');
        header('Location: departments.php');
        exit;
    }

    if (($_POST['action'] ?? '') !== 'delete') {
        http_response_code(400);
        $errorMessage = 'Invalid department action.';
    } elseif (auth_current_user()['role'] !== 'Admin') {
        http_response_code(403);
        $errorMessage = 'Only Admin accounts can delete departments.';
    } else {
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($departmentId === false) {
            department_set_notice('error', 'A valid department is required.');
        } else {
            try {
                $pdo->beginTransaction();
                $lock = $pdo->prepare(
                    'SELECT department_id, department_name FROM departments WHERE department_id = :department_id FOR UPDATE'
                );
                $lock->execute([':department_id' => $departmentId]);
                $department = $lock->fetch();

                if ($department === false) {
                    $pdo->rollBack();
                    department_set_notice('error', 'Department not found.');
                } else {
                    $employeeCount = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE department_id = :department_id');
                    $employeeCount->execute([':department_id' => $departmentId]);
                    $assignedEmployees = (int) $employeeCount->fetchColumn();

                    if ($assignedEmployees > 0) {
                        $pdo->rollBack();
                        department_set_notice(
                            'error',
                            'This department cannot be deleted because ' . $assignedEmployees
                            . ' employee' . ($assignedEmployees === 1 ? ' is' : 's are')
                            . ' assigned to it. Reassign those employees first.'
                        );
                    } else {
                        $departmentName = (string) $department['department_name'];
                        $delete = $pdo->prepare('DELETE FROM departments WHERE department_id = :department_id');
                        $delete->execute([':department_id' => $departmentId]);
                        audit_record(
                            $pdo,
                            'department_deleted',
                            'department',
                            (int) $departmentId,
                            ['department_name' => $departmentName],
                            (int) auth_current_user()['user_id']
                        );
                        $pdo->commit();
                        department_set_notice('success', 'Department deleted successfully.');
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Department delete failed: ' . $e->getMessage());
                department_set_notice('error', 'The department could not be deleted. Please try again.');
            }
        }

        header('Location: departments.php');
        exit;
    }
}

try {
    $parameters = [];
    $whereSql = '';
    if ($search !== '') {
        $whereSql = ' WHERE d.department_name LIKE :department_name OR d.description LIKE :description';
        $searchTerm = '%' . $search . '%';
        $parameters = [
            ':department_name' => $searchTerm,
            ':description' => $searchTerm,
        ];
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM departments AS d' . $whereSql);
    $count->execute($parameters);
    $totalDepartments = (int) $count->fetchColumn();
    $totalPages = max(1, (int) ceil($totalDepartments / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listing = $pdo->prepare(
        'SELECT d.department_id, d.department_name, d.description,
                COUNT(e.employee_id) AS employee_count
         FROM departments AS d
         LEFT JOIN employees AS e ON e.department_id = d.department_id'
         . $whereSql .
        ' GROUP BY d.department_id, d.department_name, d.description
          ORDER BY d.department_name, d.department_id
          LIMIT :limit OFFSET :offset'
    );
    foreach ($parameters as $name => $value) {
        $listing->bindValue($name, $value, PDO::PARAM_STR);
    }
    $listing->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listing->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listing->execute();
    $departments = $listing->fetchAll();
} catch (PDOException $e) {
    error_log('Department listing failed: ' . $e->getMessage());
    $errorMessage = 'Department records could not be loaded. Please try again later.';
}

$pageUrl = static function (int $targetPage) use ($search): string {
    return 'departments.php?' . http_build_query(['search' => $search, 'page' => $targetPage]);
};

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">WORKSPACE</p>
        <h1>Departments</h1>
        <p class="page-description">Manage departments and view their employee counts.</p>
    </div>
    <a class="button button-primary" href="add-department.php">Add department</a>
</section>

<?php if ($notice !== null): ?>
    <div class="alert <?= $notice['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" role="status">
        <?= department_escape($notice['message']) ?>
    </div>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="alert alert-error" role="alert"><?= department_escape($errorMessage) ?></div>
<?php else: ?>
    <section class="panel employee-panel">
        <form class="employee-filters" method="get" action="departments.php">
            <div class="search-field">
                <label class="visually-hidden" for="department-search">Search departments</label>
                <input id="department-search" type="search" name="search" value="<?= department_escape($search) ?>" placeholder="Search departments or descriptions">
            </div>
            <button class="button button-secondary" type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a class="button button-quiet" href="departments.php">Clear</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table class="employee-table">
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Department</th>
                        <th scope="col">Description</th>
                        <th scope="col">Employees</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($departments === []): ?>
                        <tr>
                            <td class="empty-state" colspan="5"><?= $search === '' ? 'No departments have been added yet.' : 'No departments match your search.' ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($departments as $department): ?>
                            <tr>
                                <td><?= department_escape($department['department_id']) ?></td>
                                <td class="person-name"><?= department_escape($department['department_name']) ?></td>
                                <td class="department-description"><?= nl2br(department_escape($department['description'] ?? '—')) ?></td>
                                <td><?= number_format((int) $department['employee_count']) ?></td>
                                <td>
                                    <div class="employee-actions">
                                        <a class="action-link" href="edit-department.php?id=<?= department_escape($department['department_id']) ?>">Edit</a>
                                        <?php if (auth_current_user()['role'] === 'Admin'): ?>
                                            <form method="post" action="<?= department_escape($pageUrl($page)) ?>" onsubmit="return confirm('Delete this department? This action cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?= department_escape(auth_csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="department_id" value="<?= department_escape($department['department_id']) ?>">
                                                <button class="action-link action-danger" type="submit">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="listing-footer">
            <p><?= number_format($totalDepartments) ?> department<?= $totalDepartments === 1 ? '' : 's' ?><?= $totalDepartments > 0 ? ' · Page ' . $page . ' of ' . $totalPages : '' ?></p>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Department pages">
                    <?php if ($page > 1): ?><a class="button button-quiet" href="<?= department_escape($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a class="button button-quiet" href="<?= department_escape($pageUrl($page + 1)) ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
