<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

$admin = app_current_admin_user();
if ($admin === null) {
    header('Location: ' . app_admin_url('login.php'), true, 302);
    exit;
}

$businesses = app_current_admin_businesses();
$csrfToken = app_csrf_token();
app_html_page_headers();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Owner admin | JuanTap Link</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #24352f; background: #f5f5ef; }
        body { margin: 0; }
        main { width: min(100% - 2rem, 760px); margin: 3rem auto; }
        header, section { border: 1px solid #d8ded7; border-radius: 12px; background: #fff; padding: 1.25rem; margin-bottom: 1rem; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: .25rem; font-size: 1.5rem; }
        .muted { color: #58675f; }
        a, button { font: inherit; }
        a { color: #285a42; }
        button { border: 0; border-radius: 7px; padding: .65rem .9rem; background: #59665f; color: #fff; cursor: pointer; }
        form { margin: 0; }
    </style>
</head>
<body>
<main>
    <header>
        <div><h1>Owner admin</h1><p class="muted">Signed in as admin account #<?= app_escape_html($admin['id']) ?>.</p></div>
        <form method="post" action="<?= app_escape_html(app_admin_url('logout.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
            <button type="submit">Sign out</button>
        </form>
    </header>
    <?php if ($businesses === []): ?>
        <section><p>No active Terra Mango business is configured yet.</p></section>
    <?php endif; ?>
    <?php foreach ($businesses as $business): ?>
        <section>
            <h2><?= app_escape_html($business['business_name']) ?></h2>
            <p><a href="<?= app_escape_html(app_admin_url('customers.php') . '?business_id=' . (int) $business['business_id']) ?>">Review customer accounts</a></p>
            <p><a href="<?= app_escape_html(app_admin_url('stamps.php') . '?business_id=' . (int) $business['business_id']) ?>">Open stamp desk</a></p>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
