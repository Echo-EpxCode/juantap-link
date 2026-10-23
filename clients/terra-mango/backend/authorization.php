<?php
declare(strict_types=1);

function app_require_admin_business(int $businessId, string $permission, ?int $branchId = null): array
{
    if ($businessId < 1) {
        throw new AppHttpException(404, 'Business not found.');
    }
    $admin = app_require_admin_user();
    $businessQuery = app_database()->prepare(
        'SELECT b.id, b.status
         FROM businesses b
         INNER JOIN admin_businesses ab
            ON ab.business_id = b.id AND ab.admin_user_id = ? AND ab.status = \'active\'
         WHERE b.id = ? AND b.status IN (\'active\', \'draft\')
         LIMIT 1'
    );
    $adminUserId = (int) $admin['id'];
    $businessQuery->bind_param('ii', $adminUserId, $businessId);
    $businessQuery->execute();
    $businessQuery->bind_result($matchedBusinessId, $businessStatus);
    $found = $businessQuery->fetch();
    $businessQuery->close();
    if (!$found) {
        throw new AppHttpException(403, 'Access denied.');
    }

    if ($branchId !== null) {
        if ($branchId < 1) {
            throw new AppHttpException(403, 'Access denied.');
        }
        $branchQuery = app_database()->prepare(
            'SELECT id FROM branches WHERE id = ? AND business_id = ? AND status = \'active\' LIMIT 1'
        );
        $branchQuery->bind_param('ii', $branchId, $businessId);
        $branchQuery->execute();
        $branchQuery->bind_result($matchedBranchId);
        $branchFound = $branchQuery->fetch();
        $branchQuery->close();
        if (!$branchFound) {
            throw new AppHttpException(403, 'Access denied.');
        }
    }

    return [
        'admin_user_id' => (int) $admin['id'],
        'business_id' => (int) $matchedBusinessId,
    ];
}

function app_current_admin_businesses(): array
{
    $adminId = (int) app_require_admin_user()['id'];
    $statement = app_database()->prepare(
        "SELECT b.id, b.display_name, b.status
         FROM businesses b
         INNER JOIN admin_businesses ab
            ON ab.business_id = b.id AND ab.admin_user_id = ? AND ab.status = 'active'
         WHERE b.status IN ('active', 'draft')
         ORDER BY b.display_name, b.id"
    );
    $statement->bind_param('i', $adminId);
    $statement->execute();
    $statement->bind_result($matchedBusinessId, $businessName, $businessStatus);
    $businesses = [];
    while ($statement->fetch()) {
        $businessId = (int) $matchedBusinessId;
        $businesses[] = [
            'business_id' => $businessId,
            'business_name' => (string) $businessName,
            'business_status' => (string) $businessStatus,
            'admin_user_id' => $adminId,
        ];
    }
    $statement->close();
    return $businesses;
}

function app_admin_business_branches(array $adminBusiness): array
{
    $businessId = (int) ($adminBusiness['business_id'] ?? 0);
    app_require_admin_business($businessId, 'branch.view');
    $statement = app_database()->prepare(
        'SELECT id, display_name FROM branches WHERE business_id = ? AND status = \'active\' ORDER BY id'
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

function app_active_branches_for_owner(int $businessId): array
{
    app_require_admin_business($businessId, 'branch.manage');
    $statement = app_database()->prepare(
        'SELECT id, display_name FROM branches WHERE business_id = ? AND status = \'active\' ORDER BY display_name, id'
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
