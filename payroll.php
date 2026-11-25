<?php
require __DIR__ . '/includes/auth.php';
auth_require_roles(['Admin', 'HR', 'Employee']);
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/payroll_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = auth_current_user();
$canManagePayroll = in_array($currentUser['role'], ['Admin', 'HR'], true);

$pageTitle = $canManagePayroll ? 'Payroll Management' : 'My Payroll';
$activePage = 'payroll';

$payrollError = null;
$successMessage = null;
$payrollRecords = [];
$selectedPayroll = null;
$employeeId = null;

$payrollStats = [
    'total' => 0,
    'pending' => 0,
    'paid' => 0,
    'total_amount' => 0
];

if (empty($_SESSION['payroll_csrf'])) {
    $_SESSION['payroll_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['payroll_csrf'];

/* ==========================================
   HELPER FUNCTIONS
========================================== */

function payroll_escape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function payroll_audit(
    PDO $pdo,
    array $currentUser,
    string $action,
    int $entityId,
    array $details
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs
        (actor_user_id, action, entity_type, entity_id, details)
        VALUES (:actor, :action, 'payroll', :entity, :details)"
    );

    $stmt->execute([
        ':actor' => $currentUser['user_id'] ?? null,
        ':action' => $action,
        ':entity' => $entityId,
        ':details' => json_encode($details)
    ]);
}

function payroll_period(
    int $year,
    int $month,
    string $cutoff
): array {
    $firstDay = new DateTime(
        sprintf('%04d-%02d-01', $year, $month)
    );

    $lastDay = new DateTime($firstDay->format('Y-m-t'));

    if ($cutoff === 'first') {
        return [
            $firstDay->format('Y-m-d'),
            sprintf('%04d-%02d-15', $year, $month)
        ];
    }

    return [
        sprintf('%04d-%02d-16', $year, $month),
        $lastDay->format('Y-m-d')
    ];
}

/* ==========================================
   HANDLE PAYROLL ACTIONS
========================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !is_string($_POST['csrf_token'] ?? null) ||
            !hash_equals($csrfToken, $_POST['csrf_token'])
        ) {
            throw new RuntimeException(
                'Invalid request token. Please refresh the page.'
            );
        }

        if (!$canManagePayroll) {
            throw new RuntimeException(
                'You are not authorized to manage payroll.'
            );
        }

        $action = $_POST['action'] ?? '';

        /* GENERATE PAYROLL */

        if ($action === 'generate') {
            $month = filter_var(
                $_POST['month'] ?? null,
                FILTER_VALIDATE_INT
            );

            $year = filter_var(
                $_POST['year'] ?? null,
                FILTER_VALIDATE_INT
            );

            $cutoff = $_POST['cutoff'] ?? '';

            if (!$month || $month < 1 || $month > 12) {
                throw new RuntimeException(
                    'Please select a valid month.'
                );
            }

            if (!$year || $year < 2000 || $year > 2100) {
                throw new RuntimeException(
                    'Please enter a valid year.'
                );
            }

            if (!in_array($cutoff, ['first', 'second'], true)) {
                throw new RuntimeException(
                    'Please select a valid cutoff.'
                );
            }

            [$periodStart, $periodEnd] = payroll_period(
                $year,
                $month,
                $cutoff
            );

            $employees = $pdo->query(
                "SELECT employee_id, first_name, last_name, basic_salary
                 FROM employees
                 WHERE employment_status = 'Active'
                 ORDER BY last_name, first_name"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!$employees) {
                throw new RuntimeException(
                    'There are no active employees to process.'
                );
            }

            $pdo->beginTransaction();

            $checkStmt = $pdo->prepare(
                "SELECT payroll_id
                 FROM payroll
                 WHERE employee_id = :employee_id
                   AND pay_period_start = :period_start
                   AND pay_period_end = :period_end
                 LIMIT 1"
            );

            $insertStmt = $pdo->prepare(
                "INSERT INTO payroll (
                    employee_id,
                    pay_period_start,
                    pay_period_end,
                    basic_salary,
                    allowances,
                    overtime_pay,
                    deductions,
                    sss_deduction,
                    philhealth_deduction,
                    pagibig_deduction,
                    gross_salary,
                    net_salary,
                    payment_status
                ) VALUES (
                    :employee_id,
                    :period_start,
                    :period_end,
                    :basic_salary,
                    0, 0, 0,
                    :sss_deduction,
                    :philhealth_deduction,
                    :pagibig_deduction,
                    :gross_salary,
                    :net_salary,
                    'Pending'
                )"
            );

            $generated = 0;
            $skipped = 0;

            foreach ($employees as $employee) {
                $checkStmt->execute([
                    ':employee_id' => $employee['employee_id'],
                    ':period_start' => $periodStart,
                    ':period_end' => $periodEnd
                ]);

                if ($checkStmt->fetch()) {
                    $skipped++;
                    continue;
                }

                $monthlySalaryCents = payroll_amount_to_cents(
                    $employee['basic_salary']
                );

                if ($monthlySalaryCents === null) {
                    throw new RuntimeException(
                        'An employee has an invalid monthly basic salary.'
                    );
                }

                $basicSalaryCents = payroll_cutoff_share(
                    $monthlySalaryCents,
                    $cutoff
                );

                $monthlyDeductions = payroll_statutory_monthly_deductions(
                    $monthlySalaryCents
                );

                $sssCents = payroll_cutoff_share(
                    $monthlyDeductions['sss'],
                    $cutoff
                );

                $philhealthCents = payroll_cutoff_share(
                    $monthlyDeductions['philhealth'],
                    $cutoff
                );

                $pagibigCents = payroll_cutoff_share(
                    $monthlyDeductions['pagibig'],
                    $cutoff
                );

                $totals = payroll_calculate_totals(
                    $basicSalaryCents,
                    0,
                    0,
                    0,
                    $sssCents,
                    $philhealthCents,
                    $pagibigCents
                );

                $insertStmt->execute([
                    ':employee_id' => $employee['employee_id'],
                    ':period_start' => $periodStart,
                    ':period_end' => $periodEnd,
                    ':basic_salary' => payroll_cents_to_amount($basicSalaryCents),
                    ':sss_deduction' => payroll_cents_to_amount($sssCents),
                    ':philhealth_deduction' => payroll_cents_to_amount($philhealthCents),
                    ':pagibig_deduction' => payroll_cents_to_amount($pagibigCents),
                    ':gross_salary' => payroll_cents_to_amount($totals['gross']),
                    ':net_salary' => payroll_cents_to_amount($totals['net']),
                ]);

                $payrollId = (int)$pdo->lastInsertId();

                payroll_audit(
                    $pdo,
                    $currentUser,
                    'payroll_generated',
                    $payrollId,
                    [
                        'employee_id' => (int)$employee['employee_id'],
                        'employee_name' =>
                            $employee['first_name'] . ' ' .
                            $employee['last_name'],
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'basic_salary' => payroll_cents_to_amount($basicSalaryCents),
                        'sss_deduction' => payroll_cents_to_amount($sssCents),
                        'philhealth_deduction' => payroll_cents_to_amount($philhealthCents),
                        'pagibig_deduction' => payroll_cents_to_amount($pagibigCents),
                    ]
                );

                $generated++;
            }

            $pdo->commit();

            $successMessage =
                "Payroll generation completed. {$generated} record(s) created and {$skipped} duplicate record(s) skipped.";
        }

        /* UPDATE PAYROLL AMOUNTS */

        elseif ($action === 'update_amounts') {
            $payrollId = filter_var(
                $_POST['payroll_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $allowances = payroll_amount_to_cents(
                $_POST['allowances'] ?? null
            );

            $overtime = payroll_amount_to_cents(
                $_POST['overtime_pay'] ?? null
            );

            $deductions = payroll_amount_to_cents(
                $_POST['deductions'] ?? null
            );

            if (
                !$payrollId ||
                $allowances === null ||
                $overtime === null ||
                $deductions === null
            ) {
                throw new RuntimeException(
                    'Please enter valid payroll amounts.'
                );
            }

            $stmt = $pdo->prepare(
                "SELECT basic_salary, sss_deduction, philhealth_deduction,
                        pagibig_deduction, payment_status
                 FROM payroll
                 WHERE payroll_id = :id"
            );

            $stmt->execute([':id' => $payrollId]);

            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                throw new RuntimeException(
                    'Payroll record not found.'
                );
            }

            if ($record['payment_status'] !== 'Pending') {
                throw new RuntimeException(
                    'Paid payroll records cannot be edited.'
                );
            }

            $basicSalary = payroll_amount_to_cents(
                $record['basic_salary']
            );

            if ($basicSalary === null) {
                throw new RuntimeException(
                    'The payroll record has an invalid basic salary.'
                );
            }

            $sssCents = payroll_amount_to_cents(
                $record['sss_deduction']
            );

            $philhealthCents = payroll_amount_to_cents(
                $record['philhealth_deduction']
            );

            $pagibigCents = payroll_amount_to_cents(
                $record['pagibig_deduction']
            );

            if (
                $sssCents === null ||
                $philhealthCents === null ||
                $pagibigCents === null
            ) {
                throw new RuntimeException(
                    'The payroll record has an invalid statutory deduction.'
                );
            }

            $totals = payroll_calculate_totals(
                $basicSalary,
                $allowances,
                $overtime,
                $deductions,
                $sssCents,
                $philhealthCents,
                $pagibigCents
            );

            if ($totals['net'] < 0) {
                throw new RuntimeException(
                    'Deductions cannot exceed the gross salary.'
                );
            }

            $updateStmt = $pdo->prepare(
                "UPDATE payroll
                 SET allowances = :allowances,
                     overtime_pay = :overtime,
                     deductions = :deductions,
                     gross_salary = :gross_salary,
                     net_salary = :net_salary
                 WHERE payroll_id = :id
                   AND payment_status = 'Pending'"
            );

            $updateStmt->execute([
                ':allowances' => payroll_cents_to_amount($allowances),
                ':overtime' => payroll_cents_to_amount($overtime),
                ':deductions' => payroll_cents_to_amount($deductions),
                ':gross_salary' => payroll_cents_to_amount($totals['gross']),
                ':net_salary' => payroll_cents_to_amount($totals['net']),
                ':id' => $payrollId
            ]);

            payroll_audit(
                $pdo,
                $currentUser,
                'payroll_updated',
                $payrollId,
                [
                    'allowances' => payroll_cents_to_amount($allowances),
                    'overtime_pay' => payroll_cents_to_amount($overtime),
                    'deductions' => payroll_cents_to_amount($deductions),
                    'total_deductions' => payroll_cents_to_amount($totals['total_deductions']),
                    'gross_salary' => payroll_cents_to_amount($totals['gross']),
                    'net_salary' => payroll_cents_to_amount($totals['net'])
                ]
            );

            $successMessage =
                'Payroll amounts and deductions updated successfully.';
        }

        /* MARK AS PAID */

        elseif ($action === 'mark_paid') {
            $payrollId = filter_var(
                $_POST['payroll_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$payrollId) {
                throw new RuntimeException(
                    'Invalid payroll record.'
                );
            }

            $stmt = $pdo->prepare(
                "UPDATE payroll
                 SET payment_status = 'Paid',
                     payment_date = CURRENT_DATE()
                 WHERE payroll_id = :id
                   AND payment_status = 'Pending'"
            );

            $stmt->execute([':id' => $payrollId]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException(
                    'Record not found or already paid.'
                );
            }

            payroll_audit(
                $pdo,
                $currentUser,
                'payroll_marked_paid',
                $payrollId,
                [
                    'payment_status' => 'Paid',
                    'payment_date' => date('Y-m-d')
                ]
            );

            $successMessage =
                'Payroll successfully marked as paid.';
        }

        else {
            throw new RuntimeException(
                'Invalid payroll action.'
            );
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof PDOException) {
            error_log('Payroll database error: ' . $e->getMessage());

            $payrollError =
                'A database error occurred. Please check the server log.';
        } else {
            $payrollError = $e->getMessage();
        }
    }
}

