<?php $currentUser = auth_current_user(); ?>
<aside class="sidebar">
    <a class="brand" href="dashboard.php" aria-label="PeopleSuite dashboard">
        <span class="brand-mark" aria-hidden="true">P</span>
        <span class="brand-copy">
            <strong>PeopleSuite</strong>
            <small>HR management</small>
        </span>
    </a>

    <p class="nav-label">WORKSPACE</p>
    <nav class="primary-nav" aria-label="Main navigation">
        <?php if ($currentUser === null): ?>
            <a class="nav-link <?= $activePage === 'login' ? 'is-active' : '' ?>" href="login.php" <?= $activePage === 'login' ? 'aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">IN</span>
                <span>Sign in</span>
            </a>
        <?php elseif ($currentUser['role'] === 'Employee'): ?>
            <a class="nav-link <?= $activePage === 'my-account' ? 'is-active' : '' ?>" href="my-account.php" <?= $activePage === 'my-account' ? 'aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">ME</span>
                <span>Profile</span>
            </a>
            <a class="nav-link <?= $activePage === 'attendance' ? 'is-active' : '' ?>" href="attendance.php" <?= $activePage === 'attendance' ? 'aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true">AT</span><span>Attendance</span></a>
            <a class="nav-link <?= $activePage === 'leave' ? 'is-active' : '' ?>" href="leave.php" <?= $activePage === 'leave' ? 'aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true">LV</span><span>Leave Request</span></a>
            <a class="nav-link <?= $activePage === 'payroll' ? 'is-active' : '' ?>" href="payroll.php" <?= $activePage === 'payroll' ? 'aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true">PY</span><span>View Payroll</span></a>
            <a class="nav-link <?= $activePage === 'performance' ? 'is-active' : '' ?>" href="performance.php" <?= $activePage === 'performance' ? 'aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true">PR</span><span>Performane Overview</span></a>
        <?php else: ?>
            <a class="nav-link <?= $activePage === 'dashboard' ? 'is-active' : '' ?>" href="dashboard.php" <?= $activePage === 'dashboard' ? 'aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">OV</span>
                <span>Dashboard</span>
            </a>
            <a class="nav-link <?= $activePage === 'employees' ? 'is-active' : '' ?>" href="employees.php" <?= $activePage === 'employees' ? 'aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">EM</span>
                <span>Employees</span>
            </a>
            <a class="nav-link <?= $activePage === 'departments' ? 'is-active' : '' ?>" href="departments.php" <?= $activePage === 'departments' ? 'aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">DP</span>
                <span>Departments</span>
            </a>
            <a class="nav-link <?= $activePage === 'attendance' ? 'is-active' : '' ?>" href="attendance.php" <?= $activePage === 'attendance' ? 'aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">AT</span>
                <span>Attendance</span>
            </a>
            <a class="nav-link <?= $activePage === 'leave' ? 'is-active' : '' ?>" href="leave.php" <?= $activePage === 'leave' ? 'aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">LV</span>
                <span>Leave requests</span>
            </a>
            <a class="nav-link <?= $activePage === 'reports' ? 'is-active' : '' ?>" href="reports.php" <?= $activePage === 'reports' ? 'aria-current="page"' : '' ?>>
                <span class="nav-icon" aria-hidden="true">RP</span>
                <span>Reports</span>
            </a>
            <?php if ($currentUser['role'] === 'Admin'): ?>
                <a class="nav-link <?= $activePage === 'payroll' ? 'is-active' : '' ?>" href="payroll.php" <?= $activePage === 'payroll' ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true">PY</span>
                    <span>Payroll</span>
                </a>
                <a class="nav-link <?= $activePage === 'performance' ? 'is-active' : '' ?>" href="performance.php" <?= $activePage === 'performance' ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true">PR</span>
                    <span>Performance</span>
                </a>
                <a class="nav-link <?= $activePage === 'users' ? 'is-active' : '' ?>" href="users.php" <?= $activePage === 'users' ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true">US</span>
                    <span>User access</span>
                </a>
                <a class="nav-link <?= $activePage === 'audit' ? 'is-active' : '' ?>" href="audit.php" <?= $activePage === 'audit' ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true">AU</span>
                    <span>Audit trail</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <?php if ($currentUser !== null): ?>
            <span class="sidebar-footer-dot" aria-hidden="true"></span>
            <span><?= htmlspecialchars($currentUser['username'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($currentUser['role'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php else: ?>
            <span>Secure workspace access</span>
        <?php endif; ?>
    </div>
</aside>
<main class="main-panel">
    <header class="topbar">
        <span class="topbar-context"><?= $currentUser !== null ? 'Human Resources' : 'PeopleSuite' ?></span>
        <?php if ($currentUser !== null): ?>
            <form class="topbar-account" method="post" action="logout.php">
                <span class="topbar-label"><span class="online-dot" aria-hidden="true"></span><?= htmlspecialchars($currentUser['username'], ENT_QUOTES, 'UTF-8') ?></span>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <button class="topbar-logout" type="submit">Sign out</button>
            </form>
        <?php else: ?>
            <span class="topbar-label">Secure sign in</span>
        <?php endif; ?>
    </header>
    <div class="page-content">