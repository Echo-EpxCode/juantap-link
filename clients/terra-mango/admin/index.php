<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

$staffUser = app_current_staff_user();
if ($staffUser === null) {
    header('Location: ' . app_admin_url('login.php'), true, 302);
    exit;
}

$errorMessage = null;
$noticeMessage = null;
$httpStatus = 200;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    try {
        app_verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = app_post_string('action', 40);
        $membershipId = app_post_positive_int('membership_id');

        if ($action === 'update-branches') {
            $branchIds = $_POST['branch_ids'] ?? [];
            if (!is_array($branchIds)) {
                throw new AppHttpException(422, 'The selected branches are invalid.');
            }
            app_update_membership_branches($membershipId, $branchIds);
            $_SESSION['admin_notice'] = 'Branch access was updated.';
            header('Location: ' . app_admin_url('index.php'), true, 303);
            exit;
        }

        if ($action === 'deactivate-membership') {
            app_deactivate_business_membership($membershipId);
            $_SESSION['admin_notice'] = 'Business access was deactivated.';
            header('Location: ' . app_admin_url('index.php'), true, 303);
            exit;
        }

        throw new AppHttpException(422, 'The requested action is invalid.');
    } catch (AppHttpException $exception) {
        $httpStatus = $exception->httpStatus();
        $errorMessage = $exception->safeMessage();
    }
}

if (isset($_SESSION['admin_notice']) && is_string($_SESSION['admin_notice'])) {
    $noticeMessage = $_SESSION['admin_notice'];
    unset($_SESSION['admin_notice']);
}

$memberships = app_current_staff_business_memberships();
$management = [];
foreach ($memberships as $membership) {
    if ($membership['role_code'] !== 'owner') {
        continue;
    }

    $businessId = (int) $membership['business_id'];
    $management[$businessId] = [
        'memberships' => app_active_business_memberships_for_owner($businessId),
        'branches' => app_active_branches_for_owner($businessId),
    ];
}