/* ==========================================
   LOAD PAYROLL INFORMATION
========================================== */

try {
    if ($canManagePayroll) {
        $payrollStats = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(payment_status = 'Pending'), 0) AS pending,
                COALESCE(SUM(payment_status = 'Paid'), 0) AS paid,
                COALESCE(SUM(net_salary), 0) AS total_amount
             FROM payroll"
        )->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->query(
            "SELECT p.*, e.employee_code, e.first_name,
                    e.last_name, e.position, d.department_name
             FROM payroll p
             INNER JOIN employees e
                ON e.employee_id = p.employee_id
             LEFT JOIN departments d
                ON d.department_id = e.department_id
             ORDER BY p.pay_period_start DESC, p.payroll_id DESC"
        );

        $payrollRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        $employeeId = $currentUser['employee_id'] ?? null;

        if (!$employeeId) {
            throw new RuntimeException(
                'Your account is not connected to an employee record.'
            );
        }

        $stmt = $pdo->prepare(
            "SELECT p.*, e.employee_code, e.first_name,
                    e.last_name, e.position, d.department_name
             FROM payroll p
             INNER JOIN employees e
                ON e.employee_id = p.employee_id
             LEFT JOIN departments d
                ON d.department_id = e.department_id
             WHERE p.employee_id = :employee_id
             ORDER BY p.pay_period_start DESC, p.payroll_id DESC"
        );

        $stmt->execute([
            ':employee_id' => $employeeId
        ]);

        $payrollRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* LOAD SELECTED PAYSLIP */

    if (isset($_GET['view'])) {
        $viewId = filter_var(
            $_GET['view'],
            FILTER_VALIDATE_INT
        );

        if (!$viewId) {
            throw new RuntimeException(
                'Invalid payslip selection.'
            );
        }

        if ($canManagePayroll) {
            $detailStmt = $pdo->prepare(
                "SELECT p.*, e.employee_code, e.first_name,
                        e.middle_name, e.last_name, e.position,
                        e.email, d.department_name
                 FROM payroll p
                 INNER JOIN employees e
                    ON e.employee_id = p.employee_id
                 LEFT JOIN departments d
                    ON d.department_id = e.department_id
                 WHERE p.payroll_id = :id"
            );

            $detailStmt->execute([
                ':id' => $viewId
            ]);

        } else {
            $detailStmt = $pdo->prepare(
                "SELECT p.*, e.employee_code, e.first_name,
                        e.middle_name, e.last_name, e.position,
                        e.email, d.department_name
                 FROM payroll p
                 INNER JOIN employees e
                    ON e.employee_id = p.employee_id
                 LEFT JOIN departments d
                    ON d.department_id = e.department_id
                 WHERE p.payroll_id = :id
                   AND p.employee_id = :employee_id"
            );

            $detailStmt->execute([
                ':id' => $viewId,
                ':employee_id' => $employeeId
            ]);
        }

        $selectedPayroll = $detailStmt->fetch(PDO::FETCH_ASSOC);

        if (!$selectedPayroll) {
            throw new RuntimeException(
                'Payslip not found or you are not authorized to view it.'
            );
        }
    }

} catch (Throwable $e) {
    error_log('Payroll loading error: ' . $e->getMessage());

    $payrollError = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Payroll information could not be loaded. Please try again later.';
}

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>

