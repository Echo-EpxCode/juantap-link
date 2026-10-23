<?php
declare(strict_types=1);

// Local-only credential recovery. This is deliberately not a web endpoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/backend/bootstrap.php';

function recovery_abort(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

try {
    $config = app_config();
    $databaseConfig = $config['database'] ?? [];
    $host = strtolower(trim((string) ($databaseConfig['host'] ?? '')));
    $databaseName = (string) ($databaseConfig['database'] ?? '');
    if (
        ($config['app']['environment'] ?? null) !== 'development'
        || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
        || !str_ends_with($databaseName, '_local')
    ) {
        recovery_abort(
            'Refusing recovery: use development mode, a loopback database host, and a database name ending in _local.'
        );
    }

    $database = app_database();
    $migration = $database->query(
        "SELECT COUNT(*) AS total FROM schema_migrations WHERE version IN ('003_owner_customer_accounts', '004_remove_legacy_staff_tables')"
    )->fetch_assoc();
    if ((int) ($migration['total'] ?? 0) !== 2) {
        recovery_abort('Owner account migrations 003 and 004 are required before credential recovery.');
    }

    $query = $database->query(
        "SELECT a.id, a.email_value_ciphertext, a.status, b.display_name AS business_name
         FROM admin_users a
         LEFT JOIN admin_businesses ab
            ON ab.admin_user_id = a.id AND ab.status = 'active'
         LEFT JOIN businesses b
            ON b.id = ab.business_id AND b.status IN ('active', 'draft')
         ORDER BY a.id, b.display_name"
    );

    $accountsById = [];
    while ($row = $query->fetch_assoc()) {
        $accountId = (int) $row['id'];
        if (!isset($accountsById[$accountId])) {
            $accountsById[$accountId] = [
                'id' => $accountId,
                'email' => app_decrypt_admin_email((string) $row['email_value_ciphertext']),
                'status' => (string) $row['status'],
                'businesses' => [],
            ];
        }
        if (is_string($row['business_name']) && $row['business_name'] !== '') {
            $accountsById[$accountId]['businesses'][] = $row['business_name'];
        }
    }
    $query->close();
    $accounts = array_values($accountsById);

    if ($accounts === []) {
        recovery_abort('No admin accounts were found. Create an owner account through the controlled provisioning process.');
    }

    fwrite(STDOUT, 'Existing admin accounts:' . PHP_EOL);
    foreach ($accounts as $account) {
        $email = $account['email'] ?? '[email could not be decrypted]';
        $businesses = $account['businesses'] === []
            ? '[no active business mapping]'
            : implode(', ', $account['businesses']);
        fwrite(
            STDOUT,
            sprintf("  ID %d | %s | %s | %s\n", $account['id'], $email, $account['status'], $businesses)
        );
    }

    fwrite(STDOUT, PHP_EOL . 'Enter the ID of an active account with an active business mapping: ');
    $selection = trim((string) fgets(STDIN));
    $selectedId = filter_var($selection, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($selectedId === false) {
        recovery_abort('Enter a valid admin account ID.');
    }

    $selected = null;
    foreach ($accounts as $account) {
        if ($account['id'] === (int) $selectedId) {
            $selected = $account;
            break;
        }
    }
    if (
        !is_array($selected)
        || $selected['status'] !== 'active'
        || $selected['businesses'] === []
        || $selected['email'] === null
    ) {
        recovery_abort('That account is not active, has no active business mapping, or its email could not be decrypted.');
    }

    $newPassword = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!is_string($passwordHash)) {
        recovery_abort('Could not create the new password hash.');
    }

    $update = $database->prepare(
        "UPDATE admin_users SET password_hash = ?, last_login_at = NULL
         WHERE id = ? AND status = 'active'"
    );
    $adminUserId = (int) $selected['id'];
    $update->bind_param('si', $passwordHash, $adminUserId);
    $update->execute();
    $updated = $update->affected_rows === 1;
    $update->close();
    if (!$updated) {
        recovery_abort('The account password was not changed.');
    }

    fwrite(STDOUT, PHP_EOL . 'Admin password reset.' . PHP_EOL);
    fwrite(STDOUT, 'Username: ' . $selected['email'] . PHP_EOL);
    fwrite(STDOUT, 'New password (shown once): ' . $newPassword . PHP_EOL);
    fwrite(STDOUT, 'Save it securely, then sign in at /admin/login.php.' . PHP_EOL);
} catch (Throwable $exception) {
    recovery_abort('Admin recovery failed (' . get_class($exception) . '): ' . $exception->getMessage());
}
