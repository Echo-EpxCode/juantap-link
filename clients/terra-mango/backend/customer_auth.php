<?php
declare(strict_types=1);

const APP_CUSTOMER_IDLE_TIMEOUT_SECONDS = 1800;
const APP_CUSTOMER_ABSOLUTE_TIMEOUT_SECONDS = 43200;

function app_normalize_customer_email(string $email): ?string
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

function app_customer_email_lookup_hash(string $email): ?string
{
    $normalized = app_normalize_customer_email($email);
    if ($normalized === null) {
        return null;
    }

    return hash_hmac(
        'sha256',
        "customer-login\0" . $normalized,
        app_auth_secret_key('contact_lookup'),
        true
    );
}

function app_customer_email_encrypt(string $email): string
{
    $normalized = app_normalize_customer_email($email);
    if ($normalized === null || !function_exists('openssl_encrypt')) {
        throw new RuntimeException('Customer email encryption is unavailable.');
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
        'juan-tap-customer-email-v1',
        16
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('Customer email encryption failed.');
    }

    return base64_encode("\x01" . $nonce . $tag . $ciphertext);
}

function app_customer_email_decrypt(string $encodedCiphertext): ?string
{
    $payload = base64_decode($encodedCiphertext, true);
    if (!is_string($payload) || strlen($payload) < 30 || $payload[0] !== "\x01") {
        return null;
    }

    $nonce = substr($payload, 1, 12);
    $tag = substr($payload, 13, 16);
    $ciphertext = substr($payload, 29);
    $key = hash('sha256', app_auth_secret_key('contact_encryption'), true);
    $email = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'juan-tap-customer-email-v1'
    );

    return is_string($email) ? $email : null;
}

