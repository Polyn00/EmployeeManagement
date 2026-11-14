<?php
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
auth_require_roles(['Admin', 'HR', 'Employee']);
require __DIR__ . '/config/db.php';

$user = auth_current_user();
$isEmployee = $user['role'] === 'Employee';
$employeeId = $isEmployee
    ? filter_var($user['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;
$pageTitle = $isEmployee ? 'My leave' : 'Leave requests';
$activePage = 'leave';
$leaveTypes = ['Vacation', 'Sick', 'Emergency', 'Maternity', 'Paternity', 'Other'];
$leaveStatuses = ['Pending', 'Approved', 'Rejected'];
$notice = $_SESSION['leave_notice'] ?? null;
unset($_SESSION['leave_notice']);
$formValues = $_SESSION['leave_form_values'] ?? [
    'leave_type' => 'Vacation',
    'start_date' => '',
    'end_date' => '',
    'reason' => '',
];
unset($_SESSION['leave_form_values']);
$detail = null;
$detailId = null;
$requests = [];
$departments = [];
$totalRecords = 0;
$totalPages = 1;
$page = isset($_GET['page']) && is_scalar($_GET['page'])
    ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : 1;
$page = $page === false ? 1 : $page;
$perPage = 20;
$loadError = null;

if ($isEmployee && $employeeId === false) {
    http_response_code(403);
    $loadError = 'This account is not linked to an employee record. Contact HR for assistance.';
}

$dateIsValid = static function (string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed !== false
        && $parsed->format('Y-m-d') === $date
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
};

if ($loadError === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $noticeType = 'error';
    $message = 'The leave request could not be processed. Please try again.';

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $message = 'Your session expired. Please reload the page and try again.';
    } elseif ($isEmployee && $action === 'submit') {
        $formValues = [
            'leave_type' => isset($_POST['leave_type']) && is_string($_POST['leave_type']) ? trim($_POST['leave_type']) : '',
            'start_date' => isset($_POST['start_date']) && is_string($_POST['start_date']) ? trim($_POST['start_date']) : '',
            'end_date' => isset($_POST['end_date']) && is_string($_POST['end_date']) ? trim($_POST['end_date']) : '',
            'reason' => isset($_POST['reason']) && is_string($_POST['reason']) ? trim($_POST['reason']) : '',
        ];
        $reasonLength = function_exists('mb_strlen')
            ? mb_strlen($formValues['reason'], 'UTF-8')
            : strlen($formValues['reason']);

        if (!in_array($formValues['leave_type'], $leaveTypes, true)
            || !$dateIsValid($formValues['start_date'])
            || !$dateIsValid($formValues['end_date'])
        ) {
            $message = 'Select a valid leave type and enter valid start and end dates.';
        } elseif ($formValues['end_date'] < $formValues['start_date']) {
            $message = 'The end date must be on or after the start date.';
        } elseif ($formValues['reason'] === '' || $reasonLength > 2000) {
            $message = 'Enter a reason of 1 to 2,000 characters.';
        } else {
            try {
                $pdo->beginTransaction();
                $employeeLock = $pdo->prepare(
                    'SELECT employee_id FROM employees WHERE employee_id = :employee_id FOR UPDATE'
                );
                $employeeLock->execute([':employee_id' => $employeeId]);
                if ($employeeLock->fetchColumn() === false) {
                    throw new RuntimeException('The linked employee record no longer exists.');
                }

                $overlap = $pdo->prepare(
                    "SELECT leave_id
                     FROM leave_requests
                     WHERE employee_id = :employee_id
                       AND leave_status = 'Pending'
                       AND start_date <= :end_date
                       AND end_date >= :start_date
                     LIMIT 1"
                );
                $overlap->execute([
                    ':employee_id' => $employeeId,
                    ':end_date' => $formValues['end_date'],
                    ':start_date' => $formValues['start_date'],
                ]);
                if ($overlap->fetchColumn() !== false) {
                    $pdo->rollBack();
                    $message = 'This request overlaps another pending leave request.';
                    $_SESSION['leave_form_values'] = $formValues;
                } else {
                    $insert = $pdo->prepare(
                        "INSERT INTO leave_requests
                            (employee_id, leave_type, start_date, end_date, reason, leave_status)
                         VALUES
                            (:employee_id, :leave_type, :start_date, :end_date, :reason, 'Pending')"
                    );
                    $insert->execute([
                        ':employee_id' => $employeeId,
                        ':leave_type' => $formValues['leave_type'],
                        ':start_date' => $formValues['start_date'],
                        ':end_date' => $formValues['end_date'],
                        ':reason' => $formValues['reason'],
                    ]);
                    $leaveId = (int) $pdo->lastInsertId();
                    audit_record(
                        $pdo,
                        'leave_submitted',
                        'leave_request',
                        $leaveId,
                        [
                            'leave_type' => $formValues['leave_type'],
                            'start_date' => $formValues['start_date'],
                            'end_date' => $formValues['end_date'],
                        ],
                        (int) $user['user_id']
                    );
                    $pdo->commit();
                    $noticeType = 'success';
                    $message = 'Your leave request has been submitted for review.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Leave request submission failed: ' . $e->getMessage());
                $message = 'Your leave request could not be submitted. Please try again later.';
                $_SESSION['leave_form_values'] = $formValues;
            }
        }

        if ($message !== 'Your leave request has been submitted for review.'
            && !isset($_SESSION['leave_form_values'])
        ) {
            $_SESSION['leave_form_values'] = $formValues;
        }
    } elseif (!$isEmployee && $action === 'review') {
        $leaveId = filter_var($_POST['leave_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $newStatus = isset($_POST['leave_status']) && is_string($_POST['leave_status'])
            ? $_POST['leave_status']
            : '';

        if ($leaveId === false || !in_array($newStatus, ['Approved', 'Rejected'], true)) {
            $message = 'Select a valid leave request and review decision.';
        } else {
            try {
                $pdo->beginTransaction();
                $lockRequest = $pdo->prepare(
                    'SELECT leave_id, employee_id, leave_status
                     FROM leave_requests
                     WHERE leave_id = :leave_id
                     FOR UPDATE'
                );
                $lockRequest->execute([':leave_id' => $leaveId]);
                $request = $lockRequest->fetch();

                if ($request === false) {
                    $pdo->rollBack();
                    $message = 'The leave request was not found.';
                } elseif ($request['leave_status'] !== 'Pending') {
                    $pdo->rollBack();
                    $message = 'This leave request has already been reviewed.';
                } elseif (isset($user['employee_id']) && (int) $user['employee_id'] === (int) $request['employee_id']) {
                    $pdo->rollBack();
                    $message = 'You cannot review your own leave request.';
                } else {
                    $review = $pdo->prepare(
                        'UPDATE leave_requests
                         SET leave_status = :leave_status,
                             approved_by = :approved_by,
                             approval_date = NOW()
                         WHERE leave_id = :leave_id
                           AND leave_status = \'Pending\''
                    );
                    $review->execute([
                        ':leave_status' => $newStatus,
                        ':approved_by' => (int) $user['user_id'],
                        ':leave_id' => $leaveId,
                    ]);
                    if ($review->rowCount() !== 1) {
                        throw new RuntimeException('The leave request changed while being reviewed.');
                    }
                    audit_record(
                        $pdo,
                        'leave_' . strtolower($newStatus),
                        'leave_request',
                        (int) $leaveId,
                        ['previous_status' => 'Pending', 'new_status' => $newStatus],
                        (int) $user['user_id']
                    );
                    $pdo->commit();
                    $noticeType = 'success';
                    $message = 'Leave request ' . strtolower($newStatus) . ' successfully.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Leave request review failed: ' . $e->getMessage());
                $message = 'The leave request could not be reviewed. Please try again later.';
            }
        }
    } else {
        http_response_code(400);
        $message = 'Invalid leave request action.';
    }

    $_SESSION['leave_notice'] = ['type' => $noticeType, 'message' => $message];
    header('Location: leave.php');
    exit;
}

$search = isset($_GET['search']) && is_string($_GET['search']) ? substr(trim($_GET['search']), 0, 100) : '';
$departmentFilter = isset($_GET['department_id']) && is_string($_GET['department_id']) ? $_GET['department_id'] : '';
$typeFilter = isset($_GET['leave_type']) && is_string($_GET['leave_type']) ? $_GET['leave_type'] : '';
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
if (!in_array($typeFilter, $leaveTypes, true)) {
    $typeFilter = '';
}
if (!in_array($statusFilter, $leaveStatuses, true)) {
    $statusFilter = '';
}

if ($loadError === null) {
    try {
        if (!$isEmployee) {
            $departments = $pdo->query(
                'SELECT department_id, department_name FROM departments ORDER BY department_name'
            )->fetchAll();
        }

        $detailId = isset($_GET['id']) && is_scalar($_GET['id'])
            ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : null;
        if ($detailId !== null && $detailId !== false) {
            $detailSql = 'SELECT lr.leave_id, lr.employee_id, lr.leave_type, lr.start_date,
                                 lr.end_date, lr.reason, lr.leave_status, lr.created_at,
                                 lr.approval_date, u.username AS reviewer_username,
                                 e.employee_code, e.first_name, e.last_name,
                                 d.department_name
                          FROM leave_requests AS lr
                          INNER JOIN employees AS e ON e.employee_id = lr.employee_id
                          LEFT JOIN departments AS d ON d.department_id = e.department_id
                          LEFT JOIN users AS u ON u.user_id = lr.approved_by
                          WHERE lr.leave_id = :leave_id';
            $detailParameters = [':leave_id' => $detailId];
            if ($isEmployee) {
                $detailSql .= ' AND lr.employee_id = :employee_id';
                $detailParameters[':employee_id'] = $employeeId;
            }
            $detailQuery = $pdo->prepare($detailSql);
            $detailQuery->execute($detailParameters);
            $detail = $detailQuery->fetch() ?: null;
            if ($detail === null) {
                http_response_code(404);
            }
        }

        if ($isEmployee) {
            $count = $pdo->prepare('SELECT COUNT(*) FROM leave_requests WHERE employee_id = :employee_id');
            $count->execute([':employee_id' => $employeeId]);
            $totalRecords = (int) $count->fetchColumn();
            $totalPages = max(1, (int) ceil($totalRecords / $perPage));
            $page = min($page, $totalPages);
            $listing = $pdo->prepare(
                'SELECT lr.leave_id, lr.leave_type, lr.start_date, lr.end_date,
                        lr.reason, lr.leave_status, lr.created_at,
                        lr.approval_date, u.username AS reviewer_username
                 FROM leave_requests AS lr
                 LEFT JOIN users AS u ON u.user_id = lr.approved_by
                 WHERE lr.employee_id = :employee_id
                 ORDER BY lr.created_at DESC, lr.leave_id DESC
                 LIMIT :limit OFFSET :offset'
            );
            $listing->bindValue(':employee_id', (int) $employeeId, PDO::PARAM_INT);
        } else {
            $where = [];
            $parameters = [];
            if ($search !== '') {
                $where[] = '(e.first_name LIKE :first_search OR e.last_name LIKE :last_search OR e.employee_code LIKE :code_search)';
                $searchTerm = '%' . $search . '%';
                $parameters[':first_search'] = $searchTerm;
                $parameters[':last_search'] = $searchTerm;
                $parameters[':code_search'] = $searchTerm;
            }
            if ($departmentFilter !== '' && ctype_digit($departmentFilter)) {
                $where[] = 'e.department_id = :department_id';
                $parameters[':department_id'] = (int) $departmentFilter;
            }
            if ($typeFilter !== '') {
                $where[] = 'lr.leave_type = :leave_type';
                $parameters[':leave_type'] = $typeFilter;
            }
            if ($statusFilter !== '') {
                $where[] = 'lr.leave_status = :leave_status';
                $parameters[':leave_status'] = $statusFilter;
            }
            $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
            $count = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM leave_requests AS lr
                 INNER JOIN employees AS e ON e.employee_id = lr.employee_id'
                 . $whereSql
            );
            $count->execute($parameters);
            $totalRecords = (int) $count->fetchColumn();
            $totalPages = max(1, (int) ceil($totalRecords / $perPage));
            $page = min($page, $totalPages);
            $listing = $pdo->prepare(
                'SELECT lr.leave_id, lr.employee_id, lr.leave_type, lr.start_date,
                        lr.end_date, lr.reason, lr.leave_status, lr.created_at,
                        lr.approval_date, u.username AS reviewer_username,
                        e.employee_code, e.first_name, e.last_name, d.department_name
                 FROM leave_requests AS lr
                 INNER JOIN employees AS e ON e.employee_id = lr.employee_id
                 LEFT JOIN departments AS d ON d.department_id = e.department_id
                 LEFT JOIN users AS u ON u.user_id = lr.approved_by'
                 . $whereSql .
                ' ORDER BY lr.created_at DESC, lr.leave_id DESC
                  LIMIT :limit OFFSET :offset'
            );
            foreach ($parameters as $name => $value) {
                $listing->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
        }

        $listing->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $listing->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $listing->execute();
        $requests = $listing->fetchAll();
    } catch (PDOException $e) {
        error_log('Leave request listing failed: ' . $e->getMessage());
        $loadError = 'Leave requests could not be loaded. Please try again later.';
    }
}

$pageUrl = static function (int $targetPage) use ($isEmployee, $search, $departmentFilter, $typeFilter, $statusFilter): string {
    return 'leave.php?' . http_build_query([
        'search' => $isEmployee ? null : $search,
        'department_id' => $isEmployee ? null : $departmentFilter,
        'leave_type' => $isEmployee ? null : $typeFilter,
        'status' => $isEmployee ? null : $statusFilter,
        'page' => $targetPage,
    ]);
};
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow"><?= $isEmployee ? 'MY WORKSPACE' : 'WORKSPACE' ?></p>
        <h1><?= $isEmployee ? 'My leave' : 'Leave requests' ?></h1>
        <p class="page-description"><?= $isEmployee ? 'Submit leave and track your requests.' : 'Review leave requests submitted by employees.' ?></p>
    </div>
</section>

<?php if ($notice !== null): ?>
    <div class="alert <?= ($notice['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="status"><?= $escape($notice['message'] ?? '') ?></div>
<?php endif; ?>
<?php if ($loadError !== null): ?>
    <div class="alert alert-error" role="alert"><?= $escape($loadError) ?></div>
<?php endif; ?>
<?php if ($detailId !== null && $detailId !== false && $detail === null): ?>
    <div class="alert alert-error" role="alert">The requested leave record was not found or is not available to your account.</div>
<?php elseif ($detail !== null): ?>
    <section class="panel leave-detail-panel">
        <div class="panel-heading">
            <div>
                <h2>Leave request details</h2>
                <p><?= $escape($detail['employee_code'] . ' · ' . $detail['first_name'] . ' ' . $detail['last_name']) ?><?= $detail['department_name'] !== null ? ' · ' . $escape($detail['department_name']) : '' ?></p>
            </div>
            <span class="status-badge leave-status-<?= $escape(strtolower($detail['leave_status'])) ?>"><?= $escape($detail['leave_status']) ?></span>
        </div>
        <dl class="leave-detail-grid">
            <div><dt>Leave type</dt><dd><?= $escape($detail['leave_type']) ?></dd></div>
            <div><dt>Dates</dt><dd><?= $escape(date('M j, Y', strtotime($detail['start_date']))) ?> – <?= $escape(date('M j, Y', strtotime($detail['end_date']))) ?></dd></div>
            <div><dt>Submitted</dt><dd><?= $escape(date('M j, Y g:i A', strtotime($detail['created_at']))) ?></dd></div>
            <div><dt>Reviewed by</dt><dd><?= $escape($detail['reviewer_username'] ?? 'Not reviewed') ?></dd></div>
            <div><dt>Review date</dt><dd><?= $detail['approval_date'] !== null ? $escape(date('M j, Y g:i A', strtotime($detail['approval_date']))) : '—' ?></dd></div>
            <div class="leave-reason"><dt>Reason</dt><dd><?= nl2br($escape($detail['reason'] ?? 'No reason provided.')) ?></dd></div>
        </dl>
        <?php if (!$isEmployee && $detail['leave_status'] === 'Pending'): ?>
            <?php if (isset($user['employee_id']) && (int) $user['employee_id'] === (int) $detail['employee_id']): ?>
                <div class="alert alert-error leave-self-review-warning" role="status">You cannot review your own leave request.</div>
            <?php else: ?>
                <div class="leave-review-actions">
                    <?php foreach (['Approved', 'Rejected'] as $decision): ?>
                        <form method="post" action="leave.php">
                            <input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>">
                            <input type="hidden" name="action" value="review">
                            <input type="hidden" name="leave_id" value="<?= (int) $detail['leave_id'] ?>">
                            <input type="hidden" name="leave_status" value="<?= $escape($decision) ?>">
                            <button class="button <?= $decision === 'Approved' ? 'button-primary' : 'button-danger' ?>" type="submit" onclick="return confirm('<?= $decision === 'Approved' ? 'Approve' : 'Reject' ?> this leave request?')"><?= $decision === 'Approved' ? 'Approve request' : 'Reject request' ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="leave-detail-footer"><a class="button button-quiet" href="leave.php">Back to leave requests</a></div>
    </section>
<?php endif; ?>

<?php if ($isEmployee && $loadError === null): ?>
    <section class="panel employee-panel leave-form-panel">
        <div class="panel-heading"><div><h2>Submit a leave request</h2><p>Requests that overlap another pending request will be blocked.</p></div></div>
        <form class="employee-form" method="post" action="leave.php">
            <input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>">
            <input type="hidden" name="action" value="submit">
            <div class="form-grid">
                <div class="form-field">
                    <label for="leave_type">Leave type</label>
                    <select id="leave_type" name="leave_type" required>
                        <?php foreach ($leaveTypes as $leaveType): ?>
                            <option value="<?= $escape($leaveType) ?>" <?= ($formValues['leave_type'] ?? '') === $leaveType ? 'selected' : '' ?>><?= $escape($leaveType) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label for="start_date">Start date</label>
                    <input id="start_date" name="start_date" type="date" value="<?= $escape($formValues['start_date'] ?? '') ?>" required>
                </div>
                <div class="form-field">
                    <label for="end_date">End date</label>
                    <input id="end_date" name="end_date" type="date" value="<?= $escape($formValues['end_date'] ?? '') ?>" required>
                </div>
                <div class="form-field leave-reason-field">
                    <label for="reason">Reason</label>
                    <textarea id="reason" name="reason" rows="4" maxlength="2000" required><?= $escape($formValues['reason'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="form-actions"><button class="button button-primary" type="submit">Submit request</button></div>
        </form>
    </section>
<?php endif; ?>

<?php if ($loadError === null): ?>
    <section class="panel employee-panel">
        <?php if (!$isEmployee): ?>
            <form class="employee-filters leave-filters" method="get" action="leave.php">
                <div class="search-field">
                    <label class="visually-hidden" for="leave-search">Search employees</label>
                    <input id="leave-search" type="search" name="search" value="<?= $escape($search) ?>" placeholder="Search name or employee code">
                </div>
                <div class="filter-field">
                    <label class="visually-hidden" for="leave-department">Department</label>
                    <select id="leave-department" name="department_id">
                        <option value="">All departments</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= (int) $department['department_id'] ?>" <?= $departmentFilter === (string) $department['department_id'] ? 'selected' : '' ?>><?= $escape($department['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label class="visually-hidden" for="leave-type-filter">Leave type</label>
                    <select id="leave-type-filter" name="leave_type">
                        <option value="">All leave types</option>
                        <?php foreach ($leaveTypes as $leaveType): ?>
                            <option value="<?= $escape($leaveType) ?>" <?= $typeFilter === $leaveType ? 'selected' : '' ?>><?= $escape($leaveType) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label class="visually-hidden" for="leave-status-filter">Status</label>
                    <select id="leave-status-filter" name="status">
                        <option value="">All statuses</option>
                        <?php foreach ($leaveStatuses as $leaveStatus): ?>
                            <option value="<?= $escape($leaveStatus) ?>" <?= $statusFilter === $leaveStatus ? 'selected' : '' ?>><?= $escape($leaveStatus) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="button button-secondary" type="submit">Filter</button>
                <?php if ($search !== '' || $departmentFilter !== '' || $typeFilter !== '' || $statusFilter !== ''): ?>
                    <a class="button button-quiet" href="leave.php">Clear</a>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <div class="panel-heading"><div><h2>My leave history</h2><p><?= number_format($totalRecords) ?> request<?= $totalRecords === 1 ? '' : 's' ?></p></div></div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="employee-table">
                <thead>
                    <tr>
                        <?php if (!$isEmployee): ?><th scope="col">Employee</th><th scope="col">Department</th><?php endif; ?>
                        <th scope="col">Leave type</th>
                        <th scope="col">Dates</th>
                        <th scope="col">Status</th>
                        <th scope="col">Reviewer</th>
                        <th scope="col">Review date</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($requests === []): ?>
                        <tr><td class="empty-state" colspan="<?= $isEmployee ? 6 : 8 ?>"><?= $isEmployee ? 'No leave requests yet.' : 'No leave requests match these filters.' ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <?php if (!$isEmployee): ?>
                                    <td><span class="person-name"><?= $escape($request['first_name'] . ' ' . $request['last_name']) ?></span><span class="secondary-text"><?= $escape($request['employee_code']) ?></span></td>
                                    <td><?= $escape($request['department_name'] ?? 'Unassigned') ?></td>
                                <?php endif; ?>
                                <td><?= $escape($request['leave_type']) ?></td>
                                <td><?= $escape(date('M j', strtotime($request['start_date']))) ?> – <?= $escape(date('M j, Y', strtotime($request['end_date']))) ?></td>
                                <td><span class="status-badge leave-status-<?= $escape(strtolower($request['leave_status'])) ?>"><?= $escape($request['leave_status']) ?></span></td>
                                <td><?= $escape($request['reviewer_username'] ?? '—') ?></td>
                                <td><?= $request['approval_date'] !== null ? $escape(date('M j, Y', strtotime($request['approval_date']))) : '—' ?></td>
                                <td><a class="action-link" href="leave.php?id=<?= (int) $request['leave_id'] ?>">View details</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="listing-footer">
            <p><?= number_format($totalRecords) ?> request<?= $totalRecords === 1 ? '' : 's' ?><?= $totalRecords > 0 ? ' · Page ' . $page . ' of ' . $totalPages : '' ?></p>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Leave request pages">
                    <?php if ($page > 1): ?><a class="button button-quiet" href="<?= $escape($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a class="button button-quiet" href="<?= $escape($pageUrl($page + 1)) ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
