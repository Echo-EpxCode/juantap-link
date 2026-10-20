<?php
declare(strict_types=1);

const APP_STAFF_IDLE_TIMEOUT_SECONDS = 1800;
const APP_STAFF_ABSOLUTE_TIMEOUT_SECONDS = 43200;

function app_auth_secret_key(string $name): string
{
    $keys = app_config()['keys'] ?? null;
    $key = is_array($keys) ? ($keys[$name] ?? null) : null;

    if (
        !is_string($key)
        || strlen($key) < 32
        || str_starts_with($key, 'REPLACE_WITH_')
    ) {
        throw new RuntimeException('Required application key is missing or invalid.');
    }

    return $key;
}

function app_normalize_staff_email(string $email): ?string
{
    $normalized = strtolower(trim($email));

    if (
        $normalized === ''
        || strlen($normalized) > 254
        || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false
    ) {
        return null;
    }

    return $normalized;
}

function app_staff_email_lookup_hash(string $email): ?string
{
    $normalized = app_normalize_staff_email($email);
    if ($normalized === null) {
        return null;
    }

    return hash_hmac(
        'sha256',
        "staff-login\0" . $normalized,
        app_auth_secret_key('contact_lookup'),
        true
    );
}

function app_encrypt_staff_email(string $email): string
{
    $normalized = app_normalize_staff_email($email);
    if ($normalized === null) {
        throw new InvalidArgumentException('A valid email address is required.');
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('The configured PHP runtime does not provide OpenSSL encryption.');
    }

    $key = hash('sha256', app_auth_secret_key('contact_encryption'), true);
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $normalized,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'juan-tap-staff-email-v1',
        16
    );

    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('Staff email encryption failed.');
    }

    return "\x01" . $nonce . $tag . $ciphertext;
}

function app_login_rate_limit_bucket_hash(string $actionKey, string $value): string
{
    return hash_hmac(
        'sha256',
        $actionKey . "\0" . $value,
        app_auth_secret_key('auth_rate_limit'),
        true
    );
}

function app_record_login_attempt(string $actionKey, string $value): int
{
    $database = app_database();
    $database->query(
        'DELETE FROM rate_limit_buckets
         WHERE updated_at < UTC_TIMESTAMP() - INTERVAL 1 DAY
         LIMIT 100'
    );

    $bucketHash = app_login_rate_limit_bucket_hash($actionKey, $value);
    $windowSeconds = 900;
    $windowStart = gmdate('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);

    $insert = $database->prepare(
        'INSERT INTO rate_limit_buckets (action_key, bucket_hash, window_started_at, attempt_count, updated_at)
         VALUES (?, ?, ?, 1, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            attempt_count = IF(attempt_count < 65535, attempt_count + 1, 65535),
            updated_at = UTC_TIMESTAMP()'
    );
    $insert->bind_param('sss', $actionKey, $bucketHash, $windowStart);
    $insert->execute();
    $insert->close();

    $select = $database->prepare(
        'SELECT attempt_count
         FROM rate_limit_buckets
         WHERE action_key = ? AND bucket_hash = ? AND window_started_at = ?'
    );
    $select->bind_param('sss', $actionKey, $bucketHash, $windowStart);
    $select->execute();
    $select->bind_result($attemptCount);
    $found = $select->fetch();
    $select->close();

    if (!$found) {
        throw new RuntimeException('Login rate limit state could not be read.');
    }

    return (int) $attemptCount;
}

function app_clear_login_rate_limit(string $actionKey, string $value): void
{
    $windowSeconds = 900;
    $windowStart = gmdate('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
    $bucketHash = app_login_rate_limit_bucket_hash($actionKey, $value);
    $statement = app_database()->prepare(
        'DELETE FROM rate_limit_buckets
         WHERE action_key = ? AND bucket_hash = ? AND window_started_at = ?'
    );
    $statement->bind_param('sss', $actionKey, $bucketHash, $windowStart);
    $statement->execute();
    $statement->close();
}

function app_authenticate_staff(string $email, string $password): bool
{
    app_start_session();

    $normalizedEmail = app_normalize_staff_email($email);
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        $remoteAddress = 'unknown';
    }

    $ipAttempts = app_record_login_attempt('staff_login_ip', $remoteAddress);
    $identifierAttempts = 0;
    if ($normalizedEmail !== null) {
        $identifierAttempts = app_record_login_attempt('staff_login_identifier', $normalizedEmail);
    }

    if ($ipAttempts > 20 || $identifierAttempts > 8) {
        throw new AppHttpException(429, 'Too many sign-in attempts. Try again later.');
    }

    $user = null;
    $emailLookupHash = $normalizedEmail === null ? null : app_staff_email_lookup_hash($normalizedEmail);

    if ($emailLookupHash !== null) {
        $statement = app_database()->prepare(
            'SELECT id, password_hash, status
             FROM staff_users
             WHERE email_lookup_hash = ?
             LIMIT 1'
        );
        $statement->bind_param('s', $emailLookupHash);
        $statement->execute();
        $statement->bind_result($staffUserId, $storedPasswordHash, $staffStatus);
        if ($statement->fetch()) {
            $user = [
                'id' => (int) $staffUserId,
                'password_hash' => (string) $storedPasswordHash,
                'status' => (string) $staffStatus,
            ];
        }
        $statement->close();
    }

    $dummyPasswordHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $passwordHash = is_array($user) ? (string) $user['password_hash'] : $dummyPasswordHash;
    $passwordWithinSupportedLength = $password !== '' && strlen($password) <= 72;
    $passwordForVerification = $passwordWithinSupportedLength ? $password : '';
    $passwordMatches = password_verify($passwordForVerification, $passwordHash);

    if (
        !is_array($user)
        || !$passwordWithinSupportedLength
        || !$passwordMatches
        || ($user['status'] ?? null) !== 'active'
    ) {
        return false;
    }

    $staffUserId = (int) $user['id'];
    if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
        $replacementHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($replacementHash)) {
            throw new RuntimeException('Password hash update failed.');
        }

        $update = app_database()->prepare(
            'UPDATE staff_users
             SET password_hash = ?, last_login_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = \'active\''
        );
        $update->bind_param('si', $replacementHash, $staffUserId);
    } else {
        $update = app_database()->prepare(
            'UPDATE staff_users
             SET last_login_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = \'active\''
        );
        $update->bind_param('i', $staffUserId);
    }
    $update->execute();
    $update->close();

    $activeCheck = app_database()->prepare(
        'SELECT id
         FROM staff_users
         WHERE id = ? AND status = \'active\'
         LIMIT 1'
    );
    $activeCheck->bind_param('i', $staffUserId);
    $activeCheck->execute();
    $activeCheck->bind_result($activeStaffUserId);
    $stillActive = $activeCheck->fetch();
    $activeCheck->close();

    if (!$stillActive) {
        return false;
    }

    app_clear_login_rate_limit('staff_login_ip', $remoteAddress);
    if ($normalizedEmail !== null) {
        app_clear_login_rate_limit('staff_login_identifier', $normalizedEmail);
    }

    app_regenerate_session_id();
    unset($_SESSION['csrf_token']);
    $_SESSION['staff_user_id'] = $staffUserId;
    $_SESSION['staff_authenticated_at'] = time();
    $_SESSION['staff_last_activity_at'] = time();

    return true;
}