<style>
.payroll-page {
    display: flex;
    flex-direction: column;
    gap: 26px;
}

.payroll-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.payroll-heading h1 {
    color: var(--text);
    font-size: 30px;
    font-weight: 750;
    margin: 5px 0;
    letter-spacing: -.8px;
}

.payroll-heading .eyebrow {
    color: var(--accent);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 2px;
}

.payroll-heading .page-description {
    color: var(--muted);
    font-size: 14px;
}

.payroll-date {
    background: var(--panel-raised);
    border: 1px solid var(--border);
    border-radius: 12px;
    color: var(--text);
    padding: 12px 17px;
    font-size: 13px;
}

.payroll-alert {
    padding: 15px 18px;
    border-radius: 12px;
    font-size: 13px;
}

.payroll-alert.success {
    background: rgba(99,214,162,.10);
    color: var(--green);
    border: 1px solid rgba(99,214,162,.25);
}

.payroll-alert.error {
    background: rgba(255,105,125,.10);
    color: #ff8d9c;
    border: 1px solid rgba(255,105,125,.25);
}

.payroll-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.payroll-stat {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 21px;
    min-width: 0;
    transition: .2s ease;
}

.payroll-stat:hover {
    transform: translateY(-3px);
    border-color: var(--accent);
}

.payroll-stat-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.payroll-stat-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
}

