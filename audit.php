<?php
require __DIR__ . '/includes/auth.php';
auth_require_roles(['Admin']);
require __DIR__ . '/config/db.php';

$pageTitle = 'Audit trail';
$activePage = 'audit';
$perPage = 25;
$actionFilter = isset($_GET['action']) && is_string($_GET['action'])
    ? substr(trim($_GET['action']), 0, 64)
    : '';
$entityFilter = isset($_GET['entity']) && is_string($_GET['entity'])
    ? substr(trim($_GET['entity']), 0, 64)
    : '';
$search = isset($_GET['search']) && is_string($_GET['search'])
    ? substr(trim($_GET['search']), 0, 100)
    : '';
$page = isset($_GET['page']) && is_scalar($_GET['page'])
    ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : 1;
$page = $page === false ? 1 : $page;
$errorMessage = null;
$entries = [];
$totalPages = 1;
$totalEntries = 0;

try {
    $where = [];
    $parameters = [];
    if ($actionFilter !== '') {
        if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $actionFilter)) {
            $errorMessage = 'Enter a valid action filter.';
        } else {
            $where[] = 'al.action = :action';
            $parameters[':action'] = $actionFilter;
        }
    }
    if ($entityFilter !== '') {
        if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $entityFilter)) {
            $errorMessage = 'Enter a valid entity filter.';
        } else {
            $where[] = 'al.entity_type = :entity_type';
            $parameters[':entity_type'] = $entityFilter;
        }
    }
    if ($search !== '') {
        $where[] = '(al.action LIKE :action_search OR al.entity_type LIKE :entity_search
                     OR CAST(al.entity_id AS CHAR) LIKE :id_search OR u.username LIKE :username_search)';
        $term = '%' . $search . '%';
        $parameters[':action_search'] = $term;
        $parameters[':entity_search'] = $term;
        $parameters[':id_search'] = $term;
        $parameters[':username_search'] = $term;
    }
    if ($errorMessage === null) {
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM audit_logs AS al
             LEFT JOIN users AS u ON u.user_id = al.actor_user_id' . $whereSql
        );
        $count->execute($parameters);
        $totalEntries = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($totalEntries / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $query = $pdo->prepare(
            'SELECT al.audit_id, al.actor_user_id, al.action, al.entity_type,
                    al.entity_id, al.details, al.occurred_at, u.username
             FROM audit_logs AS al
             LEFT JOIN users AS u ON u.user_id = al.actor_user_id'
            . $whereSql
            . ' ORDER BY al.occurred_at DESC, al.audit_id DESC
                LIMIT ' . $perPage . ' OFFSET ' . (int) $offset
        );
        $query->execute($parameters);
        $entries = $query->fetchAll();
    }
} catch (PDOException $e) {
    error_log('Audit trail query failed: ' . $e->getMessage());
    $errorMessage = 'Audit records could not be loaded. Please try again later.';
}

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$queryValues = array_filter([
    'action' => $actionFilter,
    'entity' => $entityFilter,
    'search' => $search,
], static function ($value): bool {
    return $value !== '';
});
$pageUrl = static function (int $targetPage) use ($queryValues): string {
    return 'audit.php?' . http_build_query($queryValues + ['page' => $targetPage]);
};

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">ADMINISTRATION</p>
        <h1>Audit trail</h1>
        <p class="page-description">Review recorded account and HR record changes.</p>
    </div>
</section>

<?php if ($errorMessage !== null): ?>
    <div class="alert alert-error" role="alert"><?= $escape($errorMessage) ?></div>
<?php endif; ?>

<section class="panel employee-panel reports-filter-panel">
    <form method="get" action="audit.php" class="employee-filters reports-filters">
        <div class="filter-field">
            <label for="action">Action</label>
            <input id="action" name="action" maxlength="64" value="<?= $escape($actionFilter) ?>" placeholder="e.g. employee_updated">
        </div>
        <div class="filter-field">
            <label for="entity">Record type</label>
            <input id="entity" name="entity" maxlength="64" value="<?= $escape($entityFilter) ?>" placeholder="e.g. payroll">
        </div>
        <div class="filter-field">
            <label for="search">Search</label>
            <input id="search" name="search" maxlength="100" value="<?= $escape($search) ?>" placeholder="Actor, action, or record ID">
        </div>
        <button class="button button-secondary" type="submit">Filter</button>
        <a class="button button-quiet" href="audit.php">Clear</a>
    </form>

    <div class="panel-heading">
        <h2>Recorded events</h2>
        <span><?= number_format($totalEntries) ?> event<?= $totalEntries === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap reports-list-panel">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Actor</th>
                    <th>Action</th>
                    <th>Record</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($entries === []): ?>
                    <tr><td colspan="5" class="table-empty">No audit events match these filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        $details = null;

                    if (is_string($entry['details']) && $entry['details'] !== '') {
                        $decoded = json_decode($entry['details'], true);

                        if (is_array($decoded) && count($decoded) === 1 && isset($decoded['role'])) {
                            // Display role-only details as plain text.
                            $details = (string) $decoded['role'];
                        } elseif (is_array($decoded)) {
                            // Keep other details readable without raw JSON formatting.
                            $formattedDetails = [];

                            foreach ($decoded as $key => $value) {
                                $label = ucwords(str_replace('_', ' ', (string) $key));

                                if (is_scalar($value) || $value === null) {
                                    $formattedDetails[] = $label . ': ' . (string) ($value ?? '—');
                                } else {
                                    $formattedDetails[] = $label . ': [unsupported value]';
                                }
                            }

                            $details = implode("\n", $formattedDetails);
                        } else {
                            // Preserve the original value if it is not valid JSON.
                            $details = $entry['details'];
                        }
                    }
                        ?>
                        <tr>
                            <td><?= $escape($entry['occurred_at']) ?></td>
                            <td><?= $escape($entry['username'] ?? 'Deleted user') ?></td>
                            <td><code><?= $escape($entry['action']) ?></code></td>
                            <td><?= $escape($entry['entity_type']) ?><?= $entry['entity_id'] !== null ? ' #' . $escape($entry['entity_id']) : '' ?></td>
                            <td><pre class="audit-details"><?= $escape($details ?? '—') ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Audit trail pages">
            <?php if ($page > 1): ?><a class="button button-quiet" href="<?= $escape($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?>
            <span>Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?><a class="button button-quiet" href="<?= $escape($pageUrl($page + 1)) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
