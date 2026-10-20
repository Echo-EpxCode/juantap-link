<?php
declare(strict_types=1);

require_once __DIR__ . '/backend/bootstrap.php';

app_require_method('GET');
$slug = $_GET['slug'] ?? null;
if (!is_string($slug) || strlen($slug) > 120) {
    throw new AppHttpException(404, 'Business page not found.');
}

app_json_response([
    'data' => app_business_public_data($slug, app_business_local_preview_allowed()),
]);
