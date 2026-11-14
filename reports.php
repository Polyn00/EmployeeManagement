<?php
require __DIR__ . '/includes/auth.php';
auth_require_roles(['Admin', 'HR']);
require __DIR__ . '/config/db.php';

$user = auth_current_user();
$isAdmin = $user['role'] === 'Admin';
$allowedReports = $isAdmin
    ? ['employees', 'attendance', 'leave', 'payroll', 'performance']
    : ['employees', 'attendance', 'leave'];
$report = isset($_GET['report']) && is_string($_GET['report']) ? $_GET['report'] : 'employees';
if (!in_array($report, $allowedReports, true)) {
    http_response_code($isAdmin ? 400 : 403);
    $report = $isAdmin ? 'employees' : 'employees';
    $accessError = $isAdmin ? 'Select a valid report.' : 'Your account does not have permission to access that report.';
} else {
    $accessError = null;
}

$pageTitle = 'Reports';
$activePage = 'reports';
$perPage = 20;
$validDate = static function (string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed !== false
        && $parsed->format('Y-m-d') === $date
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
};
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$money = static function ($value): string {
    return number_format((float) ($value ?? 0), 2, '.', ',');
};
$search = isset($_GET['search']) && is_string($_GET['search']) ? substr(trim($_GET['search']), 0, 100) : '';
$dateFrom = isset($_GET['date_from']) && is_string($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) && is_string($_GET['date_to']) ? trim($_GET['date_to']) : '';
$departmentFilter = isset($_GET['department_id']) && is_string($_GET['department_id']) ? trim($_GET['department_id']) : '';
$employeeFilter = isset($_GET['employee_id']) && is_string($_GET['employee_id']) ? trim($_GET['employee_id']) : '';
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
$employmentTypeFilter = isset($_GET['employment_type']) && is_string($_GET['employment_type']) ? trim($_GET['employment_type']) : '';
$page = isset($_GET['page']) && is_scalar($_GET['page'])
    ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : 1;
$page = $page === false ? 1 : $page;
$errorMessage = $accessError;
$departments = [];
$employees = [];
$rows = [];
$breakdowns = [];
$metrics = [];
$totalRecords = 0;
$totalPages = 1;

