<?php
require __DIR__ . '/includes/auth.php';
auth_require_roles(['Admin', 'HR', 'Employee']);
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/payroll_helpers.php';

$user = auth_current_user();
$isEmployee = $user['role'] === 'Employee';
$payrollId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$employeeId = $isEmployee
    ? filter_var($user['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;
$record = null;
$loadError = null;

if ($payrollId === false || ($isEmployee && $employeeId === false)) {
    http_response_code(400);
    $loadError = 'A valid payroll record is required.';
} else {
    try {
        $sql = 'SELECT p.payroll_id, p.pay_period_start, p.pay_period_end,
                       p.basic_salary, p.allowances, p.overtime_pay, p.deductions,
                       p.sss_deduction, p.philhealth_deduction, p.pagibig_deduction,
                       p.gross_salary, p.net_salary, p.payment_status,
                       p.payment_date, e.employee_code, e.first_name,
                       e.middle_name, e.last_name, e.email, e.position,
                       e.hire_date, d.department_name
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
        error_log('Payslip load failed: ' . $e->getMessage());
        $loadError = 'The payslip could not be loaded. Please try again later.';
    }
}

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$totalDeductions = $record === null ? 0 : payroll_total_deductions_cents($record);
$pageTitle = 'Payslip';
$activePage = 'payroll';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading payslip-page-heading">
    <div>
        <p class="eyebrow">PEOPLESUITE PAYROLL</p>
        <h1>Payslip</h1>
        <p class="page-description">Printable payroll statement.</p>
    </div>
    <?php if ($record !== null): ?>
        <button class="button button-primary no-print" type="button" onclick="window.print()">Print payslip</button>
    <?php endif; ?>
</section>

<?php if ($loadError !== null): ?>
    <div class="alert alert-error" role="alert"><?= $escape($loadError) ?></div>
<?php elseif ($record === null): ?>
    <div class="alert alert-error" role="alert">The requested payslip was not found or is not available to your account.</div>
<?php else: ?>
    <article class="panel payslip-sheet">
        <header class="payslip-brand">
            <div><span class="brand-mark" aria-hidden="true">P</span><div><strong>PeopleSuite</strong><small>PAYSLIP</small></div></div>
            <span class="payslip-number">Payroll #<?= (int) $record['payroll_id'] ?></span>
        </header>
        <section class="payslip-employee">
            <div><span>Employee</span><strong><?= $escape(trim($record['first_name'] . ' ' . ($record['middle_name'] ?? '') . ' ' . $record['last_name'])) ?></strong></div>
            <div><span>Employee code</span><strong><?= $escape($record['employee_code']) ?></strong></div>
            <div><span>Department</span><strong><?= $escape($record['department_name'] ?? 'Unassigned') ?></strong></div>
            <div><span>Position</span><strong><?= $escape($record['position'] ?? '—') ?></strong></div>
            <div><span>Pay period</span><strong><?= $escape(date('M j, Y', strtotime($record['pay_period_start']))) ?> – <?= $escape(date('M j, Y', strtotime($record['pay_period_end']))) ?></strong></div>
            <div><span>Email</span><strong><?= $escape($record['email']) ?></strong></div>
            <div><span>Hire date</span><strong><?= $escape(date('M j, Y', strtotime($record['hire_date']))) ?></strong></div>
        </section>
        <div class="table-wrap">
            <table class="payslip-table">
                <thead><tr><th scope="col">Salary component</th><th scope="col">Amount</th></tr></thead>
                <tbody>
                    <tr><td>Basic salary</td><td><?= $escape(payroll_money($record['basic_salary'])) ?></td></tr>
                    <tr><td>Allowances</td><td><?= $escape(payroll_money($record['allowances'])) ?></td></tr>
                    <tr><td>Overtime pay</td><td><?= $escape(payroll_money($record['overtime_pay'])) ?></td></tr>
                    <tr class="payslip-total"><th scope="row">Gross salary</th><td><?= $escape(payroll_money($record['gross_salary'])) ?></td></tr>
                    <tr><td>SSS</td><td>− <?= $escape(payroll_money($record['sss_deduction'])) ?></td></tr>
                    <tr><td>PhilHealth</td><td>− <?= $escape(payroll_money($record['philhealth_deduction'])) ?></td></tr>
                    <tr><td>Pag-IBIG</td><td>− <?= $escape(payroll_money($record['pagibig_deduction'])) ?></td></tr>
                    <tr><td>Other deductions</td><td>− <?= $escape(payroll_money($record['deductions'])) ?></td></tr>
                    <tr class="payslip-total"><th scope="row">Total deductions</th><td>− <?= $escape(payroll_money(payroll_cents_to_amount($totalDeductions))) ?></td></tr>
                    <tr class="payslip-net"><th scope="row">Net salary</th><td><?= $escape(payroll_money($record['net_salary'])) ?></td></tr>
                </tbody>
            </table>
        </div>
        <footer class="payslip-footer">
            <div><span>Payment status</span><strong><?= $escape($record['payment_status']) ?></strong></div>
            <div><span>Payment date</span><strong><?= $record['payment_date'] !== null ? $escape(date('M j, Y', strtotime($record['payment_date']))) : 'Not paid' ?></strong></div>
        </footer>
        <div class="payslip-actions no-print">
            <a class="button button-quiet" href="payroll-details.php?id=<?= (int) $record['payroll_id'] ?>">Back to payroll details</a>
            <button class="button button-primary" type="button" onclick="window.print()">Print payslip</button>
        </div>
    </article>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
