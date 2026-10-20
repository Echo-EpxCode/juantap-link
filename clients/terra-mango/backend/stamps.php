<?php
declare(strict_types=1);

function app_stamp_schema_ready(): bool
{
    $version = '002_unique_purchase_references';
    $statement = app_database()->prepare(
        'SELECT 1
         FROM schema_migrations m
         WHERE m.version = ?
           AND EXISTS (
               SELECT 1
               FROM information_schema.statistics s
               WHERE s.table_schema = DATABASE()
                 AND s.table_name = \'stamp_transactions\'
                 AND s.index_name = \'uq_stamp_purchase_reference\'
                 AND s.non_unique = 0
               GROUP BY s.table_name
               HAVING COUNT(*) = 3
           )
         LIMIT 1'
    );
    $statement->bind_param('s', $version);
    $statement->execute();
    $statement->store_result();
    $ready = $statement->num_rows === 1;
    $statement->close();

    return $ready;
}

function app_require_stamp_schema(): void
{
    if (!app_stamp_schema_ready()) {
        throw new AppHttpException(503, 'Stamp setup is incomplete. Apply migration 002 first.');
    }
}

function app_stamp_normalize_purchase_reference(string $reference): string
{
    $normalized = strtoupper(trim($reference));
    if ($normalized === '' || strlen($normalized) > 120) {
        throw new AppHttpException(422, 'Enter a receipt reference of up to 120 characters.');
    }

    return $normalized;
}

function app_stamp_idempotency_hash(int $businessId, string $action, string $key): string
{
    if (!preg_match('/\A[a-f0-9]{64}\z/i', $key)) {
        throw new AppHttpException(422, 'The request token is invalid. Refresh the page and try again.');
    }

    return hash_hmac(
        'sha256',
        'stamp-action' . "\0" . $action . "\0" . $businessId . "\0" . strtolower($key),
        app_auth_secret_key('auth_rate_limit'),
        true
    );
}

function app_stamp_purchase_reference_hash(int $businessId, int $branchId, string $reference): string
{
    return hash_hmac(
        'sha256',
        'purchase-reference' . "\0" . $businessId . "\0" . $branchId . "\0" . $reference,
        app_auth_secret_key('contact_lookup'),
        true
    );
}

function app_stamp_existing_issue(
    int $businessId,
    string $idempotencyHash,
    int $cardId,
    int $branchId,
    string $purchaseReferenceHash
): ?int {
    $statement = app_database()->prepare(
        'SELECT id, loyalty_card_id, branch_id, transaction_type, purchase_reference_hash
         FROM stamp_transactions
         WHERE business_id = ? AND idempotency_key_hash = ?
         LIMIT 1'
    );
    $statement->bind_param('is', $businessId, $idempotencyHash);
    $statement->execute();
    $statement->bind_result($transactionId, $matchedCardId, $matchedBranchId, $transactionType, $matchedReferenceHash);
    $found = $statement->fetch();
    $statement->close();

    if (!$found) {
        return null;
    }

    if (
        (int) $matchedCardId !== $cardId
        || (int) $matchedBranchId !== $branchId
        || $transactionType !== 'issue'
        || !is_string($matchedReferenceHash)
        || !hash_equals($matchedReferenceHash, $purchaseReferenceHash)
    ) {
        throw new AppHttpException(409, 'This request token was already used for a different action.');
    }

    return (int) $transactionId;
}

function app_stamp_existing_redemption(
    int $businessId,
    string $idempotencyHash,
    int $cardId,
    int $branchId,
    int $rewardId
): ?int {
    $statement = app_database()->prepare(
        'SELECT id, loyalty_card_id, branch_id, reward_id
         FROM reward_redemptions
         WHERE business_id = ? AND idempotency_key_hash = ?
         LIMIT 1'
    );
    $statement->bind_param('is', $businessId, $idempotencyHash);
    $statement->execute();
    $statement->bind_result($redemptionId, $matchedCardId, $matchedBranchId, $matchedRewardId);
    $found = $statement->fetch();
    $statement->close();

    if (!$found) {
        return null;
    }

    if (
        (int) $matchedCardId !== $cardId
        || (int) $matchedBranchId !== $branchId
        || (int) $matchedRewardId !== $rewardId
    ) {
        throw new AppHttpException(409, 'This request token was already used for a different action.');
    }

    return (int) $redemptionId;
}

