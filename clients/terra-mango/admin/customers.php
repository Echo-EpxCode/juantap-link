<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

if (app_current_admin_user() === null) {
    header('Location: ' . app_admin_url('login.php'), true, 302);
    exit;
}

$businesses = app_current_admin_businesses();
$requestedBusinessId = filter_var($_REQUEST['business_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedBusinessId = 0;
foreach ($businesses as $business) {
    if ($requestedBusinessId !== false && $requestedBusinessId !== null && (int) $business['business_id'] === (int) $requestedBusinessId) {
        $selectedBusinessId = (int) $business['business_id'];
        break;
    }
}
if ($selectedBusinessId === 0 && $businesses !== []) {
    $selectedBusinessId = (int) $businesses[0]['business_id'];
}

$errorMessage = null;
$noticeMessage = null;
$httpStatus = 200;
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    try {
        app_verify_csrf_token($_POST['csrf_token'] ?? null);
        $customerId = app_post_positive_int('customer_id');
        $action = app_post_string('action', 16);

        if ($action === 'approve' || $action === 'reject') {
            $reasonValue = $_POST['reason'] ?? '';
            if (!is_string($reasonValue) || strlen($reasonValue) > 500) {
                throw new AppHttpException(422, 'The rejection reason is invalid.');
            }
            $decision = $action === 'approve' ? 'approved' : 'rejected';
            app_decide_customer($selectedBusinessId, $customerId, $decision, trim($reasonValue));
            $noticeMessage = $decision === 'approved' ? 'Customer account approved.' : 'Customer account rejected.';
        } elseif ($action === 'edit') {
            $fullName = app_post_string('full_name', 160);
            $email = app_post_string('email', 254);
            $newPassword = $_POST['new_password'] ?? '';
            if (!is_string($newPassword)) {
                throw new AppHttpException(422, 'The replacement password is invalid.');
            }
            app_update_customer_details($selectedBusinessId, $customerId, $fullName, $email, $newPassword);
            $noticeMessage = 'Customer details updated.';
        } elseif ($action === 'delete') {
            app_deactivate_customer($selectedBusinessId, $customerId);
            $noticeMessage = 'Customer deleted. Their loyalty history has been retained.';
        } else {
            throw new AppHttpException(422, 'The requested customer action is invalid.');
        }
    } catch (AppHttpException $exception) {
        $httpStatus = $exception->httpStatus();
        $errorMessage = $exception->safeMessage();
    } catch (Throwable $exception) {
        error_log('JuanTap Link customer management exception: ' . get_class($exception));
        $httpStatus = 500;
        $errorMessage = 'The customer account could not be updated. Check the local PHP error log.';
    }
}

$pendingCustomers = $selectedBusinessId > 0 ? app_pending_customers_for_business($selectedBusinessId) : [];
$approvedCustomers = $selectedBusinessId > 0 ? app_approved_customers_for_business($selectedBusinessId) : [];
app_html_page_headers();
http_response_code($httpStatus);
$csrfToken = app_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer accounts | JuanTap Link</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #24352f; background: #f5f5ef; }
        body { margin: 0; }
        main { width: min(100% - 2rem, 1120px); margin: 2rem auto; }
        header, section { border: 1px solid #d8ded7; border-radius: 12px; background: #fff; padding: 1.25rem; margin-bottom: 1rem; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        h1, h2, p { margin-top: 0; }
        h1 { font-size: 1.5rem; }
        h2 { font-size: 1.15rem; }
        .muted { color: #58675f; }
        .notice, .error { padding: .8rem; border-radius: 8px; }
        .notice { background: #e7f4e8; }
        .error { background: #fff0ed; color: #812b1d; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: .75rem .6rem; border-bottom: 1px solid #e3e7e2; text-align: left; vertical-align: middle; }
        th { color: #58675f; font-size: .9rem; white-space: nowrap; }
        td.actions { white-space: nowrap; }
        form.inline { display: inline; margin: 0 .25rem 0 0; }
        button { border: 0; border-radius: 7px; padding: .55rem .8rem; background: #285a42; color: #fff; font: inherit; cursor: pointer; }
        button.secondary { background: #59665f; }
        button.danger { background: #8d3425; }
        a { color: #285a42; }
        dialog { width: min(100% - 2rem, 480px); border: 1px solid #d8ded7; border-radius: 12px; padding: 1.5rem; color: #24352f; }
        dialog::backdrop { background: rgb(23 35 29 / 55%); }
        dialog h2 { margin-bottom: .5rem; }
        label { display: block; margin: 1rem 0 .35rem; font-weight: 650; }
        input { box-sizing: border-box; width: 100%; padding: .7rem; border: 1px solid #aeb9af; border-radius: 7px; font: inherit; }
        .dialog-actions { display: flex; justify-content: flex-end; gap: .6rem; margin-top: 1.25rem; }
        @media (max-width: 640px) { main { margin: 1rem auto; } header { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<main>
    <header>
        <div><h1>Customer accounts</h1><p class="muted">Review requests and manage approved customers.</p></div>
        <a href="<?= app_escape_html(app_admin_url('index.php')) ?>">Admin home</a>
    </header>
    <?php if ($noticeMessage !== null): ?><p class="notice" role="status"><?= app_escape_html($noticeMessage) ?></p><?php endif; ?>
    <?php if ($errorMessage !== null): ?><p class="error" role="alert"><?= app_escape_html($errorMessage) ?></p><?php endif; ?>
    <?php if ($selectedBusinessId < 1): ?>
        <section><p>No owner-admin business access is configured.</p></section>
    <?php else: ?>
        <section aria-labelledby="pending-heading">
            <h2 id="pending-heading">Customers awaiting approval</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Customer</th><th>Email</th><th>Registered (UTC)</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if ($pendingCustomers === []): ?>
                        <tr><td colspan="4" class="muted">No customers are waiting for approval.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pendingCustomers as $customer): ?>
                            <tr>
                                <td><?= app_escape_html($customer['full_name']) ?></td>
                                <td><?= app_escape_html($customer['email']) ?></td>
                                <td><?= app_escape_html($customer['created_at']) ?></td>
                                <td class="actions">
                                    <form class="inline" method="post" action="<?= app_escape_html(app_admin_url('customers.php')) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                                        <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
                                        <input type="hidden" name="customer_id" value="<?= app_escape_html($customer['id']) ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit">Approve</button>
                                    </form>
                                    <form class="inline" method="post" action="<?= app_escape_html(app_admin_url('customers.php')) ?>"
                                        onsubmit="const reason = prompt('Reason for rejecting this account:'); if (reason === null || !reason.trim()) return false; this.elements.reason.value = reason.trim(); return true;">
                                        <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                                        <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
                                        <input type="hidden" name="customer_id" value="<?= app_escape_html($customer['id']) ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="reason" value="">
                                        <button class="danger" type="submit">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted">Approval is manual; the email address has not been verified.</p>
        </section>

        <section aria-labelledby="approved-heading">
            <h2 id="approved-heading">Approved customers</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Customer</th><th>Email</th><th>Stamps</th><th>Account status</th><th>Joined (UTC)</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if ($approvedCustomers === []): ?>
                        <tr><td colspan="6" class="muted">No approved customers yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($approvedCustomers as $customer): ?>
                            <tr>
                                <td><?= app_escape_html($customer['full_name']) ?></td>
                                <td><?= app_escape_html($customer['email']) ?></td>
                                <td><?= app_escape_html($customer['stamp_balance']) ?></td>
                                <td><?= $customer['status'] === 'active' ? 'Active' : 'Deleted' ?></td>
                                <td><?= app_escape_html($customer['created_at']) ?></td>
                                <td class="actions">
                                    <?php if ($customer['status'] === 'active'): ?>
                                        <button type="button" class="secondary" data-edit-customer
                                            data-id="<?= app_escape_html($customer['id']) ?>"
                                            data-name="<?= app_escape_html($customer['full_name']) ?>"
                                            data-email="<?= app_escape_html($customer['email'] === 'Unavailable email' || $customer['email'] === 'No email on record' ? '' : $customer['email']) ?>">Edit</button>
                                        <form class="inline" method="post" action="<?= app_escape_html(app_admin_url('customers.php')) ?>"
                                            onsubmit="return confirm('Delete this customer account? The customer will lose login access, but their stamp and redemption history will be retained.');">
                                            <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                                            <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
                                            <input type="hidden" name="customer_id" value="<?= app_escape_html($customer['id']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button class="danger" type="submit">Delete</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">Deleted</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>

<dialog id="edit-customer-dialog" aria-labelledby="edit-customer-heading">
    <form method="post" action="<?= app_escape_html(app_admin_url('customers.php')) ?>">
        <h2 id="edit-customer-heading">Edit customer</h2>
        <p class="muted">Leave the password blank to keep the current password.</p>
        <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
        <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
        <input type="hidden" name="customer_id" id="edit-customer-id">
        <input type="hidden" name="action" value="edit">
        <label for="edit-customer-name">Full name</label>
        <input id="edit-customer-name" name="full_name" maxlength="160" required>
        <label for="edit-customer-email">Email / username</label>
        <input id="edit-customer-email" name="email" type="email" maxlength="254" required>
        <label for="edit-customer-password">New password (optional)</label>
        <input id="edit-customer-password" name="new_password" type="password" minlength="8" maxlength="72" autocomplete="new-password">
        <div class="dialog-actions">
            <button type="button" class="secondary" data-close-dialog>Cancel</button>
            <button type="submit">Save changes</button>
        </div>
    </form>
</dialog>
<script>
    const editDialog = document.getElementById('edit-customer-dialog');
    document.querySelectorAll('[data-edit-customer]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('edit-customer-id').value = button.dataset.id || '';
            document.getElementById('edit-customer-name').value = button.dataset.name || '';
            document.getElementById('edit-customer-email').value = button.dataset.email || '';
            document.getElementById('edit-customer-password').value = '';
            editDialog.showModal();
        });
    });
    document.querySelector('[data-close-dialog]').addEventListener('click', () => editDialog.close());
</script>
</body>
</html>