$statusOptions = [
    'employees' => ['Active', 'On Leave', 'Resigned', 'Terminated', 'Retired'],
    'attendance' => ['Present', 'Absent', 'Late', 'Half-day', 'On Leave'],
    'leave' => ['Pending', 'Approved', 'Rejected'],
    'payroll' => ['Pending', 'Paid'],
    'performance' => [],
];
$reportDescriptions = [
    'employees' => 'Inactive counts include every employment status other than Active, including On Leave.',
    'attendance' => 'Status totals use the existing attendance values, including Half-day and On Leave.',
    'leave' => 'Date filters include requests overlapping the selected range; Pending, Approved, and Rejected are reported separately.',
    'payroll' => 'Totals reflect stored payroll values and are not statutory payroll calculations.',
    'performance' => 'Reviews are filtered by review date. The existing schema has no review-period or review-status fields.',
];
$employmentTypes = ['Regular', 'Probationary', 'Contractual', 'Part-time'];
$dateColumns = [
    'employees' => ['e.hire_date', 'e.hire_date'],
    'attendance' => ['a.attendance_date', 'a.attendance_date'],
    'leave' => ['lr.end_date', 'lr.start_date'],
    'payroll' => ['p.pay_period_end', 'p.pay_period_start'],
    'performance' => ['pr.review_date', 'pr.review_date'],
];
$reportTables = [
    'employees' => [
        'from' => 'FROM employees AS e LEFT JOIN departments AS d ON d.department_id = e.department_id',
        'select' => 'SELECT e.employee_id, e.employee_code, e.first_name, e.last_name,
                            d.department_name, e.position, e.employment_type,
                            e.employment_status, e.hire_date, e.email',
        'search' => ['e.first_name', 'e.last_name', 'e.employee_code'],
        'columns' => [
            'Employee ID' => 'employee_id', 'Code' => 'employee_code',
            'First name' => 'first_name', 'Last name' => 'last_name',
            'Department' => 'department_name', 'Position' => 'position',
            'Employment type' => 'employment_type', 'Status' => 'employment_status',
            'Hire date' => 'hire_date', 'Email' => 'email',
        ],
    ],
    'attendance' => [
        'from' => 'FROM attendance AS a
                   INNER JOIN employees AS e ON e.employee_id = a.employee_id
                   LEFT JOIN departments AS d ON d.department_id = e.department_id',
        'select' => 'SELECT a.attendance_id, e.employee_id, e.employee_code,
                            e.first_name, e.last_name, d.department_name,
                            a.attendance_date, a.time_in, a.time_out,
                            a.attendance_status, a.remarks',
        'search' => ['e.first_name', 'e.last_name', 'e.employee_code'],
        'columns' => [
            'Attendance ID' => 'attendance_id', 'Employee ID' => 'employee_id',
            'Employee code' => 'employee_code', 'Employee' => 'employee_name',
            'Department' => 'department_name', 'Date' => 'attendance_date',
            'Time in' => 'time_in', 'Time out' => 'time_out',
            'Status' => 'attendance_status', 'Remarks' => 'remarks',
        ],
    ],
    'leave' => [
        'from' => 'FROM leave_requests AS lr
                   INNER JOIN employees AS e ON e.employee_id = lr.employee_id
                   LEFT JOIN departments AS d ON d.department_id = e.department_id
                   LEFT JOIN users AS reviewer ON reviewer.user_id = lr.approved_by',
        'select' => 'SELECT lr.leave_id, e.employee_id, e.employee_code,
                            e.first_name, e.last_name, d.department_name,
                            lr.leave_type, lr.start_date, lr.end_date,
                            lr.leave_status, reviewer.username AS reviewer,
                            lr.approval_date, lr.reason',
        'search' => ['e.first_name', 'e.last_name', 'e.employee_code'],
        'columns' => [
            'Leave ID' => 'leave_id', 'Employee ID' => 'employee_id',
            'Employee code' => 'employee_code', 'Employee' => 'employee_name',
            'Department' => 'department_name', 'Leave type' => 'leave_type',
            'Start date' => 'start_date', 'End date' => 'end_date',
            'Status' => 'leave_status', 'Reviewer' => 'reviewer',
            'Review date' => 'approval_date', 'Reason' => 'reason',
        ],
    ],
    'payroll' => [
        'from' => 'FROM payroll AS p
                   INNER JOIN employees AS e ON e.employee_id = p.employee_id
                   LEFT JOIN departments AS d ON d.department_id = e.department_id',
        'select' => 'SELECT p.payroll_id, e.employee_id, e.employee_code,
                            e.first_name, e.last_name, d.department_name,
                            p.pay_period_start, p.pay_period_end,
                            p.basic_salary, p.allowances, p.overtime_pay,
                            p.deductions, p.gross_salary, p.net_salary,
                            p.payment_status, p.payment_date',
        'search' => ['e.first_name', 'e.last_name', 'e.employee_code'],
        'columns' => [
            'Payroll ID' => 'payroll_id', 'Employee ID' => 'employee_id',
            'Employee code' => 'employee_code', 'Employee' => 'employee_name',
            'Department' => 'department_name', 'Period start' => 'pay_period_start',
            'Period end' => 'pay_period_end', 'Basic salary' => 'basic_salary',
            'Allowances' => 'allowances', 'Overtime pay' => 'overtime_pay',
            'Deductions' => 'deductions', 'Gross pay' => 'gross_salary',
            'Net pay' => 'net_salary', 'Payment status' => 'payment_status',
            'Payment date' => 'payment_date',
        ],
    ],
    'performance' => [
        'from' => 'FROM performance_reviews AS pr
                   INNER JOIN employees AS e ON e.employee_id = pr.employee_id
                   LEFT JOIN departments AS d ON d.department_id = e.department_id
                   LEFT JOIN users AS reviewer ON reviewer.user_id = pr.reviewer_id',
        'select' => 'SELECT pr.review_id, e.employee_id, e.employee_code,
                            e.first_name, e.last_name, d.department_name,
                            pr.review_date, reviewer.username AS reviewer,
                            pr.rating, pr.comments',
        'search' => ['e.first_name', 'e.last_name', 'e.employee_code'],
        'columns' => [
            'Review ID' => 'review_id', 'Employee ID' => 'employee_id',
            'Employee code' => 'employee_code', 'Employee' => 'employee_name',
            'Department' => 'department_name', 'Review date' => 'review_date',
            'Reviewer' => 'reviewer', 'Rating' => 'rating',
            'Comments' => 'comments',
        ],
    ],
];

