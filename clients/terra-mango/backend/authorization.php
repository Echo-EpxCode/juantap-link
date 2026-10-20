<?php
declare(strict_types=1);

function app_role_permissions(): array
{
    return [
        'owner' => [
            'business.view',
            'business.manage',
            'branch.view',
            'branch.manage',
            'membership.manage',
            'product.manage',
            'product.availability.manage',
            'loyalty.manage',
            'stamp.issue',
            'stamp.reverse',
            'reward.redeem',
            'audit.view',
        ],
        'manager' => [
            'business.view',
            'branch.view',
            'product.availability.manage',
            'stamp.issue',
            'reward.redeem',
        ],
        'staff' => [
            'business.view',
            'branch.view',
            'stamp.issue',
            'reward.redeem',
        ],
    ];
}

function app_role_label(string $roleCode): string
{
    return match ($roleCode) {
        'owner' => 'Owner',
        'manager' => 'Manager',
        'staff' => 'Staff',
        default => 'Unrecognized role',
    };
}

function app_role_has_permission(string $roleCode, string $permission): bool
{
    $permissions = app_role_permissions()[$roleCode] ?? null;
    return is_array($permissions) && in_array($permission, $permissions, true);
}

function app_assert_business_scope(array $membership, int $businessId): void
{
    if (
        ($membership['status'] ?? null) !== 'active'
        || !in_array($membership['business_status'] ?? null, ['active', 'draft'], true)
        || ($membership['staff_status'] ?? null) !== 'active'
        || (int) ($membership['business_id'] ?? 0) !== $businessId
    ) {
        throw new AppHttpException(403, 'Access denied.');
    }
}

function app_assert_permission(array $membership, string $permission, ?int $branchId = null): void
{
    app_assert_business_scope($membership, (int) ($membership['business_id'] ?? 0));

    $roleCode = $membership['role_code'] ?? null;
    if (!is_string($roleCode) || !app_role_has_permission($roleCode, $permission)) {
        throw new AppHttpException(403, 'Access denied.');
    }

    $branchScopedPermissions = [
        'branch.view',
        'product.availability.manage',
        'stamp.issue',
        'stamp.reverse',
        'reward.redeem',
    ];
    if (in_array($permission, $branchScopedPermissions, true) && $branchId === null) {
        throw new AppHttpException(403, 'Access denied.');
    }

    if ($branchId !== null) {
        app_assert_branch_scope($membership, $branchId);
    }
}

function app_membership_branch_ids(array $membership): array
{
    return array_column(app_membership_branch_records($membership), 'id');
}

function app_membership_branch_records(array $membership): array
{
    $businessId = (int) ($membership['business_id'] ?? 0);
    $membershipId = (int) ($membership['id'] ?? 0);
    $roleCode = (string) ($membership['role_code'] ?? '');

    if ($businessId < 1 || $membershipId < 1 || !isset(app_role_permissions()[$roleCode])) {
        throw new AppHttpException(403, 'Access denied.');
    }

    if ($roleCode === 'owner') {
        $statement = app_database()->prepare(
            'SELECT id, display_name
             FROM branches
             WHERE business_id = ? AND status = \'active\'
             ORDER BY id'
        );
        $statement->bind_param('i', $businessId);
    } else {
        $statement = app_database()->prepare(
            'SELECT b.id, b.display_name
             FROM membership_branches mb
             INNER JOIN branches b
                ON b.id = mb.branch_id
               AND b.business_id = mb.business_id
             WHERE mb.business_membership_id = ?
               AND mb.business_id = ?
               AND b.status = \'active\'
             ORDER BY b.id'
        );
        $statement->bind_param('ii', $membershipId, $businessId);
    }

    $statement->execute();
    $statement->bind_result($branchId, $branchName);
    $branches = [];
    while ($statement->fetch()) {
        $branches[] = ['id' => (int) $branchId, 'name' => (string) $branchName];
    }
    $statement->close();

    return $branches;
}