function app_auth_logout(): void
{
    app_start_session();
    $_SESSION = [];

    $cookie = session_get_cookie_params();
    $options = [
        'expires' => time() - 42000,
        'path' => $cookie['path'] ?? '/',
        'secure' => (bool) ($cookie['secure'] ?? false),
        'httponly' => (bool) ($cookie['httponly'] ?? true),
        'samesite' => $cookie['samesite'] ?? 'Lax',
    ];
    if (isset($cookie['domain']) && $cookie['domain'] !== '') {
        $options['domain'] = $cookie['domain'];
    }

    setcookie(session_name(), '', $options);
    if (!session_destroy()) {
        throw new RuntimeException('Session could not be closed.');
    }
}

function app_current_staff_user(): ?array
{
    app_start_session();

    $staffUserId = filter_var(
        $_SESSION['staff_user_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $authenticatedAt = $_SESSION['staff_authenticated_at'] ?? null;
    $lastActivityAt = $_SESSION['staff_last_activity_at'] ?? null;
    $now = time();

    if (
        $staffUserId === false
        || !is_int($authenticatedAt)
        || !is_int($lastActivityAt)
        || $authenticatedAt > $now
        || $lastActivityAt > $now
        || $now - $lastActivityAt > APP_STAFF_IDLE_TIMEOUT_SECONDS
        || $now - $authenticatedAt > APP_STAFF_ABSOLUTE_TIMEOUT_SECONDS
    ) {
        if (isset($_SESSION['staff_user_id'])) {
            app_auth_logout();
        }
        return null;
    }

    $statement = app_database()->prepare(
        'SELECT id
         FROM staff_users
         WHERE id = ? AND status = \'active\'
         LIMIT 1'
    );
    $statement->bind_param('i', $staffUserId);
    $statement->execute();
    $statement->bind_result($activeStaffUserId);
    $userExists = $statement->fetch();
    $statement->close();

    if (!$userExists) {
        app_auth_logout();
        return null;
    }

    $_SESSION['staff_last_activity_at'] = $now;
    return ['id' => (int) $activeStaffUserId];
}

function app_require_staff_user(): array
{
    $user = app_current_staff_user();
    if ($user === null) {
        throw new AppHttpException(401, 'Sign-in is required.');
    }

    return $user;
}

function app_admin_url(string $page): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $adminPath = preg_replace('/\/(?:index|login|logout|stamps|business)\.php\z/', '', $scriptName);

    if (!is_string($adminPath) || $adminPath === '' || $adminPath === '/') {
        $adminPath = '/admin';
    }

    return rtrim($adminPath, '/') . '/' . ltrim($page, '/');
}