if ($errorMessage === null) {
    try {
        $departments = $pdo->query(
            'SELECT department_id, department_name FROM departments ORDER BY department_name'
        )->fetchAll();
        $employees = $pdo->query(
            'SELECT employee_id, employee_code, first_name, last_name
             FROM employees ORDER BY last_name, first_name, employee_code'
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Report filter options failed: ' . $e->getMessage());
        $errorMessage = 'Report filters could not be loaded. Please try again later.';
    }
}

if ($errorMessage === null) {
    if (($dateFrom !== '' && !$validDate($dateFrom)) || ($dateTo !== '' && !$validDate($dateTo))) {
        $errorMessage = 'Enter valid report dates.';
    } elseif ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        $errorMessage = 'The start date must be on or before the end date.';
    } elseif ($departmentFilter !== '' && filter_var($departmentFilter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        $errorMessage = 'Select a valid department.';
    } elseif ($employeeFilter !== '' && filter_var($employeeFilter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        $errorMessage = 'Select a valid employee.';
    } elseif ($statusFilter !== '' && !in_array($statusFilter, $statusOptions[$report], true)) {
        $errorMessage = 'Select a status supported by this report.';
    } elseif ($employmentTypeFilter !== '' && ($report !== 'employees' || !in_array($employmentTypeFilter, $employmentTypes, true))) {
        $errorMessage = 'Select an employment type supported by this report.';
    } elseif ($departmentFilter !== '' && !in_array((int) $departmentFilter, array_map('intval', array_column($departments, 'department_id')), true)) {
        $errorMessage = 'Select an existing department.';
    } elseif ($employeeFilter !== '' && !in_array((int) $employeeFilter, array_map('intval', array_column($employees, 'employee_id')), true)) {
        $errorMessage = 'Select an existing employee.';
    }
}

$where = [];
$parameters = [];
if ($errorMessage === null) {
    if ($departmentFilter !== '' && $report !== 'employees') {
        $where[] = 'e.department_id = :department_id';
        $parameters[':department_id'] = (int) $departmentFilter;
    } elseif ($departmentFilter !== '' && $report === 'employees') {
        $where[] = 'e.department_id = :department_id';
        $parameters[':department_id'] = (int) $departmentFilter;
    }
    if ($employeeFilter !== '' && $report !== 'employees') {
        $where[] = 'e.employee_id = :employee_id_filter';
        $parameters[':employee_id_filter'] = (int) $employeeFilter;
    } elseif ($employeeFilter !== '' && $report === 'employees') {
        $where[] = 'e.employee_id = :employee_id_filter';
        $parameters[':employee_id_filter'] = (int) $employeeFilter;
    }
    if ($dateFrom !== '') {
        [$fromColumn] = $dateColumns[$report];
        $where[] = ($report === 'leave' ? 'lr.end_date' : $fromColumn) . ' >= :date_from';
        $parameters[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        [, $toColumn] = $dateColumns[$report];
        $where[] = ($report === 'leave' ? 'lr.start_date' : $toColumn) . ' <= :date_to';
        $parameters[':date_to'] = $dateTo;
    }
    if ($statusFilter !== '') {
        $statusColumn = [
            'employees' => 'e.employment_status',
            'attendance' => 'a.attendance_status',
            'leave' => 'lr.leave_status',
            'payroll' => 'p.payment_status',
        ][$report] ?? null;
        if ($statusColumn !== null) {
            $where[] = $statusColumn . ' = :status_filter';
            $parameters[':status_filter'] = $statusFilter;
        }
    }
    if ($employmentTypeFilter !== '' && $report === 'employees') {
        $where[] = 'e.employment_type = :employment_type';
        $parameters[':employment_type'] = $employmentTypeFilter;
    }
    if ($search !== '' && isset($reportTables[$report]['search'])) {
        $searchParts = [];
        foreach ($reportTables[$report]['search'] as $index => $column) {
            $parameter = ':search_' . $index;
            $searchParts[] = $column . ' LIKE ' . $parameter;
            $parameters[$parameter] = '%' . $search . '%';
        }
        if (ctype_digit($search)) {
            $searchParts[] = 'e.employee_id = :search_employee_id';
            $parameters[':search_employee_id'] = (int) $search;
        }
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
    }
}
$whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
$reportConfig = $reportTables[$report];
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

if ($errorMessage === null) {
    try {
        $countQuery = $pdo->prepare('SELECT COUNT(*) ' . $reportConfig['from'] . $whereSql);
        $countQuery->execute($parameters);
        $totalRecords = (int) $countQuery->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRecords / $perPage));
        $page = min($page, $totalPages);

        $sql = $reportConfig['select'] . ' ' . $reportConfig['from'] . $whereSql;
        if ($report === 'employees') {
            $sql .= ' ORDER BY e.last_name, e.first_name, e.employee_id';
        } elseif ($report === 'attendance') {
            $sql .= ' ORDER BY a.attendance_date DESC, a.attendance_id DESC';
        } elseif ($report === 'leave') {
            $sql .= ' ORDER BY lr.start_date DESC, lr.leave_id DESC';
        } elseif ($report === 'payroll') {
            $sql .= ' ORDER BY p.pay_period_end DESC, p.payroll_id DESC';
        } else {
            $sql .= ' ORDER BY pr.review_date DESC, pr.review_id DESC';
        }
        if (!$export) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $query = $pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $query->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        if (!$export) {
            $query->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $query->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        }
        $query->execute();
        $rows = $query->fetchAll();

        if ($report === 'employees') {
            $activeQuery = $pdo->query(
                "SELECT
                    COUNT(*) AS total,
                    SUM(employment_status = 'Active') AS active_count,
                    SUM(employment_status <> 'Active') AS inactive_count
                 FROM employees"
            );
            $metrics = $activeQuery->fetch() ?: [];
            $breakdowns['Employee count by department'] = $pdo->query(
                'SELECT d.department_name, COUNT(e.employee_id) AS employee_count
                 FROM departments AS d
                 LEFT JOIN employees AS e ON e.department_id = d.department_id
                 GROUP BY d.department_id, d.department_name
                 ORDER BY d.department_name'
            )->fetchAll();
            $breakdowns['Employee count by employment type'] = $pdo->query(
                'SELECT employment_type, COUNT(*) AS employee_count
                 FROM employees GROUP BY employment_type ORDER BY employment_type'
            )->fetchAll();
        } elseif ($report === 'attendance') {
            $metricsQuery = $pdo->prepare(
                'SELECT COUNT(*) AS total_records,
                        COUNT(DISTINCT a.employee_id) AS employees,
                        SUM(a.attendance_status = \'Present\') AS present_count,
                        SUM(a.attendance_status = \'Absent\') AS absent_count,
                        SUM(a.attendance_status = \'Late\') AS late_count,
                        SUM(a.attendance_status = \'Half-day\') AS half_day_count,
                        SUM(a.attendance_status = \'On Leave\') AS on_leave_count
                 ' . $reportConfig['from'] . $whereSql
            );
            $metricsQuery->execute($parameters);
            $metrics = $metricsQuery->fetch() ?: [];
            $employeeSummary = $pdo->prepare(
                'SELECT e.employee_id, e.employee_code,
                        CONCAT(e.first_name, \' \', e.last_name) AS employee_name,
                        COUNT(*) AS record_count,
                        SUM(a.attendance_status = \'Present\') AS present_count,
                        SUM(a.attendance_status = \'Absent\') AS absent_count,
                        SUM(a.attendance_status = \'Late\') AS late_count,
                        SUM(a.attendance_status = \'Half-day\') AS half_day_count,
                        SUM(a.attendance_status = \'On Leave\') AS on_leave_count
                 ' . $reportConfig['from'] . $whereSql . '
                 GROUP BY e.employee_id, e.employee_code, e.first_name, e.last_name
                 ORDER BY employee_name'
            );
            $employeeSummary->execute($parameters);
            $breakdowns['Attendance summary by employee'] = $employeeSummary->fetchAll();
            $departmentSummary = $pdo->prepare(
                'SELECT d.department_name, COUNT(*) AS record_count,
                        SUM(a.attendance_status = \'Present\') AS present_count,
                        SUM(a.attendance_status = \'Absent\') AS absent_count,
                        SUM(a.attendance_status = \'Late\') AS late_count,
                        SUM(a.attendance_status = \'Half-day\') AS half_day_count,
                        SUM(a.attendance_status = \'On Leave\') AS on_leave_count
                 ' . $reportConfig['from'] . $whereSql . '
                 GROUP BY d.department_id, d.department_name
                 ORDER BY d.department_name'
            );
            $departmentSummary->execute($parameters);
            $breakdowns['Attendance summary by department'] = $departmentSummary->fetchAll();
        } elseif ($report === 'leave') {
            $metricsQuery = $pdo->prepare(
                'SELECT COUNT(*) AS total_requests,
                        SUM(lr.leave_status = \'Pending\') AS pending_count,
                        SUM(lr.leave_status = \'Approved\') AS approved_count,
                        SUM(lr.leave_status = \'Rejected\') AS rejected_count
                 ' . $reportConfig['from'] . $whereSql
            );
            $metricsQuery->execute($parameters);
            $metrics = $metricsQuery->fetch() ?: [];
            $employeeSummary = $pdo->prepare(
                'SELECT e.employee_id, e.employee_code,
                        CONCAT(e.first_name, \' \', e.last_name) AS employee_name,
                        COUNT(*) AS request_count,
                        SUM(lr.leave_status = \'Pending\') AS pending_count,
                        SUM(lr.leave_status = \'Approved\') AS approved_count,
                        SUM(lr.leave_status = \'Rejected\') AS rejected_count
                 ' . $reportConfig['from'] . $whereSql . '
                 GROUP BY e.employee_id, e.employee_code, e.first_name, e.last_name
                 ORDER BY employee_name'
            );
            $employeeSummary->execute($parameters);
            $breakdowns['Leave summary by employee'] = $employeeSummary->fetchAll();
            $departmentSummary = $pdo->prepare(
                'SELECT d.department_name, COUNT(*) AS request_count,
                        SUM(lr.leave_status = \'Pending\') AS pending_count,
                        SUM(lr.leave_status = \'Approved\') AS approved_count,
                        SUM(lr.leave_status = \'Rejected\') AS rejected_count
                 ' . $reportConfig['from'] . $whereSql . '
                 GROUP BY d.department_id, d.department_name
                 ORDER BY d.department_name'
            );
            $departmentSummary->execute($parameters);
            $breakdowns['Leave summary by department'] = $departmentSummary->fetchAll();
        } elseif ($report === 'payroll') {
            $metricsQuery = $pdo->prepare(
                'SELECT COUNT(*) AS total_records,
                        COALESCE(SUM(p.gross_salary), 0) AS gross_total,
                        COALESCE(SUM(p.net_salary), 0) AS net_total,
                        SUM(p.payment_status = \'Pending\') AS pending_count,
                        SUM(p.payment_status = \'Paid\') AS paid_count
                 ' . $reportConfig['from'] . $whereSql
            );
            $metricsQuery->execute($parameters);
            $metrics = $metricsQuery->fetch() ?: [];
            $periodSummary = $pdo->prepare(
                'SELECT p.pay_period_start, p.pay_period_end,
                        COUNT(*) AS payroll_count,
                        COALESCE(SUM(p.gross_salary), 0) AS gross_total,
                        COALESCE(SUM(p.net_salary), 0) AS net_total,
                        SUM(p.payment_status = \'Pending\') AS pending_count,
                        SUM(p.payment_status = \'Paid\') AS paid_count
                 ' . $reportConfig['from'] . $whereSql . '
                 GROUP BY p.pay_period_start, p.pay_period_end
                 ORDER BY p.pay_period_end DESC'
            );
            $periodSummary->execute($parameters);
            $breakdowns['Payroll summary by pay period'] = $periodSummary->fetchAll();
        } else {
            $metricsQuery = $pdo->prepare(
                'SELECT COUNT(*) AS review_count, COALESCE(AVG(pr.rating), 0) AS average_rating
                 ' . $reportConfig['from'] . $whereSql
            );
            $metricsQuery->execute($parameters);
            $metrics = $metricsQuery->fetch() ?: [];
            $ratingSummary = $pdo->prepare(
                'SELECT pr.rating, COUNT(*) AS review_count
                 ' . $reportConfig['from'] . $whereSql . '
                 GROUP BY pr.rating ORDER BY pr.rating'
            );
            $ratingSummary->execute($parameters);
            $breakdowns['Rating distribution'] = $ratingSummary->fetchAll();
            $employeeSummary = $pdo->prepare(
                'SELECT e.employee_id, e.employee_code,
                        CONCAT(e.first_name, \' \', e.last_name) AS employee_name,
                        COUNT(*) AS review_count, AVG(pr.rating) AS average_rating
                 ' . $reportConfig['from'] . $whereSql . '
                 GROUP BY e.employee_id, e.employee_code, e.first_name, e.last_name
                 ORDER BY employee_name'
            );
            $employeeSummary->execute($parameters);
            $breakdowns['Performance summary by employee'] = $employeeSummary->fetchAll();
            $departmentSummary = $pdo->prepare(
                'SELECT d.department_name, COUNT(*) AS review_count, AVG(pr.rating) AS average_rating
                 ' . $reportConfig['from'] . $whereSql . '
                 GROUP BY d.department_id, d.department_name
                 ORDER BY d.department_name'
            );
            $departmentSummary->execute($parameters);
            $breakdowns['Performance summary by department'] = $departmentSummary->fetchAll();
        }
    } catch (PDOException $e) {
        error_log('Report query failed: ' . $e->getMessage());
        $errorMessage = 'The requested report could not be loaded. Please try again later.';
    }
}

if ($export && $errorMessage === null) {
    $csvSafe = static function ($value): string {
        $value = (string) ($value ?? '');
        if (preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $value) === 1) {
            return "'" . $value;
        }
        return $value;
    };
    $filename = 'peoplesuite-' . $report . '-report-' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit;
    }
    fputcsv($output, array_keys($reportConfig['columns']));
    foreach ($rows as $row) {
        $csvRow = [];
        foreach ($reportConfig['columns'] as $key => $field) {
            if ($field === 'employee_name') {
                $value = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            } else {
                $value = $row[$field] ?? '';
            }
            $csvRow[] = $csvSafe($value);
        }
        fputcsv($output, $csvRow);
    }
    fclose($output);
    exit;
}

$filterParams = [
    'report' => $report,
    'search' => $search,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'department_id' => $departmentFilter,
    'employee_id' => $employeeFilter,
    'status' => $statusFilter,
    'employment_type' => $employmentTypeFilter,
];
$filteredParams = array_filter($filterParams, static function ($value, $key): bool {
    return $key === 'report' || $value !== '';
}, ARRAY_FILTER_USE_BOTH);
$pageUrl = static function (int $targetPage) use ($filteredParams): string {
    return 'reports.php?' . http_build_query($filteredParams + ['page' => $targetPage]);
};
$exportUrl = 'reports.php?' . http_build_query($filteredParams + ['export' => 'csv']);

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">INSIGHTS</p>
        <h1>Reports</h1>
        <p class="page-description"><?= $escape($reportDescriptions[$report]) ?></p>
    </div>
    <?php if ($errorMessage === null): ?><a class="button button-secondary" href="<?= $escape($exportUrl) ?>">Export CSV</a><?php endif; ?>
</section>

<?php if ($errorMessage !== null): ?>
    <div class="alert alert-error" role="alert"><?= $escape($errorMessage) ?></div>
<?php endif; ?>

<section class="panel employee-panel reports-filter-panel">
    <form class="employee-filters reports-filters" method="get" action="reports.php">
        <div class="filter-field">
            <label for="report">Report</label>
            <select id="report" name="report">
                <?php foreach ($allowedReports as $allowedReport): ?>
                    <option value="<?= $escape($allowedReport) ?>" <?= $report === $allowedReport ? 'selected' : '' ?>><?= $escape(ucfirst($allowedReport)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="search-field"><label class="visually-hidden" for="report-search">Search employees</label><input id="report-search" name="search" type="search" value="<?= $escape($search) ?>" placeholder="Search name, employee ID, or code"></div>
        <div class="filter-field"><label class="visually-hidden" for="department-filter">Department</label><select id="department-filter" name="department_id"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['department_id'] ?>" <?= $departmentFilter === (string) $department['department_id'] ? 'selected' : '' ?>><?= $escape($department['department_name']) ?></option><?php endforeach; ?></select></div>
        <div class="filter-field"><label class="visually-hidden" for="employee-filter">Employee</label><select id="employee-filter" name="employee_id"><option value="">All employees</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['employee_id'] ?>" <?= $employeeFilter === (string) $employee['employee_id'] ? 'selected' : '' ?>><?= $escape($employee['employee_code'] . ' · ' . $employee['first_name'] . ' ' . $employee['last_name']) ?></option><?php endforeach; ?></select></div>
        <div class="filter-field"><label class="visually-hidden" for="date-from">Date from</label><input id="date-from" name="date_from" type="date" value="<?= $escape($dateFrom) ?>"></div>
        <div class="filter-field"><label class="visually-hidden" for="date-to">Date to</label><input id="date-to" name="date_to" type="date" value="<?= $escape($dateTo) ?>"></div>
        <?php if ($statusOptions[$report] !== []): ?><div class="filter-field"><label class="visually-hidden" for="status-filter">Status</label><select id="status-filter" name="status"><option value="">All statuses</option><?php foreach ($statusOptions[$report] as $statusOption): ?><option value="<?= $escape($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= $escape($statusOption) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <?php if ($report === 'employees'): ?><div class="filter-field"><label class="visually-hidden" for="employment-type-filter">Employment type</label><select id="employment-type-filter" name="employment_type"><option value="">All employment types</option><?php foreach ($employmentTypes as $type): ?><option value="<?= $escape($type) ?>" <?= $employmentTypeFilter === $type ? 'selected' : '' ?>><?= $escape($type) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <button class="button button-secondary" type="submit">Apply filters</button>
        <a class="button button-quiet" href="reports.php?report=<?= $escape($report) ?>">Clear</a>
    </form>
</section>

<?php if ($errorMessage === null): ?>
    <section class="metric-grid reports-metric-grid" aria-label="<?= $escape(ucfirst($report)) ?> report summary">
        <?php if ($report === 'employees'): ?>
            <article class="metric-card"><span class="metric-label">Total employees</span><p class="metric-value"><?= number_format((int) ($metrics['total'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Active employees</span><p class="metric-value"><?= number_format((int) ($metrics['active_count'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Inactive employees</span><p class="metric-value"><?= number_format((int) ($metrics['inactive_count'] ?? 0)) ?></p><p class="metric-note">All non-Active statuses, including On Leave</p></article>
        <?php elseif ($report === 'attendance'): ?>
            <article class="metric-card"><span class="metric-label">Attendance records</span><p class="metric-value"><?= number_format((int) ($metrics['total_records'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Employees represented</span><p class="metric-value"><?= number_format((int) ($metrics['employees'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Present</span><p class="metric-value"><?= number_format((int) ($metrics['present_count'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Absent</span><p class="metric-value"><?= number_format((int) ($metrics['absent_count'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Late</span><p class="metric-value"><?= number_format((int) ($metrics['late_count'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Half-day</span><p class="metric-value"><?= number_format((int) ($metrics['half_day_count'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">On leave</span><p class="metric-value"><?= number_format((int) ($metrics['on_leave_count'] ?? 0)) ?></p></article>
        <?php elseif ($report === 'leave'): ?>
            <article class="metric-card"><span class="metric-label">Leave requests</span><p class="metric-value"><?= number_format((int) ($metrics['total_requests'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Pending</span><p class="metric-value"><?= number_format((int) ($metrics['pending_count'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Approved</span><p class="metric-value"><?= number_format((int) ($metrics['approved_count'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Rejected</span><p class="metric-value"><?= number_format((int) ($metrics['rejected_count'] ?? 0)) ?></p></article>
        <?php elseif ($report === 'payroll'): ?>
            <article class="metric-card"><span class="metric-label">Payroll records</span><p class="metric-value"><?= number_format((int) ($metrics['total_records'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Gross pay total</span><p class="metric-value"><?= $escape($money($metrics['gross_total'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Net pay total</span><p class="metric-value"><?= $escape($money($metrics['net_total'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Pending / Paid</span><p class="metric-value"><?= number_format((int) ($metrics['pending_count'] ?? 0)) ?> / <?= number_format((int) ($metrics['paid_count'] ?? 0)) ?></p></article>
        <?php else: ?>
            <article class="metric-card"><span class="metric-label">Reviews</span><p class="metric-value"><?= number_format((int) ($metrics['review_count'] ?? 0)) ?></p></article>
            <article class="metric-card"><span class="metric-label">Average rating</span><p class="metric-value"><?= number_format((float) ($metrics['average_rating'] ?? 0), 1) ?> / 5</p></article>
        <?php endif; ?>
    </section>

    <?php if ($breakdowns !== []): ?>
        <section class="reports-breakdown-grid">
            <?php foreach ($breakdowns as $title => $breakdownRows): ?>
                <article class="panel report-breakdown">
                    <div class="panel-heading"><div><h2><?= $escape($title) ?></h2></div></div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><?php foreach (array_keys($breakdownRows[0] ?? []) as $heading): ?><th scope="col"><?= $escape(ucwords(str_replace('_', ' ', $heading))) ?></th><?php endforeach; ?></tr></thead>
                            <tbody>
                                <?php if ($breakdownRows === []): ?><tr><td class="empty-state" colspan="6">No data for these filters.</td></tr><?php else: ?>
                                    <?php foreach ($breakdownRows as $breakdownRow): ?><tr><?php foreach ($breakdownRow as $key => $value): ?><td><?= $escape(strpos($key, 'average_rating') !== false ? number_format((float) $value, 1) : (strpos($key, 'total') !== false ? $money($value) : $value)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="panel employee-panel reports-list-panel">
        <div class="panel-heading">
            <div>
                <h2><?= $escape(ucfirst($report)) ?> records</h2>
                <p><?= number_format($totalRecords) ?> matching record<?= $totalRecords === 1 ? '' : 's' ?></p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="employee-table">
                <thead><tr><?php foreach (array_keys($reportConfig['columns']) as $heading): ?><th scope="col"><?= $escape($heading) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td class="empty-state" colspan="<?= count($reportConfig['columns']) ?>">No records match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ($reportConfig['columns'] as $key => $field): ?>
                                    <?php
                                    if ($field === 'employee_name') {
                                        $value = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                                    } else {
                                        $value = $row[$field] ?? '—';
                                    }
                                    if (in_array($field, ['basic_salary', 'allowances', 'overtime_pay', 'deductions', 'gross_salary', 'net_salary'], true)) {
                                        $value = $money($value);
                                    } elseif ($field === 'rating') {
                                        $value = number_format((float) $value, 1) . ' / 5';
                                    } elseif (in_array($field, ['hire_date', 'attendance_date', 'start_date', 'end_date', 'pay_period_start', 'pay_period_end', 'review_date'], true) && $value !== '—') {
                                        $value = date('M j, Y', strtotime((string) $value));
                                    } elseif ($field === 'approval_date' && $value !== '—') {
                                        $value = date('M j, Y H:i', strtotime((string) $value));
                                    }
                                    ?>
                                    <td><?= $escape($value) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="listing-footer">
            <p><?= number_format($totalRecords) ?> record<?= $totalRecords === 1 ? '' : 's' ?><?= $totalRecords > 0 ? ' · Page ' . $page . ' of ' . $totalPages : '' ?></p>
            <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Report pages"><?php if ($page > 1): ?><a class="button button-quiet" href="<?= $escape($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?><?php if ($page < $totalPages): ?><a class="button button-quiet" href="<?= $escape($pageUrl($page + 1)) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
