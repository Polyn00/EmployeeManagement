<?php
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
auth_require_roles(['Admin', 'Employee']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_require_roles(['Admin']);
}
require __DIR__ . '/config/db.php';

$user = auth_current_user();
$isEmployee = $user['role'] === 'Employee';
$employeeId = $isEmployee
    ? filter_var($user['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;
$reviewId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$review = null;
$loadError = null;
$notice = $_SESSION['performance_notice'] ?? null;
unset($_SESSION['performance_notice']);
$form = $_SESSION['performance_edit_form'] ?? null;
unset($_SESSION['performance_edit_form']);

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

if ($reviewId === false || ($isEmployee && $employeeId === false)) {
    http_response_code(400);
    $loadError = 'A valid performance review is required.';
} elseif (!$isEmployee && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reviewDate = isset($_POST['review_date']) && is_string($_POST['review_date']) ? trim($_POST['review_date']) : '';
    $rating = isset($_POST['rating']) && is_string($_POST['rating']) ? trim($_POST['rating']) : '';
    $comments = isset($_POST['comments']) && is_string($_POST['comments']) ? trim($_POST['comments']) : '';
    $error = null;

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif (!$validDate($reviewDate)) {
        $error = 'Enter a valid review date.';
    } elseif (preg_match('/^[1-4](?:\.[0-9])?$|^5(?:\.0)?$/D', $rating) !== 1) {
        $error = 'Rating must be between 1.0 and 5.0, in increments of 0.1.';
    } else {
        $commentsLength = function_exists('mb_strlen') ? mb_strlen($comments, 'UTF-8') : strlen($comments);
        if ($commentsLength > 10000) {
            $error = 'Comments must not exceed 10,000 characters.';
        }
    }

    if ($error === null) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                'SELECT employee_id FROM performance_reviews WHERE review_id = :review_id FOR UPDATE'
            );
            $lock->execute([':review_id' => $reviewId]);
            $targetEmployee = $lock->fetchColumn();

            if ($targetEmployee === false) {
                $pdo->rollBack();
                $error = 'The performance review could not be found.';
            } else {
                $duplicate = $pdo->prepare(
                    'SELECT review_id
                     FROM performance_reviews
                     WHERE employee_id = :employee_id
                       AND review_date = :review_date
                       AND review_id <> :review_id
                     LIMIT 1'
                );
                $duplicate->execute([
                    ':employee_id' => (int) $targetEmployee,
                    ':review_date' => $reviewDate,
                    ':review_id' => $reviewId,
                ]);
                if ($duplicate->fetchColumn() !== false) {
                    $pdo->rollBack();
                    $error = 'A review already exists for this employee and review date.';
                } else {
                    $update = $pdo->prepare(
                        'UPDATE performance_reviews
                         SET reviewer_id = :reviewer_id,
                             review_date = :review_date,
                             rating = :rating,
                             comments = :comments
                         WHERE review_id = :review_id'
                    );
                    $update->execute([
                        ':reviewer_id' => (int) $user['user_id'],
                        ':review_date' => $reviewDate,
                        ':rating' => $rating,
                        ':comments' => $comments !== '' ? $comments : null,
                        ':review_id' => $reviewId,
                    ]);
                    audit_record(
                        $pdo,
                        'performance_review_updated',
                        'performance_review',
                        (int) $reviewId,
                        [
                            'employee_id' => (int) $targetEmployee,
                            'review_date' => $reviewDate,
                        ],
                        (int) $user['user_id']
                    );
                    $pdo->commit();
                    $_SESSION['performance_notice'] = [
                        'type' => 'success',
                        'message' => 'Performance review updated successfully.',
                    ];
                    header('Location: performance-details.php?id=' . (int) $reviewId);
                    exit;
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Performance review update failed: ' . $e->getMessage());
            $error = 'The review could not be updated. Please try again later.';
        }
    }

    $_SESSION['performance_edit_form'] = [
        'review_date' => $reviewDate,
        'rating' => $rating,
        'comments' => $comments,
        'error' => $error,
    ];
    header('Location: performance-details.php?id=' . (int) $reviewId);
    exit;
}

if ($loadError === null) {
    try {
        $sql = 'SELECT pr.review_id, pr.employee_id, pr.reviewer_id,
                       pr.review_date, pr.rating, pr.comments, pr.created_at,
                       reviewer.username AS reviewer_username,
                       e.employee_code, e.first_name, e.middle_name, e.last_name,
                       e.email, e.position, e.hire_date, e.employment_status,
                       d.department_name
                FROM performance_reviews AS pr
                INNER JOIN employees AS e ON e.employee_id = pr.employee_id
                LEFT JOIN departments AS d ON d.department_id = e.department_id
                LEFT JOIN users AS reviewer ON reviewer.user_id = pr.reviewer_id
                WHERE pr.review_id = :review_id';
        $parameters = [':review_id' => $reviewId];
        if ($isEmployee) {
            $sql .= ' AND pr.employee_id = :employee_id';
            $parameters[':employee_id'] = $employeeId;
        }
        $query = $pdo->prepare($sql);
        $query->execute($parameters);
        $review = $query->fetch() ?: null;
        if ($review === null) {
            http_response_code(404);
        }
    } catch (PDOException $e) {
        error_log('Performance review detail load failed: ' . $e->getMessage());
        $loadError = 'Performance review details could not be loaded. Please try again later.';
    }
}

$pageTitle = 'Performance review details';
$activePage = 'performance';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow"><?= $isEmployee ? 'MY PERFORMANCE' : 'PERFORMANCE' ?></p>
        <h1>Review details</h1>
        <p class="page-description">Review rating, comments, and reviewer information.</p>
    </div>
    <a class="button button-quiet" href="performance.php">Back to reviews</a>
</section>
<?php if ($notice !== null): ?>
    <div class="alert <?= ($notice['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="status"><?= $escape($notice['message'] ?? '') ?></div>
<?php endif; ?>
<?php if ($loadError !== null): ?>
    <div class="alert alert-error" role="alert"><?= $escape($loadError) ?></div>
<?php elseif ($review === null): ?>
    <div class="alert alert-error" role="alert">The requested review was not found or is not available to your account.</div>
<?php else: ?>
    <section class="panel performance-detail-panel">
        <div class="panel-heading">
            <div>
                <h2><?= $escape(trim($review['first_name'] . ' ' . ($review['middle_name'] ?? '') . ' ' . $review['last_name'])) ?></h2>
                <p>Employee ID <?= (int) $review['employee_id'] ?> · <?= $escape($review['employee_code']) ?> · <?= $escape($review['department_name'] ?? 'Unassigned') ?> · <?= $escape($review['position'] ?? 'Position not set') ?></p>
            </div>
            <span class="rating-badge rating-badge-large"><?= $escape(number_format((float) $review['rating'], 1)) ?> / 5</span>
        </div>
        <dl class="performance-detail-grid">
            <div><dt>Review date</dt><dd><?= $escape(date('M j, Y', strtotime($review['review_date']))) ?></dd></div>
            <div><dt>Reviewer</dt><dd><?= $escape($review['reviewer_username'] ?? 'Not recorded') ?></dd></div>
            <div><dt>Employee ID</dt><dd><?= (int) $review['employee_id'] ?></dd></div>
            <div><dt>Employee code</dt><dd><?= $escape($review['employee_code']) ?></dd></div>
            <div><dt>Email</dt><dd><?= $escape($review['email']) ?></dd></div>
            <div><dt>Employment status</dt><dd><?= $escape($review['employment_status'] ?? '—') ?></dd></div>
            <div><dt>Comments</dt><dd class="performance-detail-comments"><?= nl2br($escape($review['comments'] ?? 'No comments provided.')) ?></dd></div>
        </dl>
        <?php if (!$isEmployee): ?>
            <?php
            $editValues = [
                'review_date' => $review['review_date'],
                'rating' => number_format((float) $review['rating'], 1),
                'comments' => $review['comments'] ?? '',
            ];
            if (is_array($form)) {
                $editValues = array_merge($editValues, array_intersect_key($form, array_flip(['review_date', 'rating', 'comments'])));
            }
            ?>
            <?php if (is_array($form) && isset($form['error'])): ?>
                <div class="alert alert-error performance-edit-error" role="alert"><?= $escape($form['error']) ?></div>
            <?php endif; ?>
            <details class="performance-edit">
                <summary>Edit review</summary>
                <form class="employee-form" method="post" action="performance-details.php?id=<?= (int) $review['review_id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>">
                    <div class="form-grid">
                        <div class="form-field"><label for="review_date">Review date</label><input id="review_date" name="review_date" type="date" value="<?= $escape($editValues['review_date']) ?>" required></div>
                        <div class="form-field"><label for="rating">Rating (1.0–5.0)</label><input id="rating" name="rating" type="number" min="1" max="5" step="0.1" value="<?= $escape($editValues['rating']) ?>" required></div>
                        <div class="form-field performance-comments-field"><label for="comments">Comments</label><textarea id="comments" name="comments" rows="4" maxlength="10000"><?= $escape($editValues['comments']) ?></textarea></div>
                    </div>
                    <p class="form-help">Saving records the current Admin as the reviewer. The schema does not retain prior review versions.</p>
                    <div class="form-actions"><button class="button button-primary" type="submit">Save review</button></div>
                </form>
            </details>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