.payroll-stat-icon {
    width: 37px;
    height: 37px;
    border-radius: 11px;
    display: grid;
    place-items: center;
    background: rgba(156,140,255,.14);
    color: var(--accent);
    font-size: 12px;
    font-weight: 800;
}

.payroll-stat-value {
    color: var(--text);
    font-size: 25px;
    font-weight: 750;
    margin: 18px 0 5px;
    overflow-wrap: anywhere;
}

.payroll-stat-note {
    color: var(--muted);
    font-size: 11px;
}

.payroll-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 24px;
    min-width: 0;
}

.payroll-card-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 22px;
}

.payroll-card-heading h2 {
    color: var(--text);
    font-size: 17px;
    font-weight: 700;
    margin: 0 0 6px;
}

.payroll-card-heading p {
    color: var(--muted);
    font-size: 12px;
    margin: 0;
}

.payroll-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1.4fr auto;
    align-items: end;
    gap: 15px;
}

.payroll-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}

.payroll-field label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 650;
}

.payroll-field input,
.payroll-field select {
    width: 100%;
    min-width: 0;
    background: var(--panel-raised);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text);
    padding: 12px 13px;
    font-family: inherit;
    font-size: 13px;
    outline: none;
}

.payroll-field input:focus,
.payroll-field select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(156,140,255,.12);
}

.payroll-button {
    border: 0;
    border-radius: 10px;
    padding: 12px 19px;
    background: var(--accent);
    color: #171323;
    font-family: inherit;
    font-size: 12px;
    font-weight: 750;
    cursor: pointer;
    transition: .2s ease;
}

.payroll-button:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
}

.payroll-button.secondary {
    background: var(--panel-raised);
    border: 1px solid var(--border);
    color: var(--text);
}

.payroll-button.paid-button {
    background: rgba(99,214,162,.14);
    color: var(--green);
    border: 1px solid rgba(99,214,162,.25);
}

.payroll-hint {
    margin-top: 17px;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.6;
}

.payroll-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.payroll-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1250px;
}

.payroll-table th {
    text-align: left;
    color: var(--muted);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 750;
    padding: 12px 11px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}

.payroll-table td {
    padding: 10px 11px;
    color: var(--text);
    font-size: 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    white-space: nowrap;
    line-height: 1.4;
}

.payroll-table tbody tr:last-child td {
    border-bottom: none;
}

.payroll-table tbody tr:hover {
    background: rgba(156,140,255,.035);
}

.payroll-person {
    display: block;
    color: var(--text);
    font-weight: 700;
    margin-bottom: 3px;
}

.payroll-secondary {
    display: block;
    color: var(--muted);
    font-size: 10px;
}

.payroll-status {
    display: inline-flex;
    align-items: center;
    border-radius: 30px;
    padding: 6px 10px;
    font-size: 10px;
    font-weight: 750;
}

.payroll-status.pending {
    background: rgba(255,190,92,.12);
    color: #ffc46d;
}

.payroll-status.paid {
    background: rgba(99,214,162,.12);
    color: var(--green);
}

.payroll-link {
    display: inline-block;
    color: var(--accent);
    text-decoration: none;
    font-size: 11px;
    font-weight: 700;
}

.payroll-link:hover {
    text-decoration: underline;
}

/* COMPACT TABLE ACTIONS */

.payroll-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 7px;
    min-width: 115px;
}

.payroll-actions form {
    margin: 0;
}

.payroll-adjust-button {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 12px;
    background: var(--panel-raised);
    color: var(--accent);
    font-family: inherit;
    font-size: 11px;
    font-weight: 750;
    cursor: pointer;
    transition: .2s ease;
}

.payroll-adjust-button:hover {
    border-color: var(--accent);
    background: rgba(156,140,255,.10);
}

/* RECORDS AND ADJUSTMENT PANEL LAYOUT */

.payroll-record-layout {
    display: block;
    width: 100%;
}

.payroll-record-layout.has-adjustment {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 320px;
    gap: 20px;
    align-items: start;
}

.payroll-record-layout .payroll-table-wrap {
    min-width: 0;
    width: 100%;
}

/* ADJUSTMENT PANEL */

.payroll-adjustment-panel {
    display: none;
    position: sticky;
    top: 20px;
    min-width: 0;
    padding: 20px;
    background: var(--panel-raised);
    border: 1px solid var(--border);
    border-radius: 14px;
}

