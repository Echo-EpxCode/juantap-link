<?php
declare(strict_types=1);

function app_business_local_preview_allowed(): bool
{
    if ((app_config()['app']['environment'] ?? null) !== 'development') {
        return false;
    }

    $remoteAddress = strtolower((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $remotePackedAddress = @inet_pton($remoteAddress);
    if (
        $remotePackedAddress === false
        || ($remoteAddress !== '::1'
            && !str_starts_with($remoteAddress, '127.')
            && !str_starts_with($remoteAddress, '::ffff:127.'))
    ) {
        return false;
    }

    $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host = parse_url('http://' . $hostHeader, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return false;
    }

    $host = strtolower(trim(rtrim($host, '.'), '[]'));
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test')) {
        return true;
    }

    $packedAddress = @inet_pton($host);
    if ($packedAddress === false) {
        return false;
    }

    return $host === '::1' || str_starts_with($host, '127.') || str_starts_with($host, '::ffff:127.');
}

function app_business_public_url_is_valid(string $url): bool
{
    if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    return in_array($scheme, ['http', 'https'], true)
        && isset($parts['host'])
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function app_business_public_data(string $slug, bool $allowDraft): array
{
    try {
        $slug = app_validate_slug(strtolower(trim($slug)));
    } catch (AppHttpException $exception) {
        throw new AppHttpException(404, 'Business page not found.');
    }

    $database = app_database();
    $statusCondition = $allowDraft
        ? "status IN ('active', 'draft')"
        : "status = 'active'";
    $businessQuery = $database->prepare(
        'SELECT id, slug, display_name, tagline, description
         FROM businesses
         WHERE slug = ? AND ' . $statusCondition . '
         LIMIT 1'
    );
    $businessQuery->bind_param('s', $slug);
    $businessQuery->execute();
    $businessQuery->bind_result($businessId, $businessSlug, $displayName, $tagline, $description);
    $found = $businessQuery->fetch();
    $businessQuery->close();

    if (!$found) {
        throw new AppHttpException(404, 'Business page not found.');
    }

    $businessId = (int) $businessId;
    $branchQuery = $database->prepare(
        'SELECT display_name, address, public_phone
         FROM branches
         WHERE business_id = ? AND status = \'active\'
         ORDER BY display_name, id'
    );
    $branchQuery->bind_param('i', $businessId);
    $branchQuery->execute();
    $branchQuery->bind_result($branchName, $branchAddress, $branchPhone);
    $branches = [];
    while ($branchQuery->fetch()) {
        $branches[] = [
            'name' => (string) $branchName,
            'address' => (string) ($branchAddress ?? ''),
            'phone' => (string) ($branchPhone ?? ''),
        ];
    }
    $branchQuery->close();

    $linkQuery = $database->prepare(
        'SELECT link_type, label, url, icon
         FROM business_links
         WHERE business_id = ? AND is_active = 1
         ORDER BY link_type, sort_order, id'
    );
    $linkQuery->bind_param('i', $businessId);
    $linkQuery->execute();
    $linkQuery->bind_result($linkType, $linkLabel, $linkUrl, $linkIcon);
    $links = [];
    while ($linkQuery->fetch()) {
        $linkUrl = (string) $linkUrl;
        if (!app_business_public_url_is_valid($linkUrl)) {
            continue;
        }

        $icon = (string) ($linkIcon ?? '');
        if (!preg_match('/\Abi-[a-z0-9-]{1,60}\z/', $icon)) {
            $icon = 'bi-link-45deg';
        }

        $links[] = [
            'type' => (string) $linkType,
            'label' => (string) $linkLabel,
            'url' => $linkUrl,
            'icon' => $icon,
        ];
    }
    $linkQuery->close();

    return [
        'business' => [
            'slug' => (string) $businessSlug,
            'name' => (string) $displayName,
            'tagline' => (string) ($tagline ?? ''),
            'description' => (string) ($description ?? ''),
        ],
        'branches' => $branches,
        'links' => $links,
    ];
}

function app_business_post_optional_string(string $field, int $maxBytes): string
{
    $value = $_POST[$field] ?? '';
    if (!is_string($value)) {
        throw new AppHttpException(422, 'A submitted field is invalid.');
    }

    $value = trim($value);
    if (strlen($value) > $maxBytes) {
        throw new AppHttpException(422, 'A submitted field is too long.');
    }

    return $value;
}

function app_business_post_nonnegative_int(string $field): int
{
    $value = $_POST[$field] ?? '0';
    if (!is_string($value)) {
        throw new AppHttpException(422, 'A submitted number is invalid.');
    }
    if ($value === '') {
        return 0;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => 65535],
    ]);
    if ($validated === false) {
        throw new AppHttpException(422, 'A submitted number is invalid.');
    }

    return (int) $validated;
}

