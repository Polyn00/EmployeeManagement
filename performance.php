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
$pageTitle = $isEmployee ? 'My performance reviews' : 'Performance';
$activePage = 'performance';
$perPage = 20;
$notice = $_SESSION['performance_notice'] ?? null;
unset($_SESSION['performance_notice']);
$formValues = $_SESSION['performance_form_values'] ?? [
    'employee_id' => '',
    'review_date' => '',
    'rating' => '',
    'comments' => '',
];
unset($_SESSION['performance_form_values']);
$employees = [];
$reviews = [];
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
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

if ($isEmployee && $employeeId === false) {
    http_response_code(403);
    $errorMessage = 'This account is not linked to an employee record. Contact HR for assistance.';
}

if (!$isEmployee && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $noticeType = 'error';
    $message = 'The performance review could not be created. Please try again.';

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $message = 'Your session expired. Please reload the page and try again.';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'create') {
        $formValues = [
            'employee_id' => isset($_POST['employee_id']) && is_string($_POST['employee_id']) ? trim($_POST['employee_id']) : '',
            'review_date' => isset($_POST['review_date']) && is_string($_POST['review_date']) ? trim($_POST['review_date']) : '',
            'rating' => isset($_POST['rating']) && is_string($_POST['rating']) ? trim($_POST['rating']) : '',
            'comments' => isset($_POST['comments']) && is_string($_POST['comments']) ? trim($_POST['comments']) : '',
        ];
        $selectedEmployee = filter_var($formValues['employee_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $ratingValid = preg_match('/^[1-4](?:\.[0-9])?$|^5(?:\.0)?$/D', $formValues['rating']) === 1;
        $commentsLength = function_exists('mb_strlen')
            ? mb_strlen($formValues['comments'], 'UTF-8')
            : strlen($formValues['comments']);

        if ($selectedEmployee === false || !$validDate($formValues['review_date'])) {
            $message = 'Select an employee and enter a valid review date.';
        } elseif (!$ratingValid) {
            $message = 'Rating must be between 1.0 and 5.0, in increments of 0.1.';
        } elseif ($commentsLength > 10000) {
            $message = 'Comments must not exceed 10,000 characters.';
        } else {
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
                        'SELECT review_id
                         FROM performance_reviews
                         WHERE employee_id = :employee_id AND review_date = :review_date
                         LIMIT 1'
                    );
                    $duplicate->execute([
                        ':employee_id' => $selectedEmployee,
                        ':review_date' => $formValues['review_date'],
                    ]);
                    if ($duplicate->fetchColumn() !== false) {
                        $pdo->rollBack();
                        $message = 'A review already exists for this employee and review date.';
                    } else {
                        $insert = $pdo->prepare(
                            'INSERT INTO performance_reviews
                                (employee_id, reviewer_id, review_date, rating, comments)
                             VALUES
                                (:employee_id, :reviewer_id, :review_date, :rating, :comments)'
                        );
                        $insert->execute([
                            ':employee_id' => $selectedEmployee,
                            ':reviewer_id' => (int) $user['user_id'],
                            ':review_date' => $formValues['review_date'],
                            ':rating' => $formValues['rating'],
                            ':comments' => $formValues['comments'] !== '' ? $formValues['comments'] : null,
                        ]);
                        $reviewId = (int) $pdo->lastInsertId();
                        audit_record(
                            $pdo,
                            'performance_review_created',
                            'performance_review',
                            $reviewId,
                            [
                                'employee_id' => (int) $selectedEmployee,
                                'review_date' => $formValues['review_date'],
                            ],
                            (int) $user['user_id']
                        );
                        $pdo->commit();
                        $_SESSION['performance_notice'] = [
                            'type' => 'success',
                            'message' => 'Performance review created successfully.',
                        ];
                        header('Location: performance-details.php?id=' . $reviewId);
                        exit;
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Performance review creation failed: ' . $e->getMessage());
                $message = 'The review could not be created. Please try again later.';
            }
        }
        $_SESSION['performance_form_values'] = $formValues;
    } else {
        http_response_code(400);
        $message = 'Invalid performance review action.';
    }

    $_SESSION['performance_notice'] = ['type' => $noticeType, 'message' => $message];
    header('Location: performance.php');
    exit;
}

