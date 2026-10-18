<?php
declare(strict_types=1);

/*
 * These helpers enforce tenant and branch scope only.
 * Callers must load a current, active membership from the database first.
 * Role-specific permissions are intentionally deferred for approval in Phase 2.
 */
function app_assert_business_scope(array $membership, int $businessId): void
{
    if (
        ($membership['status'] ?? null) !== 'active'
        || (int) ($membership['business_id'] ?? 0) !== $businessId
    ) {
        throw new AppHttpException(403, 'Access denied.');
    }
}

function app_assert_branch_scope(array $membership, int $branchId): void
{
    $allowedBranchIds = $membership['allowed_branch_ids'] ?? null;
    if (!is_array($allowedBranchIds)) {
        throw new AppHttpException(403, 'Access denied.');
    }

    $allowed = false;
    foreach ($allowedBranchIds as $allowedBranchId) {
        if ((int) $allowedBranchId === $branchId) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed) {
        throw new AppHttpException(403, 'Access denied.');
    }
}
