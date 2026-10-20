<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

$staffUser = app_current_staff_user();
if ($staffUser === null) {
    header('Location: ' . app_admin_url('login.php'), true, 302);
    exit;
}

function app_admin_stamp_redirect(int $businessId, int $branchId, int $cardId = 0): void
{
    $query = http_build_query([
        'business_id' => $businessId,
        'branch_id' => $branchId,
        'card_id' => $cardId,
    ]);
    header('Location: ' . app_admin_url('stamps.php') . '?' . $query, true, 303);
    exit;
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($requestMethod === 'POST') {
    $businessId = 0;
    $branchId = 0;
    $cardId = 0;
    try {
        app_verify_csrf_token($_POST['csrf_token'] ?? null);
        $businessId = app_post_positive_int('business_id');
        $branchId = app_post_positive_int('branch_id');
        $cardId = app_post_positive_int('card_id');
        $action = app_post_string('action', 24);
        $idempotencyKey = app_post_string('idempotency_key', 64);

        if ($action === 'issue-stamp') {
            $purchaseReference = app_post_string('purchase_reference', 120);
            $result = app_issue_stamp(
                $businessId,
                $branchId,
                $cardId,
                $purchaseReference,
                $idempotencyKey
            );
            $_SESSION['stamp_notice'] = $result['already_processed']
                ? 'That stamp request was already recorded.'
                : 'Stamp issued. The card balance is now ' . $result['stamp_balance'] . '.';
        } elseif ($action === 'redeem-reward') {
            $rewardId = app_post_positive_int('reward_id');
            $result = app_redeem_reward(
                $businessId,
                $branchId,
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

    app_admin_stamp_redirect($businessId, $branchId, $cardId);
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

$memberships = app_current_staff_business_memberships();
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

$branches = $selectedMembership === null
    ? []
    : app_membership_branch_records($selectedMembership);
$requestedBranchId = filter_var($_GET['branch_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$selectedBranchId = 0;
$selectedBranchName = '';
foreach ($branches as $branch) {
    if ($requestedBranchId !== false && (int) $branch['id'] === (int) $requestedBranchId) {
        $selectedBranchId = (int) $branch['id'];
        $selectedBranchName = (string) $branch['name'];
        break;
    }
}
if ($selectedBranchId === 0 && $branches !== []) {
    $selectedBranchId = (int) $branches[0]['id'];
    $selectedBranchName = (string) $branches[0]['name'];
}

$schemaReady = $selectedMembership !== null && app_stamp_schema_ready();
$branchAccessReady = $selectedMembership !== null && $selectedBranchId > 0;
if ($branchAccessReady) {
    app_require_business_membership(
        (int) $selectedMembership['business_id'],
        'stamp.issue',
        $selectedBranchId
    );
}
$cards = $schemaReady && $branchAccessReady
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

$recentTransactions = [];
if ($schemaReady && $branchAccessReady) {
    $recentQuery = app_database()->prepare(
        'SELECT id, loyalty_card_id, transaction_type, stamp_delta, created_at
         FROM stamp_transactions
         WHERE business_id = ? AND branch_id = ?
         ORDER BY id DESC
         LIMIT 15'
    );
    $businessId = (int) $selectedMembership['business_id'];
    $recentQuery->bind_param('ii', $businessId, $selectedBranchId);
    $recentQuery->execute();
    $recentQuery->bind_result($transactionId, $transactionCardId, $transactionType, $stampDelta, $createdAt);
    while ($recentQuery->fetch()) {
        $recentTransactions[] = [
            'id' => (int) $transactionId,
            'card_id' => (int) $transactionCardId,
            'type' => (string) $transactionType,
            'delta' => (int) $stampDelta,
            'created_at' => (string) $createdAt,
        ];
    }
    $recentQuery->close();
}

$csrfToken = app_csrf_token();
$issueIdempotencyKey = bin2hex(random_bytes(32));
$redeemIdempotencyKey = bin2hex(random_bytes(32));
$selectedBusinessId = $selectedMembership === null ? 0 : (int) $selectedMembership['business_id'];
$actionUrl = app_admin_url('stamps.php') . '?' . http_build_query([
    'business_id' => $selectedBusinessId,
    'branch_id' => $selectedBranchId,
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
        .history { width: 100%; border-collapse: collapse; }
        .history th, .history td { border-bottom: 1px solid #e3e7e2; padding: .6rem; text-align: left; }
        @media (max-width: 560px) { header { align-items: flex-start; flex-direction: column; } select, input { box-sizing: border-box; width: 100%; min-width: 0; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>Stamp desk</h1>
            <p class="muted">Issue one stamp per unique receipt or redeem an available reward.</p>
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
        <section><p>No active business access is available for this staff account.</p></section>
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
                <?php if ($branches !== []): ?>
                    <label>Branch
                        <select name="branch_id">
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= app_escape_html($branch['id']) ?>" <?= (int) $branch['id'] === $selectedBranchId ? 'selected' : '' ?>>
                                    <?= app_escape_html($branch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <button type="submit">Show stamp desk</button>
            </form>
        </section>

        <?php if (!$schemaReady): ?>
            <section>
                <h2>Stamp setup needs one database migration</h2>
                <p>Import <code>002_unique_purchase_references.sql</code> into this local database, then reload this page.</p>
            </section>
        <?php elseif ($branches === []): ?>
            <section><p>This membership has no active branch access, so it cannot issue or redeem stamps.</p></section>
        <?php elseif ($cards === []): ?>
            <section>
                <h2>No active loyalty cards</h2>
                <p>The local setup helper creates one demo card. Production customer enrollment is not part of this local stamp desk.</p>
            </section>
        <?php else: ?>
            <section>
                <h2><?= app_escape_html($selectedMembership['business_name']) ?></h2>
                <p class="muted">Branch: <?= app_escape_html($selectedBranchName) ?></p>
                <?php if ($selectedCard !== null): ?>
                    <p>Card #<?= app_escape_html($selectedCard['id']) ?> · <?= app_escape_html($selectedCard['customer_name']) ?> · <?= app_escape_html($selectedCard['stamp_balance']) ?> stamps</p>
                <?php endif; ?>
            </section>

            <div class="actions">
                <section>
                    <h2>Issue a stamp</h2>
                    <p class="muted">A receipt can receive one stamp at this branch. The receipt value is stored as a keyed hash.</p>
                    <form method="post" action="<?= app_escape_html($actionUrl) ?>">
                        <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
                        <input type="hidden" name="action" value="issue-stamp">
                        <input type="hidden" name="idempotency_key" value="<?= app_escape_html($issueIdempotencyKey) ?>">
                        <input type="hidden" name="business_id" value="<?= app_escape_html($selectedBusinessId) ?>">
                        <input type="hidden" name="branch_id" value="<?= app_escape_html($selectedBranchId) ?>">
                        <label>Customer card
                            <select name="card_id" required>
                                <?php foreach ($cards as $card): ?>
                                    <option value="<?= app_escape_html($card['id']) ?>" <?= $selectedCard !== null && (int) $card['id'] === (int) $selectedCard['id'] ? 'selected' : '' ?>>
                                        #<?= app_escape_html($card['id']) ?> · <?= app_escape_html($card['customer_name']) ?> · <?= app_escape_html($card['stamp_balance']) ?> stamps
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Receipt reference
                            <input name="purchase_reference" type="text" maxlength="120" autocomplete="off" required>
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
                            <input type="hidden" name="branch_id" value="<?= app_escape_html($selectedBranchId) ?>">
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

            <section>
                <h2>Recent activity at this branch</h2>
                <?php if ($recentTransactions === []): ?>
                    <p class="muted">No stamp transactions have been recorded here.</p>
                <?php else: ?>
                    <table class="history">
                        <thead><tr><th>Time (UTC)</th><th>Card</th><th>Action</th><th>Change</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td><?= app_escape_html($transaction['created_at']) ?></td>
                                <td>#<?= app_escape_html($transaction['card_id']) ?></td>
                                <td><?= app_escape_html($transaction['type']) ?></td>
                                <td><?= $transaction['delta'] > 0 ? '+' : '' ?><?= app_escape_html($transaction['delta']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