.payroll-adjustment-panel.is-open {
    display: block;
}

.payroll-adjustment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 20px;
}

.payroll-adjustment-header h3 {
    color: var(--text);
    font-size: 16px;
    margin: 0 0 6px;
}

.payroll-adjustment-header p {
    color: var(--muted);
    font-size: 11px;
    margin: 0;
}

.payroll-adjustment-close {
    border: 1px solid var(--border);
    background: var(--panel);
    color: var(--muted);
    border-radius: 8px;
    width: 30px;
    height: 30px;
    cursor: pointer;
    font-size: 17px;
    line-height: 1;
    flex-shrink: 0;
}

.payroll-adjustment-close:hover {
    color: var(--text);
    border-color: var(--accent);
}

.payroll-adjustment-employee {
    padding: 12px;
    margin-bottom: 18px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 10px;
}

.payroll-adjustment-employee span {
    display: block;
    color: var(--muted);
    font-size: 10px;
    margin-bottom: 5px;
}

.payroll-adjustment-employee strong {
    color: var(--text);
    font-size: 13px;
}

.payroll-adjustment-panel .payroll-field {
    margin-bottom: 14px;
}

.payroll-adjustment-panel .payroll-button {
    width: 100%;
    margin-top: 5px;
}

.payroll-adjustment-panel .payroll-hint {
    margin-top: 12px;
}

.payroll-empty {
    text-align: center;
    color: var(--muted) !important;
    padding: 35px 15px !important;
}

.payroll-detail {
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 23px;
    background: var(--panel-raised);
}

.payroll-detail-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 25px;
}

.payroll-detail-header h2 {
    color: var(--text);
    font-size: 19px;
    margin: 0 0 5px;
}

.payroll-detail-header p {
    color: var(--muted);
    font-size: 12px;
    margin: 0;
}