function app_assert_branch_scope(array $membership, int $branchId): void
{
    app_assert_business_scope($membership, (int) ($membership['business_id'] ?? 0));

    if ($branchId < 1) {
        throw new AppHttpException(403, 'Access denied.');
    }

    $businessId = (int) $membership['business_id'];
    $membershipId = (int) ($membership['id'] ?? 0);
    $roleCode = (string) ($membership['role_code'] ?? '');

    if ($roleCode === 'owner') {
        $statement = app_database()->prepare(
            'SELECT id
             FROM branches
             WHERE id = ? AND business_id = ? AND status = \'active\'
             LIMIT 1'
        );
        $statement->bind_param('ii', $branchId, $businessId);
    } elseif ($roleCode === 'manager' || $roleCode === 'staff') {
        $statement = app_database()->prepare(
            'SELECT b.id
             FROM membership_branches mb
             INNER JOIN branches b
                ON b.id = mb.branch_id
               AND b.business_id = mb.business_id
             WHERE mb.business_membership_id = ?
               AND mb.business_id = ?
               AND mb.branch_id = ?
               AND b.status = \'active\'
             LIMIT 1'
        );
        $statement->bind_param('iii', $membershipId, $businessId, $branchId);
    } else {
        throw new AppHttpException(403, 'Access denied.');
    }

    $statement->execute();
    $statement->bind_result($matchedBranchId);
    $allowed = $statement->fetch();
    $statement->close();

    if (!$allowed) {
        throw new AppHttpException(403, 'Access denied.');
    }
}

function app_assert_branch_permission(array $membership, int $branchId, string $permission): void
{
    app_assert_permission($membership, $permission, $branchId);
}

function app_require_business_membership(int $businessId, string $permission, ?int $branchId = null): array
{
    if ($businessId < 1) {
        throw new AppHttpException(404, 'Business not found.');
    }

    $staffUser = app_require_staff_user();
    $statement = app_database()->prepare(
        'SELECT bm.id, bm.business_id, bm.staff_user_id, bm.role_code, bm.status,
                b.status AS business_status, u.status AS staff_status
         FROM business_memberships bm
         INNER JOIN businesses b ON b.id = bm.business_id
         INNER JOIN staff_users u ON u.id = bm.staff_user_id
         WHERE bm.business_id = ? AND bm.staff_user_id = ?
         LIMIT 1'
    );
    $statement->bind_param('ii', $businessId, $staffUser['id']);
    $statement->execute();
    $statement->bind_result(
        $membershipId,
        $matchedBusinessId,
        $staffUserId,
        $roleCode,
        $membershipStatus,
        $businessStatus,
        $staffStatus
    );
    $found = $statement->fetch();
    $statement->close();

    if (!$found) {
        throw new AppHttpException(403, 'Access denied.');
    }

    $membership = [
        'id' => (int) $membershipId,
        'business_id' => (int) $matchedBusinessId,
        'staff_user_id' => (int) $staffUserId,
        'role_code' => (string) $roleCode,
        'status' => (string) $membershipStatus,
        'business_status' => (string) $businessStatus,
        'staff_status' => (string) $staffStatus,
    ];

    app_assert_business_scope($membership, $businessId);
    app_assert_permission($membership, $permission, $branchId);
    return $membership;
}

