<?php
require __DIR__ . '/includes/auth.php';
auth_require_roles(['Employee']);
require __DIR__ . '/config/db.php';

$pageTitle = 'My Account';
$activePage = 'my-account';

$user = auth_current_user();

$profile = null;
$attendance = [];
$leaveRequests = [];
$payroll = [];
$loadError = null;

try {
    // Employee profile
    $profileQuery = $pdo->prepare(
        'SELECT e.employee_id, e.employee_code, e.first_name, e.middle_name, e.last_name,
                e.email, e.phone, e.position, e.hire_date, e.employment_type,
                e.employment_status, d.department_name
         FROM employees AS e
         LEFT JOIN departments AS d ON d.department_id = e.department_id
         WHERE e.employee_id = :employee_id'
    );

    $profileQuery->execute([
        ':employee_id' => $user['employee_id']
    ]);

    $profile = $profileQuery->fetch();

    if ($profile === false) {
        auth_logout();
        header('Location: login.php');
        exit;
    }

    // Attendance records
    $attendanceQuery = $pdo->prepare(
        'SELECT attendance_date, time_in, time_out, attendance_status, remarks
         FROM attendance
         WHERE employee_id = :employee_id
         ORDER BY attendance_date DESC
         LIMIT 10'
    );

    $attendanceQuery->execute([
        ':employee_id' => $user['employee_id']
    ]);

    $attendance = $attendanceQuery->fetchAll();

    // Leave requests
    $leaveQuery = $pdo->prepare(
        'SELECT leave_type, start_date, end_date, leave_status, reason
         FROM leave_requests
         WHERE employee_id = :employee_id
         ORDER BY created_at DESC, leave_id DESC
         LIMIT 10'
    );

    $leaveQuery->execute([
        ':employee_id' => $user['employee_id']
    ]);

    $leaveRequests = $leaveQuery->fetchAll();

    // Payroll records
    $payrollQuery = $pdo->prepare(
        'SELECT pay_period_start, pay_period_end, gross_salary, net_salary,
                payment_status, payment_date
         FROM payroll
         WHERE employee_id = :employee_id
         ORDER BY pay_period_end DESC, payroll_id DESC
         LIMIT 10'
    );

    $payrollQuery->execute([
        ':employee_id' => $user['employee_id']
    ]);

    $payroll = $payrollQuery->fetchAll();

} catch (PDOException $e) {
    error_log('Employee self-service load failed: ' . $e->getMessage());

    $loadError = 'Your account information could not be loaded. Please try again later.';
}

// Escape output
$escape = static function ($value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
};

// Employee full name
$fullName = $profile
    ? trim(
        $profile['first_name'] . ' ' .
        ($profile['middle_name'] ?? '') . ' ' .
        $profile['last_name']
    )
    : '';

// First letter of employee's first name
$profileInitial = $profile
    ? strtoupper(substr(trim($profile['first_name']), 0, 1))
    : '';

// Employment status class
$statusClass = $profile
    ? strtolower(str_replace(' ', '-', $profile['employment_status'] ?? 'unknown'))
    : 'unknown';

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>

<style>
/* =========================================
   MY ACCOUNT PAGE
========================================= */

.my-account-page {
    width: 100%;
    max-width: 1450px;
    margin: 0 auto;
    padding-bottom: 30px;
}

/* Page heading */

.account-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    gap: 15px;
}

.account-heading .eyebrow {
    color: var(--accent);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.8px;
    margin-bottom: 7px;
}

.account-heading h1 {
    color: var(--text);
    font-size: 28px;
    font-weight: 800;
    margin: 0 0 7px;
}

.account-heading .page-description {
    color: var(--muted);
    font-size: 14px;
    margin: 0;
}

/* =========================================
   PROFILE CARD
========================================= */

.account-profile-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 28px;
    margin-bottom: 25px;
    box-shadow: 0 5px 18px rgba(0, 0, 0, 0.04);
}

.account-profile-top {
    display: flex;
    align-items: center;
    gap: 22px;
    padding-bottom: 25px;
    border-bottom: 1px solid var(--border);
}

/* Profile avatar */

.account-avatar {
    width: 92px;
    height: 92px;
    min-width: 92px;
    border-radius: 50%;
    background: var(--accent);
    color: var(--panel);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 38px;
    font-weight: 800;
    text-transform: uppercase;
    border: 5px solid var(--panel-raised);
    box-shadow: 0 3px 12px rgba(0, 0, 0, 0.08);
}

.account-profile-info {
    flex: 1;
    min-width: 0;
}

.account-profile-info h2 {
    color: var(--text);
    font-size: 23px;
    font-weight: 800;
    margin: 0 0 7px;
}

.account-profile-info p {
    color: var(--muted);
    font-size: 13px;
    margin: 0 0 10px;
}

.account-profile-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.account-code {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
}

/* Status badge */

.account-status {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 30px;
    font-size: 11px;
    font-weight: 800;
    text-transform: capitalize;
    background: var(--panel-raised);
    color: var(--text);
}

.account-status.status-active {
    background: rgba(34, 160, 100, 0.12);
    color: #218653;
}

