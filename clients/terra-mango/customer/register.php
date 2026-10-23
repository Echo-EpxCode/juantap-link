<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

if (app_current_customer() !== null) {
    header('Location: ' . app_customer_url('account.php'), true, 302);
    exit;
}

$errorMessage = null;
$httpStatus = 200;
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    try {
        app_verify_csrf_token($_POST['csrf_token'] ?? null);
        $email = $_POST['email'] ?? null;
        $fullName = $_POST['full_name'] ?? null;
        $password = $_POST['password'] ?? null;
        if (!is_string($email) || !is_string($fullName) || !is_string($password)) {
            throw new AppHttpException(422, 'Complete each required field.');
        }
        $customerId = app_customer_register($email, $fullName, $password);
        app_regenerate_session_id();
        unset($_SESSION['csrf_token']);
        $_SESSION['customer_id'] = $customerId;
        $_SESSION['customer_authenticated_at'] = time();
        $_SESSION['customer_last_activity_at'] = time();
        header('Location: ' . app_customer_url('account.php'), true, 303);
        exit;
    } catch (AppHttpException $exception) {
        $httpStatus = $exception->httpStatus();
        $errorMessage = $exception->safeMessage();
    } catch (Throwable $exception) {
        error_log('JuanTap Link customer registration exception: ' . get_class($exception));
        $httpStatus = 500;
        $errorMessage = 'Registration could not be completed. Please try again later.';
    }
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
    <title>Join Terra Mango rewards</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #24352f; background: #f5f5ef; }
        body { min-height: 100vh; display: grid; place-items: center; margin: 0; padding: 1rem; }
        main { width: min(100%, 430px); padding: 2rem; border: 1px solid #d8ded7; border-radius: 12px; background: #fff; }
        h1 { margin: 0 0 .5rem; font-size: 1.6rem; }
        p { color: #58675f; }
        label { display: block; margin: 1rem 0 .35rem; font-weight: 650; }
        input { box-sizing: border-box; width: 100%; padding: .75rem; border: 1px solid #aeb9af; border-radius: 7px; font: inherit; }
        button { width: 100%; margin-top: 1.25rem; padding: .8rem; border: 0; border-radius: 7px; background: #285a42; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        .error { padding: .8rem; border-radius: 8px; background: #fff0ed; color: #812b1d; }
        a { color: #285a42; }
    </style>
</head>
<body><main>
    <h1>Join Terra Mango rewards</h1>
    <p>Registration is optional. You can browse the Terra Mango site without an account. New accounts need owner approval before loyalty features are available.</p>
    <?php if ($errorMessage !== null): ?><p class="error" role="alert"><?= app_escape_html($errorMessage) ?></p><?php endif; ?>
    <form method="post" action="<?= app_escape_html(app_customer_url('register.php')) ?>" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= app_escape_html($csrfToken) ?>">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" maxlength="254" autocomplete="username" required>
        <label for="full-name">Full name</label>
        <input id="full-name" name="full_name" type="text" maxlength="160" autocomplete="name" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
        <button type="submit">Create customer account</button>
    </form>
    <p>Already registered? <a href="<?= app_escape_html(app_customer_url('login.php')) ?>">Sign in</a></p>
    <p><a href="../">Back to Terra Mango</a></p>
</main></body>
</html>