function app_current_staff_business_memberships(): array
{
    $staffUser = app_require_staff_user();
    $statement = app_database()->prepare(
        'SELECT bm.id, bm.business_id, bm.staff_user_id, bm.role_code, bm.status,
                b.display_name, b.status AS business_status, u.status AS staff_status
         FROM business_memberships bm
         INNER JOIN businesses b ON b.id = bm.business_id
         INNER JOIN staff_users u ON u.id = bm.staff_user_id
         WHERE bm.staff_user_id = ?
           AND bm.status = \'active\'
           AND b.status IN (\'draft\', \'active\')
           AND u.status = \'active\'
         ORDER BY b.display_name, bm.id'
    );
    $statement->bind_param('i', $staffUser['id']);
    $statement->execute();
    $statement->store_result();
    $statement->bind_result(
        $membershipId,
        $businessId,
        $staffUserId,
        $roleCode,
        $membershipStatus,
        $businessName,
        $businessStatus,
        $staffStatus
    );

    $memberships = [];
    while ($statement->fetch()) {
        $membership = [
            'id' => (int) $membershipId,
            'business_id' => (int) $businessId,
            'staff_user_id' => (int) $staffUserId,
            'role_code' => (string) $roleCode,
            'status' => (string) $membershipStatus,
            'business_status' => (string) $businessStatus,
            'staff_status' => (string) $staffStatus,
            'business_name' => (string) $businessName,
        ];

        if (!isset(app_role_permissions()[$membership['role_code']])) {
            continue;
        }

        $membership['allowed_branch_ids'] = app_membership_branch_ids($membership);
        $memberships[] = $membership;
    }
    $statement->close();

    return $memberships;
}

function app_active_branches_for_owner(int $businessId): array
{
    app_require_business_membership($businessId, 'branch.manage');
    $statement = app_database()->prepare(
        'SELECT id, display_name
         FROM branches
         WHERE business_id = ? AND status = \'active\'
         ORDER BY display_name, id'
    );
    $statement->bind_param('i', $businessId);
    $statement->execute();
    $statement->bind_result($branchId, $branchName);

    $branches = [];
    while ($statement->fetch()) {
        $branches[] = ['id' => (int) $branchId, 'name' => (string) $branchName];
    }
    $statement->close();

    return $branches;
}

function app_active_business_memberships_for_owner(int $businessId): array
{
    app_require_business_membership($businessId, 'membership.manage');
    $statement = app_database()->prepare(
        'SELECT bm.id, bm.staff_user_id, bm.role_code, bm.status,
                u.status AS staff_status, b.status AS business_status
         FROM business_memberships bm
         INNER JOIN staff_users u ON u.id = bm.staff_user_id
         INNER JOIN businesses b ON b.id = bm.business_id
         WHERE bm.business_id = ?
           AND bm.status = \'active\'
           AND b.status IN (\'draft\', \'active\')
         ORDER BY bm.role_code, bm.id'
    );
    $statement->bind_param('i', $businessId);
    $statement->execute();
    $statement->store_result();
    $statement->bind_result(
        $membershipId,
        $staffUserId,
        $roleCode,
        $membershipStatus,
        $staffStatus,
        $businessStatus
    );

    $memberships = [];
    while ($statement->fetch()) {
        $membership = [
            'id' => (int) $membershipId,
            'business_id' => $businessId,
            'staff_user_id' => (int) $staffUserId,
            'role_code' => (string) $roleCode,
            'status' => (string) $membershipStatus,
            'business_status' => (string) $businessStatus,
            'staff_status' => (string) $staffStatus,
        ];

        if (!isset(app_role_permissions()[$membership['role_code']])) {
            continue;
        }

        $membership['allowed_branch_ids'] = app_membership_branch_ids($membership);
        $memberships[] = $membership;
    }
    $statement->close();

    return $memberships;
}

function app_record_membership_audit(
    mysqli $database,
    int $businessId,
    int $actorUserId,
    int $actorMembershipId,
    string $eventType,
    int $subjectMembershipId,
    array $details
): void {
    $detailsJson = json_encode($details, JSON_THROW_ON_ERROR);
    $statement = $database->prepare(
        'INSERT INTO audit_logs
            (business_id, actor_user_id, actor_membership_id, event_type, subject_type, subject_id, details)
         VALUES (?, ?, ?, ?, \'business_membership\', ?, ?)'
    );
    $statement->bind_param(
        'iiisis',
        $businessId,
        $actorUserId,
        $actorMembershipId,
        $eventType,
        $subjectMembershipId,
        $detailsJson
    );
    $statement->execute();
    $statement->close();
}

function app_update_membership_branches(int $targetMembershipId, array $branchIds): void
{
    if ($targetMembershipId < 1 || count($branchIds) > 100) {
        throw new AppHttpException(422, 'Membership or branch selection is invalid.');
    }

    $validatedBranchIds = [];
    foreach ($branchIds as $branchId) {
        $validated = filter_var($branchId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($validated === false) {
            throw new AppHttpException(422, 'Membership or branch selection is invalid.');
        }
        $validatedBranchIds[] = (int) $validated;
    }
    $branchIds = array_values(array_unique($validatedBranchIds));
    if (in_array(0, $branchIds, true) || ($branchIds !== [] && min($branchIds) < 1)) {
        throw new AppHttpException(422, 'Membership or branch selection is invalid.');
    }

    $database = app_database();
    $database->begin_transaction();

    try {
        $targetQuery = $database->prepare(
            'SELECT id, business_id, role_code, status
             FROM business_memberships
             WHERE id = ?
             FOR UPDATE'
        );
        $targetQuery->bind_param('i', $targetMembershipId);
        $targetQuery->execute();
        $targetQuery->bind_result($targetId, $businessId, $targetRole, $targetStatus);
        $targetFound = $targetQuery->fetch();
        $targetQuery->close();

        if (!$targetFound || $targetStatus !== 'active' || !in_array($targetRole, ['manager', 'staff'], true)) {
            throw new AppHttpException(404, 'Membership not found.');
        }

        $actorMembership = app_require_business_membership((int) $businessId, 'membership.manage');
        foreach ($branchIds as $branchId) {
            $branchCheck = $database->prepare(
                'SELECT id
                 FROM branches
                 WHERE id = ? AND business_id = ? AND status = \'active\'
                 LIMIT 1'
            );
            $branchCheck->bind_param('ii', $branchId, $businessId);
            $branchCheck->execute();
            $branchCheck->bind_result($matchedBranchId);
            $branchFound = $branchCheck->fetch();
            $branchCheck->close();

            if (!$branchFound) {
                throw new AppHttpException(422, 'A selected branch is unavailable.');
            }
        }

        $previousQuery = $database->prepare(
            'SELECT branch_id
             FROM membership_branches
             WHERE business_membership_id = ? AND business_id = ?
             ORDER BY branch_id'
        );
        $previousQuery->bind_param('ii', $targetId, $businessId);
        $previousQuery->execute();
        $previousQuery->store_result();
        $previousQuery->bind_result($previousBranchId);
        $previousBranchIds = [];
        while ($previousQuery->fetch()) {
            $previousBranchIds[] = (int) $previousBranchId;
        }
        $previousQuery->close();

        $remove = $database->prepare(
            'DELETE FROM membership_branches
             WHERE business_membership_id = ? AND business_id = ?'
        );
        $remove->bind_param('ii', $targetId, $businessId);
        $remove->execute();
        $remove->close();

        if ($branchIds !== []) {
            $insert = $database->prepare(
                'INSERT INTO membership_branches (business_membership_id, business_id, branch_id)
                 VALUES (?, ?, ?)'
            );
            foreach ($branchIds as $branchId) {
                $insert->bind_param('iii', $targetId, $businessId, $branchId);
                $insert->execute();
            }
            $insert->close();
        }

        app_record_membership_audit(
            $database,
            (int) $businessId,
            (int) $actorMembership['staff_user_id'],
            (int) $actorMembership['id'],
            'membership.branches.updated',
            (int) $targetId,
            [
                'role' => (string) $targetRole,
                'previous_branch_ids' => $previousBranchIds,
                'branch_ids' => $branchIds,
            ]
        );

        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function app_deactivate_business_membership(int $targetMembershipId): void
{
    if ($targetMembershipId < 1) {
        throw new AppHttpException(404, 'Membership not found.');
    }

    $database = app_database();
    $database->begin_transaction();

    try {
        $targetQuery = $database->prepare(
            'SELECT id, business_id, role_code, status
             FROM business_memberships
             WHERE id = ?
             FOR UPDATE'
        );
        $targetQuery->bind_param('i', $targetMembershipId);
        $targetQuery->execute();
        $targetQuery->bind_result($targetId, $businessId, $targetRole, $targetStatus);
        $targetFound = $targetQuery->fetch();
        $targetQuery->close();

        if (!$targetFound || $targetStatus !== 'active' || !in_array($targetRole, ['manager', 'staff'], true)) {
            throw new AppHttpException(404, 'Membership not found.');
        }

        $actorMembership = app_require_business_membership((int) $businessId, 'membership.manage');
        $update = $database->prepare(
            'UPDATE business_memberships
             SET status = \'inactive\'
             WHERE id = ? AND business_id = ? AND status = \'active\'
               AND role_code IN (\'manager\', \'staff\')'
        );
        $update->bind_param('ii', $targetId, $businessId);
        $update->execute();
        $deactivated = $update->affected_rows === 1;
        $update->close();

        if (!$deactivated) {
            throw new AppHttpException(409, 'Membership could not be deactivated.');
        }

        app_record_membership_audit(
            $database,
            (int) $businessId,
            (int) $actorMembership['staff_user_id'],
            (int) $actorMembership['id'],
            'membership.deactivated',
            (int) $targetId,
            ['role' => (string) $targetRole]
        );

        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}
