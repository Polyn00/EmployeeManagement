<?php
require __DIR__ . '/includes/auth.php';
auth_require_roles(['Admin', 'Employee']);
require __DIR__ . '/config/db.php';

$user = auth_current_user();
$isEmployee = $user['role'] === 'Employee';
$payrollId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$record = null;
$loadError = null;
$employeeId = $isEmployee
    ? filter_var($user['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;
$editFormValues = null;
if ($payrollId !== false && isset($_SESSION['payroll_edit_form_values'][$payrollId])) {
    $editFormValues = $_SESSION['payroll_edit_form_values'][$payrollId];
    unset($_SESSION['payroll_edit_form_values'][$payrollId]);
    if (isset($_SESSION['payroll_edit_form_values']) && $_SESSION['payroll_edit_form_values'] === []) {
        unset($_SESSION['payroll_edit_form_values']);
    }
}

if ($payrollId === false || ($isEmployee && $employeeId === false)) {
    http_response_code(400);
    $loadError = 'A valid payroll record is required.';
} else {
    try {
        $sql = 'SELECT p.payroll_id, p.employee_id, p.pay_period_start,
                       p.pay_period_end, p.basic_salary, p.allowances,
                       p.overtime_pay, p.deductions, p.gross_salary, p.net_salary,
                       p.payment_status, p.payment_date, p.created_at,
                       e.employee_code, e.first_name, e.middle_name, e.last_name,
                       e.email, e.position, e.hire_date, d.department_name
                FROM payroll AS p
                INNER JOIN employees AS e ON e.employee_id = p.employee_id
                LEFT JOIN departments AS d ON d.department_id = e.department_id
                WHERE p.payroll_id = :payroll_id';
        $parameters = [':payroll_id' => $payrollId];
        if ($isEmployee) {
            $sql .= ' AND p.employee_id = :employee_id';
            $parameters[':employee_id'] = $employeeId;
        }
        $query = $pdo->prepare($sql);
        $query->execute($parameters);
        $record = $query->fetch() ?: null;
        if ($record === null) {
            http_response_code(404);
        }
    } catch (PDOException $e) {
        error_log('Payroll detail load failed: ' . $e->getMessage());
        $loadError = 'Payroll details could not be loaded. Please try again later.';
    }
}

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$displayMoney = static function ($amount): string {
    $value = (string) ($amount ?? '0.00');
    if (!preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', $value, $parts)) {
        return '0.00';
    }
    $whole = (int) $parts[1];
    $fraction = (int) str_pad($parts[2] ?? '', 2, '0');
    return number_format($whole, 0, '.', ',') . '.' . str_pad((string) $fraction, 2, '0');
};
$pageTitle = 'Payroll details';
$activePage = 'payroll';
$notice = $_SESSION['payroll_notice'] ?? null;
unset($_SESSION['payroll_notice']);
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow"><?= $isEmployee ? 'MY PAYROLL' : 'PAYROLL' ?></p>
        <h1>Payroll details</h1>
        <p class="page-description">Salary breakdown and payment status.</p>
    </div>
    <a class="button button-quiet" href="payroll.php">Back to payroll</a>
</section>

<?php if ($notice !== null): ?>
    <div class="alert <?= ($notice['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="status"><?= $escape($notice['message'] ?? '') ?></div>
<?php endif; ?>
<?php if ($loadError !== null): ?>
    <div class="alert alert-error" role="alert"><?= $escape($loadError) ?></div>
<?php elseif ($record === null): ?>
    <div class="alert alert-error" role="alert">The requested payroll record was not found or is not available to your account.</div>
<?php else: ?>
    <section class="panel payroll-detail-panel">
        <div class="panel-heading">
            <div>
                <h2>Payroll #<?= (int) $record['payroll_id'] ?></h2>
                <p><?= $escape($record['employee_code'] . ' · ' . trim($record['first_name'] . ' ' . ($record['middle_name'] ?? '') . ' ' . $record['last_name'])) ?></p>
            </div>
            <span class="status-badge payment-status-<?= $escape(strtolower($record['payment_status'])) ?>"><?= $escape($record['payment_status']) ?></span>
        </div>
        <dl class="payroll-detail-grid">
            <div><dt>Department</dt><dd><?= $escape($record['department_name'] ?? 'Unassigned') ?></dd></div>
            <div><dt>Position</dt><dd><?= $escape($record['position'] ?? '—') ?></dd></div>
            <div><dt>Email</dt><dd><?= $escape($record['email']) ?></dd></div>
            <div><dt>Hire date</dt><dd><?= $escape(date('M j, Y', strtotime($record['hire_date']))) ?></dd></div>
            <div><dt>Pay period</dt><dd><?= $escape(date('M j, Y', strtotime($record['pay_period_start']))) ?> – <?= $escape(date('M j, Y', strtotime($record['pay_period_end']))) ?></dd></div>
            <div><dt>Basic salary</dt><dd><?= $escape($displayMoney($record['basic_salary'])) ?></dd></div>
            <div><dt>Allowances</dt><dd><?= $escape($displayMoney($record['allowances'])) ?></dd></div>
            <div><dt>Overtime pay</dt><dd><?= $escape($displayMoney($record['overtime_pay'])) ?></dd></div>
            <div><dt>Gross salary</dt><dd><?= $escape($displayMoney($record['gross_salary'])) ?></dd></div>
            <div><dt>Deductions</dt><dd><?= $escape($displayMoney($record['deductions'])) ?></dd></div>
            <div class="payroll-net"><dt>Net salary</dt><dd><?= $escape($displayMoney($record['net_salary'])) ?></dd></div>
            <div><dt>Payment status</dt><dd><?= $escape($record['payment_status']) ?></dd></div>
            <div><dt>Payment date</dt><dd><?= $record['payment_date'] !== null ? $escape(date('M j, Y', strtotime($record['payment_date']))) : 'Not paid' ?></dd></div>
        </dl>
        <div class="payroll-detail-actions">
            <a class="button button-secondary" href="payslip.php?id=<?= (int) $record['payroll_id'] ?>">View printable payslip</a>
            <?php if (!$isEmployee): ?>
                <form method="post" action="payroll.php" onsubmit="return confirm('Mark payroll #<?= (int) $record['payroll_id'] ?> as <?= $record['payment_status'] === 'Pending' ? 'paid' : 'unpaid' ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="payroll_id" value="<?= (int) $record['payroll_id'] ?>">
                    <input type="hidden" name="payment_status" value="<?= $record['payment_status'] === 'Pending' ? 'Paid' : 'Pending' ?>">
                    <button class="button button-primary" type="submit">Mark as <?= $record['payment_status'] === 'Pending' ? 'paid' : 'unpaid' ?></button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (!$isEmployee && $record['payment_status'] === 'Pending'): ?>
            <?php
            $editValues = is_array($editFormValues) ? array_merge([
                'pay_period_start' => $record['pay_period_start'],
                'pay_period_end' => $record['pay_period_end'],
                'basic_salary' => $record['basic_salary'],
                'allowances' => $record['allowances'],
                'overtime_pay' => $record['overtime_pay'],
                'deductions' => $record['deductions'],
            ], array_intersect_key($editFormValues, array_flip([
                'pay_period_start', 'pay_period_end', 'basic_salary',
                'allowances', 'overtime_pay', 'deductions',
            ]))) : [
                'pay_period_start' => $record['pay_period_start'],
                'pay_period_end' => $record['pay_period_end'],
                'basic_salary' => $record['basic_salary'],
                'allowances' => $record['allowances'],
                'overtime_pay' => $record['overtime_pay'],
                'deductions' => $record['deductions'],
            ];
            ?>
            <details class="payroll-edit-disclosure">
                <summary class="button button-secondary">Edit pending payroll</summary>
                <form class="employee-form" method="post" action="payroll.php">
                    <input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_pending">
                    <input type="hidden" name="payroll_id" value="<?= (int) $record['payroll_id'] ?>">
                    <div class="form-grid">
                        <div class="form-field"><label for="pay_period_start">Pay period start</label><input id="pay_period_start" name="pay_period_start" type="date" value="<?= $escape($editValues['pay_period_start']) ?>" required></div>
                        <div class="form-field"><label for="pay_period_end">Pay period end</label><input id="pay_period_end" name="pay_period_end" type="date" value="<?= $escape($editValues['pay_period_end']) ?>" required></div>
                        <div class="form-field"><label for="basic_salary">Basic salary</label><input id="basic_salary" name="basic_salary" type="number" min="0" max="9999999999.99" step="0.01" value="<?= $escape($editValues['basic_salary']) ?>" required></div>
                        <div class="form-field"><label for="allowances">Allowances</label><input id="allowances" name="allowances" type="number" min="0" max="9999999999.99" step="0.01" value="<?= $escape($editValues['allowances']) ?>" required></div>
                        <div class="form-field"><label for="overtime_pay">Overtime pay</label><input id="overtime_pay" name="overtime_pay" type="number" min="0" max="9999999999.99" step="0.01" value="<?= $escape($editValues['overtime_pay']) ?>" required></div>
                        <div class="form-field"><label for="deductions">Deductions</label><input id="deductions" name="deductions" type="number" min="0" max="9999999999.99" step="0.01" value="<?= $escape($editValues['deductions']) ?>" required></div>
                    </div>
                    <p class="form-help">Gross and net amounts are recalculated when saved. Paid payroll records cannot be edited.</p>
                    <div class="form-actions"><button class="button button-primary" type="submit">Save payroll changes</button></div>
                </form>
            </details>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
