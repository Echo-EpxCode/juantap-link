<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

app_require_method('POST');
app_start_session();
app_verify_csrf_token($_POST['csrf_token'] ?? null);
app_customer_logout();
header('Location: ' . app_customer_url('login.php'), true, 303);
exit;
