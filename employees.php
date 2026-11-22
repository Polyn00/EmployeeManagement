<?php
require __DIR__ . '/includes/employee_helpers.php';
require_once __DIR__ . '/includes/audit.php';
auth_require_roles(['Admin', 'HR']);
require __DIR__ . '/config/db.php';

$pageTitle = 'Employees';
$activePage = 'employees';
$notice = employee_take_notice();
$errorMessage = null;
$employees = [];
$totalPages = 1;
$totalEmployees = 0;
$perPage = 10;
$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$search = substr($search, 0, 100);
$status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$statuses = ['Active', 'On Leave', 'Resigned', 'Terminated', 'Retired'];

if (!in_array($status, $statuses, true)) {
    $status = '';
}

$page = isset($_GET['page']) && is_scalar($_GET['page'])
    ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : 1;

$page = $page === false ? 1 : $page;

try {
    $where = [];
    $parameters = [];
    if ($search !== '') {
        $where[] = '(e.employee_code LIKE :code_search OR e.first_name LIKE :first_search OR e.middle_name LIKE :middle_search OR e.last_name LIKE :last_search OR e.email LIKE :email_search)';
        $searchTerm = '%' . $search . '%';
        $parameters[':code_search'] = $searchTerm;
        $parameters[':first_search'] = $searchTerm;
        $parameters[':middle_search'] = $searchTerm;
        $parameters[':last_search'] = $searchTerm;
        $parameters[':email_search'] = $searchTerm;
    }
    if ($status !== '') {
        $where[] = 'e.employment_status = :employment_status';
        $parameters[':employment_status'] = $status;
    }

    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM employees AS e' . $whereSql);
    $count->execute($parameters);
    $totalEmployees = (int) $count->fetchColumn();
    $totalPages = max(1, (int) ceil($totalEmployees / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listing = $pdo->prepare(
        'SELECT e.employee_id, e.employee_code, e.first_name, e.middle_name,
                e.last_name, e.position, e.employment_status, e.hire_date,
                d.department_name
         FROM employees AS e
         LEFT JOIN departments AS d ON d.department_id = e.department_id'
         . $whereSql .
        ' ORDER BY e.employee_id DESC
          LIMIT :limit OFFSET :offset'
    );
    foreach ($parameters as $name => $value) {
        $listing->bindValue($name, $value, PDO::PARAM_STR);
    }
    $listing->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listing->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listing->execute();
    $employees = $listing->fetchAll();
} catch (PDOException $e) {
    error_log('Employee listing failed: ' . $e->getMessage());
    $errorMessage = 'Employee records could not be loaded. Please try again later.';
}

$pageUrl = static function (int $targetPage) use ($search, $status): string {
    return 'employees.php?' . http_build_query([
        'search' => $search,
        'status' => $status,
        'page' => $targetPage,
    ]);
};

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">PEOPLE</p>
        <h1>Employees</h1>
        <p class="page-description">Manage employee records and employment details.</p>
    </div>
    <a class="button button-primary" href="add-employee.php">Add employee</a>
</section>

<?php if ($notice !== null): ?>
    <div class="alert <?= $notice['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" role="status">
        <?= employee_escape($notice['message']) ?>
    </div>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="alert alert-error" role="alert"><?= employee_escape($errorMessage) ?></div>
<?php else: ?>
    <section class="panel employee-panel">
        <form class="employee-filters" method="get" action="employees.php">
            <div class="search-field">
                <label class="visually-hidden" for="employee-search">Search employees</label>
                <input id="employee-search" type="search" name="search" value="<?= employee_escape($search) ?>" placeholder="Search name, code, or email">
            </div>
            <div class="filter-field">
                <label class="visually-hidden" for="status-filter">Employment status</label>
                <select id="status-filter" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $option): ?>
                        <option value="<?= employee_escape($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                            <?= employee_escape($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="button button-secondary" type="submit">Search</button>
            <?php if ($search !== '' || $status !== ''): ?>
                <a class="button button-quiet" href="employees.php">Clear</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table class="employee-table">
                <thead>
                    <tr>
                        <th scope="col">Employee</th>
                        <th scope="col">Department</th>
                        <th scope="col">Position</th>
                        <th scope="col">Status</th>
                        <th scope="col">Hire date</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($employees === []): ?>
                        <tr>
                            <td class="empty-state" colspan="6"><?= $totalEmployees === 0 && $search === '' && $status === '' ? 'No employees have been added yet.' : 'No employees match your search.' ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                            <?php $fullName = trim($employee['first_name'] . ' ' . ($employee['middle_name'] ?? '') . ' ' . $employee['last_name']); ?>
                            <tr>
                                <td>
                                    <span class="person-name"><?= employee_escape($fullName) ?></span>
                                    <span class="secondary-text">#<?= employee_escape($employee['employee_id']) ?> · <?= employee_escape($employee['employee_code']) ?></span>
                                </td>
                                <td><?= employee_escape($employee['department_name'] ?? 'Unassigned') ?></td>
                                <td><?= employee_escape($employee['position'] ?? '—') ?></td>
                                <td>
                                    <span class="status-badge status-<?= employee_escape(strtolower(str_replace(' ', '-', $employee['employment_status']))) ?>">
                                        <?= employee_escape($employee['employment_status']) ?>
                                    </span>
                                </td>
                                <td><?= employee_escape(date('M j, Y', strtotime($employee['hire_date']))) ?></td>
                                <td>
                                    <div class="employee-actions">
                                        <a class="action-link" href="edit-employee.php?id=<?= employee_escape($employee['employee_id']) ?>">Edit</a>
                                        <form method="post" action="<?= employee_escape($pageUrl($page)) ?>" onsubmit="return confirm('Delete this employee? Attendance, leave, payroll, and performance records will also be deleted. Linked user accounts will be unlinked. This cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?= employee_escape(employee_csrf_token()) ?>">
                                            <input type="hidden" name="employee_id" value="<?= employee_escape($employee['employee_id']) ?>">
                                            <button class="action-link action-danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="listing-footer">
            <p><?= number_format($totalEmployees) ?> employee<?= $totalEmployees === 1 ? '' : 's' ?><?= $totalEmployees > 0 ? ' · Page ' . $page . ' of ' . $totalPages : '' ?></p>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Employee pages">
                    <?php if ($page > 1): ?>
                        <a class="button button-quiet" href="<?= employee_escape($pageUrl($page - 1)) ?>">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="button button-quiet" href="<?= employee_escape($pageUrl($page + 1)) ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>