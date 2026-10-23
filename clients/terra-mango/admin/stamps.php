<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

$adminUser = app_current_admin_user();
if ($adminUser === null) {
    header('Location: ' . app_admin_url('login.php'), true, 302);
    exit;
}

function app_admin_stamp_redirect(int $businessId, int $cardId = 0): void
{
    $query = http_build_query([
        'business_id' => $businessId,
        'card_id' => $cardId,
    ]);
    header('Location: ' . app_admin_url('stamps.php') . '?' . $query, true, 303);
    exit;
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($requestMethod === 'POST') {
    $businessId = 0;
    $cardId = 0;
    try {
        app_verify_csrf_token($_POST['csrf_token'] ?? null);
        $businessId = app_post_positive_int('business_id');
        $cardId = app_post_positive_int('card_id');
        $action = app_post_string('action', 24);
        $idempotencyKey = app_post_string('idempotency_key', 64);

        if ($action === 'issue-stamp') {
            $stampNumber = app_post_positive_int('stamp_number');
            $result = app_issue_stamp(
                $businessId,
                $cardId,
                $stampNumber,
                $idempotencyKey
            );
            $_SESSION['stamp_notice'] = $result['already_processed']
                ? 'That stamp request was already recorded.'
                : 'Stamp issued. The card balance is now ' . $result['stamp_balance'] . '.';
        } elseif ($action === 'redeem-reward') {
            $rewardId = app_post_positive_int('reward_id');
            $result = app_redeem_reward(
                $businessId,
                $cardId,
                $rewardId,
                $idempotencyKey
            );
            $_SESSION['stamp_notice'] = $result['already_processed']
                ? 'That reward redemption was already recorded.'
                : 'Reward redeemed. The card balance is now ' . $result['stamp_balance'] . '.';
        } else {
            throw new AppHttpException(422, 'The requested stamp action is invalid.');
        }
    } catch (AppHttpException $exception) {
        $_SESSION['stamp_error'] = $exception->safeMessage();
    } catch (Throwable $exception) {
        error_log('JuanTap Link stamp desk exception: ' . get_class($exception));
        $_SESSION['stamp_error'] = 'The stamp action could not be completed. Check the local PHP error log.';
    }

    app_admin_stamp_redirect($businessId, $cardId);
}

if ($requestMethod !== 'GET') {
    http_response_code(405);
    exit;
}

$noticeMessage = isset($_SESSION['stamp_notice']) && is_string($_SESSION['stamp_notice'])
    ? $_SESSION['stamp_notice']
    : null;
$errorMessage = isset($_SESSION['stamp_error']) && is_string($_SESSION['stamp_error'])
    ? $_SESSION['stamp_error']
    : null;
unset($_SESSION['stamp_notice'], $_SESSION['stamp_error']);

$memberships = app_current_admin_businesses();
$selectedMembership = null;
$requestedBusinessId = filter_var($_GET['business_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
foreach ($memberships as $membership) {
    if ($requestedBusinessId !== false && (int) $membership['business_id'] === (int) $requestedBusinessId) {
        $selectedMembership = $membership;
        break;
    }
}
if ($selectedMembership === null && $memberships !== []) {
    $selectedMembership = $memberships[0];
}

$schemaReady = $selectedMembership !== null && app_stamp_schema_ready();
$cards = $schemaReady && $selectedMembership !== null
    ? app_stamp_cards_for_business((int) $selectedMembership['business_id'])
    : [];
$requestedCardId = filter_var($_GET['card_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$selectedCard = null;
foreach ($cards as $card) {
    if ($requestedCardId !== false && (int) $card['id'] === (int) $requestedCardId) {
        $selectedCard = $card;
        break;
    }
}
if ($selectedCard === null && $cards !== []) {
    $selectedCard = $cards[0];
}

$rewards = $selectedCard === null
    ? []
    : app_stamp_rewards_for_version(
        (int) $selectedMembership['business_id'],
        (int) $selectedCard['program_version_id']
    );

$recentTransactions = $schemaReady && $selectedMembership !== null
    ? app_stamp_recent_activity_for_business((int) $selectedMembership['business_id'])
    : [];

$csrfToken = app_csrf_token();
$issueIdempotencyKey = bin2hex(random_bytes(32));
$redeemIdempotencyKey = bin2hex(random_bytes(32));
$selectedBusinessId = $selectedMembership === null ? 0 : (int) $selectedMembership['business_id'];
$actionUrl = app_admin_url('stamps.php') . '?' . http_build_query([
    'business_id' => $selectedBusinessId,
    'card_id' => $selectedCard === null ? 0 : $selectedCard['id'],
]);

app_html_page_headers();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stamp desk | JuanTap Link</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #24352f; background: #f5f5ef; }
        body { margin: 0; }
        main { width: min(100% - 2rem, 960px); margin: 2rem auto; }
        header, section { border: 1px solid #d8ded7; border-radius: 12px; background: #fff; padding: 1.25rem; margin-bottom: 1rem; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: .25rem; font-size: 1.5rem; }
        h2 { font-size: 1.1rem; }
        .muted { color: #58675f; }
        .notice, .error { padding: .8rem 1rem; border-radius: 8px; }
        .notice { background: #e7f4e8; }
        .error { background: #fff0ed; color: #812b1d; }
        .filters, .actions { display: flex; flex-wrap: wrap; align-items: end; gap: 1rem; }
        .filters label, .actions label { display: grid; gap: .35rem; font-weight: 650; }
        select, input { min-width: 220px; padding: .7rem; border: 1px solid #aeb9af; border-radius: 7px; font: inherit; }
        button, .button { display: inline-block; border: 0; border-radius: 7px; padding: .7rem 1rem; background: #285a42; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .secondary { background: #59665f; }
        .actions section { flex: 1 1 360px; margin: 0; }
        @media (max-width: 560px) { header { align-items: flex-start; flex-direction: column; } select, input { box-sizing: border-box; width: 100%; min-width: 0; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>Stamp desk</h1>
            <p class="muted">Issue an unused stamp number for a customer or redeem an available reward.</p>
        </div>
        <nav>
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

    <?php if ($memberships === []): ?>
        <section><p>No active business is available for this owner-admin account.</p></section>
    <?php else: ?>
        <section>
            <form class="filters" method="get" action="<?= app_escape_html(app_admin_url('stamps.php')) ?>">
                <label>Business
                    <select name="business_id">
                        <?php foreach ($memberships as $membership): ?>
                            <option value="<?= app_escape_html($membership['business_id']) ?>" <?= (int) $membership['business_id'] === $selectedBusinessId ? 'selected' : '' ?>>
                                <?= app_escape_html($membership['business_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Show stamp desk</button>
            </form>
        </section>

        <?php if (!$schemaReady): ?>
            <section>
                <h2>Stamp setup needs one database migration</h2>
                <p>Import migrations <code>002_unique_purchase_references.sql</code> and <code>005_branchless_loyalty.sql</code> into this local database, then reload this page.</p>
            </section>
        <?php elseif ($cards === []): ?>
            <section>
                <h2>No active loyalty cards</h2>
                <p>Customers can register from the public Terra Mango site. Their cards appear here after owner approval.</p>
            </section>
        <?php elseif ($cards !== []): ?>
            <div class="actions">
                <section>
                    <h2>Issue a stamp</h2>
                    <p class="muted">Choose an unused number in the customer's current set of eight stamps.</p>
                    <form method="post" action="<?= app_escape_html($actionUrl) ?>">
                        <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                        <input type="hidden" name="action" value="issue-stamp">
                        <input type="hidden" name="idempotency_key" value="<?= app_escape_html($issueIdempotencyKey) ?>">
                        <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
                        <label>Customer card
                            <select id="stamp-card-select" name="card_id" required>
                                <?php foreach ($cards as $card): ?>
                                    <option value="<?= app_escape_html($card['id']) ?>" data-issued-stamps="<?= app_escape_html(implode(',', $card['issued_stamp_numbers'])) ?>" <?= $selectedCard !== null && (int) $card['id'] === (int) $selectedCard['id'] ? 'selected' : '' ?>>
                                        #<?= app_escape_html($card['id']) ?> · <?= app_escape_html($card['customer_name']) ?> · <?= app_escape_html($card['stamp_balance']) ?> stamps
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <p id="issued-stamp-info" class="muted" role="status" aria-live="polite"></p>
                        <label>Stamp number
                            <select id="stamp-number-select" name="stamp_number" required>
                                <?php for ($stampNumber = 1; $stampNumber <= 8; $stampNumber++): ?>
                                    <option value="<?= $stampNumber ?>">Stamp <?= $stampNumber ?></option>
                                <?php endfor; ?>
                            </select>
                        </label>
                        <button type="submit">Issue stamp</button>
                    </form>
                </section>

                <section>
                    <h2>Redeem a reward</h2>
                    <?php if ($rewards === []): ?>
                        <p class="muted">This card has no active rewards.</p>
                    <?php else: ?>
                        <form method="post" action="<?= app_escape_html($actionUrl) ?>">
                            <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                            <input type="hidden" name="action" value="redeem-reward">
                            <input type="hidden" name="idempotency_key" value="<?= app_escape_html($redeemIdempotencyKey) ?>">
                            <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
                            <label>Customer card
                                <select name="card_id" required>
                                    <?php foreach ($cards as $card): ?>
                                        <option value="<?= app_escape_html($card['id']) ?>" <?= $selectedCard !== null && (int) $card['id'] === (int) $selectedCard['id'] ? 'selected' : '' ?>>
                                            #<?= app_escape_html($card['id']) ?> · <?= app_escape_html($card['customer_name']) ?> · <?= app_escape_html($card['stamp_balance']) ?> stamps
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Reward
                                <select name="reward_id" required>
                                    <?php foreach ($rewards as $reward): ?>
                                        <option value="<?= app_escape_html($reward['id']) ?>">
                                            <?= app_escape_html($reward['name']) ?> (<?= app_escape_html($reward['stamp_cost']) ?> stamps)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="submit">Redeem reward</button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>

        <?php endif; ?>

        <?php if ($schemaReady): ?>
            <section>
                <h2>Recent activity</h2>
                <?php if ($recentTransactions === []): ?>
                    <p class="muted">No stamp or redemption activity has been recorded yet.</p>
                <?php else: ?>
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;text-align:left">
                            <thead><tr><th style="padding:.6rem">Time (UTC)</th><th style="padding:.6rem">Customer</th><th style="padding:.6rem">Activity</th><th style="padding:.6rem">Change</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentTransactions as $activity): ?>
                                <tr>
                                    <td style="padding:.6rem;border-top:1px solid #e1e6e0"><?= app_escape_html($activity['created_at']) ?></td>
                                    <td style="padding:.6rem;border-top:1px solid #e1e6e0"><?= app_escape_html($activity['customer_name']) ?></td>
                                    <td style="padding:.6rem;border-top:1px solid #e1e6e0"><?= app_escape_html($activity['activity']) ?></td>
                                    <td style="padding:.6rem;border-top:1px solid #e1e6e0"><?= (int) $activity['stamp_delta'] > 0 ? '+' : '' ?><?= app_escape_html($activity['stamp_delta']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
<script>
(() => {
    const customerSelect = document.getElementById('stamp-card-select');
    const stampSelect = document.getElementById('stamp-number-select');
    const issuedInfo = document.getElementById('issued-stamp-info');
    if (!customerSelect || !stampSelect || !issuedInfo) return;

    function updateStampChoices() {
        const selectedCustomer = customerSelect.selectedOptions[0];
        const issuedNumbers = (selectedCustomer?.dataset.issuedStamps ?? '')
            .split(',')
            .map(value => Number.parseInt(value, 10))
            .filter(value => Number.isInteger(value) && value >= 1 && value <= 8);
        const issuedSet = new Set(issuedNumbers);

        for (const option of stampSelect.options) {
            const stampNumber = Number.parseInt(option.value, 10);
            option.disabled = issuedSet.has(stampNumber);
        }

        issuedInfo.textContent = issuedNumbers.length
            ? 'Already issued in the current set: ' + issuedNumbers.map(number => 'Stamp ' + number).join(', ') + '.'
            : 'No stamps issued in the current set yet.';

        const firstAvailable = Array.from(stampSelect.options).find(option => !option.disabled);
        if (firstAvailable) stampSelect.value = firstAvailable.value;
    }

    customerSelect.addEventListener('change', updateStampChoices);
    updateStampChoices();
})();
</script>
</body>
</html>
