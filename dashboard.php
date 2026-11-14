<?php
require __DIR__ . '/includes/auth.php';
auth_require_roles(['Admin', 'HR']);
require __DIR__ . '/config/db.php';

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
$dashboardError = null;
$stats = null;
$recentEmployees = [];
$pendingLeaves = [];

try {
    $stats = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM employees) AS total_employees,
            (SELECT COUNT(*) FROM employees WHERE employment_status = 'Active') AS active_employees,
            (SELECT COUNT(*) FROM departments) AS total_departments,
            (SELECT COUNT(*) FROM leave_requests WHERE leave_status = 'Pending') AS pending_leave_requests,
            (SELECT COUNT(*) FROM attendance WHERE attendance_date = CURRENT_DATE()) AS attendance_records_today,
            (SELECT COUNT(*) FROM attendance
             WHERE attendance_date = CURRENT_DATE() AND time_in IS NOT NULL AND time_out IS NULL) AS employees_clocked_in,
            (SELECT COUNT(*) FROM attendance
             WHERE attendance_date = CURRENT_DATE() AND time_out IS NOT NULL) AS employees_clocked_out"
    )->fetch();

    $recentEmployees = $pdo->query(
        "SELECT e.employee_code, e.first_name, e.last_name, e.position,
                e.employment_status, e.hire_date, d.department_name
         FROM employees AS e
         LEFT JOIN departments AS d ON d.department_id = e.department_id
         ORDER BY e.hire_date DESC, e.employee_id DESC
         LIMIT 5"
    )->fetchAll();

    $pendingLeaves = $pdo->query(
        "SELECT lr.leave_type, lr.start_date, lr.end_date,
                e.first_name, e.last_name
         FROM leave_requests AS lr
         INNER JOIN employees AS e ON e.employee_id = lr.employee_id
         WHERE lr.leave_status = 'Pending'
         ORDER BY lr.created_at DESC, lr.leave_id DESC
         LIMIT 5"
    )->fetchAll();
} catch (PDOException $e) {
    error_log('Dashboard query failed: ' . $e->getMessage());
    $dashboardError = 'Dashboard data could not be loaded. Please try again later.';
}

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">OVERVIEW</p>
        <h1>Dashboard</h1>
        <p class="page-description">A snapshot of your people operations.</p>
    </div>
    <div class="date-chip"><?= htmlspecialchars(date('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?></div>
</section>

<?php if ($dashboardError !== null): ?>
    <div class="alert alert-error" role="alert">
        <?= htmlspecialchars($dashboardError, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php else: ?>
    <section class="metric-grid" aria-label="Employee statistics">
        <article class="metric-card">
            <div class="metric-topline">
                <span class="metric-label">Total employees</span>
                <span class="metric-icon icon-violet" aria-hidden="true">EM</span>
            </div>
            <p class="metric-value"><?= number_format((int) $stats['total_employees']) ?></p>
            <p class="metric-note">People in your organization</p>
        </article>
        <article class="metric-card">
            <div class="metric-topline">
                <span class="metric-label">Active employees</span>
                <span class="metric-icon icon-green" aria-hidden="true">AC</span>
            </div>
            <p class="metric-value"><?= number_format((int) $stats['active_employees']) ?></p>
            <p class="metric-note">Currently active</p>
        </article>
        <article class="metric-card">
            <div class="metric-topline">
                <span class="metric-label">Departments</span>
                <span class="metric-icon icon-blue" aria-hidden="true">DP</span>
            </div>
            <p class="metric-value"><?= number_format((int) $stats['total_departments']) ?></p>
            <p class="metric-note">Across the organization</p>
        </article>
        <article class="metric-card">
            <div class="metric-topline">
                <span class="metric-label">Pending leave</span>
                <span class="metric-icon icon-amber" aria-hidden="true">LV</span>
            </div>
            <p class="metric-value"><?= number_format((int) $stats['pending_leave_requests']) ?></p>
            <p class="metric-note">Requests awaiting review</p>
        </article>
    </section>

    <section class="metric-grid attendance-metric-grid" aria-label="Today's attendance statistics">
        <article class="metric-card">
            <div class="metric-topline">
                <span class="metric-label">Attendance today</span>
                <span class="metric-icon icon-blue" aria-hidden="true">AT</span>
            </div>
            <p class="metric-value"><?= number_format((int) $stats['attendance_records_today']) ?></p>
            <p class="metric-note">Records for today</p>
        </article>
        <article class="metric-card">
            <div class="metric-topline">
                <span class="metric-label">Currently clocked in</span>
                <span class="metric-icon icon-green" aria-hidden="true">IN</span>
            </div>
            <p class="metric-value"><?= number_format((int) $stats['employees_clocked_in']) ?></p>
            <p class="metric-note">Time-in recorded, no time-out yet</p>
        </article>
        <article class="metric-card">
            <div class="metric-topline">
                <span class="metric-label">Completed today</span>
                <span class="metric-icon icon-violet" aria-hidden="true">OUT</span>
            </div>
            <p class="metric-value"><?= number_format((int) $stats['employees_clocked_out']) ?></p>
            <p class="metric-note">Time-out recorded</p>
        </article>
    </section>

    <section class="activity-grid" aria-label="Recent HR activity">
        <article class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Recent employees</h2>
                    <p>Latest hires in your organization</p>
                </div>
                <a class="text-link" href="employees.php">View all</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Employee</th>
                            <th scope="col">Department</th>
                            <th scope="col">Position</th>
                            <th scope="col">Status</th>
                            <th scope="col">Hire date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentEmployees === []): ?>
                            <tr>
                                <td class="empty-state" colspan="5">No employees have been added yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentEmployees as $employee): ?>
                                <tr>
                                    <td>
                                        <span class="person-name">
                                            <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <span class="secondary-text"><?= htmlspecialchars($employee['employee_code'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($employee['department_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($employee['position'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower(str_replace(' ', '-', htmlspecialchars($employee['employment_status'], ENT_QUOTES, 'UTF-8'))) ?>">
                                            <?= htmlspecialchars($employee['employment_status'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($employee['hire_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Pending leave requests</h2>
                    <p>Requests that need attention</p>
                </div>
                <a class="text-link" href="leave.php">View all</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Employee</th>
                            <th scope="col">Leave type</th>
                            <th scope="col">Dates</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($pendingLeaves === []): ?>
                            <tr>
                                <td class="empty-state" colspan="3">No pending leave requests.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pendingLeaves as $leave): ?>
                                <tr>
                                    <td class="person-name">
                                        <?= htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td><?= htmlspecialchars($leave['leave_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="date-range">
                                            <?= htmlspecialchars(date('M j', strtotime($leave['start_date'])), ENT_QUOTES, 'UTF-8') ?>
                                            –
                                            <?= htmlspecialchars(date('M j, Y', strtotime($leave['end_date'])), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
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