$search = isset($_GET['search']) && is_string($_GET['search']) ? substr(trim($_GET['search']), 0, 100) : '';
$dateFrom = isset($_GET['date_from']) && is_string($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) && is_string($_GET['date_to']) ? trim($_GET['date_to']) : '';
foreach ([$dateFrom, $dateTo] as $filterDate) {
    if ($filterDate !== '' && !$validDate($filterDate)) {
        $errorMessage = 'Enter valid review period filter dates.';
        break;
    }
}
if ($errorMessage === null && $dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $errorMessage = 'The review period start filter must be on or before the end filter.';
}

try {
    if (!$isEmployee) {
        $employees = $pdo->query(
            'SELECT employee_id, employee_code, first_name, last_name
             FROM employees
             ORDER BY last_name, first_name, employee_code'
        )->fetchAll();
    }
    $where = [];
    $parameters = [];
    if ($isEmployee) {
        $where[] = 'pr.employee_id = :employee_id';
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
    if ($dateFrom !== '') {
        $where[] = 'pr.review_date >= :date_from';
        $parameters[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'pr.review_date <= :date_to';
        $parameters[':date_to'] = $dateTo;
    }
    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

    $count = $pdo->prepare(
        'SELECT COUNT(*)
         FROM performance_reviews AS pr
         INNER JOIN employees AS e ON e.employee_id = pr.employee_id'
         . $whereSql
    );
    $count->execute($parameters);
    $totalRecords = (int) $count->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRecords / $perPage));
    $page = min($page, $totalPages);
    $listing = $pdo->prepare(
        'SELECT pr.review_id, pr.review_date, pr.rating, pr.comments,
                pr.reviewer_id, u.username AS reviewer_username,
                e.employee_id, e.employee_code, e.first_name, e.last_name,
                d.department_name
         FROM performance_reviews AS pr
         INNER JOIN employees AS e ON e.employee_id = pr.employee_id
         LEFT JOIN departments AS d ON d.department_id = e.department_id
         LEFT JOIN users AS u ON u.user_id = pr.reviewer_id'
         . $whereSql .
        ' ORDER BY pr.review_date DESC, pr.review_id DESC
          LIMIT :limit OFFSET :offset'
    );
    foreach ($parameters as $name => $value) {
        $listing->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $listing->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listing->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $listing->execute();
    $reviews = $listing->fetchAll();
} catch (PDOException $e) {
    error_log('Performance review listing failed: ' . $e->getMessage());
    $errorMessage = 'Performance reviews could not be loaded. Please try again later.';
}

$pageUrl = static function (int $targetPage) use ($isEmployee, $search, $dateFrom, $dateTo): string {
    return 'performance.php?' . http_build_query([
        'search' => $isEmployee ? null : $search,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'page' => $targetPage,
    ]);
};

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow"><?= $isEmployee ? 'MY WORKSPACE' : 'WORKSPACE' ?></p>
        <h1><?= $isEmployee ? 'My performance reviews' : 'Performance' ?></h1>
        <p class="page-description"><?= $isEmployee ? 'View your performance review history.' : 'Create and manage employee performance reviews. The database stores a review date, but not a review-period range or status.' ?></p>
    </div>
</section>
<?php if ($notice !== null): ?>
    <div class="alert <?= ($notice['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="status"><?= $escape($notice['message'] ?? '') ?></div>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="alert alert-error" role="alert"><?= $escape($errorMessage) ?></div>
<?php endif; ?>

<?php if (!$isEmployee && $errorMessage === null): ?>
    <section class="panel employee-panel performance-create-panel">
        <div class="panel-heading"><div><h2>Create a review</h2><p>The existing schema stores one review date, rating, comments, and reviewer.</p></div></div>
        <form class="employee-form" method="post" action="performance.php">
            <input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div class="form-field performance-employee-field">
                    <label for="employee_id">Employee</label>
                    <select id="employee_id" name="employee_id" required>
                        <option value="">Select an employee</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['employee_id'] ?>" <?= (string) $formValues['employee_id'] === (string) $employee['employee_id'] ? 'selected' : '' ?>><?= $escape($employee['employee_code'] . ' · ' . $employee['first_name'] . ' ' . $employee['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field"><label for="review_date">Review date</label><input id="review_date" name="review_date" type="date" value="<?= $escape($formValues['review_date']) ?>" required></div>
                <div class="form-field"><label for="rating">Rating (1.0–5.0)</label><input id="rating" name="rating" type="number" min="1" max="5" step="0.1" value="<?= $escape($formValues['rating']) ?>" required></div>
                <div class="form-field performance-comments-field"><label for="comments">Comments</label><textarea id="comments" name="comments" rows="4" maxlength="10000"><?= $escape($formValues['comments']) ?></textarea></div>
            </div>
            <p class="form-help">Ratings are limited to 1.0–5.0 in 0.1 increments by the application; the existing database column does not define a rating range.</p>
            <div class="form-actions"><button class="button button-primary" type="submit">Create review</button></div>
        </form>
    </section>
<?php endif; ?>

<?php if ($errorMessage === null): ?>
    <section class="panel employee-panel performance-list-panel">
        <form class="employee-filters performance-filters" method="get" action="performance.php">
            <?php if (!$isEmployee): ?><div class="search-field"><label class="visually-hidden" for="performance-search">Search employees</label><input id="performance-search" name="search" type="search" value="<?= $escape($search) ?>" placeholder="Search name, employee ID, or code"></div><?php endif; ?>
            <div class="filter-field"><label class="visually-hidden" for="review-from">Review date from</label><input id="review-from" name="date_from" type="date" value="<?= $escape($dateFrom) ?>"></div>
            <div class="filter-field"><label class="visually-hidden" for="review-to">Review date through</label><input id="review-to" name="date_to" type="date" value="<?= $escape($dateTo) ?>"></div>
            <button class="button button-secondary" type="submit">Filter</button>
            <?php if ($search !== '' || $dateFrom !== '' || $dateTo !== ''): ?><a class="button button-quiet" href="performance.php">Clear</a><?php endif; ?>
        </form>
        <div class="table-wrap">
            <table class="employee-table">
                <thead><tr><th scope="col">Employee</th><?php if (!$isEmployee): ?><th scope="col">Department</th><?php endif; ?><th scope="col">Review date</th><th scope="col">Rating</th><th scope="col">Reviewer</th><th scope="col">Comments</th><th scope="col">Details</th></tr></thead>
                <tbody>
                    <?php if ($reviews === []): ?>
                        <tr><td class="empty-state" colspan="<?= $isEmployee ? 6 : 7 ?>"><?= $isEmployee ? 'No performance reviews are available.' : 'No reviews match these filters.' ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($reviews as $review): ?>
                            <tr>
                                <td><span class="person-name"><?= $escape($review['first_name'] . ' ' . $review['last_name']) ?></span><span class="secondary-text">ID <?= (int) $review['employee_id'] ?> · <?= $escape($review['employee_code']) ?></span></td>
                                <?php if (!$isEmployee): ?><td><?= $escape($review['department_name'] ?? 'Unassigned') ?></td><?php endif; ?>
                                <td><?= $escape(date('M j, Y', strtotime($review['review_date']))) ?></td>
                                <td><span class="rating-badge"><?= $escape(number_format((float) $review['rating'], 1)) ?> / 5</span></td>
                                <td><?= $escape($review['reviewer_username'] ?? '—') ?></td>
                                <td class="review-comments-preview"><?= $escape($review['comments'] ?? '—') ?></td>
                                <td><a class="action-link" href="performance-details.php?id=<?= (int) $review['review_id'] ?>">View<?= !$isEmployee ? ' / edit' : '' ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="listing-footer">
            <p><?= number_format($totalRecords) ?> review<?= $totalRecords === 1 ? '' : 's' ?><?= $totalRecords > 0 ? ' · Page ' . $page . ' of ' . $totalPages : '' ?></p>
            <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Performance review pages"><?php if ($page > 1): ?><a class="button button-quiet" href="<?= $escape($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?><?php if ($page < $totalPages): ?><a class="button button-quiet" href="<?= $escape($pageUrl($page + 1)) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
