<?php
declare(strict_types=1);

function app_record_customer_audit(
    mysqli $database,
    int $businessId,
    int $adminUserId,
    string $eventType,
    int $customerId,
    array $details = []
): void {
    $detailsJson = json_encode($details, JSON_THROW_ON_ERROR);
    $subjectType = 'customer';
    $statement = $database->prepare(
        'INSERT INTO audit_logs
            (business_id, actor_admin_id, event_type, subject_type, subject_id, details)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param('iissis', $businessId, $adminUserId, $eventType, $subjectType, $customerId, $detailsJson);
    $statement->execute();
    $statement->close();
}

function app_pending_customers_for_business(int $businessId): array
{
    app_require_admin_business($businessId, 'customer.approve');
    $statement = app_database()->prepare(
        'SELECT c.id, c.display_name, c.created_at, cc.contact_value_ciphertext
         FROM customers c
         INNER JOIN customer_contacts cc
            ON cc.customer_id = c.id AND cc.business_id = c.business_id AND cc.channel = \'email\'
         WHERE c.business_id = ? AND c.approval_status = \'pending\'
         ORDER BY c.created_at, c.id'
    );
    $statement->bind_param('i', $businessId);
    $statement->execute();
    $statement->bind_result($customerId, $fullName, $createdAt, $emailCiphertext);
    $customers = [];
    while ($statement->fetch()) {
        $customers[] = [
            'id' => (int) $customerId,
            'full_name' => (string) $fullName,
            'created_at' => (string) $createdAt,
            'email' => app_customer_email_decrypt((string) $emailCiphertext) ?? 'Unavailable email',
        ];
    }
    $statement->close();
    return $customers;
}

function app_approved_customers_for_business(int $businessId): array
{
    app_require_admin_business($businessId, 'customer.manage');
    $statement = app_database()->prepare(
        'SELECT c.id, c.display_name, c.created_at, c.status,
                (SELECT cc.contact_value_ciphertext
                 FROM customer_contacts cc
                 WHERE cc.customer_id = c.id AND cc.business_id = c.business_id AND cc.channel = \'email\'
                 ORDER BY cc.id LIMIT 1),
                COALESCE(SUM(cb.stamp_balance), 0)
         FROM customers c
         LEFT JOIN loyalty_cards lc
            ON lc.customer_id = c.id AND lc.business_id = c.business_id AND lc.status = \'active\'
         LEFT JOIN card_balances cb
            ON cb.loyalty_card_id = lc.id AND cb.business_id = lc.business_id
         WHERE c.business_id = ? AND c.approval_status = \'approved\'
         GROUP BY c.id, c.display_name, c.created_at, c.status
         ORDER BY c.display_name, c.id'
    );
    $statement->bind_param('i', $businessId);
    $statement->execute();
    $statement->bind_result($customerId, $fullName, $createdAt, $status, $emailCiphertext, $stampBalance);
    $customers = [];
    while ($statement->fetch()) {
        $customers[] = [
            'id' => (int) $customerId,
            'full_name' => (string) $fullName,
            'created_at' => (string) $createdAt,
            'status' => (string) $status,
            'email' => is_string($emailCiphertext)
                ? (app_customer_email_decrypt($emailCiphertext) ?? 'Unavailable email')
                : 'No email on record',
            'stamp_balance' => (int) $stampBalance,
        ];
    }
    $statement->close();
    return $customers;
}

function app_update_customer_details(
    int $businessId,
    int $customerId,
    string $fullName,
    string $email,
    string $newPassword = ''
): void {
    $fullName = trim($fullName);
    $normalizedEmail = app_normalize_customer_email($email);
    if ($fullName === '' || strlen($fullName) > 160 || $normalizedEmail === null) {
        throw new AppHttpException(422, 'Enter a valid full name and email address.');
    }
    if ($newPassword !== '' && (strlen($newPassword) < 8 || strlen($newPassword) > 72)) {
        throw new AppHttpException(422, 'A replacement password must be between 8 and 72 bytes.');
    }

    $admin = app_require_admin_business($businessId, 'customer.manage');
    $database = app_database();
    $database->begin_transaction();
    try {
        $customerQuery = $database->prepare(
            'SELECT id FROM customers
             WHERE id = ? AND business_id = ? AND approval_status = \'approved\' AND status = \'active\'
             LIMIT 1 FOR UPDATE'
        );
        $customerQuery->bind_param('ii', $customerId, $businessId);
        $customerQuery->execute();
        $customerQuery->bind_result($matchedCustomerId);
        $found = $customerQuery->fetch();
        $customerQuery->close();
        if (!$found) {
            throw new AppHttpException(404, 'Active approved customer not found.');
        }

        if ($newPassword === '') {
            $updateCustomer = $database->prepare(
                'UPDATE customers SET display_name = ? WHERE id = ? AND business_id = ?'
            );
            $updateCustomer->bind_param('sii', $fullName, $customerId, $businessId);
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Customer password hash could not be created.');
            }
            $updateCustomer = $database->prepare(
                'UPDATE customers SET display_name = ?, password_hash = ? WHERE id = ? AND business_id = ?'
            );
            $updateCustomer->bind_param('ssii', $fullName, $passwordHash, $customerId, $businessId);
        }
        $updateCustomer->execute();
        $updateCustomer->close();

        $emailLookupHash = app_customer_email_lookup_hash($normalizedEmail);
        if (!is_string($emailLookupHash)) {
            throw new AppHttpException(422, 'Enter a valid email address.');
        }
        $emailCiphertext = app_customer_email_encrypt($normalizedEmail);
        $contactQuery = $database->prepare(
            'SELECT id FROM customer_contacts
             WHERE customer_id = ? AND business_id = ? AND channel = \'email\'
             ORDER BY id LIMIT 1 FOR UPDATE'
        );
        $contactQuery->bind_param('ii', $customerId, $businessId);
        $contactQuery->execute();
        $contactQuery->bind_result($contactId);
        $hasContact = $contactQuery->fetch();
        $contactQuery->close();
        if ($hasContact) {
            $contactUpdate = $database->prepare(
                'UPDATE customer_contacts
                 SET contact_value_ciphertext = ?, contact_lookup_hash = ?, is_verified = 0, verified_at = NULL
                 WHERE id = ? AND business_id = ? AND customer_id = ?'
            );
            $contactUpdate->bind_param('ssiii', $emailCiphertext, $emailLookupHash, $contactId, $businessId, $customerId);
            $contactUpdate->execute();
            $contactUpdate->close();
        } else {
            $channel = 'email';
            $isVerified = 0;
            $contactInsert = $database->prepare(
                'INSERT INTO customer_contacts
                    (business_id, customer_id, channel, contact_value_ciphertext, contact_lookup_hash, is_verified)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $contactInsert->bind_param('iisssi', $businessId, $customerId, $channel, $emailCiphertext, $emailLookupHash, $isVerified);
            $contactInsert->execute();
            $contactInsert->close();
        }

        $changedFields = ['full_name', 'email'];
        if ($newPassword !== '') {
            $changedFields[] = 'password';
        }
        app_record_customer_audit(
            $database,
            $businessId,
            (int) $admin['admin_user_id'],
            'customer.updated',
            $customerId,
            ['fields' => $changedFields]
        );
        $database->commit();
    } catch (mysqli_sql_exception $exception) {
        $database->rollback();
        if ((int) $exception->getCode() === 1062) {
            throw new AppHttpException(409, 'Another customer account already uses that email address.');
        }
        throw $exception;
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function app_deactivate_customer(int $businessId, int $customerId): void
{
    $admin = app_require_admin_business($businessId, 'customer.manage');
    $database = app_database();
    $database->begin_transaction();
    try {
        $update = $database->prepare(
            'UPDATE customers
             SET status = \'inactive\'
             WHERE id = ? AND business_id = ? AND approval_status = \'approved\' AND status = \'active\''
        );
        $update->bind_param('ii', $customerId, $businessId);
        $update->execute();
        $deactivated = $update->affected_rows === 1;
        $update->close();
        if (!$deactivated) {
            throw new AppHttpException(404, 'Active approved customer not found.');
        }

        app_record_customer_audit(
            $database,
            $businessId,
            (int) $admin['admin_user_id'],
            'customer.deactivated',
            $customerId
        );
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function app_decide_customer(int $businessId, int $customerId, string $decision, string $reason = ''): void
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new AppHttpException(422, 'Select an approval decision.');
    }
    if (strlen($reason) > 500 || ($decision === 'rejected' && trim($reason) === '')) {
        throw new AppHttpException(422, 'Enter a short reason when rejecting an account.');
    }

    $admin = app_require_admin_business($businessId, 'customer.approve');
    $adminUserId = (int) $admin['admin_user_id'];
    $database = app_database();
    $database->begin_transaction();
    try {
        $customerQuery = $database->prepare(
            'SELECT id, approval_status
             FROM customers
             WHERE id = ? AND business_id = ?
             LIMIT 1 FOR UPDATE'
        );
        $customerQuery->bind_param('ii', $customerId, $businessId);
        $customerQuery->execute();
        $customerQuery->bind_result($matchedCustomerId, $currentStatus);
        $found = $customerQuery->fetch();
        $customerQuery->close();
        if (!$found) {
            throw new AppHttpException(404, 'Customer account not found.');
        }
        if ($currentStatus !== 'pending') {
            throw new AppHttpException(409, 'This account has already been reviewed.');
        }

        if ($decision === 'approved') {
            $programQuery = $database->prepare(
                'SELECT p.id, v.id
                 FROM loyalty_programs p
                 INNER JOIN loyalty_program_versions v
                    ON v.loyalty_program_id = p.id AND v.business_id = p.business_id
                 WHERE p.business_id = ? AND p.status = \'active\' AND v.status = \'active\'
                   AND (v.effective_from IS NULL OR v.effective_from <= UTC_TIMESTAMP())
                   AND (v.effective_until IS NULL OR v.effective_until > UTC_TIMESTAMP())
                 ORDER BY v.version_number DESC
                 LIMIT 1'
            );
            $programQuery->bind_param('i', $businessId);
            $programQuery->execute();
            $programQuery->bind_result($programId, $versionId);
            $hasProgram = $programQuery->fetch();
            $programQuery->close();
            if (!$hasProgram) {
                throw new AppHttpException(409, 'Customer approval is unavailable until a loyalty program is active.');
            }

            $cardQuery = $database->prepare(
                'SELECT id FROM loyalty_cards WHERE customer_id = ? AND loyalty_program_id = ? LIMIT 1'
            );
            $cardQuery->bind_param('ii', $customerId, $programId);
            $cardQuery->execute();
            $cardQuery->bind_result($cardId);
            $hasCard = $cardQuery->fetch();
            $cardQuery->close();
            if (!$hasCard) {
                $cardStatus = 'active';
                $cardInsert = $database->prepare(
                    'INSERT INTO loyalty_cards
                        (business_id, customer_id, loyalty_program_id, enrollment_version_id, status)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $cardInsert->bind_param('iiiis', $businessId, $customerId, $programId, $versionId, $cardStatus);
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
            }
        }

        $rejectionReason = $decision === 'rejected' ? trim($reason) : null;
        $update = $database->prepare(
            'UPDATE customers
             SET approval_status = ?, reviewed_by_admin_id = ?, approval_decided_at = UTC_TIMESTAMP(), rejection_reason = ?
             WHERE id = ? AND business_id = ? AND approval_status = \'pending\''
        );
        $update->bind_param('sisii', $decision, $adminUserId, $rejectionReason, $customerId, $businessId);
        $update->execute();
        if ($update->affected_rows !== 1) {
            $update->close();
            throw new AppHttpException(409, 'This account has already been reviewed.');
        }
        $update->close();

        app_record_customer_audit(
            $database,
            $businessId,
            $adminUserId,
            'customer.' . $decision,
            $customerId,
            $rejectionReason === null ? [] : ['reason' => $rejectionReason]
        );
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function app_customer_loyalty_view(int $customerId): array
{
    $customer = app_require_customer();
    if ($customerId !== (int) $customer['id']) {
        throw new AppHttpException(403, 'Access denied.');
    }
    if ($customer['approval_status'] !== 'approved') {
        return ['approval_status' => $customer['approval_status'], 'card' => null, 'history' => [], 'rewards' => []];
    }

    $database = app_database();
    $statement = $database->prepare(
        'SELECT lc.id, cb.stamp_balance, p.name, v.id
         FROM loyalty_cards lc
         INNER JOIN card_balances cb ON cb.loyalty_card_id = lc.id AND cb.business_id = lc.business_id
         INNER JOIN loyalty_programs p ON p.id = lc.loyalty_program_id AND p.business_id = lc.business_id
         INNER JOIN loyalty_program_versions v
            ON v.id = lc.enrollment_version_id AND v.loyalty_program_id = p.id AND v.business_id = p.business_id
         WHERE lc.customer_id = ? AND lc.business_id = ? AND lc.status = \'active\'
         ORDER BY lc.id DESC LIMIT 1'
    );
    $statement->bind_param('ii', $customerId, $customer['business_id']);
    $statement->execute();
    $statement->bind_result($cardId, $stampBalance, $programName, $versionId);
    $hasCard = $statement->fetch();
    $statement->close();
    if (!$hasCard) {
        return ['approval_status' => 'approved', 'card' => null, 'history' => [], 'rewards' => []];
    }

    $businessId = (int) $customer['business_id'];
    $cardId = (int) $cardId;
    $historyQuery = $database->prepare(
        'SELECT st.transaction_type, st.stamp_delta, st.created_at, rr.reward_name_snapshot
         FROM stamp_transactions st
         LEFT JOIN reward_redemptions rr
            ON rr.stamp_transaction_id = st.id
           AND rr.business_id = st.business_id
           AND rr.loyalty_card_id = st.loyalty_card_id
         WHERE st.business_id = ? AND st.loyalty_card_id = ?
         ORDER BY st.created_at DESC, st.id DESC LIMIT 100'
    );
    $historyQuery->bind_param('ii', $businessId, $cardId);
    $historyQuery->execute();
    $historyQuery->bind_result($transactionType, $stampDelta, $createdAt, $rewardName);
    $history = [];
    while ($historyQuery->fetch()) {
        $history[] = [
            'type' => (string) $transactionType,
            'delta' => (int) $stampDelta,
            'created_at' => (string) $createdAt,
            'reward_name' => is_string($rewardName) ? $rewardName : null,
        ];
    }
    $historyQuery->close();

    $rewardQuery = $database->prepare(
        'SELECT id, name, stamp_cost FROM loyalty_rewards
         WHERE business_id = ? AND program_version_id = ? AND status = \'active\'
         ORDER BY stamp_cost, name'
    );
    $versionId = (int) $versionId;
    $rewardQuery->bind_param('ii', $businessId, $versionId);
    $rewardQuery->execute();
    $rewardQuery->bind_result($rewardId, $rewardName, $rewardCost);
    $rewards = [];
    while ($rewardQuery->fetch()) {
        $rewards[] = ['id' => (int) $rewardId, 'name' => (string) $rewardName, 'stamp_cost' => (int) $rewardCost];
    }
    $rewardQuery->close();

    return [
        'approval_status' => 'approved',
        'card' => ['id' => $cardId, 'stamp_balance' => (int) $stampBalance, 'program_name' => (string) $programName],
        'history' => $history,
        'rewards' => $rewards,
    ];
}
