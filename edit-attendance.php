<?php
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
auth_require_roles(['Admin', 'HR']);
require __DIR__ . '/config/db.php';

$pageTitle = 'Correct attendance';
$activePage = 'attendance';
$statuses = ['Present', 'Late', 'Absent', 'Half-day', 'On Leave'];
$attendanceId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$record = null;
$databaseError = null;
$errors = [];

if ($attendanceId === false) {
    http_response_code(400);
    $databaseError = 'A valid attendance record ID is required.';
} else {
    try {
        $lookup = $pdo->prepare(
            'SELECT a.attendance_id, a.employee_id, a.attendance_date,
                    a.time_in, a.time_out, a.attendance_status, a.remarks,
                    e.employee_code, e.first_name, e.last_name
             FROM attendance AS a
             INNER JOIN employees AS e ON e.employee_id = a.employee_id
             WHERE a.attendance_id = :attendance_id'
        );
        $lookup->execute([':attendance_id' => $attendanceId]);
        $record = $lookup->fetch();
        if ($record === false) {
            $record = null;
            http_response_code(404);
            $databaseError = 'Attendance record not found.';
        }
    } catch (PDOException $e) {
        error_log('Attendance correction load failed: ' . $e->getMessage());
        $databaseError = 'Attendance details could not be loaded. Please try again later.';
    }
}

$values = [
    'attendance_date' => $record['attendance_date'] ?? '',
    'time_in' => isset($record['time_in']) ? substr($record['time_in'], 0, 5) : '',
    'time_out' => isset($record['time_out']) ? substr($record['time_out'], 0, 5) : '',
    'attendance_status' => $record['attendance_status'] ?? 'Present',
    'remarks' => $record['remarks'] ?? '',
    'correction_reason' => '',
];
$formKey = $record !== null ? 'attendance_correction_form_' . $attendanceId : '';

if ($record !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($values) as $field) {
        $values[$field] = isset($_POST[$field]) && is_string($_POST[$field])
            ? trim($_POST[$field])
            : '';
    }

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the correction again.';
    }

    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['attendance_date']);
    $dateErrors = DateTimeImmutable::getLastErrors();
    $validDate = $parsedDate !== false
        && $parsedDate->format('Y-m-d') === $values['attendance_date']
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));
    if (!$validDate) {
        $errors[] = 'Enter a valid attendance date.';
    }

    $normalizeTime = static function (string $value): string|false|null {
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/D', $value, $matches)) {
            return false;
        }
        return $matches[1] . ':' . $matches[2] . ':' . ($matches[3] ?? '00');
    };
    $normalizedTimeIn = $normalizeTime($values['time_in']);
    $normalizedTimeOut = $normalizeTime($values['time_out']);
    if ($normalizedTimeIn === false || $normalizedTimeOut === false) {
        $errors[] = 'Enter valid 24-hour times.';
    } elseif ($normalizedTimeIn !== null && $normalizedTimeOut !== null && $normalizedTimeOut < $normalizedTimeIn) {
        $errors[] = 'Time-out cannot be earlier than time-in.';
    }

    if (!in_array($values['attendance_status'], $statuses, true)) {
        $errors[] = 'Select a valid attendance status.';
    }

    $reasonLength = function_exists('mb_strlen')
        ? mb_strlen($values['correction_reason'], 'UTF-8')
        : strlen($values['correction_reason']);
    $remarksLength = function_exists('mb_strlen')
        ? mb_strlen($values['remarks'], 'UTF-8')
        : strlen($values['remarks']);
    $storedRemarks = 'Correction: ' . $values['correction_reason']
        . ($values['remarks'] !== '' ? ' | ' . $values['remarks'] : '');
    $storedLength = function_exists('mb_strlen')
        ? mb_strlen($storedRemarks, 'UTF-8')
        : strlen($storedRemarks);

    if ($reasonLength === 0) {
        $errors[] = 'A correction reason is required.';
    } elseif ($reasonLength > 200 || $remarksLength > 255 || $storedLength > 255) {
        $errors[] = 'The correction reason and remarks must fit within the 255-character remarks field.';
    }

    if ($errors === []) {
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
                ':attendance_date' => $values['attendance_date'],
                ':time_in' => $normalizedTimeIn,
                ':time_out' => $normalizedTimeOut,
                ':attendance_status' => $values['attendance_status'],
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
                'message' => 'Attendance corrected; the reason was recorded in remarks.',
            ];
            header('Location: attendance.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Attendance correction save failed: ' . $e->getMessage());
            $errors[] = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062
                ? 'This employee already has an attendance record for that date.'
                : 'Attendance could not be corrected. Please try again.';
        }
    }

    $_SESSION['attendance_correction_form_' . $attendanceId] = [
        'values' => $values,
        'errors' => $errors,
    ];
    header('Location: edit-attendance.php?id=' . (int) $attendanceId);
    exit;
}

if ($record !== null && isset($_SESSION[$formKey]) && is_array($_SESSION[$formKey])) {
    $form = $_SESSION[$formKey];
    unset($_SESSION[$formKey]);
    $values = array_merge($values, is_array($form['values'] ?? null) ? $form['values'] : []);
    $errors = is_array($form['errors'] ?? null) ? $form['errors'] : [];
}

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">ATTENDANCE</p>
        <h1><?= $record !== null ? 'Correct attendance' : 'Attendance unavailable' ?></h1>
        <p class="page-description">
            <?= $record !== null
                ? htmlspecialchars($record['employee_code'] . ' · ' . $record['first_name'] . ' ' . $record['last_name'], ENT_QUOTES, 'UTF-8')
                : 'The requested attendance record could not be opened.' ?>
        </p>
    </div>
    <a class="button button-quiet" href="attendance.php">Back to attendance</a>
</section>

<?php if ($databaseError !== null): ?>
    <div class="alert alert-error" role="alert"><?= htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8') ?></div>
<?php elseif ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <p>Please review the following:</p>
        <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($record !== null): ?>
    <form class="panel employee-form attendance-correction-form" method="post" action="edit-attendance.php?id=<?= (int) $attendanceId ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-grid">
            <div class="form-field">
                <label for="attendance_date">Attendance date</label>
                <input id="attendance_date" name="attendance_date" type="date" value="<?= htmlspecialchars($values['attendance_date'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-field">
                <label for="time_in">Time in</label>
                <input id="time_in" name="time_in" type="time" value="<?= htmlspecialchars($values['time_in'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-field">
                <label for="time_out">Time out</label>
                <input id="time_out" name="time_out" type="time" value="<?= htmlspecialchars($values['time_out'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-field">
                <label for="attendance_status">Attendance status</label>
                <select id="attendance_status" name="attendance_status" required>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $values['attendance_status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field department-description-field">
                <label for="correction_reason">Correction reason (required)</label>
                <input id="correction_reason" name="correction_reason" type="text" maxlength="200" value="<?= htmlspecialchars($values['correction_reason'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-field department-description-field">
                <label for="remarks">Additional remarks (optional)</label>
                <textarea id="remarks" name="remarks" rows="3" maxlength="255"><?= htmlspecialchars($values['remarks'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <span class="form-help">The correction reason is saved in the existing attendance remarks field.</span>
            </div>
        </div>
        <div class="form-actions">
            <a class="button button-quiet" href="attendance.php">Cancel</a>
            <button class="button button-primary" type="submit">Save correction</button>
        </div>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