.account-status.status-inactive {
    background: rgba(220, 70, 70, 0.12);
    color: #c34242;
}

/* Profile details */

.account-details-heading {
    color: var(--text);
    font-size: 14px;
    font-weight: 800;
    margin: 22px 0 17px;
}

.account-details-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px 25px;
}

.account-detail-item {
    min-width: 0;
}

.account-detail-item span {
    display: block;
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    margin-bottom: 7px;
}

.account-detail-item strong {
    display: block;
    color: var(--text);
    font-size: 13px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

/* =========================================
   INFORMATION SECTIONS
========================================= */

.account-sections {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px;
}

.account-panel {
    min-width: 0;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 22px;
    box-shadow: 0 5px 18px rgba(0, 0, 0, 0.035);
}

.account-panel.payroll-panel {
    grid-column: 1 / -1;
}

.account-panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}

.account-panel-heading h2 {
    color: var(--text);
    font-size: 16px;
    font-weight: 800;
    margin: 0 0 5px;
}

.account-panel-heading p {
    color: var(--muted);
    font-size: 12px;
    margin: 0;
}

.account-panel-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0 9px;
    border-radius: 9px;
    background: var(--panel-raised);
    color: var(--text);
    font-size: 12px;
    font-weight: 800;
}

/* =========================================
   TABLES
========================================= */

.account-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.account-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 430px;
}

.account-table th {
    color: var(--muted);
    background: var(--panel-raised);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    text-align: left;
    padding: 13px 12px;
    white-space: nowrap;
}

.account-table th:first-child {
    border-radius: 8px 0 0 8px;
}

.account-table th:last-child {
    border-radius: 0 8px 8px 0;
}

.account-table td {
    color: var(--text);
    font-size: 12px;
    font-weight: 500;
    padding: 15px 12px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}

.account-table tbody tr:last-child td {
    border-bottom: none;
}

.account-table tbody tr:hover {
    background: var(--panel-raised);
}

/* Empty state */

.account-empty-state {
    text-align: center !important;
    color: var(--muted) !important;
    padding: 30px 15px !important;
    font-size: 12px !important;
}

/* General status labels */

.account-record-status {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    background: var(--panel-raised);
    color: var(--text);
    font-size: 10px;
    font-weight: 800;
}

/* =========================================
   ERROR MESSAGE
========================================= */

.account-error {
    background: rgba(220, 70, 70, 0.09);
    border: 1px solid rgba(220, 70, 70, 0.25);
    color: #c34242;
    border-radius: 12px;
    padding: 16px 18px;
    font-size: 13px;
    margin-bottom: 20px;
}

/* =========================================
   RESPONSIVE DESIGN
========================================= */

@media (max-width: 1000px) {
    .account-details-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 750px) {
    .account-sections {
        grid-template-columns: 1fr;
    }

    .account-panel.payroll-panel {
        grid-column: auto;
    }

    .account-profile-card {
        padding: 22px;
    }
}

@media (max-width: 520px) {
    .account-heading h1 {
        font-size: 23px;
    }

    .account-profile-top {
        align-items: flex-start;
        gap: 15px;
    }

    .account-avatar {
        width: 68px;
        height: 68px;
        min-width: 68px;
        font-size: 28px;
    }

    .account-profile-info h2 {
        font-size: 18px;
    }

    .account-details-grid {
        grid-template-columns: 1fr;
        gap: 17px;
    }

    .account-profile-card,
    .account-panel {
        padding: 17px;
    }
}
</style>

