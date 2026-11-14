<?php
require __DIR__ . '/includes/auth.php';
auth_require_roles(['Admin', 'HR', 'Employee']);
require __DIR__ . '/config/db.php';

$user = auth_current_user();
$pageTitle = 'Attendance';
$activePage = 'attendance';
$today = date('Y-m-d');
$statuses = ['Present', 'Late', 'Absent', 'Half-day', 'On Leave'];

if ($user['role'] === 'Employee') {
    $employeeId = filter_var($user['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($employeeId === false) {
        http_response_code(403);
        $pageError = 'This account is not linked to an employee record. Contact HR for assistance.';
        require __DIR__ . '/includes/header.php';
        require __DIR__ . '/includes/sidebar.php';
        ?>
        <div class="alert alert-error" role="alert"><?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php
        require __DIR__ . '/includes/footer.php';
        exit;
    }

    $notice = $_SESSION['attendance_notice'] ?? null;
    unset($_SESSION['attendance_notice']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $message = 'Unable to update attendance. Please try again.';
        $messageType = 'error';

        if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
            $message = 'Your session expired. Please reload the page and try again.';
        } else {
            $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
            try {
                if ($action === 'time_in') {
                    $insert = $pdo->prepare(
                        "INSERT INTO attendance (employee_id, attendance_date, time_in, attendance_status)
                         VALUES (:employee_id, :attendance_date, CURRENT_TIME(), 'Present')"
                    );
                    $insert->execute([
                        ':employee_id' => $employeeId,
                        ':attendance_date' => $today,
                    ]);
                    $message = 'Time-in recorded successfully.';
                    $messageType = 'success';
                } elseif ($action === 'time_out') {
                    $update = $pdo->prepare(
                        'UPDATE attendance
                         SET time_out = CURRENT_TIME()
                         WHERE employee_id = :employee_id
                           AND attendance_date = :attendance_date
                           AND time_in IS NOT NULL
                           AND time_out IS NULL
                           AND time_in <= CURRENT_TIME()'
                    );
                    $update->execute([
                        ':employee_id' => $employeeId,
                        ':attendance_date' => $today,
                    ]);

                    if ($update->rowCount() === 1) {
                        $message = 'Time-out recorded successfully.';
                        $messageType = 'success';
                    } else {
                        $current = $pdo->prepare(
                            'SELECT time_in, time_out FROM attendance
                             WHERE employee_id = :employee_id AND attendance_date = :attendance_date'
                        );
                        $current->execute([
                            ':employee_id' => $employeeId,
                            ':attendance_date' => $today,
                        ]);
                        $record = $current->fetch();
                        $message = $record === false
                            ? 'Record your time-in before recording time-out.'
                            : ($record['time_out'] !== null
                                ? 'Time-out has already been recorded for today.'
                                : 'Time-out cannot be earlier than time-in.');
                    }
                } else {
                    $message = 'Invalid attendance action.';
                }
            } catch (PDOException $e) {
                error_log('Employee attendance update failed: ' . $e->getMessage());
                if ($action === 'time_in' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                    $message = 'Time-in has already been recorded for today.';
                } else {
                    $message = 'Attendance could not be updated. Please try again.';
                }
            }
        }

        $_SESSION['attendance_notice'] = ['type' => $messageType, 'message' => $message];
        header('Location: attendance.php');
        exit;
    }

    try {
        $todayQuery = $pdo->prepare(
            'SELECT attendance_id, time_in, time_out, attendance_status
             FROM attendance
             WHERE employee_id = :employee_id AND attendance_date = :attendance_date'
        );
        $todayQuery->execute([
            ':employee_id' => $employeeId,
            ':attendance_date' => $today,
        ]);
        $todayRecord = $todayQuery->fetch();

        $historyQuery = $pdo->prepare(
            'SELECT attendance_date, time_in, time_out, attendance_status, remarks
             FROM attendance
             WHERE employee_id = :employee_id
             ORDER BY attendance_date DESC
             LIMIT 30'
        );
        $historyQuery->execute([':employee_id' => $employeeId]);
        $history = $historyQuery->fetchAll();
        $loadError = null;
    } catch (PDOException $e) {
        error_log('Employee attendance history failed: ' . $e->getMessage());
        $todayRecord = false;
        $history = [];
        $loadError = 'Attendance information could not be loaded. Please try again later.';
    }

    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/sidebar.php';
    ?>
    <section class="page-heading">
        <div>
            <p class="eyebrow">MY WORKSPACE</p>
            <h1>My attendance</h1>
            <p class="page-description">Record today’s time and review your attendance history.</p>
        </div>
        <div class="date-chip"><?= htmlspecialchars(date('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?></div>
    </section>
    <?php if ($notice !== null): ?>
        <div class="alert <?= $notice['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" role="status"><?= htmlspecialchars($notice['message'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($loadError !== null): ?>
        <div class="alert alert-error" role="alert"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
        <section class="panel attendance-clock">
            <div class="attendance-clock-copy">
                <p class="eyebrow">TODAY</p>
                <?php if ($todayRecord === false): ?>
                    <h2>Ready to start your day?</h2>
                    <p>No time-in has been recorded for today.</p>
                <?php elseif ($todayRecord['time_out'] === null): ?>
                    <h2>You’re currently clocked in</h2>
                    <p>Time-in: <?= htmlspecialchars($todayRecord['time_in'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                <?php else: ?>
                    <h2>Attendance recorded for today</h2>
                    <p><?= htmlspecialchars($todayRecord['time_in'] ?? '—', ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars($todayRecord['time_out'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <div class="attendance-clock-actions">
                <?php if ($todayRecord === false): ?>
                    <form method="post" action="attendance.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="time_in">
                        <button class="button button-primary" type="submit">Time in</button>
                    </form>
                <?php elseif ($todayRecord['time_out'] === null): ?>
                    <form method="post" action="attendance.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="time_out">
                        <button class="button button-primary" type="submit">Time out</button>
                    </form>
                <?php else: ?>
                    <span class="status-badge">Day complete</span>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel employee-panel attendance-history">
            <div class="panel-heading">
                <div><h2>Attendance history</h2><p>Your 30 most recent records</p></div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Time in</th>
                            <th scope="col">Time out</th>
                            <th scope="col">Status</th>
                            <th scope="col">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($history === []): ?>
                            <tr><td class="empty-state" colspan="5">No attendance records yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($history as $record): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($record['attendance_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($record['time_in'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($record['time_out'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($record['attendance_status'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="department-description"><?= nl2br(htmlspecialchars($record['remarks'] ?? '—', ENT_QUOTES, 'UTF-8')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$notice = $_SESSION['attendance_notice'] ?? null;
unset($_SESSION['attendance_notice']);
$errorMessage = null;
$records = [];
$approvedLeaves = [];
$totalPages = 1;
$perPage = 20;
$search = isset($_GET['search']) && is_string($_GET['search']) ? substr(trim($_GET['search']), 0, 100) : '';
$dateFrom = isset($_GET['date_from']) && is_string($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) && is_string($_GET['date_to']) ? $_GET['date_to'] : '';
$departmentFilter = isset($_GET['department_id']) && is_string($_GET['department_id']) ? $_GET['department_id'] : '';
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$page = isset($_GET['page']) && is_scalar($_GET['page'])
    ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : 1;
$page = $page === false ? 1 : $page;

foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $filterName => $filterValue) {
    if ($filterValue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $filterValue)) {
        $errorMessage = 'Enter valid dates for the attendance filters.';
        break;
    }
    if ($filterValue !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $filterValue);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || $parsed->format('Y-m-d') !== $filterValue
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        ) {
            $errorMessage = 'Enter valid dates for the attendance filters.';
            break;
        }
    }
}
if ($errorMessage === null && $dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $errorMessage = 'The start date must be on or before the end date.';
}
if ($statusFilter !== '' && !in_array($statusFilter, $statuses, true)) {
    $statusFilter = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $returnUrl = 'attendance.php?' . http_build_query([
        'search' => $search,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'department_id' => $departmentFilter,
        'status' => $statusFilter,
        'page' => $page,
    ]);

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $_SESSION['attendance_notice'] = ['type' => 'error', 'message' => 'Your session expired. Please try again.'];
        header('Location: ' . $returnUrl);
        exit;
    }
    if ($action !== 'correct') {
        http_response_code(400);
        $errorMessage = 'Invalid attendance action.';
    } else {
        $attendanceId = filter_var($_POST['attendance_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $recordDate = isset($_POST['attendance_date']) && is_string($_POST['attendance_date']) ? trim($_POST['attendance_date']) : '';
        $timeIn = isset($_POST['time_in']) && is_string($_POST['time_in']) ? trim($_POST['time_in']) : '';
        $timeOut = isset($_POST['time_out']) && is_string($_POST['time_out']) ? trim($_POST['time_out']) : '';
        $status = isset($_POST['attendance_status']) && is_string($_POST['attendance_status']) ? $_POST['attendance_status'] : '';
        $reason = isset($_POST['correction_reason']) && is_string($_POST['correction_reason']) ? trim($_POST['correction_reason']) : '';
        $remarks = isset($_POST['remarks']) && is_string($_POST['remarks']) ? trim($_POST['remarks']) : '';
        $message = null;

        $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $recordDate) === 1;
        if ($validDate) {
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $recordDate);
            $dateErrors = DateTimeImmutable::getLastErrors();
            $validDate = $parsedDate !== false
                && $parsedDate->format('Y-m-d') === $recordDate
                && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));
        }
        $validTime = static function (string $value): bool {
            if ($value === '') {
                return true;
            }
            if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/D', $value)) {
                return false;
            }
            return strtotime($value) !== false;
        };
        $normalizedTimeIn = $timeIn !== '' && strlen($timeIn) === 5 ? $timeIn . ':00' : $timeIn;
        $normalizedTimeOut = $timeOut !== '' && strlen($timeOut) === 5 ? $timeOut . ':00' : $timeOut;
        $correctionLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
        $remarksLength = function_exists('mb_strlen') ? mb_strlen($remarks, 'UTF-8') : strlen($remarks);
        $storedRemarks = 'Correction: ' . $reason . ($remarks !== '' ? ' | ' . $remarks : '');
        $storedLength = function_exists('mb_strlen') ? mb_strlen($storedRemarks, 'UTF-8') : strlen($storedRemarks);

        if ($attendanceId === false || !$validDate || !in_array($status, $statuses, true)
            || !$validTime($timeIn) || !$validTime($timeOut)
        ) {
            $message = 'Enter a valid attendance date, time, and status.';
        } elseif ($normalizedTimeIn !== '' && $normalizedTimeOut !== '' && $normalizedTimeOut < $normalizedTimeIn) {
            $message = 'Time-out cannot be earlier than time-in.';
        } elseif ($correctionLength === 0) {
            $message = 'A correction reason is required.';
        } elseif ($correctionLength > 200 || $remarksLength > 255 || $storedLength > 255) {
            $message = 'The correction reason and remarks must fit within the 255-character attendance remarks field.';
        } else {
            try {
                $pdo->beginTransaction();
                $lock = $pdo->prepare(
                    'SELECT employee_id FROM attendance WHERE attendance_id = :attendance_id FOR UPDATE'
                );
                $lock->execute([':attendance_id' => $attendanceId]);
                $targetEmployeeId = $lock->fetchColumn();
                if ($targetEmployeeId === false) {
                    throw new RuntimeException('The attendance record no longer exists.');
                }
                $update = $pdo->prepare(
                    'UPDATE attendance
                     SET attendance_date = :attendance_date,
                         time_in = :time_in,
                         time_out = :time_out,
                         attendance_status = :attendance_status,
                         remarks = :remarks
                     WHERE attendance_id = :attendance_id'
                );
                $update->execute([
                    ':attendance_date' => $recordDate,
                    ':time_in' => $normalizedTimeIn !== '' ? $normalizedTimeIn : null,
                    ':time_out' => $normalizedTimeOut !== '' ? $normalizedTimeOut : null,
                    ':attendance_status' => $status,
                    ':remarks' => $storedRemarks,
                    ':attendance_id' => $attendanceId,
                ]);
                audit_record(
                    $pdo,
                    'attendance_corrected',
                    'attendance',
                    (int) $attendanceId,
                    [
                        'employee_id' => (int) $targetEmployeeId,
                        'correction_reason_recorded' => true,
                    ],
                    (int) auth_current_user()['user_id']
                );
                $pdo->commit();
                $_SESSION['attendance_notice'] = [
                    'type' => 'success',
                    'message' => 'Attendance corrected; the correction reason was recorded in remarks.',
                ];
                header('Location: ' . $returnUrl);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Attendance correction failed: ' . $e->getMessage());
                $message = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062
                    ? 'This employee already has an attendance record for that date.'
                    : 'Attendance could not be corrected. Please try again.';
            }
        }

        $_SESSION['attendance_notice'] = ['type' => 'error', 'message' => $message];
        header('Location: ' . $returnUrl);
        exit;
    }
}

try {
    $departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll();
    $where = [];
    $parameters = [];
    if ($search !== '') {
        $where[] = '(e.employee_code LIKE :code_search OR e.first_name LIKE :first_search OR e.last_name LIKE :last_search OR e.email LIKE :email_search)';
        $term = '%' . $search . '%';
        $parameters[':code_search'] = $term;
        $parameters[':first_search'] = $term;
        $parameters[':last_search'] = $term;
        $parameters[':email_search'] = $term;
    }
    if ($dateFrom !== '') {
        $where[] = 'a.attendance_date >= :date_from';
        $parameters[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'a.attendance_date <= :date_to';
        $parameters[':date_to'] = $dateTo;
    }
    if ($departmentFilter !== '' && ctype_digit($departmentFilter)) {
        $where[] = 'e.department_id = :department_id';
        $parameters[':department_id'] = (int) $departmentFilter;
    }
    if ($statusFilter !== '') {
        $where[] = 'a.attendance_status = :attendance_status';
        $parameters[':attendance_status'] = $statusFilter;
    }
    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

    $count = $pdo->prepare(
        'SELECT COUNT(*)
         FROM attendance AS a
         INNER JOIN employees AS e ON e.employee_id = a.employee_id
         LEFT JOIN departments AS d ON d.department_id = e.department_id'
         . $whereSql
    );
    $count->execute($parameters);
    $totalRecords = (int) $count->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRecords / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listing = $pdo->prepare(
        'SELECT a.attendance_id, a.attendance_date, a.time_in, a.time_out,
                a.attendance_status, a.remarks, e.employee_id, e.employee_code,
                e.first_name, e.last_name, d.department_name
         FROM attendance AS a
         INNER JOIN employees AS e ON e.employee_id = a.employee_id
         LEFT JOIN departments AS d ON d.department_id = e.department_id'
         . $whereSql .
        ' ORDER BY a.attendance_date DESC, a.attendance_id DESC
          LIMIT :limit OFFSET :offset'
    );
    foreach ($parameters as $name => $value) {
        $listing->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $listing->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listing->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listing->execute();
    $records = $listing->fetchAll();

    $leaveWhere = ["lr.leave_status = 'Approved'"];
    $leaveParameters = [];
    if ($search !== '') {
        $leaveWhere[] = '(e.employee_code LIKE :leave_code_search OR e.first_name LIKE :leave_first_search OR e.last_name LIKE :leave_last_search OR e.email LIKE :leave_email_search)';
        $leaveTerm = '%' . $search . '%';
        $leaveParameters[':leave_code_search'] = $leaveTerm;
        $leaveParameters[':leave_first_search'] = $leaveTerm;
        $leaveParameters[':leave_last_search'] = $leaveTerm;
        $leaveParameters[':leave_email_search'] = $leaveTerm;
    }
    if ($dateFrom !== '') {
        $leaveWhere[] = 'lr.end_date >= :leave_date_from';
        $leaveParameters[':leave_date_from'] = $dateFrom;
    } else {
        $leaveWhere[] = 'lr.end_date >= CURRENT_DATE()';
    }
    if ($dateTo !== '') {
        $leaveWhere[] = 'lr.start_date <= :leave_date_to';
        $leaveParameters[':leave_date_to'] = $dateTo;
    }
    if ($departmentFilter !== '' && ctype_digit($departmentFilter)) {
        $leaveWhere[] = 'e.department_id = :leave_department_id';
        $leaveParameters[':leave_department_id'] = (int) $departmentFilter;
    }
    $approvedLeaveQuery = $pdo->prepare(
        'SELECT lr.leave_id, lr.leave_type, lr.start_date, lr.end_date,
                e.employee_code, e.first_name, e.last_name, d.department_name
         FROM leave_requests AS lr
         INNER JOIN employees AS e ON e.employee_id = lr.employee_id
         LEFT JOIN departments AS d ON d.department_id = e.department_id
         WHERE ' . implode(' AND ', $leaveWhere) . '
         ORDER BY lr.start_date, e.last_name, e.first_name'
    );
    $approvedLeaveQuery->execute($leaveParameters);
    $approvedLeaves = $approvedLeaveQuery->fetchAll();
} catch (PDOException $e) {
    error_log('Attendance monitoring query failed: ' . $e->getMessage());
    $errorMessage = 'Attendance records could not be loaded. Please try again later.';
    $departments = [];
}

$pageUrl = static function (int $targetPage) use ($search, $dateFrom, $dateTo, $departmentFilter, $statusFilter): string {
    return 'attendance.php?' . http_build_query([
        'search' => $search,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'department_id' => $departmentFilter,
        'status' => $statusFilter,
        'page' => $targetPage,
    ]);
};

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">WORKSPACE</p>
        <h1>Attendance</h1>
        <p class="page-description">Monitor and correct employee attendance records.</p>
    </div>
</section>
<?php if ($notice !== null): ?>
    <div class="alert <?= $notice['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" role="status"><?= htmlspecialchars($notice['message'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="alert alert-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php else: ?>
    <section class="panel employee-panel approved-leave-report">
        <div class="panel-heading">
            <div>
                <h2>Approved leave</h2>
                <p>Approved leave periods are shown separately and do not create or alter attendance records.</p>
            </div>
            <a class="text-link" href="leave.php">Review leave requests</a>
        </div>
        <div class="table-wrap">
            <table class="employee-table">
                <thead>
                    <tr>
                        <th scope="col">Employee</th>
                        <th scope="col">Department</th>
                        <th scope="col">Leave type</th>
                        <th scope="col">Approved dates</th>
                        <th scope="col">Record</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($approvedLeaves === []): ?>
                        <tr><td class="empty-state" colspan="5">No approved leave periods match this date range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($approvedLeaves as $leave): ?>
                            <tr>
                                <td><span class="person-name"><?= htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name'], ENT_QUOTES, 'UTF-8') ?></span><span class="secondary-text"><?= htmlspecialchars($leave['employee_code'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars($leave['department_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($leave['leave_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(date('M j', strtotime($leave['start_date'])), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(date('M j, Y', strtotime($leave['end_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><a class="action-link" href="leave.php?id=<?= (int) $leave['leave_id'] ?>">View leave</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="panel employee-panel">
        <form class="employee-filters attendance-filters" method="get" action="attendance.php">
            <div class="search-field">
                <label class="visually-hidden" for="attendance-search">Search employees</label>
                <input id="attendance-search" type="search" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search name, code, or email">
            </div>
            <div class="filter-field">
                <label class="visually-hidden" for="attendance-from">From date</label>
                <input id="attendance-from" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="filter-field">
                <label class="visually-hidden" for="attendance-to">To date</label>
                <input id="attendance-to" type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="filter-field">
                <label class="visually-hidden" for="department-filter">Department</label>
                <select id="department-filter" name="department_id">
                    <option value="">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['department_id'] ?>" <?= $departmentFilter === (string) $department['department_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($department['department_name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label class="visually-hidden" for="attendance-status-filter">Attendance status</label>
                <select id="attendance-status-filter" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="button button-secondary" type="submit">Filter</button>
            <?php if ($search !== '' || $dateFrom !== '' || $dateTo !== '' || $departmentFilter !== '' || $statusFilter !== ''): ?>
                <a class="button button-quiet" href="attendance.php">Clear</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table class="employee-table">
                <thead>
                    <tr>
                        <th scope="col">Employee</th>
                        <th scope="col">Department</th>
                        <th scope="col">Date</th>
                        <th scope="col">Time in</th>
                        <th scope="col">Time out</th>
                        <th scope="col">Status</th>
                        <th scope="col">Remarks</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($records === []): ?>
                        <tr><td class="empty-state" colspan="8">No attendance records match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td>
                                    <span class="person-name"><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="secondary-text"><?= htmlspecialchars($record['employee_code'], ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td><?= htmlspecialchars($record['department_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($record['attendance_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($record['time_in'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($record['time_out'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="status-badge"><?= htmlspecialchars($record['attendance_status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="department-description"><?= nl2br(htmlspecialchars($record['remarks'] ?? '—', ENT_QUOTES, 'UTF-8')) ?></td>
                                <td><a class="action-link" href="edit-attendance.php?id=<?= (int) $record['attendance_id'] ?>">Correct</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="listing-footer">
            <p><?= number_format($totalRecords) ?> record<?= $totalRecords === 1 ? '' : 's' ?><?= $totalRecords > 0 ? ' · Page ' . $page . ' of ' . $totalPages : '' ?></p>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Attendance pages">
                    <?php if ($page > 1): ?><a class="button button-quiet" href="<?= htmlspecialchars($pageUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a class="button button-quiet" href="<?= htmlspecialchars($pageUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
