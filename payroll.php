<?php
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
auth_require_roles(['Admin', 'Employee']);
require __DIR__ . '/config/db.php';

$user = auth_current_user();
$isEmployee = $user['role'] === 'Employee';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_require_roles(['Admin']);
}
$employeeId = $isEmployee
    ? filter_var($user['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;
$pageTitle = $isEmployee ? 'My payroll' : 'Payroll';
$activePage = 'payroll';
$perPage = 20;
$notice = $_SESSION['payroll_notice'] ?? null;
unset($_SESSION['payroll_notice']);
$formValues = $_SESSION['payroll_form_values'] ?? [
    'employee_id' => '',
    'pay_period_start' => '',
    'pay_period_end' => '',
    'basic_salary' => '',
    'allowances' => '0.00',
    'overtime_pay' => '0.00',
    'deductions' => '0.00',
];
unset($_SESSION['payroll_form_values']);
$employees = [];
$records = [];
$totalRecords = 0;
$totalPages = 1;
$errorMessage = null;
$page = isset($_GET['page']) && is_scalar($_GET['page'])
    ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : 1;
$page = $page === false ? 1 : $page;

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
$toCents = static function (string $amount): ?int {
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount)) {
        return null;
    }
    $parts = explode('.', $amount, 2);
    $whole = (int) $parts[0];
    $fraction = isset($parts[1]) ? (int) str_pad($parts[1], 2, '0') : 0;
    $cents = $whole * 100 + $fraction;
    return $cents <= 999999999999 ? $cents : null;
};
$formatMoney = static function (int $cents): string {
    return number_format(intdiv($cents, 100), 0, '.', ',') . '.' . str_pad((string) ($cents % 100), 2, '0');
};
$formatDecimal = static function (int $cents): string {
    return (string) intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0');
};
$displayMoney = static function ($amount) use ($toCents, $formatMoney): string {
    $cents = $toCents((string) ($amount ?? '0.00'));
    return $cents === null ? '0.00' : $formatMoney($cents);
};
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

if ($isEmployee && $employeeId === false) {
    http_response_code(403);
    $errorMessage = 'This account is not linked to an employee record. Contact HR for assistance.';
}