<div class="my-account-page">

    <!-- PAGE HEADING -->
    <section class="account-heading">
        <div>
            <p class="eyebrow">EMPLOYEE SELF-SERVICE</p>
            <h1>My Account</h1>
            <p class="page-description">
                View your personal information and employment records.
            </p>
        </div>
    </section>

    <?php if ($loadError !== null): ?>

        <div class="account-error" role="alert">
            <?= $escape($loadError) ?>
        </div>

    <?php elseif ($profile !== null): ?>

        <!-- EMPLOYEE PROFILE -->
        <section class="account-profile-card">

            <div class="account-profile-top">

                <!-- First letter of employee's first name -->
                <div class="account-avatar" aria-label="Employee profile initial">
                    <?= $escape($profileInitial) ?>
                </div>

                <div class="account-profile-info">

                    <h2><?= $escape($fullName) ?></h2>

                    <p>
                        <?= $escape($profile['position'] ?? 'Position not assigned') ?>
                    </p>

                    <div class="account-profile-meta">

                        <span class="account-code">
                            Employee ID: <?= $escape($profile['employee_code']) ?>
                        </span>

                        <span class="account-status status-<?= $escape($statusClass) ?>">
                            <?= $escape($profile['employment_status']) ?>
                        </span>

                    </div>

                </div>

            </div>

            <!-- PROFILE INFORMATION -->
            <h3 class="account-details-heading">
                Personal and Employment Information
            </h3>

            <div class="account-details-grid">

                <div class="account-detail-item">
                    <span>First Name</span>
                    <strong><?= $escape($profile['first_name']) ?></strong>
                </div>

                <div class="account-detail-item">
                    <span>Middle Name</span>
                    <strong><?= $escape($profile['middle_name'] ?: '—') ?></strong>
                </div>

                <div class="account-detail-item">
                    <span>Last Name</span>
                    <strong><?= $escape($profile['last_name']) ?></strong>
                </div>

                <div class="account-detail-item">
                    <span>Email Address</span>
                    <strong><?= $escape($profile['email']) ?></strong>
                </div>

                <div class="account-detail-item">
                    <span>Phone Number</span>
                    <strong><?= $escape($profile['phone'] ?: '—') ?></strong>
                </div>

                <div class="account-detail-item">
                    <span>Department</span>
                    <strong><?= $escape($profile['department_name'] ?? 'Unassigned') ?></strong>
                </div>

                <div class="account-detail-item">
                    <span>Position</span>
                    <strong><?= $escape($profile['position'] ?? 'Not assigned') ?></strong>
                </div>

                <div class="account-detail-item">
                    <span>Hire Date</span>
                    <strong>
                        <?= $escape($profile['hire_date'] ? date('M j, Y', strtotime($profile['hire_date'])) : '—') ?>
                    </strong>
                </div>

                <div class="account-detail-item">
                    <span>Employment Type</span>
                    <strong><?= $escape($profile['employment_type'] ?? '—') ?></strong>
                </div>

            </div>

        </section>

        <!-- EMPLOYEE RECORDS -->
        <section class="account-sections">

            <!-- ATTENDANCE -->
            <article class="account-panel" id="attendance">

                <div class="account-panel-heading">
                    <div>
                        <h2>My Attendance</h2>
                        <p>Your 10 most recent attendance records</p>
                    </div>

                    <span class="account-panel-count">
                        <?= count($attendance) ?>
                    </span>
                </div>

                <div class="account-table-wrap">
                    <table class="account-table">

                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if ($attendance === []): ?>

                                <tr>
                                    <td colspan="4" class="account-empty-state">
                                        No attendance records yet.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($attendance as $record): ?>

                                    <tr>
                                        <td>
                                            <?= $escape(date('M j, Y', strtotime($record['attendance_date']))) ?>
                                        </td>

                                        <td>
                                            <?= $escape($record['time_in'] ?? '—') ?>
                                        </td>

                                        <td>
                                            <?= $escape($record['time_out'] ?? '—') ?>
                                        </td>

                                        <td>
                                            <span class="account-record-status">
                                                <?= $escape($record['attendance_status']) ?>
                                            </span>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>
                    </table>
                </div>

            </article>

            <!-- LEAVE REQUESTS -->
            <article class="account-panel" id="leave">

                <div class="account-panel-heading">
                    <div>
                        <h2>My Leave Requests</h2>
                        <p>Your 10 most recent leave applications</p>
                    </div>

                    <span class="account-panel-count">
                        <?= count($leaveRequests) ?>
                    </span>
                </div>

                <div class="account-table-wrap">
                    <table class="account-table">

                        <thead>
                            <tr>
                                <th>Leave Type</th>
                                <th>Dates</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if ($leaveRequests === []): ?>

                                <tr>
                                    <td colspan="3" class="account-empty-state">
                                        No leave requests yet.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($leaveRequests as $request): ?>

                                    <tr>
                                        <td>
                                            <?= $escape($request['leave_type']) ?>
                                        </td>

                                        <td>
                                            <?= $escape(date('M j', strtotime($request['start_date']))) ?>
                                            –
                                            <?= $escape(date('M j, Y', strtotime($request['end_date']))) ?>
                                        </td>

                                        <td>
                                            <span class="account-record-status">
                                                <?= $escape($request['leave_status']) ?>
                                            </span>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>
                    </table>
                </div>

            </article>

            <!-- PAYROLL -->
            <article class="account-panel payroll-panel" id="payroll">

                <div class="account-panel-heading">
                    <div>
                        <h2>My Payroll</h2>
                        <p>Your 10 most recent salary records</p>
                    </div>

                    <span class="account-panel-count">
                        <?= count($payroll) ?>
                    </span>
                </div>

                <div class="account-table-wrap">
                    <table class="account-table">

                        <thead>
                            <tr>
                                <th>Pay Period</th>
                                <th>Gross Salary</th>
                                <th>Net Salary</th>
                                <th>Payment Status</th>
                                <th>Payment Date</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if ($payroll === []): ?>

                                <tr>
                                    <td colspan="5" class="account-empty-state">
                                        No payroll records yet.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($payroll as $record): ?>

                                    <tr>
                                        <td>
                                            <?= $escape(date('M j', strtotime($record['pay_period_start']))) ?>
                                            –
                                            <?= $escape(date('M j, Y', strtotime($record['pay_period_end']))) ?>
                                        </td>

                                        <td>
                                            <?= $escape(number_format((float) $record['gross_salary'], 2)) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= $escape(number_format((float) $record['net_salary'], 2)) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <span class="account-record-status">
                                                <?= $escape($record['payment_status']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= $escape(
                                                $record['payment_date']
                                                    ? date('M j, Y', strtotime($record['payment_date']))
                                                    : 'Not yet paid'
                                            ) ?>
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

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