function app_stamp_lock_card(mysqli $database, int $businessId, int $cardId): array
{
    $statement = $database->prepare(
        'SELECT lc.enrollment_version_id, cb.stamp_balance
         FROM loyalty_cards lc
         INNER JOIN customers c
            ON c.id = lc.customer_id
           AND c.business_id = lc.business_id
           AND c.status = \'active\'
         INNER JOIN loyalty_programs p
            ON p.id = lc.loyalty_program_id
           AND p.business_id = lc.business_id
           AND p.status = \'active\'
         INNER JOIN loyalty_program_versions v
            ON v.id = lc.enrollment_version_id
           AND v.loyalty_program_id = lc.loyalty_program_id
           AND v.business_id = lc.business_id
           AND v.status = \'active\'
           AND (v.effective_from IS NULL OR v.effective_from <= UTC_TIMESTAMP())
           AND (v.effective_until IS NULL OR v.effective_until > UTC_TIMESTAMP())
         INNER JOIN card_balances cb
            ON cb.loyalty_card_id = lc.id
           AND cb.business_id = lc.business_id
         WHERE lc.id = ?
           AND lc.business_id = ?
           AND lc.status = \'active\'
         LIMIT 1
         FOR UPDATE'
    );
    $statement->bind_param('ii', $cardId, $businessId);
    $statement->execute();
    $statement->bind_result($programVersionId, $stampBalance);
    $found = $statement->fetch();
    $statement->close();

    if (!$found) {
        throw new AppHttpException(404, 'Active loyalty card not found.');
    }

    return [
        'program_version_id' => (int) $programVersionId,
        'stamp_balance' => (int) $stampBalance,
    ];
}

