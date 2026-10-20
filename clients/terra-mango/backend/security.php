<?php
declare(strict_types=1);

function app_request_is_https(): bool
{
    return isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== ''
        && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (headers_sent()) {
        throw new RuntimeException('Session could not be started after output.');
    }

    $environment = app_config()['app']['environment'] ?? null;
    if (!in_array($environment, ['production', 'development'], true)) {
        throw new RuntimeException('Application environment is missing or invalid.');
    }

    if ($environment !== 'development' && !app_request_is_https()) {
        throw new AppHttpException(400, 'A secure connection is required.');
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '43200');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $environment !== 'development' || app_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('Session initialization failed.');
    }
}

function app_regenerate_session_id(): void
{
    app_start_session();

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Session ID regeneration failed.');
    }
}

function app_csrf_token(): string
{
    app_start_session();

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function app_verify_csrf_token(mixed $submittedToken): void
{
    app_start_session();

    $storedToken = $_SESSION['csrf_token'] ?? null;
    if (
        !is_string($storedToken)
        || !is_string($submittedToken)
        || !hash_equals($storedToken, $submittedToken)
    ) {
        throw new AppHttpException(403, 'Request could not be verified.');
    }
}

function app_escape_html(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function app_require_method(string $expectedMethod): void
{
    $actualMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($actualMethod !== strtoupper($expectedMethod)) {
        throw new AppHttpException(405, 'Method not allowed.');
    }
}

function app_post_string(string $field, int $maxBytes): string
{
    $value = $_POST[$field] ?? null;
    if (!is_string($value)) {
        throw new AppHttpException(422, 'A required field is missing or invalid.');
    }

    $value = trim($value);
    if ($value === '' || strlen($value) > $maxBytes) {
        throw new AppHttpException(422, 'A required field is missing or invalid.');
    }

    return $value;
}

function app_post_positive_int(string $field): int
{
    $value = $_POST[$field] ?? null;
    $validated = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($validated === false) {
        throw new AppHttpException(422, 'A required field is missing or invalid.');
    }

    return (int) $validated;
}

function app_validate_slug(string $slug): string
{
    if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug)) {
        throw new AppHttpException(422, 'The requested business page is invalid.');
    }

    return $slug;
}
