<?php
require __DIR__ . '/includes/auth.php';
auth_require_roles(['Employee']);
require __DIR__ . '/config/db.php';

$pageTitle = 'My account';
$activePage = 'my-account';
$user = auth_current_user();
$profile = null;
$attendance = [];
$leaveRequests = [];
$payroll = [];
$loadError = null;

try {
    $profileQuery = $pdo->prepare(
        'SELECT e.employee_id, e.employee_code, e.first_name, e.middle_name, e.last_name,
                e.email, e.phone, e.position, e.hire_date, e.employment_type,
                e.employment_status, d.department_name
         FROM employees AS e
         LEFT JOIN departments AS d ON d.department_id = e.department_id
         WHERE e.employee_id = :employee_id'
    );
    $profileQuery->execute([':employee_id' => $user['employee_id']]);
    $profile = $profileQuery->fetch();

    if ($profile === false) {
        auth_logout();
        header('Location: login.php');
        exit;
    }

    $attendanceQuery = $pdo->prepare(
        'SELECT attendance_date, time_in, time_out, attendance_status, remarks
         FROM attendance
         WHERE employee_id = :employee_id
         ORDER BY attendance_date DESC
         LIMIT 10'
    );
    $attendanceQuery->execute([':employee_id' => $user['employee_id']]);
    $attendance = $attendanceQuery->fetchAll();

    $leaveQuery = $pdo->prepare(
        'SELECT leave_type, start_date, end_date, leave_status, reason
         FROM leave_requests
         WHERE employee_id = :employee_id
         ORDER BY created_at DESC, leave_id DESC
         LIMIT 10'
    );
    $leaveQuery->execute([':employee_id' => $user['employee_id']]);
    $leaveRequests = $leaveQuery->fetchAll();

    $payrollQuery = $pdo->prepare(
        'SELECT pay_period_start, pay_period_end, gross_salary, net_salary,
                payment_status, payment_date
         FROM payroll
         WHERE employee_id = :employee_id
         ORDER BY pay_period_end DESC, payroll_id DESC
         LIMIT 10'
    );
    $payrollQuery->execute([':employee_id' => $user['employee_id']]);
    $payroll = $payrollQuery->fetchAll();
} catch (PDOException $e) {
    error_log('Employee self-service load failed: ' . $e->getMessage());
    $loadError = 'Your account information could not be loaded. Please try again later.';
}

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">MY WORKSPACE</p>
        <h1>My account</h1>
        <p class="page-description">Your profile, attendance, leave, and payroll information.</p>
    </div>
</section>

<?php if ($loadError !== null): ?>
    <div class="alert alert-error" role="alert"><?= $escape($loadError) ?></div>
<?php elseif ($profile !== null): ?>
    <section class="panel self-profile">
        <div class="panel-heading">
            <div>
                <h2><?= $escape(trim($profile['first_name'] . ' ' . ($profile['middle_name'] ?? '') . ' ' . $profile['last_name'])) ?></h2>
                <p><?= $escape($profile['employee_code']) ?> · <?= $escape($profile['position'] ?? 'Position not set') ?></p>
            </div>
            <span class="status-badge status-<?= $escape(strtolower(str_replace(' ', '-', $profile['employment_status']))) ?>"><?= $escape($profile['employment_status']) ?></span>
        </div>
        <div class="profile-details">
            <div><span>Department</span><strong><?= $escape($profile['department_name'] ?? 'Unassigned') ?></strong></div>
            <div><span>Email</span><strong><?= $escape($profile['email']) ?></strong></div>
            <div><span>Phone</span><strong><?= $escape($profile['phone'] ?? '—') ?></strong></div>
            <div><span>Hire date</span><strong><?= $escape(date('M j, Y', strtotime($profile['hire_date']))) ?></strong></div>
            <div><span>Employment type</span><strong><?= $escape($profile['employment_type']) ?></strong></div>
        </div>
    </section>

    <section class="self-service-grid">
        <article class="panel self-service-panel" id="attendance">
            <div class="panel-heading"><div><h2>My attendance</h2><p>Most recent records</p></div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Date</th><th scope="col">Time in</th><th scope="col">Time out</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        <?php if ($attendance === []): ?>
                            <tr><td class="empty-state" colspan="4">No attendance records yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($attendance as $record): ?>
                                <tr>
                                    <td><?= $escape(date('M j, Y', strtotime($record['attendance_date']))) ?></td>
                                    <td><?= $escape($record['time_in'] ?? '—') ?></td>
                                    <td><?= $escape($record['time_out'] ?? '—') ?></td>
                                    <td><?= $escape($record['attendance_status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel self-service-panel" id="leave">
            <div class="panel-heading"><div><h2>My leave</h2><p>Recent leave requests</p></div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Type</th><th scope="col">Dates</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        <?php if ($leaveRequests === []): ?>
                            <tr><td class="empty-state" colspan="3">No leave requests yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($leaveRequests as $request): ?>
                                <tr>
                                    <td><?= $escape($request['leave_type']) ?></td>
                                    <td><?= $escape(date('M j', strtotime($request['start_date']))) ?>–<?= $escape(date('M j, Y', strtotime($request['end_date']))) ?></td>
                                    <td><?= $escape($request['leave_status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel self-service-panel" id="payroll">
            <div class="panel-heading"><div><h2>My payroll</h2><p>Recent personal payroll records</p></div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Pay period</th><th scope="col">Gross</th><th scope="col">Net</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        <?php if ($payroll === []): ?>
                            <tr><td class="empty-state" colspan="4">No payroll records yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payroll as $record): ?>
                                <tr>
                                    <td><?= $escape(date('M j', strtotime($record['pay_period_start']))) ?>–<?= $escape(date('M j, Y', strtotime($record['pay_period_end']))) ?></td>
                                    <td><?= $escape(number_format((float) $record['gross_salary'], 2)) ?></td>
                                    <td><?= $escape(number_format((float) $record['net_salary'], 2)) ?></td>
                                    <td><?= $escape($record['payment_status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