function app_stamp_active_earning_value(mysqli $database, int $businessId, int $programVersionId): int
{
    $ruleKey = 'one-stamp-per-purchase';
    $statement = $database->prepare(
        'SELECT rule_config
         FROM loyalty_earning_rules
         WHERE business_id = ?
           AND program_version_id = ?
           AND rule_key = ?
           AND rule_type = \'per_purchase\'
           AND scope_type = \'business\'
           AND scope_id = 0
           AND status = \'active\'
         LIMIT 1'
    );
    $statement->bind_param('iis', $businessId, $programVersionId, $ruleKey);
    $statement->execute();
    $statement->bind_result($ruleConfig);
    $found = $statement->fetch();
    $statement->close();

    if (!$found || !is_string($ruleConfig)) {
        throw new AppHttpException(409, 'This card has no active per-purchase stamp rule.');
    }

    try {
        $decoded = json_decode($ruleConfig, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new AppHttpException(409, 'The active stamp rule is invalid.');
    }

    $stamps = is_array($decoded) ? filter_var($decoded['stamps_per_purchase'] ?? null, FILTER_VALIDATE_INT) : false;
    if (!is_int($stamps) || $stamps !== 1) {
        throw new AppHttpException(409, 'The active stamp rule is invalid.');
    }

    return $stamps;
}

function app_record_stamp_audit(
    mysqli $database,
    array $membership,
    string $eventType,
    int $subjectId,
    array $details
): void {
    $detailsJson = json_encode($details, JSON_THROW_ON_ERROR);
    $businessId = (int) $membership['business_id'];
    $staffUserId = (int) $membership['staff_user_id'];
    $membershipId = (int) $membership['id'];
    $subjectType = 'loyalty_card';

    $statement = $database->prepare(
        'INSERT INTO audit_logs
            (business_id, actor_user_id, actor_membership_id, event_type, subject_type, subject_id, details)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'iiissis',
        $businessId,
        $staffUserId,
        $membershipId,
        $eventType,
        $subjectType,
        $subjectId,
        $detailsJson
    );
    $statement->execute();
    $statement->close();
}

function app_issue_stamp(
    int $businessId,
    int $branchId,
    int $cardId,
    string $purchaseReference,
    string $idempotencyKey
): array {
    $membership = app_require_business_membership($businessId, 'stamp.issue', $branchId);
    app_require_stamp_schema();

    if ($cardId < 1) {
        throw new AppHttpException(422, 'Select an active loyalty card.');
    }

    $purchaseReference = app_stamp_normalize_purchase_reference($purchaseReference);
    $idempotencyHash = app_stamp_idempotency_hash($businessId, 'issue', $idempotencyKey);
    $purchaseReferenceHash = app_stamp_purchase_reference_hash($businessId, $branchId, $purchaseReference);
    $existingId = app_stamp_existing_issue(
        $businessId,
        $idempotencyHash,
        $cardId,
        $branchId,
        $purchaseReferenceHash
    );
    if ($existingId !== null) {
        return ['transaction_id' => $existingId, 'already_processed' => true];
    }

    $database = app_database();
    $database->begin_transaction();
    try {
        $card = app_stamp_lock_card($database, $businessId, $cardId);
        $stampsToIssue = app_stamp_active_earning_value(
            $database,
            $businessId,
            $card['program_version_id']
        );

        $insert = $database->prepare(
            'INSERT INTO stamp_transactions
                (business_id, loyalty_card_id, branch_id, actor_membership_id, program_version_id,
                 transaction_type, stamp_delta, idempotency_key_hash, purchase_reference_hash)
             VALUES (?, ?, ?, ?, ?, \'issue\', ?, ?, ?)'
        );
        $membershipId = (int) $membership['id'];
        $programVersionId = $card['program_version_id'];
        $insert->bind_param(
            'iiiiiiss',
            $businessId,
            $cardId,
            $branchId,
            $membershipId,
            $programVersionId,
            $stampsToIssue,
            $idempotencyHash,
            $purchaseReferenceHash
        );
        $insert->execute();
        $transactionId = (int) $database->insert_id;
        $insert->close();

        $balanceUpdate = $database->prepare(
            'UPDATE card_balances
             SET stamp_balance = stamp_balance + ?
             WHERE business_id = ? AND loyalty_card_id = ?'
        );
        $balanceUpdate->bind_param('iii', $stampsToIssue, $businessId, $cardId);
        $balanceUpdate->execute();
        if ($balanceUpdate->affected_rows !== 1) {
            $balanceUpdate->close();
            throw new RuntimeException('Card balance was not updated.');
        }
        $balanceUpdate->close();

        app_record_stamp_audit(
            $database,
            $membership,
            'stamp.issued',
            $cardId,
            ['branch_id' => $branchId, 'stamps' => $stampsToIssue, 'transaction_id' => $transactionId]
        );

        $database->commit();
        return [
            'transaction_id' => $transactionId,
            'stamp_balance' => $card['stamp_balance'] + $stampsToIssue,
            'already_processed' => false,
        ];
    } catch (Throwable $exception) {
        $database->rollback();
        if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
            $existingId = app_stamp_existing_issue(
                $businessId,
                $idempotencyHash,
                $cardId,
                $branchId,
                $purchaseReferenceHash
            );
            if ($existingId !== null) {
                return ['transaction_id' => $existingId, 'already_processed' => true];
            }
            throw new AppHttpException(409, 'This receipt already has a stamp for this branch.');
        }
        throw $exception;
    }
}

function app_redeem_reward(
    int $businessId,
    int $branchId,
    int $cardId,
    int $rewardId,
    string $idempotencyKey
): array {
    $membership = app_require_business_membership($businessId, 'reward.redeem', $branchId);
    app_require_stamp_schema();

    if ($cardId < 1 || $rewardId < 1) {
        throw new AppHttpException(422, 'Select an active loyalty card and reward.');
    }

    $idempotencyHash = app_stamp_idempotency_hash($businessId, 'redeem', $idempotencyKey);
    $existingId = app_stamp_existing_redemption(
        $businessId,
        $idempotencyHash,
        $cardId,
        $branchId,
        $rewardId
    );
    if ($existingId !== null) {
        return ['redemption_id' => $existingId, 'already_processed' => true];
    }

    $database = app_database();
    $database->begin_transaction();
    try {
        $card = app_stamp_lock_card($database, $businessId, $cardId);
        $rewardQuery = $database->prepare(
            'SELECT name, terms, stamp_cost
             FROM loyalty_rewards
             WHERE id = ?
               AND business_id = ?
               AND program_version_id = ?
               AND status = \'active\'
             LIMIT 1
             FOR UPDATE'
        );
        $programVersionId = $card['program_version_id'];
        $rewardQuery->bind_param('iii', $rewardId, $businessId, $programVersionId);
        $rewardQuery->execute();
        $rewardQuery->bind_result($rewardName, $rewardTerms, $stampCost);
        $rewardFound = $rewardQuery->fetch();
        $rewardQuery->close();

        if (!$rewardFound || !is_string($rewardName) || !is_string($rewardTerms)) {
            throw new AppHttpException(404, 'Active reward not found for this card.');
        }
        $stampCost = (int) $stampCost;
        if ($stampCost < 1) {
            throw new AppHttpException(409, 'The reward has an invalid stamp cost.');
        }
        if ($card['stamp_balance'] < $stampCost) {
            throw new AppHttpException(409, 'This card does not have enough stamps for that reward.');
        }

        $stampDelta = -$stampCost;
        $membershipId = (int) $membership['id'];
        $insert = $database->prepare(
            'INSERT INTO stamp_transactions
                (business_id, loyalty_card_id, branch_id, actor_membership_id, program_version_id,
                 transaction_type, stamp_delta, idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?, \'redeem\', ?, ?)'
        );
        $insert->bind_param(
            'iiiiiis',
            $businessId,
            $cardId,
            $branchId,
            $membershipId,
            $programVersionId,
            $stampDelta,
            $idempotencyHash
        );
        $insert->execute();
        $transactionId = (int) $database->insert_id;
        $insert->close();

        $balanceUpdate = $database->prepare(
            'UPDATE card_balances
             SET stamp_balance = stamp_balance - ?
             WHERE business_id = ?
               AND loyalty_card_id = ?
               AND stamp_balance >= ?'
        );
        $balanceUpdate->bind_param('iiii', $stampCost, $businessId, $cardId, $stampCost);
        $balanceUpdate->execute();
        if ($balanceUpdate->affected_rows !== 1) {
            $balanceUpdate->close();
            throw new AppHttpException(409, 'This card does not have enough stamps for that reward.');
        }
        $balanceUpdate->close();

        $redemptionInsert = $database->prepare(
            'INSERT INTO reward_redemptions
                (business_id, loyalty_card_id, branch_id, actor_membership_id, reward_id,
                 stamp_transaction_id, idempotency_key_hash, reward_name_snapshot,
                 reward_terms_snapshot, stamp_cost_snapshot)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $redemptionInsert->bind_param(
            'iiiiiisssi',
            $businessId,
            $cardId,
            $branchId,
            $membershipId,
            $rewardId,
            $transactionId,
            $idempotencyHash,
            $rewardName,
            $rewardTerms,
            $stampCost
        );
        $redemptionInsert->execute();
        $redemptionId = (int) $database->insert_id;
        $redemptionInsert->close();

        app_record_stamp_audit(
            $database,
            $membership,
            'reward.redeemed',
            $cardId,
            [
                'branch_id' => $branchId,
                'reward_id' => $rewardId,
                'stamp_cost' => $stampCost,
                'transaction_id' => $transactionId,
                'redemption_id' => $redemptionId,
            ]
        );

        $database->commit();
        return [
            'redemption_id' => $redemptionId,
            'stamp_balance' => $card['stamp_balance'] - $stampCost,
            'already_processed' => false,
        ];
    } catch (Throwable $exception) {
        $database->rollback();
        if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
            $existingId = app_stamp_existing_redemption(
                $businessId,
                $idempotencyHash,
                $cardId,
                $branchId,
                $rewardId
            );
            if ($existingId !== null) {
                return ['redemption_id' => $existingId, 'already_processed' => true];
            }
            throw new AppHttpException(409, 'This redemption request was already processed.');
        }
        throw $exception;
    }
}

function app_stamp_cards_for_business(int $businessId): array
{
    $statement = app_database()->prepare(
        'SELECT lc.id, COALESCE(c.display_name, CONCAT(\'Customer #\', c.id)), cb.stamp_balance,
                lc.enrollment_version_id
         FROM loyalty_cards lc
         INNER JOIN customers c
            ON c.id = lc.customer_id AND c.business_id = lc.business_id AND c.status = \'active\'
         INNER JOIN loyalty_programs p
            ON p.id = lc.loyalty_program_id AND p.business_id = lc.business_id AND p.status = \'active\'
         INNER JOIN loyalty_program_versions v
            ON v.id = lc.enrollment_version_id
           AND v.business_id = lc.business_id
           AND v.status = \'active\'
           AND (v.effective_from IS NULL OR v.effective_from <= UTC_TIMESTAMP())
           AND (v.effective_until IS NULL OR v.effective_until > UTC_TIMESTAMP())
         INNER JOIN card_balances cb
            ON cb.loyalty_card_id = lc.id AND cb.business_id = lc.business_id
         WHERE lc.business_id = ? AND lc.status = \'active\'
         ORDER BY c.display_name, lc.id'
    );
    $statement->bind_param('i', $businessId);
    $statement->execute();
    $statement->bind_result($cardId, $customerName, $stampBalance, $programVersionId);

    $cards = [];
    while ($statement->fetch()) {
        $cards[] = [
            'id' => (int) $cardId,
            'customer_name' => (string) $customerName,
            'stamp_balance' => (int) $stampBalance,
            'program_version_id' => (int) $programVersionId,
        ];
    }
    $statement->close();

    return $cards;
}

function app_stamp_rewards_for_version(int $businessId, int $programVersionId): array
{
    $statement = app_database()->prepare(
        'SELECT id, name, stamp_cost
         FROM loyalty_rewards
         WHERE business_id = ? AND program_version_id = ? AND status = \'active\'
         ORDER BY stamp_cost, name, id'
    );
    $statement->bind_param('ii', $businessId, $programVersionId);
    $statement->execute();
    $statement->bind_result($rewardId, $rewardName, $stampCost);

    $rewards = [];
    while ($statement->fetch()) {
        $rewards[] = [
            'id' => (int) $rewardId,
            'name' => (string) $rewardName,
            'stamp_cost' => (int) $stampCost,
        ];
    }
    $statement->close();

    return $rewards;
}
