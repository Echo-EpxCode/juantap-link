<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

$staffUser = app_current_staff_user();
if ($staffUser === null) {
    header('Location: ' . app_admin_url('login.php'), true, 302);
    exit;
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$businessId = 0;
$errorMessage = null;
$noticeMessage = null;
$httpStatus = 200;

if ($requestMethod === 'POST') {
    $businessId = 0;
    try {
        app_verify_csrf_token($_POST['csrf_token'] ?? null);
        $businessId = app_post_positive_int('business_id');
        $action = app_post_string('action', 32);

        if ($action === 'save-profile') {
            app_business_update_profile(
                $businessId,
                app_post_string('display_name', 160),
                app_business_post_optional_string('tagline', 255),
                app_business_post_optional_string('description', 10000)
            );
            $_SESSION['business_notice'] = 'Business profile saved.';
        } elseif ($action === 'save-branch') {
            app_business_save_branch(
                $businessId,
                app_post_positive_int('branch_id'),
                '',
                app_post_string('display_name', 160),
                app_business_post_optional_string('address', 4000),
                app_business_post_optional_string('public_phone', 40)
            );
            $_SESSION['business_notice'] = 'Branch details saved.';
        } elseif ($action === 'create-branch') {
            app_business_save_branch(
                $businessId,
                null,
                app_post_string('slug', 100),
                app_post_string('display_name', 160),
                app_business_post_optional_string('address', 4000),
                app_business_post_optional_string('public_phone', 40)
            );
            $_SESSION['business_notice'] = 'Branch added.';
        } elseif ($action === 'save-link') {
            $linkIdValue = $_POST['link_id'] ?? '';
            if (!is_string($linkIdValue)) {
                throw new AppHttpException(422, 'Business link selection is invalid.');
            }
            $linkId = $linkIdValue === ''
                ? null
                : filter_var($linkIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($linkId === false) {
                throw new AppHttpException(422, 'Business link selection is invalid.');
            }

            $type = app_post_string('link_type', 32);
            $label = app_post_string('label', 120);
            $url = app_post_string('url', 2048);
            $icon = app_business_post_optional_string('icon', 64);
            $sortOrder = app_business_post_nonnegative_int('sort_order');
            $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1';
            app_business_save_link(
                $businessId,
                $linkId === null ? null : (int) $linkId,
                $type,
                $label,
                $url,
                $icon,
                $sortOrder,
                $isActive
            );
            $_SESSION['business_notice'] = 'Business link saved.';
        } else {
            throw new AppHttpException(422, 'The requested business action is invalid.');
        }

        header(
            'Location: ' . app_admin_url('business.php') . '?' . http_build_query(['business_id' => $businessId]),
            true,
            303
        );
        exit;
    } catch (AppHttpException $exception) {
        $httpStatus = $exception->httpStatus();
        $errorMessage = $exception->safeMessage();
    } catch (Throwable $exception) {
        error_log('JuanTap Link business admin exception: ' . get_class($exception));
        $httpStatus = 500;
        $errorMessage = 'The business change could not be saved. Check the local PHP error log.';
    }
} elseif ($requestMethod !== 'GET') {
    $httpStatus = 405;
    $errorMessage = 'Method not allowed.';
}

if (isset($_SESSION['business_notice']) && is_string($_SESSION['business_notice'])) {
    $noticeMessage = $_SESSION['business_notice'];
    unset($_SESSION['business_notice']);
}

$ownerMemberships = array_values(array_filter(
    app_current_staff_business_memberships(),
    static fn (array $membership): bool => $membership['role_code'] === 'owner'
        && app_role_has_permission($membership['role_code'], 'business.manage')
));
$requestedBusinessId = filter_var($_GET['business_id'] ?? ($businessId > 0 ? (string) $businessId : null), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$selectedMembership = null;
foreach ($ownerMemberships as $membership) {
    if ($requestedBusinessId !== false && (int) $membership['business_id'] === (int) $requestedBusinessId) {
        $selectedMembership = $membership;
        break;
    }
}
if ($selectedMembership === null && $ownerMemberships !== [] && $requestedBusinessId === false) {
    $selectedMembership = $ownerMemberships[0];
}

$business = null;
$branches = [];
$links = [];
if ($selectedMembership !== null) {
    $selectedBusinessId = (int) $selectedMembership['business_id'];
    app_require_business_membership($selectedBusinessId, 'business.manage');
    $business = app_business_profile_for_owner($selectedBusinessId);
    $branches = app_business_branches_for_owner($selectedBusinessId);
    $links = app_business_links_for_owner($selectedBusinessId);
} else {
    $selectedBusinessId = 0;
    $httpStatus = 403;
}

http_response_code($httpStatus);
app_html_page_headers();
$csrfToken = app_csrf_token();
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$sitePath = rtrim(dirname(dirname($scriptName)), '/');
$publicPageUrl = ($sitePath === '' ? '' : $sitePath) . '/';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Business profile | JuanTap Link</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #24352f; background: #f5f5ef; }
        body { margin: 0; }
        main { width: min(100% - 2rem, 960px); margin: 2rem auto; }
        header, section, article { border: 1px solid #d8ded7; border-radius: 12px; background: #fff; padding: 1.25rem; margin-bottom: 1rem; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { margin-bottom: .25rem; font-size: 1.5rem; }
        h2 { font-size: 1.15rem; }
        h3 { font-size: 1rem; }
        .muted { color: #58675f; }
        .notice, .error { padding: .8rem 1rem; border-radius: 8px; }
        .notice { background: #e7f4e8; }
        .error { background: #fff0ed; color: #812b1d; }
        .toolbar, .row { display: flex; flex-wrap: wrap; align-items: end; gap: 1rem; }
        .toolbar form, .row form { margin: 0; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        label { display: grid; gap: .35rem; margin: .7rem 0; font-weight: 650; }
        input, textarea, select { box-sizing: border-box; width: 100%; padding: .7rem; border: 1px solid #aeb9af; border-radius: 7px; font: inherit; }
        textarea { min-height: 5rem; resize: vertical; }
        button, .button { display: inline-block; border: 0; border-radius: 7px; padding: .7rem 1rem; background: #285a42; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .secondary { background: #59665f; }
        .toolbar { align-items: center; }
        .toolbar form { display: flex; align-items: end; gap: .7rem; }
        .toolbar label { min-width: 220px; margin: 0; }
        article { padding: 1rem; }
        .checkbox { display: flex; align-items: center; gap: .5rem; }
        .checkbox input { width: auto; }
        @media (max-width: 620px) { header { align-items: flex-start; flex-direction: column; } .fields { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>Public business page</h1>
            <p class="muted">Edit the public profile, branches, and business links.</p>
        </div>
        <nav class="toolbar">
            <?php if ($business !== null): ?>
                <a class="button secondary" href="<?= app_escape_html($publicPageUrl) ?>" target="_blank" rel="noopener noreferrer">Preview public page</a>
            <?php endif; ?>
            <a class="button secondary" href="<?= app_escape_html(app_admin_url('index.php')) ?>">Admin home</a>
            <form method="post" action="<?= app_escape_html(app_admin_url('logout.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                <button class="secondary" type="submit">Sign out</button>
            </form>
        </nav>
    </header>

    <?php if ($noticeMessage !== null): ?>
        <p class="notice" role="status"><?= app_escape_html($noticeMessage) ?></p>
    <?php endif; ?>
    <?php if ($errorMessage !== null): ?>
        <p class="error" role="alert"><?= app_escape_html($errorMessage) ?></p>
    <?php endif; ?>

    <?php if ($ownerMemberships === []): ?>
        <section><h2>Owner access required</h2><p class="muted">Only a business owner can manage public page details.</p></section>
    <?php elseif ($business === null): ?>
        <section><h2>Business not found</h2><p class="muted">Select a business where your account has owner access.</p></section>
    <?php else: ?>
        <?php if (count($ownerMemberships) > 1): ?>
            <section>
                <form class="toolbar" method="get" action="<?= app_escape_html(app_admin_url('business.php')) ?>">
                    <label>Business
                        <select name="business_id">
                            <?php foreach ($ownerMemberships as $membership): ?>
                                <option value="<?= app_escape_html($membership['business_id']) ?>" <?= (int) $membership['business_id'] === $selectedBusinessId ? 'selected' : '' ?>><?= app_escape_html($membership['business_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit">Show business</button>
                </form>
            </section>
        <?php endif; ?>

        <section>
            <h2>Business profile</h2>
            <p class="muted">Status: <?= $business['status'] === 'draft' ? 'Draft (local preview only)' : 'Active' ?> · Public URL slug: <code><?= app_escape_html($business['slug']) ?></code></p>
            <form method="post" action="<?= app_escape_html(app_admin_url('business.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
                <input type="hidden" name="action" value="save-profile">
                <label>Business name
                    <input name="display_name" maxlength="160" value="<?= app_escape_html($business['display_name']) ?>" required>
                </label>
                <label>Tagline
                    <input name="tagline" maxlength="255" value="<?= app_escape_html($business['tagline']) ?>">
                </label>
                <label>Public description
                    <textarea name="description" maxlength="10000"><?= app_escape_html($business['description']) ?></textarea>
                </label>
                <button type="submit">Save profile</button>
            </form>
        </section>

        <section>
            <h2>Branches</h2>
            <p class="muted">Addresses and phone numbers entered here appear on the public page. Inactive branches stay in the database and are not displayed publicly.</p>
            <?php foreach ($branches as $branch): ?>
                <article>
                    <h3><?= app_escape_html($branch['display_name']) ?> · <?= app_escape_html($branch['status']) ?></h3>
                    <form method="post" action="<?= app_escape_html(app_admin_url('business.php')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                        <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
                        <input type="hidden" name="branch_id" value="<?= app_escape_html($branch['id']) ?>">
                        <input type="hidden" name="action" value="save-branch">
                        <div class="fields">
                            <label>Branch slug
                                <input value="<?= app_escape_html($branch['slug']) ?>" readonly>
                            </label>
                            <label>Branch name
                                <input name="display_name" maxlength="160" value="<?= app_escape_html($branch['display_name']) ?>" required <?= $branch['status'] !== 'active' ? 'disabled' : '' ?>>
                            </label>
                        </div>
                        <label>Public address
                            <textarea name="address" maxlength="4000" <?= $branch['status'] !== 'active' ? 'disabled' : '' ?>><?= app_escape_html($branch['address']) ?></textarea>
                        </label>
                        <label>Public phone
                            <input name="public_phone" type="tel" maxlength="40" pattern="\+?[0-9(). -]*[0-9][0-9(). -]*" value="<?= app_escape_html($branch['public_phone']) ?>" <?= $branch['status'] !== 'active' ? 'disabled' : '' ?>>
                        </label>
                        <?php if ($branch['status'] === 'active'): ?>
                            <button type="submit">Save branch</button>
                        <?php else: ?>
                            <p class="muted">This branch is inactive and read-only here.</p>
                        <?php endif; ?>
                    </form>
                </article>
            <?php endforeach; ?>

            <article>
                <h3>Add a branch</h3>
                <form method="post" action="<?= app_escape_html(app_admin_url('business.php')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                    <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
                    <input type="hidden" name="action" value="create-branch">
                    <div class="fields">
                        <label>Branch slug
                            <input name="slug" maxlength="100" pattern="[a-z0-9]+(-[a-z0-9]+)*" required>
                        </label>
                        <label>Branch name
                            <input name="display_name" maxlength="160" required>
                        </label>
                    </div>
                    <label>Public address
                        <textarea name="address" maxlength="4000"></textarea>
                    </label>
                    <label>Public phone
                        <input name="public_phone" type="tel" maxlength="40" pattern="\+?[0-9(). -]*[0-9][0-9(). -]*">
                    </label>
                    <button type="submit">Add branch</button>
                </form>
            </article>
        </section>

        <section>
            <h2>Public links</h2>
            <p class="muted">Only active links are shown on the landing page. Use full HTTPS URLs where available.</p>
            <?php foreach ($links as $link): ?>
                <article>
                    <form method="post" action="<?= app_escape_html(app_admin_url('business.php')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                        <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
                        <input type="hidden" name="link_id" value="<?= app_escape_html($link['id']) ?>">
                        <input type="hidden" name="action" value="save-link">
                        <div class="fields">
                            <label>Link type
                                <select name="link_type">
                                    <option value="social" <?= $link['type'] === 'social' ? 'selected' : '' ?>>Social</option>
                                    <option value="review" <?= $link['type'] === 'review' ? 'selected' : '' ?>>Review</option>
                                    <option value="ordering" <?= $link['type'] === 'ordering' ? 'selected' : '' ?>>Ordering</option>
                                </select>
                            </label>
                            <label>Label
                                <input name="label" maxlength="120" value="<?= app_escape_html($link['label']) ?>" required>
                            </label>
                        </div>
                        <label>URL
                            <input name="url" type="url" maxlength="2048" value="<?= app_escape_html($link['url']) ?>" required>
                        </label>
                        <div class="fields">
                            <label>Bootstrap icon class (optional)
                                <input name="icon" maxlength="64" pattern="bi-[a-z0-9-]+" value="<?= app_escape_html($link['icon']) ?>" placeholder="bi-facebook">
                            </label>
                            <label>Sort order
                                <input name="sort_order" type="number" min="0" max="65535" value="<?= app_escape_html($link['sort_order']) ?>">
                            </label>
                        </div>
                        <label class="checkbox">
                            <input type="checkbox" name="is_active" value="1" <?= $link['is_active'] ? 'checked' : '' ?>>
                            Show this link publicly
                        </label>
                        <button type="submit">Save link</button>
                    </form>
                </article>
            <?php endforeach; ?>

            <article>
                <h3>Add a link</h3>
                <form method="post" action="<?= app_escape_html(app_admin_url('business.php')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                    <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
                    <input type="hidden" name="link_id" value="">
                    <input type="hidden" name="action" value="save-link">
                    <div class="fields">
                        <label>Link type
                            <select name="link_type">
                                <option value="social">Social</option>
                                <option value="review">Review</option>
                                <option value="ordering">Ordering</option>
                            </select>
                        </label>
                        <label>Label
                            <input name="label" maxlength="120" required>
                        </label>
                    </div>
                    <label>URL
                        <input name="url" type="url" maxlength="2048" required>
                    </label>
                    <div class="fields">
                        <label>Bootstrap icon class (optional)
                            <input name="icon" maxlength="64" pattern="bi-[a-z0-9-]+" placeholder="bi-instagram">
                        </label>
                        <label>Sort order
                            <input name="sort_order" type="number" min="0" max="65535" value="0">
                        </label>
                    </div>
                    <label class="checkbox">
                        <input type="checkbox" name="is_active" value="1" checked>
                        Show this link publicly
                    </label>
                    <button type="submit">Add link</button>
                </form>
            </article>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