function app_customer_business_id(): int
{
    $query = app_database()->query(
        "SELECT id FROM businesses WHERE status IN ('active', 'draft') ORDER BY id LIMIT 2"
    );
    $ids = [];
    while ($row = $query->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
    $query->close();

    if (count($ids) !== 1 || $ids[0] < 1) {
        throw new AppHttpException(503, 'Customer registration is not available.');
    }

    return $ids[0];
}

function app_customer_register(string $email, string $fullName, string $password): int
{
    $normalizedEmail = app_normalize_customer_email($email);
    $fullName = trim($fullName);
    if ($normalizedEmail === null || $fullName === '' || strlen($fullName) > 160) {
        throw new AppHttpException(422, 'Enter a valid email address and full name.');
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        throw new AppHttpException(422, 'Password must be between 8 and 72 bytes.');
    }

    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        $remoteAddress = 'unknown';
    }
    $ipAttempts = app_record_login_attempt('customer_register_ip', $remoteAddress);
    $emailAttempts = app_record_login_attempt('customer_register_email', $normalizedEmail);
    if ($ipAttempts > 20 || $emailAttempts > 5) {
        throw new AppHttpException(429, 'Too many account creation attempts. Try again later.');
    }

    $businessId = app_customer_business_id();
    $emailHash = app_customer_email_lookup_hash($normalizedEmail);
    if (!is_string($emailHash)) {
        throw new AppHttpException(422, 'Enter a valid email address.');
    }
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($passwordHash)) {
        throw new RuntimeException('Customer password hash could not be created.');
    }
    $emailCiphertext = app_customer_email_encrypt($normalizedEmail);

    $database = app_database();
    $database->begin_transaction();
    try {
        $customerStatus = 'active';
        $approvalStatus = 'pending';
        $insertCustomer = $database->prepare(
            'INSERT INTO customers (business_id, display_name, password_hash, status, approval_status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insertCustomer->bind_param('issss', $businessId, $fullName, $passwordHash, $customerStatus, $approvalStatus);
        $insertCustomer->execute();
        $customerId = (int) $database->insert_id;
        $insertCustomer->close();

        $channel = 'email';
        $isVerified = 0;
        $insertContact = $database->prepare(
            'INSERT INTO customer_contacts
                (business_id, customer_id, channel, contact_value_ciphertext, contact_lookup_hash, is_verified)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertContact->bind_param('iisssi', $businessId, $customerId, $channel, $emailCiphertext, $emailHash, $isVerified);
        $insertContact->execute();
        $insertContact->close();
        $database->commit();
    } catch (mysqli_sql_exception $exception) {
        $database->rollback();
        if ((int) $exception->getCode() === 1062) {
            throw new AppHttpException(409, 'An account already uses that email address.');
        }
        throw $exception;
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    return $customerId;
}

function app_customer_authenticate(string $email, string $password): bool
{
    app_start_session();
    $normalizedEmail = app_normalize_customer_email($email);
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        $remoteAddress = 'unknown';
    }

    $ipAttempts = app_record_login_attempt('customer_login_ip', $remoteAddress);
    $identifierAttempts = $normalizedEmail === null
        ? 0
        : app_record_login_attempt('customer_login_email', $normalizedEmail);
    if ($ipAttempts > 20 || $identifierAttempts > 8) {
        throw new AppHttpException(429, 'Too many sign-in attempts. Try again later.');
    }

    $customer = null;
    $emailHash = $normalizedEmail === null ? null : app_customer_email_lookup_hash($normalizedEmail);
    if (is_string($emailHash)) {
        $businessId = app_customer_business_id();
        $statement = app_database()->prepare(
            'SELECT c.id, c.password_hash
             FROM customers c
             INNER JOIN customer_contacts cc
                ON cc.customer_id = c.id AND cc.business_id = c.business_id
             WHERE c.business_id = ? AND c.status = \'active\'
               AND cc.channel = \'email\' AND cc.contact_lookup_hash = ?
             LIMIT 1'
        );
        $statement->bind_param('is', $businessId, $emailHash);
        $statement->execute();
        $statement->bind_result($customerId, $storedPasswordHash);
        if ($statement->fetch()) {
            $customer = ['id' => (int) $customerId, 'password_hash' => (string) $storedPasswordHash];
        }
        $statement->close();
    }

    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $hash = is_array($customer) ? $customer['password_hash'] : $dummyHash;
    $validLength = $password !== '' && strlen($password) <= 72;
    $matches = password_verify($validLength ? $password : '', $hash);
    if (!is_array($customer) || !$validLength || !$matches) {
        return false;
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $replacementHash = password_hash($password, PASSWORD_DEFAULT);
        $update = app_database()->prepare('UPDATE customers SET password_hash = ? WHERE id = ?');
        $customerId = (int) $customer['id'];
        $update->bind_param('si', $replacementHash, $customerId);
        $update->execute();
        $update->close();
    }

    app_clear_login_rate_limit('customer_login_ip', $remoteAddress);
    if ($normalizedEmail !== null) {
        app_clear_login_rate_limit('customer_login_email', $normalizedEmail);
    }
    app_regenerate_session_id();
    unset($_SESSION['csrf_token']);
    $_SESSION['customer_id'] = (int) $customer['id'];
    $_SESSION['customer_authenticated_at'] = time();
    $_SESSION['customer_last_activity_at'] = time();

    return true;
}

function app_current_customer(): ?array
{
    app_start_session();
    $customerId = filter_var($_SESSION['customer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $authenticatedAt = $_SESSION['customer_authenticated_at'] ?? null;
    $lastActivityAt = $_SESSION['customer_last_activity_at'] ?? null;
    $now = time();
    if (
        $customerId === false
        || !is_int($authenticatedAt)
        || !is_int($lastActivityAt)
        || $authenticatedAt > $now
        || $lastActivityAt > $now
        || $now - $lastActivityAt > APP_CUSTOMER_IDLE_TIMEOUT_SECONDS
        || $now - $authenticatedAt > APP_CUSTOMER_ABSOLUTE_TIMEOUT_SECONDS
    ) {
        if (isset($_SESSION['customer_id'])) {
            unset($_SESSION['customer_id'], $_SESSION['customer_authenticated_at'], $_SESSION['customer_last_activity_at']);
        }
        return null;
    }

    $statement = app_database()->prepare(
        'SELECT id, business_id, display_name, approval_status, status
         FROM customers WHERE id = ? LIMIT 1'
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $statement->bind_result($activeId, $businessId, $displayName, $approvalStatus, $customerStatus);
    $found = $statement->fetch();
    $statement->close();
    if (!$found || $customerStatus !== 'active') {
        unset($_SESSION['customer_id'], $_SESSION['customer_authenticated_at'], $_SESSION['customer_last_activity_at']);
        return null;
    }

    $_SESSION['customer_last_activity_at'] = $now;
    return [
        'id' => (int) $activeId,
        'business_id' => (int) $businessId,
        'full_name' => (string) $displayName,
        'approval_status' => (string) $approvalStatus,
    ];
}

function app_require_customer(): array
{
    $customer = app_current_customer();
    if ($customer === null) {
        throw new AppHttpException(401, 'Sign-in is required.');
    }

    return $customer;
}

function app_customer_logout(): void
{
    app_start_session();
    unset($_SESSION['customer_id'], $_SESSION['customer_authenticated_at'], $_SESSION['customer_last_activity_at']);
    unset($_SESSION['csrf_token']);
    app_regenerate_session_id();
}

function app_customer_url(string $page): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $customerPath = preg_replace('/\/customer\/(?:register|login|logout|account)\.php\z/', '/customer', $scriptName);
    if (!is_string($customerPath) || $customerPath === '' || $customerPath === '/') {
        $customerPath = '/customer';
    }
    return rtrim($customerPath, '/') . '/' . ltrim($page, '/');
}
