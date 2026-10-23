<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

$httpStatus = 200;
$errorMessage = null;
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

app_start_session();
if ($requestMethod === 'GET' && app_current_admin_user() !== null) {
    header('Location: ' . app_admin_url('index.php'), true, 302);
    exit;
}

if ($requestMethod === 'POST') {
    try {
        app_verify_csrf_token($_POST['csrf_token'] ?? null);

        $email = $_POST['email'] ?? null;
        $password = $_POST['password'] ?? null;
        if (
            !is_string($email)
            || !is_string($password)
            || strlen($email) > 1024
            || strlen($password) > 4096
        ) {
            throw new AppHttpException(422, 'Enter a valid email and password.');
        }

        if (app_authenticate_admin($email, $password)) {
            header('Location: ' . app_admin_url('index.php'), true, 303);
            exit;
        }

        $errorMessage = 'Email or password could not be verified.';
    } catch (AppHttpException $exception) {
        $httpStatus = $exception->httpStatus();
        $errorMessage = $exception->safeMessage();
    }
} elseif ($requestMethod !== 'GET') {
    $httpStatus = 405;
    $errorMessage = 'Method not allowed.';
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
    <title>Owner admin sign in | JuanTap Link</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #24352f; background: #f5f5ef; }
        body { min-height: 100vh; display: grid; place-items: center; margin: 0; padding: 1rem; }
        main { width: min(100%, 420px); padding: 2rem; border: 1px solid #d8ded7; border-radius: 12px; background: #fff; }
        h1 { margin: 0 0 .5rem; font-size: 1.6rem; }
        p { color: #58675f; }
        label { display: block; margin: 1rem 0 .35rem; font-weight: 650; }
        input { box-sizing: border-box; width: 100%; padding: .75rem; border: 1px solid #aeb9af; border-radius: 7px; font: inherit; }
        button { width: 100%; margin-top: 1.25rem; padding: .8rem; border: 0; border-radius: 7px; background: #285a42; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        .error { padding: .8rem; border-radius: 8px; background: #fff0ed; color: #812b1d; }
        .back { display: inline-block; margin-top: 1rem; color: #285a42; }
    </style>
</head>
<body>
<main>
    <h1>Owner admin sign in</h1>
    <p>Sign in to manage the Terra Mango customer and loyalty accounts.</p>
    <?php if ($errorMessage !== null): ?>
        <p class="error" role="alert"><?= app_escape_html($errorMessage) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= app_escape_html(app_admin_url('login.php')) ?>" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" maxlength="254" autocomplete="username" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" maxlength="72" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
</main>
</body>
</html>