.payroll-detail-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.payroll-detail-item {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.payroll-detail-item span {
    color: var(--muted);
    font-size: 10px;
}

.payroll-detail-item strong {
    color: var(--text);
    font-size: 13px;
    overflow-wrap: anywhere;
}

.payroll-breakdown {
    width: 100%;
    border-collapse: collapse;
}

.payroll-breakdown th,
.payroll-breakdown td {
    border-bottom: 1px solid var(--border);
    padding: 14px 10px;
    font-size: 12px;
}

.payroll-breakdown th {
    text-align: left;
    color: var(--muted);
    font-weight: 550;
}

.payroll-breakdown td {
    text-align: right;
    color: var(--text);
    font-weight: 650;
}

.payroll-breakdown tr:last-child th,
.payroll-breakdown tr:last-child td {
    border-bottom: none;
}

.payroll-breakdown .net-row th,
.payroll-breakdown .net-row td {
    color: var(--accent);
    font-size: 15px;
    font-weight: 800;
}

/* SECTION HEADINGS */

.payroll-record-heading {
    margin-bottom: 20px;
}

.payroll-record-heading h2 {
    color: var(--text);
    font-size: 17px;
    margin: 0 0 5px;
}

.payroll-record-heading p {
    color: var(--muted);
    font-size: 12px;
    margin: 0;
}

/* RESPONSIVE */

@media (max-width: 1100px) {
    .payroll-stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .payroll-form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .payroll-record-layout.has-adjustment {
        grid-template-columns: minmax(0, 1fr);
    }

    .payroll-adjustment-panel {
        position: static;
    }
}

@media (max-width: 650px) {
    .payroll-stat-grid,
    .payroll-form-grid,
    .payroll-detail-grid {
        grid-template-columns: 1fr;
    }

    .payroll-card {
        padding: 17px;
    }

    .payroll-heading h1 {
        font-size: 24px;
    }

    .payroll-detail {
        padding: 16px;
    }

    .payroll-adjustment-panel {
        padding: 16px;
    }

    .payroll-actions {
        min-width: 100px;
    }
}
</style>

<div class="payroll-page">

    <!-- PAGE HEADER -->

    <section class="payroll-heading">
        <div>
            <p class="eyebrow">
                <?= $canManagePayroll ? 'COMPENSATION' : 'EMPLOYEE SELF-SERVICE' ?>
            </p>

            <h1>
                <?= $canManagePayroll ? 'Payroll Management' : 'My Payroll' ?>
            </h1>

            <p class="page-description">
                <?= $canManagePayroll
                    ? 'Manage employee salaries, payroll periods, deductions, and payments.'
                    : 'View your salary records, allowances, overtime, deductions, and payment history.' ?>
            </p>
        </div>

        <div class="payroll-date">
            <?= payroll_escape(date('l, F j, Y')) ?>
        </div>
    </section>

    <?php if ($successMessage !== null): ?>
        <div class="payroll-alert success" role="status">
            <?= payroll_escape($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($payrollError !== null): ?>
        <div class="payroll-alert error" role="alert">
            <?= payroll_escape($payrollError) ?>
        </div>
    <?php endif; ?>

    <?php if ($canManagePayroll): ?>

        <!-- PAYROLL STATISTICS -->

        <section class="payroll-stat-grid">

            <article class="payroll-stat">
                <div class="payroll-stat-top">
                    <span class="payroll-stat-label">Payroll records</span>
                    <span class="payroll-stat-icon">PR</span>
                </div>
                <p class="payroll-stat-value">
                    <?= number_format((int)$payrollStats['total']) ?>
                </p>
                <span class="payroll-stat-note">Total generated records</span>
            </article>

            <article class="payroll-stat">
                <div class="payroll-stat-top">
                    <span class="payroll-stat-label">Pending payments</span>
                    <span class="payroll-stat-icon">PD</span>
                </div>
                <p class="payroll-stat-value">
                    <?= number_format((int)$payrollStats['pending']) ?>
                </p>
                <span class="payroll-stat-note">Awaiting payment</span>
            </article>

            <article class="payroll-stat">
                <div class="payroll-stat-top">
                    <span class="payroll-stat-label">Paid records</span>
                    <span class="payroll-stat-icon">PA</span>
                </div>
                <p class="payroll-stat-value">
                    <?= number_format((int)$payrollStats['paid']) ?>
                </p>
                <span class="payroll-stat-note">Successfully paid</span>
            </article>

            <article class="payroll-stat">
                <div class="payroll-stat-top">
                    <span class="payroll-stat-label">Total net payroll</span>
                    <span class="payroll-stat-icon">₱</span>
                </div>
                <p class="payroll-stat-value">
                    <?= payroll_money($payrollStats['total_amount']) ?>
                </p>
                <span class="payroll-stat-note">All generated payroll records</span>
            </article>

        </section>

        <!-- GENERATE PAYROLL -->

        <section class="payroll-card">

            <div class="payroll-card-heading">
                <div>
                    <h2>Generate payroll</h2>
                    <p>Create salary records for the selected semi-monthly cutoff.</p>
                </div>
            </div>

            <form method="post" class="payroll-form-grid">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= payroll_escape($csrfToken) ?>">

                <input type="hidden"
                       name="action"
                       value="generate">

                <div class="payroll-field">
                    <label for="month">Payroll month</label>

                    <select name="month" id="month" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>"
                                <?= $m === (int)date('n') ? 'selected' : '' ?>>
                                <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="payroll-field">
                    <label for="year">Year</label>

                    <input type="number"
                           name="year"
                           id="year"
                           min="2000"
                           max="2100"
                           value="<?= date('Y') ?>"
                           required>
                </div>

                <div class="payroll-field">
                    <label for="cutoff">Cutoff period</label>

                    <select name="cutoff" id="cutoff" required>
                        <option value="first">First cutoff · 1–15</option>
                        <option value="second">Second cutoff · 16–End of month</option>
                    </select>
                </div>

                <button type="submit" class="payroll-button">
                    Generate payroll
                </button>

            </form>

            <p class="payroll-hint">
                The employee's monthly basic salary and statutory contributions are allocated across the two cutoffs.
                Duplicate payroll records are skipped. Adjust Allowances, Overtime, and Other Deductions while a record is pending.
            </p>

        </section>

    <?php endif; ?>

    <!-- SELECTED PAYSLIP DETAILS -->

    <?php if ($selectedPayroll !== null): ?>

        <section class="payroll-card">

            <div class="payroll-card-heading">
                <div>
                    <h2>Payslip details</h2>
                    <p>Complete salary breakdown and payment information.</p>
                </div>

                <a class="payroll-link" href="payroll.php">
                    Back to records
                </a>
            </div>

            <div class="payroll-detail">

                <div class="payroll-detail-header">
                    <div>
                        <h2>PeopleSuite</h2>
                        <p>Employee payslip</p>
                    </div>

                    <span class="payroll-status <?= strtolower(payroll_escape($selectedPayroll['payment_status'])) ?>">
                        <?= payroll_escape($selectedPayroll['payment_status']) ?>
                    </span>
                </div>

                <div class="payroll-detail-grid">

                    <div class="payroll-detail-item">
                        <span>Employee name</span>
                        <strong>
                            <?= payroll_escape(
                                $selectedPayroll['first_name'] . ' ' .
                                $selectedPayroll['last_name']
                            ) ?>
                        </strong>
                    </div>

                    <div class="payroll-detail-item">
                        <span>Employee code</span>
                        <strong>
                            <?= payroll_escape($selectedPayroll['employee_code']) ?>
                        </strong>
                    </div>

                    <div class="payroll-detail-item">
                        <span>Department</span>
                        <strong>
                            <?= payroll_escape(
                                $selectedPayroll['department_name'] ?? 'Unassigned'
                            ) ?>
                        </strong>
                    </div>

                    <div class="payroll-detail-item">
                        <span>Position</span>
                        <strong>
                            <?= payroll_escape($selectedPayroll['position'] ?? '—') ?>
                        </strong>
                    </div>

                    <div class="payroll-detail-item">
                        <span>Pay period</span>
                        <strong>
                            <?= payroll_escape(date(
                                'M j, Y',
                                strtotime($selectedPayroll['pay_period_start'])
                            )) ?>
                            –
                            <?= payroll_escape(date(
                                'M j, Y',
                                strtotime($selectedPayroll['pay_period_end'])
                            )) ?>
                        </strong>
                    </div>

                    <div class="payroll-detail-item">
                        <span>Payment date</span>
                        <strong>
                            <?= !empty($selectedPayroll['payment_date'])
                                ? payroll_escape(date(
                                    'M j, Y',
                                    strtotime($selectedPayroll['payment_date'])
                                ))
                                : 'Not yet paid' ?>
                        </strong>
                    </div>

                </div>

                <table class="payroll-breakdown">
                    <tbody>

                        <tr>
                            <th>Basic salary</th>
                            <td><?= payroll_money($selectedPayroll['basic_salary']) ?></td>
                        </tr>

                        <tr>
                            <th>Allowances</th>
                            <td><?= payroll_money($selectedPayroll['allowances']) ?></td>
                        </tr>

                        <tr>
                            <th>Overtime pay</th>
                            <td><?= payroll_money($selectedPayroll['overtime_pay']) ?></td>
                        </tr>

                        <tr>
                            <th>Gross salary</th>
                            <td><?= payroll_money($selectedPayroll['gross_salary']) ?></td>
                        </tr>

                        <tr>
                            <th>SSS</th>
                            <td><?= payroll_money($selectedPayroll['sss_deduction']) ?></td>
                        </tr>

                        <tr>
                            <th>PhilHealth</th>
                            <td><?= payroll_money($selectedPayroll['philhealth_deduction']) ?></td>
                        </tr>

                        <tr>
                            <th>Pag-IBIG</th>
                            <td><?= payroll_money($selectedPayroll['pagibig_deduction']) ?></td>
                        </tr>

                        <tr>
                            <th>Other deductions</th>
                            <td><?= payroll_money($selectedPayroll['deductions']) ?></td>
                        </tr>

                        <tr>
                            <th>Total deductions</th>
                            <td>
                                <?= payroll_money(
                                    payroll_cents_to_amount(
                                        payroll_total_deductions_cents($selectedPayroll)
                                    )
                                ) ?>
                            </td>
                        </tr>

                        <tr class="net-row">
                            <th>Net salary</th>
                            <td><?= payroll_money($selectedPayroll['net_salary']) ?></td>
                        </tr>

                    </tbody>
                </table>

            </div>
        </section>

    <?php endif; ?>

    <!-- PAYROLL RECORDS -->

    <section class="payroll-card">

        <div class="payroll-record-heading">
            <h2><?= $canManagePayroll ? 'Payroll records' : 'My payslips' ?></h2>

            <p>
                <?= $canManagePayroll
                    ? 'Review employee payroll records and manage pending salary adjustments.'
                    : 'Your salary, allowances, overtime, deductions, and payment history.' ?>
            </p>
        </div>

        <div class="payroll-record-layout" id="payrollRecordLayout">

            <!-- EMPLOYEE TABLE -->

            <div class="payroll-table-wrap">

                <table class="payroll-table">

                    <thead>
                        <tr>

                            <?php if ($canManagePayroll): ?>
                                <th>Employee</th>
                                <th>Department</th>
                            <?php endif; ?>

                            <th>Pay period</th>
                            <th>Basic salary</th>
                            <th>Allowances</th>
                            <th>Overtime pay</th>
                            <th>Gross salary</th>
                            <th>Total deductions</th>
                            <th>Net salary</th>
                            <th>Status</th>
                            <th>Payment date</th>
                            <th>Actions</th>

                        </tr>
                    </thead>

                    <tbody>

                        <?php if ($payrollRecords === []): ?>

                            <tr>
                                <td colspan="<?= $canManagePayroll ? 12 : 10 ?>"
                                    class="payroll-empty">
                                    No payroll records found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($payrollRecords as $record): ?>

                                <tr>

                                    <?php if ($canManagePayroll): ?>

                                        <td>
                                            <span class="payroll-person">
                                                <?= payroll_escape(
                                                    $record['first_name'] . ' ' .
                                                    $record['last_name']
                                                ) ?>
                                            </span>

                                            <span class="payroll-secondary">
                                                <?= payroll_escape($record['employee_code']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= payroll_escape(
                                                $record['department_name'] ?? 'Unassigned'
                                            ) ?>
                                        </td>

                                    <?php endif; ?>

                                    <td>
                                        <?= payroll_escape(date(
                                            'M j',
                                            strtotime($record['pay_period_start'])
                                        )) ?>
                                        –
                                        <?= payroll_escape(date(
                                            'M j, Y',
                                            strtotime($record['pay_period_end'])
                                        )) ?>
                                    </td>

                                    <td>
                                        <?= payroll_money($record['basic_salary']) ?>
                                    </td>

                                    <td>
                                        <?= payroll_money($record['allowances']) ?>
                                    </td>

                                    <td>
                                        <?= payroll_money($record['overtime_pay']) ?>
                                    </td>

                                    <td>
                                        <?= payroll_money($record['gross_salary']) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= payroll_money(
                                                payroll_cents_to_amount(
                                                    payroll_total_deductions_cents($record)
                                                )
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= payroll_money($record['net_salary']) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <span class="payroll-status <?= strtolower(payroll_escape($record['payment_status'])) ?>">
                                            <?= payroll_escape($record['payment_status']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= !empty($record['payment_date'])
                                            ? payroll_escape(date(
                                                'M j, Y',
                                                strtotime($record['payment_date'])
                                            ))
                                            : '—' ?>
                                    </td>

                                    <td>

                                        <div class="payroll-actions">

                                            <a class="payroll-link"
                                               href="payroll.php?view=<?= (int)$record['payroll_id'] ?>">
                                                View payslip
                                            </a>

                                            <a class="payroll-link"
                                               href="payroll-details.php?id=<?= (int)$record['payroll_id'] ?>">
                                                Payroll details
                                            </a>

                                            <?php if (
                                                $canManagePayroll &&
                                                $record['payment_status'] === 'Pending'
                                            ): ?>

                                                <!-- ADJUST BUTTON -->

                                                <button
                                                    type="button"
                                                    class="payroll-adjust-button"
                                                    data-payroll-id="<?= (int)$record['payroll_id'] ?>"
                                                    data-employee="<?= payroll_escape(
                                                        $record['first_name'] . ' ' .
                                                        $record['last_name']
                                                    ) ?>"
                                                    data-allowances="<?= payroll_escape($record['allowances']) ?>"
                                                    data-overtime="<?= payroll_escape($record['overtime_pay']) ?>"
                                                    data-deductions="<?= payroll_escape($record['deductions']) ?>"
                                                >
                                                    Adjust
                                                </button>

                                                <!-- MARK AS PAID -->

                                                <form method="post"
                                                      onsubmit="return confirm('Are you sure you want to mark this payroll as paid?');">

                                                    <input type="hidden"
                                                           name="csrf_token"
                                                           value="<?= payroll_escape($csrfToken) ?>">

                                                    <input type="hidden"
                                                           name="action"
                                                           value="mark_paid">

                                                    <input type="hidden"
                                                           name="payroll_id"
                                                           value="<?= (int)$record['payroll_id'] ?>">

                                                    <button type="submit"
                                                            class="payroll-button paid-button">
                                                        Mark paid
                                                    </button>

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

            <!-- SHARED ADJUSTMENT PANEL -->

            <?php if ($canManagePayroll): ?>

                <aside class="payroll-adjustment-panel"
                       id="payrollAdjustmentPanel">

                    <div class="payroll-adjustment-header">
                        <div>
                            <h3>Payroll adjustment</h3>
                            <p>Update the selected employee's pending payroll.</p>
                        </div>

                        <button type="button"
                                class="payroll-adjustment-close"
                                id="closeAdjustmentPanel"
                                aria-label="Close adjustment panel">
                            &times;
                        </button>
                    </div>

                    <div class="payroll-adjustment-employee">
                        <span>Selected employee</span>
                        <strong id="adjustmentEmployeeName">No employee selected</strong>
                    </div>

                    <form method="post" id="payrollAdjustmentForm">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= payroll_escape($csrfToken) ?>">

                        <input type="hidden"
                               name="action"
                               value="update_amounts">

                        <input type="hidden"
                               name="payroll_id"
                               id="adjustmentPayrollId">

                        <div class="payroll-field">
                            <label for="adjustmentAllowances">
                                Allowances (₱)
                            </label>

                            <input type="number"
                                   name="allowances"
                                   id="adjustmentAllowances"
                                   min="0"
                                   step="0.01"
                                   value="0.00"
                                   required>
                        </div>

                        <div class="payroll-field">
                            <label for="adjustmentOvertime">
                                Overtime pay (₱)
                            </label>

                            <input type="number"
                                   name="overtime_pay"
                                   id="adjustmentOvertime"
                                   min="0"
                                   step="0.01"
                                   value="0.00"
                                   required>
                        </div>

                        <div class="payroll-field">
                            <label for="adjustmentDeductions">
                                Other deductions (₱)
                            </label>

                            <input type="number"
                                   name="deductions"
                                   id="adjustmentDeductions"
                                   min="0"
                                   step="0.01"
                                   value="0.00"
                                   required>
                        </div>

                        <button type="submit" class="payroll-button">
                            Save adjustments
                        </button>

                        <p class="payroll-hint">
                            Statutory deductions such as SSS, PhilHealth, and Pag-IBIG
                            are calculated separately and are not edited here.
                        </p>

                    </form>

                </aside>

            <?php endif; ?>

        </div>

    </section>

</div>

<?php if ($canManagePayroll): ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const layout = document.getElementById('payrollRecordLayout');
    const panel = document.getElementById('payrollAdjustmentPanel');
    const closeButton = document.getElementById('closeAdjustmentPanel');
    const form = document.getElementById('payrollAdjustmentForm');

    const employeeName = document.getElementById('adjustmentEmployeeName');
    const payrollId = document.getElementById('adjustmentPayrollId');
    const allowances = document.getElementById('adjustmentAllowances');
    const overtime = document.getElementById('adjustmentOvertime');
    const deductions = document.getElementById('adjustmentDeductions');

    if (!layout || !panel || !form) {
        return;
    }

    // Open the adjustment panel for the selected employee.
    document.querySelectorAll('.payroll-adjust-button').forEach(function (button) {

        button.addEventListener('click', function () {

            payrollId.value = button.dataset.payrollId || '';
            employeeName.textContent = button.dataset.employee || 'Selected employee';

            allowances.value = button.dataset.allowances || '0.00';
            overtime.value = button.dataset.overtime || '0.00';
            deductions.value = button.dataset.deductions || '0.00';

            panel.classList.add('is-open');
            layout.classList.add('has-adjustment');

            // Bring the panel into view.
            panel.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        });

    });

    // Close the adjustment panel.
    if (closeButton) {
        closeButton.addEventListener('click', function () {

            panel.classList.remove('is-open');
            layout.classList.remove('has-adjustment');

        });
    }

});
</script>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
