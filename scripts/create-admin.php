<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

$adminUsername = $argv[1] ?? '';
$adminPassword = getenv('PEOPLESUITE_ADMIN_PASSWORD') ?: '';
$adminPasswordConfirmation = getenv('PEOPLESUITE_ADMIN_PASSWORD_CONFIRMATION') ?: '';

if (!preg_match('/\A[a-zA-Z0-9._-]{3,50}\z/D', $adminUsername)) {
    fwrite(STDERR, "Username must be 3–50 characters using letters, numbers, dots, underscores, or hyphens.\n");
    exit(1);
}
if (strlen($adminPassword) < 12 || strlen($adminPassword) > 72) {
    fwrite(STDERR, "Password must be between 12 and 72 bytes.\n");
    exit(1);
}
if (!hash_equals($adminPassword, $adminPasswordConfirmation)) {
    fwrite(STDERR, "The password confirmation did not match.\n");
    exit(1);
}

try {
    require dirname(__DIR__) . '/config/db.php';

    $lock = $pdo->query("SELECT GET_LOCK('peoplesuite_initial_admin_setup', 10)")->fetchColumn();
    if ((int) $lock !== 1) {
        throw new RuntimeException('Unable to acquire the initial administrator setup lock.');
    }

    try {
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn();
        if ($adminCount > 0) {
            throw new RuntimeException('An administrator account already exists; setup is limited to the first administrator.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO users (employee_id, username, password, role, account_status)
             VALUES (NULL, :username, :password, :role, :account_status)'
        );
        $insert->execute([
            ':username' => $adminUsername,
            ':password' => password_hash($adminPassword, PASSWORD_DEFAULT),
            ':role' => 'Admin',
            ':account_status' => 'Active',
        ]);
        fwrite(STDOUT, "Administrator account created. Sign in at /employeemanagementsystem/login.php.\n");
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('peoplesuite_initial_admin_setup')");
    }
} catch (Throwable $e) {
    error_log('Initial administrator setup failed: ' . $e->getMessage());
    fwrite(STDERR, "Administrator setup failed. The username may already be in use, or an administrator may already exist.\n");
    exit(1);
} finally {
    putenv('PEOPLESUITE_ADMIN_PASSWORD');
    putenv('PEOPLESUITE_ADMIN_PASSWORD_CONFIRMATION');
}