http_response_code($httpStatus);
app_html_page_headers();
$csrfToken = app_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Business admin | JuanTap Link</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #24352f; background: #f5f5ef; }
        body { margin: 0; }
        main { width: min(100% - 2rem, 860px); margin: 3rem auto; }
        .topbar, .business, .member { border: 1px solid #d8ded7; border-radius: 12px; background: #fff; }
        .topbar, .business { padding: 1.25rem; margin-bottom: 1rem; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .business { padding: 1.5rem; }
        .member { padding: 1rem; margin: .75rem 0; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { font-size: 1.5rem; margin-bottom: .25rem; }
        h2 { margin-bottom: .5rem; }
        h3 { font-size: 1rem; margin-bottom: .25rem; }
        .muted { color: #58675f; }
        .notice, .error { padding: .8rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .notice { background: #e7f4e8; }
        .error { background: #fff0ed; color: #812b1d; }
        .member-form { border-top: 1px solid #e3e7e2; margin-top: 1rem; padding-top: 1rem; }
        .branch-list { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin: .75rem 0; }
        label { display: inline-flex; align-items: center; gap: .4rem; }
        button { border: 0; border-radius: 7px; padding: .65rem .9rem; background: #285a42; color: #fff; font: inherit; cursor: pointer; }
        button.secondary { background: #59665f; }
        button.danger { background: #8d3425; }
        form { margin: .5rem 0; }
        .empty { padding: 1rem; border: 1px dashed #bdc9bd; border-radius: 8px; }
        @media (max-width: 560px) { main { margin: 1rem auto; } .topbar { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<main>
    <header class="topbar">
        <div>
            <h1>JuanTap Link admin</h1>
            <p class="muted">Signed in to staff account #<?= app_escape_html($staffUser['id']) ?>.</p>
        </div>
        <form method="post" action="<?= app_escape_html(app_admin_url('logout.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
            <button class="secondary" type="submit">Sign out</button>
        </form>
    </header>

    <?php if ($noticeMessage !== null): ?>
        <p class="notice" role="status"><?= app_escape_html($noticeMessage) ?></p>
    <?php endif; ?>
    <?php if ($errorMessage !== null): ?>
        <p class="error" role="alert"><?= app_escape_html($errorMessage) ?></p>
    <?php endif; ?>

    <?php if ($memberships === []): ?>
        <section class="business">
            <h2>No active business access</h2>
            <p class="muted">Your account is active, but it has no active business membership. Contact the business owner.</p>
        </section>
    <?php endif; ?>

    <?php foreach ($memberships as $membership): ?>
        <section class="business">
            <h2><?= app_escape_html($membership['business_name']) ?></h2>
            <p class="muted">
                <?= app_escape_html(app_role_label($membership['role_code'])) ?>
                · <?= $membership['business_status'] === 'draft' ? 'Setup in progress' : 'Active business' ?>
            </p>
            <?php if (app_role_has_permission($membership['role_code'], 'stamp.issue')): ?>
                <p><a href="<?= app_escape_html(app_admin_url('stamps.php') . '?business_id=' . (int) $membership['business_id']) ?>">Open stamp desk</a></p>
            <?php endif; ?>
            <?php if (app_role_has_permission($membership['role_code'], 'business.manage')): ?>
                <p><a href="<?= app_escape_html(app_admin_url('business.php') . '?business_id=' . (int) $membership['business_id']) ?>">Manage public page</a></p>
            <?php endif; ?>
            <?php $visibleBranches = app_membership_branch_records($membership); ?>
            <h3>Branch access</h3>
            <?php if ($visibleBranches === []): ?>
                <p class="muted">No active branches are assigned to this membership.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($visibleBranches as $branch): ?>
                        <li><?= app_escape_html($branch['name']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (isset($management[$membership['business_id']])): ?>
                <h3>Business memberships</h3>
                <?php foreach ($management[$membership['business_id']]['memberships'] as $managedMembership): ?>
                    <article class="member">
                        <h3>Staff account #<?= app_escape_html($managedMembership['staff_user_id']) ?> · <?= app_escape_html(app_role_label($managedMembership['role_code'])) ?></h3>
                        <?php if ($managedMembership['staff_status'] !== 'active'): ?>
                            <p class="muted">This staff account is globally deactivated.</p>
                        <?php endif; ?>
                        <?php $managedBranches = app_membership_branch_records($managedMembership); ?>
                        <p class="muted">
                            Assigned branches:
                            <?= $managedBranches === []
                                ? 'none'
                                : app_escape_html(implode(', ', array_column($managedBranches, 'name'))) ?>
                        </p>

                        <?php if (in_array($managedMembership['role_code'], ['manager', 'staff'], true) && $managedMembership['staff_status'] === 'active'): ?>
                            <form class="member-form" method="post" action="<?= app_escape_html(app_admin_url('index.php')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                                <input type="hidden" name="action" value="update-branches">
                                <input type="hidden" name="membership_id" value="<?= app_escape_html($managedMembership['id']) ?>">
                                <fieldset>
                                    <legend>Set branch access</legend>
                                    <div class="branch-list">
                                        <?php foreach ($management[$membership['business_id']]['branches'] as $branch): ?>
                                            <label>
                                                <input type="checkbox" name="branch_ids[]" value="<?= app_escape_html($branch['id']) ?>" <?= in_array($branch['id'], $managedMembership['allowed_branch_ids'], true) ? 'checked' : '' ?>>
                                                <?= app_escape_html($branch['name']) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($management[$membership['business_id']]['branches'] === []): ?>
                                        <p class="muted">There are no active branches. Saving will remove this membership’s branch access.</p>
                                    <?php endif; ?>
                                    <button type="submit">Save branch access</button>
                                </fieldset>
                            </form>
                            <form class="member-form" method="post" action="<?= app_escape_html(app_admin_url('index.php')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                                <input type="hidden" name="action" value="deactivate-membership">
                                <input type="hidden" name="membership_id" value="<?= app_escape_html($managedMembership['id']) ?>">
                                <button class="danger" type="submit">Deactivate business access</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
