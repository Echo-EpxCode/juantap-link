<?php
declare(strict_types=1);

// This setup helper is intentionally CLI-only. It must never create an owner
// when requested through a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/backend/bootstrap.php';

function local_setup_prompt(string $label, string $default = ''): string
{
    $suffix = $default === '' ? '' : ' [' . $default . ']';
    fwrite(STDOUT, $label . $suffix . ': ');
    $input = fgets(STDIN);
    if (!is_string($input)) {
        throw new RuntimeException('Could not read setup input.');
    }

    $value = trim($input);
    return $value === '' ? $default : $value;
}

function local_setup_abort(string $message): void
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
        local_setup_abort(
            'Refusing to provision: use development mode, a loopback database host, and a database name ending in _local.'
        );
    }

    $database = app_database();
    $migration = $database->query(
        "SELECT COUNT(*) AS total FROM schema_migrations WHERE version = '001_initial_schema'"
    )->fetch_assoc();
    if ((int) ($migration['total'] ?? 0) !== 1) {
        local_setup_abort('The initial schema is missing. Import 001_initial_schema.sql first.');
    }
    if (!app_stamp_schema_ready()) {
        local_setup_abort('Stamp setup is missing. Import 002_unique_purchase_references.sql first.');
    }

    $existingRows = $database->query(
        'SELECT
            (SELECT COUNT(*) FROM businesses) AS businesses,
            (SELECT COUNT(*) FROM staff_users) AS staff_users'
    )->fetch_assoc();
    if ((int) ($existingRows['businesses'] ?? 0) !== 0 || (int) ($existingRows['staff_users'] ?? 0) !== 0) {
        local_setup_abort('This local database already has a business or staff account; refusing to add another first owner.');
    }

    $businessName = local_setup_prompt('Local business name', 'Terra Mango (Local)');
    $businessSlug = strtolower(local_setup_prompt('Business slug', 'terra-mango-local'));
    $branchName = local_setup_prompt('Local branch name', 'Main Branch (Local)');
    $ownerEmail = local_setup_prompt('Owner sign-in email');
    $normalizedEmail = is_string($ownerEmail) ? app_normalize_staff_email($ownerEmail) : null;

    if ($businessName === '' || strlen($businessName) > 160) {
        local_setup_abort('Business name must be 1 to 160 bytes.');
    }
    if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,118}[a-z0-9])?\z/', $businessSlug)) {
        local_setup_abort('Business slug must use lowercase letters, numbers, and single hyphens.');
    }
    if ($branchName === '' || strlen($branchName) > 160) {
        local_setup_abort('Branch name must be 1 to 160 bytes.');
    }
    if ($normalizedEmail === null) {
        local_setup_abort('Enter a valid owner email address.');
    }

    $ownerEmailHash = app_staff_email_lookup_hash($normalizedEmail);
    if (!is_string($ownerEmailHash)) {
        local_setup_abort('Could not prepare the owner email.');
    }

    $ownerPassword = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $ownerPasswordHash = password_hash($ownerPassword, PASSWORD_DEFAULT);
    if (!is_string($ownerPasswordHash)) {
        local_setup_abort('Could not create the owner password hash.');
    }
    $ownerEmailCiphertext = app_encrypt_staff_email($normalizedEmail);

    $database->begin_transaction();
    try {
        $businessInsert = $database->prepare(
            'INSERT INTO businesses (slug, display_name, status) VALUES (?, ?, \'draft\')'
        );
        $businessInsert->bind_param('ss', $businessSlug, $businessName);
        $businessInsert->execute();
        $businessId = (int) $database->insert_id;
        $businessInsert->close();

        $branchSlug = 'main';
        $branchInsert = $database->prepare(
            'INSERT INTO branches (business_id, slug, display_name, status) VALUES (?, ?, ?, \'active\')'
        );
        $branchInsert->bind_param('iss', $businessId, $branchSlug, $branchName);
        $branchInsert->execute();
        $branchInsert->close();

        $staffInsert = $database->prepare(
            'INSERT INTO staff_users (email_value_ciphertext, email_lookup_hash, password_hash, status)
             VALUES (?, ?, ?, \'active\')'
        );
        $staffInsert->bind_param('sss', $ownerEmailCiphertext, $ownerEmailHash, $ownerPasswordHash);
        $staffInsert->execute();
        $staffUserId = (int) $database->insert_id;
        $staffInsert->close();

        $membershipInsert = $database->prepare(
            'INSERT INTO business_memberships (business_id, staff_user_id, role_code, status)
             VALUES (?, ?, \'owner\', \'active\')'
        );
        $membershipInsert->bind_param('ii', $businessId, $staffUserId);
        $membershipInsert->execute();
        $membershipInsert->close();

        $programName = 'Mango Rewards (Local)';
        $programStatus = 'active';
        $programInsert = $database->prepare(
            'INSERT INTO loyalty_programs (business_id, name, status) VALUES (?, ?, ?)'
        );
        $programInsert->bind_param('iss', $businessId, $programName, $programStatus);
        $programInsert->execute();
        $programId = (int) $database->insert_id;
        $programInsert->close();

        $versionNumber = 1;
        $versionStatus = 'active';
        $programTerms = 'One stamp is earned per unique receipt at a branch. Eight stamps may be redeemed for a free Mango Milk Tea.';
        $effectiveFrom = gmdate('Y-m-d H:i:s');
        $versionInsert = $database->prepare(
            'INSERT INTO loyalty_program_versions
                (business_id, loyalty_program_id, version_number, status, terms, effective_from)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $versionInsert->bind_param(
            'iiisss',
            $businessId,
            $programId,
            $versionNumber,
            $versionStatus,
            $programTerms,
            $effectiveFrom
        );
        $versionInsert->execute();
        $programVersionId = (int) $database->insert_id;
        $versionInsert->close();

        $ruleKey = 'one-stamp-per-purchase';
        $ruleType = 'per_purchase';
        $scopeType = 'business';
        $scopeId = 0;
        $ruleConfig = json_encode([
            'stamps_per_purchase' => 1,
            'duplicate_reference' => 'block_per_branch',
        ], JSON_THROW_ON_ERROR);
        $ruleStatus = 'active';
        $ruleInsert = $database->prepare(
            'INSERT INTO loyalty_earning_rules
                (business_id, program_version_id, rule_key, rule_type, scope_type, scope_id, rule_config, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ruleInsert->bind_param(
            'iisssiss',
            $businessId,
            $programVersionId,
            $ruleKey,
            $ruleType,
            $scopeType,
            $scopeId,
            $ruleConfig,
            $ruleStatus
        );
        $ruleInsert->execute();
        $ruleInsert->close();

        $rewardKey = 'free-mango-milk-tea';
        $rewardName = 'Free Mango Milk Tea';
        $rewardDescription = 'A free Mango Milk Tea reward for the local stamp test.';
        $rewardTerms = 'Redeem at an active Terra Mango branch. Eight stamps are deducted when redeemed.';
        $rewardStampCost = 8;
        $rewardStatus = 'active';
        $rewardInsert = $database->prepare(
            'INSERT INTO loyalty_rewards
                (business_id, program_version_id, reward_key, name, description, terms, stamp_cost, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $rewardInsert->bind_param(
            'iissssis',
            $businessId,
            $programVersionId,
            $rewardKey,
            $rewardName,
            $rewardDescription,
            $rewardTerms,
            $rewardStampCost,
            $rewardStatus
        );
        $rewardInsert->execute();
        $rewardInsert->close();

        $demoCustomerName = 'Local Demo Customer';
        $customerStatus = 'active';
        $customerInsert = $database->prepare(
            'INSERT INTO customers (business_id, display_name, status) VALUES (?, ?, ?)'
        );
        $customerInsert->bind_param('iss', $businessId, $demoCustomerName, $customerStatus);
        $customerInsert->execute();
        $customerId = (int) $database->insert_id;
        $customerInsert->close();

        $cardStatus = 'active';
        $cardInsert = $database->prepare(
            'INSERT INTO loyalty_cards
                (business_id, customer_id, loyalty_program_id, enrollment_version_id, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $cardInsert->bind_param(
            'iiiis',
            $businessId,
            $customerId,
            $programId,
            $programVersionId,
            $cardStatus
        );
        $cardInsert->execute();
        $cardId = (int) $database->insert_id;
        $cardInsert->close();

        $initialBalance = 0;
        $balanceInsert = $database->prepare(
            'INSERT INTO card_balances (business_id, loyalty_card_id, stamp_balance) VALUES (?, ?, ?)'
        );
        $balanceInsert->bind_param('iii', $businessId, $cardId, $initialBalance);
        $balanceInsert->execute();
        $balanceInsert->close();

        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    fwrite(STDOUT, PHP_EOL . 'Local owner created.' . PHP_EOL);
    fwrite(STDOUT, 'Business: ' . $businessName . PHP_EOL);
    fwrite(STDOUT, 'Branch: ' . $branchName . PHP_EOL);
    fwrite(STDOUT, 'Stamp program: 1 stamp per unique receipt; 8 stamps for Free Mango Milk Tea.' . PHP_EOL);
    fwrite(STDOUT, 'Demo customer card: #' . $cardId . ' (starts at 0 stamps).' . PHP_EOL);
    fwrite(STDOUT, 'Email: ' . $normalizedEmail . PHP_EOL);
    fwrite(STDOUT, 'Owner password (shown once): ' . $ownerPassword . PHP_EOL);
    fwrite(STDOUT, 'Save it securely before closing this terminal; the admin has no password-change page yet.' . PHP_EOL);
} catch (Throwable $exception) {
    local_setup_abort(
        'Local owner setup failed (' . get_class($exception) . '): ' . $exception->getMessage()
    );
}