if (!$isEmployee && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $noticeType = 'error';
    $message = 'Payroll could not be updated. Please try again.';

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $message = 'Your session expired. Please reload the page and try again.';
    } elseif ($action === 'generate') {
        $formValues = [
            'employee_id' => isset($_POST['employee_id']) && is_string($_POST['employee_id']) ? trim($_POST['employee_id']) : '',
            'pay_period_start' => isset($_POST['pay_period_start']) && is_string($_POST['pay_period_start']) ? trim($_POST['pay_period_start']) : '',
            'pay_period_end' => isset($_POST['pay_period_end']) && is_string($_POST['pay_period_end']) ? trim($_POST['pay_period_end']) : '',
            'basic_salary' => isset($_POST['basic_salary']) && is_string($_POST['basic_salary']) ? trim($_POST['basic_salary']) : '',
            'allowances' => isset($_POST['allowances']) && is_string($_POST['allowances']) ? trim($_POST['allowances']) : '',
            'overtime_pay' => isset($_POST['overtime_pay']) && is_string($_POST['overtime_pay']) ? trim($_POST['overtime_pay']) : '',
            'deductions' => isset($_POST['deductions']) && is_string($_POST['deductions']) ? trim($_POST['deductions']) : '',
        ];
        $selectedEmployee = filter_var($formValues['employee_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $basicCents = $toCents($formValues['basic_salary']);
        $allowanceCents = $toCents($formValues['allowances']);
        $overtimeCents = $toCents($formValues['overtime_pay']);
        $deductionCents = $toCents($formValues['deductions']);

        if ($selectedEmployee === false
            || !$validDate($formValues['pay_period_start'])
            || !$validDate($formValues['pay_period_end'])
        ) {
            $message = 'Select an employee and enter valid pay period dates.';
        } elseif ($formValues['pay_period_end'] < $formValues['pay_period_start']) {
            $message = 'The pay period end date must be on or after the start date.';
        } elseif ($basicCents === null || $allowanceCents === null || $overtimeCents === null || $deductionCents === null) {
            $message = 'Enter nonnegative amounts with no more than 10 whole digits and 2 decimal places.';
        } else {
            $grossCents = $basicCents + $allowanceCents + $overtimeCents;
            if ($grossCents > 999999999999 || $deductionCents > $grossCents) {
                $message = $grossCents > 999999999999
                    ? 'Gross salary exceeds the maximum supported by the payroll table.'
                    : 'Deductions cannot exceed gross salary.';
            } else {
                $netCents = $grossCents - $deductionCents;
                try {
                    $pdo->beginTransaction();
                    $employeeLock = $pdo->prepare(
                        'SELECT employee_id FROM employees WHERE employee_id = :employee_id FOR UPDATE'
                    );
                    $employeeLock->execute([':employee_id' => $selectedEmployee]);
                    if ($employeeLock->fetchColumn() === false) {
                        $pdo->rollBack();
                        $message = 'The selected employee could not be found.';
                    } else {
                        $duplicate = $pdo->prepare(
                            'SELECT payroll_id
                             FROM payroll
                             WHERE employee_id = :employee_id
                               AND pay_period_start = :pay_period_start
                               AND pay_period_end = :pay_period_end
                             LIMIT 1'
                        );
                        $duplicate->execute([
                            ':employee_id' => $selectedEmployee,
                            ':pay_period_start' => $formValues['pay_period_start'],
                            ':pay_period_end' => $formValues['pay_period_end'],
                        ]);
                        if ($duplicate->fetchColumn() !== false) {
                            $pdo->rollBack();
                            $message = 'Payroll already exists for this employee and pay period; existing payroll was not changed.';
                        } else {
                            $insert = $pdo->prepare(
                                "INSERT INTO payroll
                                    (employee_id, pay_period_start, pay_period_end,
                                     basic_salary, allowances, overtime_pay, deductions,
                                     gross_salary, net_salary, payment_status)
                                 VALUES
                                    (:employee_id, :period_start, :period_end,
                                     :basic_salary, :allowances, :overtime_pay, :deductions,
                                     :gross_salary, :net_salary, 'Pending')"
                            );
                            $insert->execute([
                                ':employee_id' => $selectedEmployee,
                                ':period_start' => $formValues['pay_period_start'],
                                ':period_end' => $formValues['pay_period_end'],
                                ':basic_salary' => $formatDecimal($basicCents),
                                ':allowances' => $formatDecimal($allowanceCents),
                                ':overtime_pay' => $formatDecimal($overtimeCents),
                                ':deductions' => $formatDecimal($deductionCents),
                                ':gross_salary' => $formatDecimal($grossCents),
                                ':net_salary' => $formatDecimal($netCents),
                            ]);
                            $payrollId = (int) $pdo->lastInsertId();
                            audit_record(
                                $pdo,
                                'payroll_generated',
                                'payroll',
                                $payrollId,
                                [
                                    'employee_id' => (int) $selectedEmployee,
                                    'pay_period_start' => $formValues['pay_period_start'],
                                    'pay_period_end' => $formValues['pay_period_end'],
                                ],
                                (int) $user['user_id']
                            );
                            $pdo->commit();
                            $_SESSION['payroll_notice'] = ['type' => 'success', 'message' => 'Payroll generated successfully.'];
                            header('Location: payroll-details.php?id=' . $payrollId);
                            exit;
                        }
                    }
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Payroll generation failed: ' . $e->getMessage());
                    $message = 'Payroll could not be generated. Please try again.';
                }
            }
        }
        $_SESSION['payroll_form_values'] = $formValues;
    } elseif ($action === 'update_pending') {
        $payrollId = filter_var($_POST['payroll_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $editValues = [
            'pay_period_start' => isset($_POST['pay_period_start']) && is_string($_POST['pay_period_start']) ? trim($_POST['pay_period_start']) : '',
            'pay_period_end' => isset($_POST['pay_period_end']) && is_string($_POST['pay_period_end']) ? trim($_POST['pay_period_end']) : '',
            'basic_salary' => isset($_POST['basic_salary']) && is_string($_POST['basic_salary']) ? trim($_POST['basic_salary']) : '',
            'allowances' => isset($_POST['allowances']) && is_string($_POST['allowances']) ? trim($_POST['allowances']) : '',
            'overtime_pay' => isset($_POST['overtime_pay']) && is_string($_POST['overtime_pay']) ? trim($_POST['overtime_pay']) : '',
            'deductions' => isset($_POST['deductions']) && is_string($_POST['deductions']) ? trim($_POST['deductions']) : '',
        ];
        $basicCents = $toCents($editValues['basic_salary']);
        $allowanceCents = $toCents($editValues['allowances']);
        $overtimeCents = $toCents($editValues['overtime_pay']);
        $deductionCents = $toCents($editValues['deductions']);
        $grossCents = null;

        if ($payrollId === false) {
            $message = 'Select a valid payroll record.';
        } elseif (!$validDate($editValues['pay_period_start']) || !$validDate($editValues['pay_period_end'])) {
            $message = 'Enter valid pay period dates.';
        } elseif ($editValues['pay_period_end'] < $editValues['pay_period_start']) {
            $message = 'The pay period end date must be on or after the start date.';
        } elseif ($basicCents === null || $allowanceCents === null || $overtimeCents === null || $deductionCents === null) {
            $message = 'Enter nonnegative amounts with no more than 10 whole digits and 2 decimal places.';
        } else {
            $grossCents = $basicCents + $allowanceCents + $overtimeCents;
            if ($grossCents > 999999999999 || $deductionCents > $grossCents) {
                $message = $grossCents > 999999999999
                    ? 'Gross salary exceeds the maximum supported by the payroll table.'
                    : 'Deductions cannot exceed gross salary.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $ownerQuery = $pdo->prepare(
                        'SELECT employee_id FROM payroll WHERE payroll_id = :payroll_id'
                    );
                    $ownerQuery->execute([':payroll_id' => $payrollId]);
                    $recordEmployeeId = $ownerQuery->fetchColumn();

                    if ($recordEmployeeId === false) {
                        $pdo->rollBack();
                        $message = 'The payroll record was not found.';
                    } else {
                        $employeeLock = $pdo->prepare(
                            'SELECT employee_id FROM employees WHERE employee_id = :employee_id FOR UPDATE'
                        );
                        $employeeLock->execute([':employee_id' => (int) $recordEmployeeId]);
                        $employeeLock->fetchColumn();

                        $lockRecord = $pdo->prepare(
                            'SELECT payment_status FROM payroll WHERE payroll_id = :payroll_id FOR UPDATE'
                        );
                        $lockRecord->execute([':payroll_id' => $payrollId]);
                        $paymentStatus = $lockRecord->fetchColumn();

                        if ($paymentStatus === false) {
                            $pdo->rollBack();
                            $message = 'The payroll record was not found.';
                        } elseif ($paymentStatus !== 'Pending') {
                            $pdo->rollBack();
                            $message = 'Only pending payroll can be edited.';
                        } else {
                            $netCents = $grossCents - $deductionCents;
                            $duplicate = $pdo->prepare(
                                'SELECT payroll_id
                                 FROM payroll
                                 WHERE employee_id = :employee_id
                                   AND pay_period_start = :pay_period_start
                                   AND pay_period_end = :pay_period_end
                                   AND payroll_id <> :payroll_id
                                 LIMIT 1'
                            );
                            $duplicate->execute([
                                ':employee_id' => (int) $recordEmployeeId,
                                ':pay_period_start' => $editValues['pay_period_start'],
                                ':pay_period_end' => $editValues['pay_period_end'],
                                ':payroll_id' => $payrollId,
                            ]);
                            if ($duplicate->fetchColumn() !== false) {
                                $pdo->rollBack();
                                $message = 'Payroll already exists for this employee and pay period; the existing record was not changed.';
                            } else {
                                $update = $pdo->prepare(
                                    'UPDATE payroll
                                     SET pay_period_start = :period_start,
                                         pay_period_end = :period_end,
                                         basic_salary = :basic_salary,
                                         allowances = :allowances,
                                         overtime_pay = :overtime_pay,
                                         deductions = :deductions,
                                         gross_salary = :gross_salary,
                                         net_salary = :net_salary
                                     WHERE payroll_id = :payroll_id
                                       AND payment_status = :payment_status'
                                );
                                $update->execute([
                                    ':period_start' => $editValues['pay_period_start'],
                                    ':period_end' => $editValues['pay_period_end'],
                                    ':basic_salary' => $formatDecimal($basicCents),
                                    ':allowances' => $formatDecimal($allowanceCents),
                                    ':overtime_pay' => $formatDecimal($overtimeCents),
                                    ':deductions' => $formatDecimal($deductionCents),
                                    ':gross_salary' => $formatDecimal($grossCents),
                                    ':net_salary' => $formatDecimal($netCents),
                                    ':payroll_id' => $payrollId,
                                    ':payment_status' => 'Pending',
                                ]);
                                audit_record(
                                    $pdo,
                                    'payroll_updated',
                                    'payroll',
                                    (int) $payrollId,
                                    [
                                        'employee_id' => (int) $recordEmployeeId,
                                        'pay_period_start' => $editValues['pay_period_start'],
                                        'pay_period_end' => $editValues['pay_period_end'],
                                        'amounts_omitted' => true,
                                    ],
                                    (int) $user['user_id']
                                );
                                $pdo->commit();
                                $_SESSION['payroll_notice'] = ['type' => 'success', 'message' => 'Pending payroll updated successfully.'];
                                header('Location: payroll-details.php?id=' . (int) $payrollId);
                                exit;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Pending payroll update failed: ' . $e->getMessage());
                    $message = 'Payroll could not be updated. Please try again.';
                }
            }
        }

        if ($payrollId !== false) {
            $_SESSION['payroll_edit_form_values'][(int) $payrollId] = $editValues;
            $_SESSION['payroll_notice_redirect'] = (int) $payrollId;
        }
    } elseif ($action === 'set_status') {
        $payrollId = filter_var($_POST['payroll_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $requestedStatus = isset($_POST['payment_status']) && is_string($_POST['payment_status'])
            ? $_POST['payment_status']
            : '';
        if ($payrollId === false || !in_array($requestedStatus, ['Pending', 'Paid'], true)) {
            $message = 'Select a valid payroll record.';
        } else {
            try {
                $pdo->beginTransaction();
                $lockRecord = $pdo->prepare(
                    'SELECT payment_status FROM payroll WHERE payroll_id = :payroll_id FOR UPDATE'
                );
                $lockRecord->execute([':payroll_id' => $payrollId]);
                $paymentStatus = $lockRecord->fetchColumn();
                if ($paymentStatus === false) {
                    $pdo->rollBack();
                    $message = 'The payroll record was not found.';
                } elseif ($paymentStatus === $requestedStatus) {
                    $pdo->rollBack();
                    $message = 'The payroll record already has that payment status.';
                } else {
                    $update = $requestedStatus === 'Paid'
                        ? $pdo->prepare(
                            "UPDATE payroll
                             SET payment_status = 'Paid', payment_date = CURRENT_DATE()
                             WHERE payroll_id = :payroll_id AND payment_status = 'Pending'"
                        )
                        : $pdo->prepare(
                            "UPDATE payroll
                             SET payment_status = 'Pending', payment_date = NULL
                             WHERE payroll_id = :payroll_id AND payment_status = 'Paid'"
                        );
                    $update->execute([':payroll_id' => $payrollId]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('The payroll record changed during payment processing.');
                    }
                    audit_record(
                        $pdo,
                        'payroll_status_changed',
                        'payroll',
                        (int) $payrollId,
                        [
                            'previous_status' => (string) $paymentStatus,
                            'new_status' => $requestedStatus,
                        ],
                        (int) $user['user_id']
                    );
                    $pdo->commit();
                    $noticeType = 'success';
                    $message = $requestedStatus === 'Paid'
                        ? 'Payroll marked as paid.'
                        : 'Payroll marked as unpaid.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Payroll payment processing failed: ' . $e->getMessage());
                $message = 'Payment status could not be updated. Please try again.';
            }
        }
    } else {
        http_response_code(400);
        $message = 'Invalid payroll action.';
    }

    $_SESSION['payroll_notice'] = ['type' => $noticeType, 'message' => $message];
    $redirectPayrollId = $_SESSION['payroll_notice_redirect'] ?? null;
    unset($_SESSION['payroll_notice_redirect']);
    header('Location: ' . ($redirectPayrollId !== null
        ? 'payroll-details.php?id=' . (int) $redirectPayrollId
        : 'payroll.php'));
    exit;
}

$search = isset($_GET['search']) && is_string($_GET['search']) ? substr(trim($_GET['search']), 0, 100) : '';
$periodFrom = isset($_GET['period_from']) && is_string($_GET['period_from']) ? trim($_GET['period_from']) : '';
$periodTo = isset($_GET['period_to']) && is_string($_GET['period_to']) ? trim($_GET['period_to']) : '';
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
if (!in_array($statusFilter, ['Pending', 'Paid'], true)) {
    $statusFilter = '';
}
foreach ([$periodFrom, $periodTo] as $filterDate) {
    if ($filterDate !== '' && !$validDate($filterDate)) {
        $errorMessage = 'Enter valid pay period filter dates.';
        break;
    }
}
if ($errorMessage === null && $periodFrom !== '' && $periodTo !== '' && $periodFrom > $periodTo) {
    $errorMessage = 'The pay period start filter must be on or before the end filter.';
}

try {
    if (!$isEmployee) {
        $employees = $pdo->query(
            "SELECT employee_id, employee_code, first_name, last_name
             FROM employees
             ORDER BY last_name, first_name, employee_code"
        )->fetchAll();
    }

    $where = [];
    $parameters = [];
    if ($isEmployee) {
        $where[] = 'p.employee_id = :employee_id';
        $parameters[':employee_id'] = (int) $employeeId;
    } elseif ($search !== '') {
        $searchConditions = [
            'e.first_name LIKE :first_search',
            'e.last_name LIKE :last_search',
            'e.employee_code LIKE :code_search',
        ];
        $term = '%' . $search . '%';
        $parameters[':first_search'] = $term;
        $parameters[':last_search'] = $term;
        $parameters[':code_search'] = $term;
        if (ctype_digit($search)) {
            $searchConditions[] = 'e.employee_id = :employee_id_search';
            $parameters[':employee_id_search'] = (int) $search;
        }
        $where[] = '(' . implode(' OR ', $searchConditions) . ')';
    }
    if ($periodFrom !== '') {
        $where[] = 'p.pay_period_end >= :period_from';
        $parameters[':period_from'] = $periodFrom;
    }
    if ($periodTo !== '') {
        $where[] = 'p.pay_period_start <= :period_to';
        $parameters[':period_to'] = $periodTo;
    }
    if ($statusFilter !== '') {
        $where[] = 'p.payment_status = :payment_status';
        $parameters[':payment_status'] = $statusFilter;
    }
    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

    $count = $pdo->prepare(
        'SELECT COUNT(*)
         FROM payroll AS p
         INNER JOIN employees AS e ON e.employee_id = p.employee_id'
         . $whereSql
    );
    $count->execute($parameters);
    $totalRecords = (int) $count->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRecords / $perPage));
    $page = min($page, $totalPages);
    $listing = $pdo->prepare(
        'SELECT p.payroll_id, p.employee_id, p.pay_period_start, p.pay_period_end,
                p.basic_salary, p.allowances, p.overtime_pay, p.deductions,
                p.gross_salary, p.net_salary, p.payment_status, p.payment_date,
                e.employee_code, e.first_name, e.last_name, d.department_name
         FROM payroll AS p
         INNER JOIN employees AS e ON e.employee_id = p.employee_id
         LEFT JOIN departments AS d ON d.department_id = e.department_id'
         . $whereSql .
        ' ORDER BY p.pay_period_end DESC, p.payroll_id DESC
          LIMIT :limit OFFSET :offset'
    );
    foreach ($parameters as $name => $value) {
        $listing->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $listing->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listing->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $listing->execute();
    $records = $listing->fetchAll();
} catch (PDOException $e) {
    error_log('Payroll listing failed: ' . $e->getMessage());
    $errorMessage = 'Payroll records could not be loaded. Please try again later.';
}

$pageUrl = static function (int $targetPage) use ($isEmployee, $search, $periodFrom, $periodTo, $statusFilter): string {
    return 'payroll.php?' . http_build_query([
        'search' => $isEmployee ? null : $search,
        'period_from' => $periodFrom,
        'period_to' => $periodTo,
        'status' => $statusFilter,
        'page' => $targetPage,
    ]);
};

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow"><?= $isEmployee ? 'MY WORKSPACE' : 'WORKSPACE' ?></p>
        <h1><?= $isEmployee ? 'My payroll' : 'Payroll' ?></h1>
        <p class="page-description"><?= $isEmployee ? 'Your payroll history and payslips.' : 'Generate payroll and review payment history.' ?></p>
    </div>
</section>
<?php if ($notice !== null): ?>
    <div class="alert <?= ($notice['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="status"><?= $escape($notice['message'] ?? '') ?></div>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="alert alert-error" role="alert"><?= $escape($errorMessage) ?></div>
<?php endif; ?>

<?php if (!$isEmployee && $errorMessage === null): ?>
    <section class="panel employee-panel payroll-generation-panel">
        <div class="panel-heading"><div><h2>Generate payroll</h2><p>Each employee can have only one record for an exact pay-period start and end date.</p></div></div>
        <form class="employee-form" method="post" action="payroll.php">
            <input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>">
            <input type="hidden" name="action" value="generate">
            <div class="form-grid">
                <div class="form-field payroll-employee-field">
                    <label for="employee_id">Employee</label>
                    <select id="employee_id" name="employee_id" required>
                        <option value="">Select an employee</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['employee_id'] ?>" <?= (string) $formValues['employee_id'] === (string) $employee['employee_id'] ? 'selected' : '' ?>><?= $escape($employee['employee_code'] . ' · ' . $employee['first_name'] . ' ' . $employee['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field"><label for="pay_period_start">Pay period start</label><input id="pay_period_start" name="pay_period_start" type="date" value="<?= $escape($formValues['pay_period_start']) ?>" required></div>
                <div class="form-field"><label for="pay_period_end">Pay period end</label><input id="pay_period_end" name="pay_period_end" type="date" value="<?= $escape($formValues['pay_period_end']) ?>" required></div>
                <div class="form-field"><label for="basic_salary">Basic salary</label><input id="basic_salary" name="basic_salary" type="number" min="0" max="9999999999.99" step="0.01" value="<?= $escape($formValues['basic_salary']) ?>" required></div>
                <div class="form-field"><label for="allowances">Allowances</label><input id="allowances" name="allowances" type="number" min="0" max="9999999999.99" step="0.01" value="<?= $escape($formValues['allowances']) ?>" required></div>
                <div class="form-field"><label for="overtime_pay">Overtime pay</label><input id="overtime_pay" name="overtime_pay" type="number" min="0" max="9999999999.99" step="0.01" value="<?= $escape($formValues['overtime_pay']) ?>" required></div>
                <div class="form-field"><label for="deductions">Deductions</label><input id="deductions" name="deductions" type="number" min="0" max="9999999999.99" step="0.01" value="<?= $escape($formValues['deductions']) ?>" required></div>
            </div>
            <p class="form-help">Gross = basic salary + allowances + overtime. Net = gross − deductions. Values are calculated in cents and stored in the existing DECIMAL columns.</p>
            <div class="form-actions"><button class="button button-primary" type="submit">Generate payroll</button></div>
        </form>
    </section>
<?php endif; ?>

<?php if ($errorMessage === null): ?>
    <section class="panel employee-panel payroll-list-panel">
        <form class="employee-filters payroll-filters" method="get" action="payroll.php">
            <?php if (!$isEmployee): ?>
                <div class="search-field"><label class="visually-hidden" for="payroll-search">Search employees</label><input id="payroll-search" name="search" type="search" value="<?= $escape($search) ?>" placeholder="Search name, employee ID, or code"></div>
            <?php endif; ?>
            <div class="filter-field"><label class="visually-hidden" for="period-from">Period overlaps from</label><input id="period-from" name="period_from" type="date" value="<?= $escape($periodFrom) ?>"></div>
            <div class="filter-field"><label class="visually-hidden" for="period-to">Period overlaps through</label><input id="period-to" name="period_to" type="date" value="<?= $escape($periodTo) ?>"></div>
            <div class="filter-field"><label class="visually-hidden" for="payment-status">Payment status</label><select id="payment-status" name="status"><option value="">All statuses</option><option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option><option value="Paid" <?= $statusFilter === 'Paid' ? 'selected' : '' ?>>Paid</option></select></div>
            <button class="button button-secondary" type="submit">Filter</button>
            <?php if ($search !== '' || $periodFrom !== '' || $periodTo !== '' || $statusFilter !== ''): ?><a class="button button-quiet" href="payroll.php">Clear</a><?php endif; ?>
        </form>
        <div class="table-wrap">
            <table class="employee-table payroll-table">
                <thead><tr>
                    <th scope="col">Payroll ID</th><?php if (!$isEmployee): ?><th scope="col">Employee</th><th scope="col">Department</th><?php endif; ?>
                    <th scope="col">Pay period</th><th scope="col">Basic salary</th><th scope="col">Allowances</th><th scope="col">Overtime</th><th scope="col">Deductions</th><th scope="col">Gross</th><th scope="col">Net</th><th scope="col">Status</th><th scope="col">Payment date</th><th scope="col">Details</th>
                </tr></thead>
                <tbody>
                    <?php if ($records === []): ?>
                        <tr><td class="empty-state" colspan="<?= $isEmployee ? 11 : 13 ?>"><?= $isEmployee ? 'No payroll records are available.' : 'No payroll records match these filters.' ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td>#<?= (int) $record['payroll_id'] ?></td>
                                <?php if (!$isEmployee): ?><td><span class="person-name"><?= $escape($record['first_name'] . ' ' . $record['last_name']) ?></span><span class="secondary-text">ID <?= (int) $record['employee_id'] ?> · <?= $escape($record['employee_code']) ?></span></td><td><?= $escape($record['department_name'] ?? 'Unassigned') ?></td><?php endif; ?>
                                <td><?= $escape(date('M j', strtotime($record['pay_period_start']))) ?> – <?= $escape(date('M j, Y', strtotime($record['pay_period_end']))) ?></td>
                                <td><?= $escape($displayMoney($record['basic_salary'])) ?></td><td><?= $escape($displayMoney($record['allowances'])) ?></td><td><?= $escape($displayMoney($record['overtime_pay'])) ?></td><td><?= $escape($displayMoney($record['deductions'])) ?></td><td><?= $escape($displayMoney($record['gross_salary'])) ?></td><td><?= $escape($displayMoney($record['net_salary'])) ?></td>
                                <td><span class="status-badge payment-status-<?= $escape(strtolower($record['payment_status'])) ?>"><?= $escape($record['payment_status']) ?></span></td>
                                <td><?= $record['payment_date'] !== null ? $escape(date('M j, Y', strtotime($record['payment_date']))) : '—' ?></td>
                                <td><a class="action-link" href="payroll-details.php?id=<?= (int) $record['payroll_id'] ?>">View</a><?php if ($isEmployee): ?> · <a class="action-link" href="payslip.php?id=<?= (int) $record['payroll_id'] ?>">Payslip</a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="listing-footer">
            <p><?= number_format($totalRecords) ?> record<?= $totalRecords === 1 ? '' : 's' ?><?= $totalRecords > 0 ? ' · Page ' . $page . ' of ' . $totalPages : '' ?></p>
            <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Payroll pages"><?php if ($page > 1): ?><a class="button button-quiet" href="<?= $escape($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?><?php if ($page < $totalPages): ?><a class="button button-quiet" href="<?= $escape($pageUrl($page + 1)) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