function app_business_profile_for_owner(int $businessId): array
{
    app_require_admin_business($businessId, 'business.manage');
    $statement = app_database()->prepare(
        'SELECT id, slug, display_name, tagline, description, status
         FROM businesses
         WHERE id = ? AND status IN (\'draft\', \'active\')
         LIMIT 1'
    );
    $statement->bind_param('i', $businessId);
    $statement->execute();
    $statement->bind_result($id, $slug, $displayName, $tagline, $description, $status);
    $found = $statement->fetch();
    $statement->close();

    if (!$found) {
        throw new AppHttpException(404, 'Business not found.');
    }

    return [
        'id' => (int) $id,
        'slug' => (string) $slug,
        'display_name' => (string) $displayName,
        'tagline' => (string) ($tagline ?? ''),
        'description' => (string) ($description ?? ''),
        'status' => (string) $status,
    ];
}

function app_business_branches_for_owner(int $businessId): array
{
    app_require_admin_business($businessId, 'business.manage');
    $statement = app_database()->prepare(
        'SELECT id, slug, display_name, address, public_phone, status
         FROM branches
         WHERE business_id = ?
         ORDER BY status, display_name, id'
    );
    $statement->bind_param('i', $businessId);
    $statement->execute();
    $statement->bind_result($id, $slug, $displayName, $address, $phone, $status);

    $branches = [];
    while ($statement->fetch()) {
        $branches[] = [
            'id' => (int) $id,
            'slug' => (string) $slug,
            'display_name' => (string) $displayName,
            'address' => (string) ($address ?? ''),
            'public_phone' => (string) ($phone ?? ''),
            'status' => (string) $status,
        ];
    }
    $statement->close();

    return $branches;
}

function app_business_links_for_owner(int $businessId): array
{
    app_require_admin_business($businessId, 'business.manage');
    $statement = app_database()->prepare(
        'SELECT id, link_type, label, url, icon, sort_order, is_active
         FROM business_links
         WHERE business_id = ?
         ORDER BY link_type, sort_order, id'
    );
    $statement->bind_param('i', $businessId);
    $statement->execute();
    $statement->bind_result($id, $type, $label, $url, $icon, $sortOrder, $isActive);

    $links = [];
    while ($statement->fetch()) {
        $links[] = [
            'id' => (int) $id,
            'type' => (string) $type,
            'label' => (string) $label,
            'url' => (string) $url,
            'icon' => (string) ($icon ?? ''),
            'sort_order' => (int) $sortOrder,
            'is_active' => (bool) $isActive,
        ];
    }
    $statement->close();

    return $links;
}

function app_business_record_audit(
    mysqli $database,
    array $membership,
    int $businessId,
    string $eventType,
    string $subjectType,
    int $subjectId,
    array $details
): void {
    $detailsJson = json_encode($details, JSON_THROW_ON_ERROR);
    $statement = $database->prepare(
        'INSERT INTO audit_logs
            (business_id, actor_admin_id, event_type, subject_type, subject_id, details)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $actorAdminId = (int) $membership['admin_user_id'];
    $statement->bind_param(
        'iissis',
        $businessId,
        $actorAdminId,
        $eventType,
        $subjectType,
        $subjectId,
        $detailsJson
    );
    $statement->execute();
    $statement->close();
}

function app_business_update_profile(int $businessId, string $name, string $tagline, string $description): void
{
    $name = trim($name);
    $tagline = trim($tagline);
    $description = trim($description);
    if ($name === '' || strlen($name) > 160 || strlen($tagline) > 255 || strlen($description) > 10000) {
        throw new AppHttpException(422, 'Business profile fields are invalid.');
    }

    $database = app_database();
    $database->begin_transaction();
    try {
        $membership = app_require_admin_business($businessId, 'business.manage');
        $current = $database->prepare(
            'SELECT id FROM businesses
             WHERE id = ? AND status IN (\'draft\', \'active\')
             FOR UPDATE'
        );
        $current->bind_param('i', $businessId);
        $current->execute();
        $current->bind_result($matchedBusinessId);
        $found = $current->fetch();
        $current->close();
        if (!$found) {
            throw new AppHttpException(404, 'Business not found.');
        }

        $taglineValue = $tagline === '' ? null : $tagline;
        $descriptionValue = $description === '' ? null : $description;
        $update = $database->prepare(
            'UPDATE businesses SET display_name = ?, tagline = ?, description = ? WHERE id = ?'
        );
        $update->bind_param('sssi', $name, $taglineValue, $descriptionValue, $businessId);
        $update->execute();
        $update->close();

        app_business_record_audit(
            $database,
            $membership,
            $businessId,
            'business.profile.updated',
            'business',
            $businessId,
            ['fields' => ['display_name', 'tagline', 'description']]
        );
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function app_business_save_branch(
    int $businessId,
    ?int $branchId,
    string $slug,
    string $name,
    string $address,
    string $phone
): void {
    $name = trim($name);
    $address = trim($address);
    $phone = trim($phone);
    if (
        $name === ''
        || strlen($name) > 160
        || strlen($address) > 4000
        || strlen($phone) > 40
        || ($phone !== '' && !preg_match('/\A\+?[0-9(). -]*[0-9][0-9(). -]*\z/', $phone))
    ) {
        throw new AppHttpException(422, 'Branch details are invalid.');
    }

    if ($branchId === null) {
        $slug = strtolower(trim($slug));
        if (strlen($slug) > 100 || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug)) {
            throw new AppHttpException(422, 'Branch slug must use lowercase letters, numbers, and single hyphens.');
        }
    }

    $database = app_database();
    $database->begin_transaction();
    try {
        $membership = app_require_admin_business($businessId, 'business.manage');
        $addressValue = $address === '' ? null : $address;
        $phoneValue = $phone === '' ? null : $phone;

        if ($branchId === null) {
            $insert = $database->prepare(
                'INSERT INTO branches (business_id, slug, display_name, address, public_phone, status)
                 VALUES (?, ?, ?, ?, ?, \'active\')'
            );
            $insert->bind_param('issss', $businessId, $slug, $name, $addressValue, $phoneValue);
            $insert->execute();
            $branchId = (int) $database->insert_id;
            $insert->close();
            $eventType = 'branch.created';
            $details = ['slug' => $slug];
        } else {
            $current = $database->prepare(
                'SELECT id FROM branches
                 WHERE id = ? AND business_id = ? AND status = \'active\'
                 FOR UPDATE'
            );
            $current->bind_param('ii', $branchId, $businessId);
            $current->execute();
            $current->bind_result($matchedBranchId);
            $found = $current->fetch();
            $current->close();
            if (!$found) {
                throw new AppHttpException(404, 'Branch not found.');
            }

            $update = $database->prepare(
                'UPDATE branches SET display_name = ?, address = ?, public_phone = ?
                 WHERE id = ? AND business_id = ?'
            );
            $update->bind_param('sssii', $name, $addressValue, $phoneValue, $branchId, $businessId);
            $update->execute();
            $update->close();
            $eventType = 'branch.details.updated';
            $details = ['fields' => ['display_name', 'address', 'public_phone']];
        }

        app_business_record_audit(
            $database,
            $membership,
            $businessId,
            $eventType,
            'branch',
            (int) $branchId,
            $details
        );
        $database->commit();
    } catch (mysqli_sql_exception $exception) {
        $database->rollback();
        if ((int) $exception->getCode() === 1062) {
            throw new AppHttpException(409, 'A branch with that slug already exists.');
        }
        throw $exception;
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function app_business_save_link(
    int $businessId,
    ?int $linkId,
    string $type,
    string $label,
    string $url,
    string $icon,
    int $sortOrder,
    bool $isActive
): void {
    $type = trim($type);
    $label = trim($label);
    $url = trim($url);
    $icon = trim($icon);
    if (
        !in_array($type, ['social', 'review', 'ordering'], true)
        || $label === ''
        || strlen($label) > 120
        || !app_business_public_url_is_valid($url)
        || strlen($icon) > 64
        || ($icon !== '' && !preg_match('/\Abi-[a-z0-9-]{1,60}\z/', $icon))
        || $sortOrder < 0
        || $sortOrder > 65535
    ) {
        throw new AppHttpException(422, 'Business link details are invalid.');
    }

    $database = app_database();
    $database->begin_transaction();
    try {
        $membership = app_require_admin_business($businessId, 'business.manage');
        $iconValue = $icon === '' ? null : $icon;
        $activeValue = $isActive ? 1 : 0;

        if ($linkId === null) {
            $insert = $database->prepare(
                'INSERT INTO business_links
                    (business_id, link_type, label, url, icon, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->bind_param(
                'issssii',
                $businessId,
                $type,
                $label,
                $url,
                $iconValue,
                $sortOrder,
                $activeValue
            );
            $insert->execute();
            $linkId = (int) $database->insert_id;
            $insert->close();
            $eventType = 'business_link.created';
        } else {
            $current = $database->prepare(
                'SELECT id FROM business_links WHERE id = ? AND business_id = ? FOR UPDATE'
            );
            $current->bind_param('ii', $linkId, $businessId);
            $current->execute();
            $current->bind_result($matchedLinkId);
            $found = $current->fetch();
            $current->close();
            if (!$found) {
                throw new AppHttpException(404, 'Business link not found.');
            }

            $update = $database->prepare(
                'UPDATE business_links
                 SET link_type = ?, label = ?, url = ?, icon = ?, sort_order = ?, is_active = ?
                 WHERE id = ? AND business_id = ?'
            );
            $update->bind_param(
                'ssssiiii',
                $type,
                $label,
                $url,
                $iconValue,
                $sortOrder,
                $activeValue,
                $linkId,
                $businessId
            );
            $update->execute();
            $update->close();
            $eventType = 'business_link.updated';
        }

        app_business_record_audit(
            $database,
            $membership,
            $businessId,
            $eventType,
            'business_link',
            (int) $linkId,
            ['type' => $type, 'active' => $isActive]
        );
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}
