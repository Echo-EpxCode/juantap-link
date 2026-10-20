<?php
declare(strict_types=1);

final class AppHttpException extends RuntimeException
{
    private int $httpStatus;
    private string $safeMessage;

    public function __construct(int $httpStatus, string $safeMessage)
    {
        parent::__construct($safeMessage);
        $this->httpStatus = $httpStatus;
        $this->safeMessage = $safeMessage;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function safeMessage(): string
    {
        return $this->safeMessage;
    }
}

function app_json_response(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    try {
        $body = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );
    } catch (Throwable $exception) {
        $body = '{"error":"Internal server error"}';
        http_response_code(500);
    }

    echo $body;
    exit;
}

function app_html_page_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}

function app_handle_exception(Throwable $exception): void
{
    try {
        $reference = bin2hex(random_bytes(8));
    } catch (Throwable $ignored) {
        $reference = 'unavailable';
    }

    $status = 500;
    $message = 'Service temporarily unavailable.';

    if ($exception instanceof AppHttpException) {
        $status = $exception->httpStatus();
        $message = $exception->safeMessage();
    } else {
        error_log('JuanTap Link exception [' . $reference . ']: ' . get_class($exception));
    }

    app_json_response([
        'error' => $message,
        'reference' => $reference,
    ], $status);
}
