<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

$customer = app_current_customer();
if ($customer === null) {
    header('Location: ' . app_customer_url('login.php'), true, 302);
    exit;
}
$loyalty = app_customer_loyalty_view((int) $customer['id']);
$issuedStampNumbers = [];
if ($loyalty['card'] !== null) {
    $issuedStampNumbers = app_stamp_issued_numbers_for_card(
        app_database(),
        (int) $customer['business_id'],
        (int) $loyalty['card']['id'],
        (int) $loyalty['card']['stamp_balance'],
        true
    );
}
$currentSetStampCount = count($issuedStampNumbers);
$stampProgressPercent = (int) round(($currentSetStampCount / 8) * 100);
$csrfToken = app_csrf_token();
app_html_page_headers();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Terra Mango rewards</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #24352f; background: #f5f5ef; }
        body { margin: 0; }
        main { width: min(100% - 2rem, 760px); margin: 2rem auto; }
        header, section { border: 1px solid #d8ded7; border-radius: 12px; background: #fff; padding: 1.25rem; margin-bottom: 1rem; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        h1, h2, p { margin-top: 0; }
        h1 { font-size: 1.5rem; }
        .muted { color: #58675f; }
        .status { padding: .8rem; border-radius: 8px; background: #fff5d7; }
        button { border: 0; border-radius: 7px; padding: .65rem .9rem; background: #59665f; color: white; font: inherit; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: .55rem; border-bottom: 1px solid #e3e7e2; text-align: left; }
        a { color: #285a42; }
        .account-stamp-panel { margin: -1.25rem; padding: 1.25rem; border-radius: 11px; background: #fff3b0; }
        .account-stamp-panel__topline, .account-stamp-panel__count { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
        .account-stamp-panel__label, .account-stamp-panel__reward-title { margin: 0; color: #14532d; font-size: .7rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
        .account-stamp-panel h2 { margin: .25rem 0 0; color: #166534; font-size: 1.3rem; }
        .account-stamp-panel__badge { display: inline-flex; align-items: center; gap: .4rem; padding: .4rem .65rem; border-radius: 999px; background: #f9c906; color: #14532d; font-size: .68rem; font-weight: 800; white-space: nowrap; }
        .account-stamp-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .5rem; max-width: 330px; margin: .75rem 0 .6rem; padding: 0; list-style: none; }
        .account-stamp-slot { display: grid; width: min(100%, 3rem); aspect-ratio: 1; place-items: center; justify-self: center; border: 1px dashed #d8ded7; border-radius: 50%; background: rgb(255 255 255 / 76%); color: #58675f; font-size: 1.05rem; }
        .account-stamp-slot.is-earned { border-style: solid; border-color: #14532d; background: #14532d; color: #f9c906; }
        .account-stamp-panel__count { margin: 0; color: #166534; font-size: .75rem; font-weight: 800; }
        .account-stamp-panel__count span:last-child { color: #58675f; font-size: .68rem; text-align: right; }
        .account-stamp-progress { height: 8px; margin-top: .65rem; overflow: hidden; border-radius: 999px; background: rgb(255 255 255 / 85%); }
        .account-stamp-progress span { display: block; width: <?= $stampProgressPercent ?>%; height: 100%; border-radius: inherit; background: #14532d; transition: width 400ms ease; }
        .account-stamp-panel__rewards { margin-top: .85rem; padding-top: .75rem; border-top: 1px solid rgb(20 83 45 / 18%); }
        .account-stamp-panel__rewards ul { display: flex; flex-wrap: wrap; gap: .35rem .9rem; margin: .35rem 0 0; padding-left: 1.1rem; font-size: .82rem; }
        @media (max-width: 480px) { .account-stamp-panel { margin: -.85rem; padding: .85rem; } .account-stamp-panel h2 { font-size: 1.15rem; } .account-stamp-panel__badge { padding: .35rem .5rem; font-size: .62rem; } }
    </style>
</head>
<body><main>
    <header>
        <div><h1>My Terra Mango rewards</h1><p class="muted">Hello, <?= app_escape_html($customer['full_name']) ?>.</p></div>
        <form method="post" action="<?= app_escape_html(app_customer_url('logout.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
            <button type="submit">Sign out</button>
        </form>
    </header>
    <?php if ($loyalty['approval_status'] === 'pending'): ?>
        <section><h2>Account pending approval</h2><p class="status">An owner will review your account in person. Your loyalty card will be available after approval.</p></section>
    <?php elseif ($loyalty['approval_status'] === 'rejected'): ?>
        <section><h2>Account not approved</h2><p class="status">This account cannot access loyalty features. Please speak with the Terra Mango owner in person.</p></section>
    <?php elseif ($loyalty['card'] === null): ?>
        <section><p>Your loyalty card is not currently available. Please contact Terra Mango.</p></section>
    <?php else: ?>
        <section>
            <div class="account-stamp-panel">
                <div class="account-stamp-panel__topline">
                    <div>
                        <p class="account-stamp-panel__label">Your loyalty card</p>
                        <h2><?= app_escape_html($loyalty['card']['program_name']) ?></h2>
                    </div>
                    <span class="account-stamp-panel__badge"><i class="bi bi-cup-hot-fill" aria-hidden="true"></i> Stamp card</span>
                </div>

                <ol class="account-stamp-grid" aria-label="Your stamps">
                    <?php for ($stampNumber = 1; $stampNumber <= 8; $stampNumber++): ?>
                        <?php $stampEarned = in_array($stampNumber, $issuedStampNumbers, true); ?>
                        <li class="account-stamp-slot<?= $stampEarned ? ' is-earned' : '' ?>" aria-label="Stamp <?= $stampNumber ?>: <?= $stampEarned ? 'earned' : 'not yet earned' ?>">
                            <i class="bi <?= $stampEarned ? 'bi-cup-hot-fill' : 'bi-plus-lg' ?>" aria-hidden="true"></i>
                        </li>
                    <?php endfor; ?>
                </ol>

                <div class="account-stamp-panel__count">
                    <span><?= $currentSetStampCount ?> of 8 stamps in this set</span>
                    <span><?= app_escape_html($loyalty['card']['stamp_balance']) ?> total stamps</span>
                </div>
                <div class="account-stamp-progress" role="progressbar" aria-label="Stamp card progress" aria-valuemin="0" aria-valuemax="8" aria-valuenow="<?= $currentSetStampCount ?>" aria-valuetext="<?= $currentSetStampCount ?> of 8 stamps earned">
                    <span></span>
                </div>

                <?php if ($loyalty['rewards'] !== []): ?>
                    <div class="account-stamp-panel__rewards">
                        <p class="account-stamp-panel__reward-title">Rewards</p>
                        <ul><?php foreach ($loyalty['rewards'] as $reward): ?>
                            <li><?= app_escape_html($reward['name']) ?> — <?= app_escape_html($reward['stamp_cost']) ?> stamps<?= $loyalty['card']['stamp_balance'] >= $reward['stamp_cost'] ? ' (eligible)' : '' ?></li>
                        <?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <section>
            <h2>Stamp history</h2>
            <?php if ($loyalty['history'] === []): ?>
                <p class="muted">No loyalty transactions yet.</p>
            <?php else: ?>
                <table><thead><tr><th>Time (UTC)</th><th>Activity</th><th>Stamps</th></tr></thead><tbody>
                <?php foreach ($loyalty['history'] as $item): ?>
                    <?php $activity = $item['type'] === 'redeem' && $item['reward_name'] !== null
                        ? 'Redeemed ' . $item['reward_name']
                        : ucfirst(str_replace('_', ' ', $item['type'])); ?>
                    <tr><td><?= app_escape_html($item['created_at']) ?></td><td><?= app_escape_html($activity) ?></td><td><?= $item['delta'] > 0 ? '+' : '' ?><?= app_escape_html($item['delta']) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <p><a href="../">Back to Terra Mango</a></p>
</main></body>
</html>
