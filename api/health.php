
<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'success' => true,
    'service' => 'EIIN Information API',
    'version' => '3.1.0',
    'status' => 'online',
    'phpVersion' => PHP_VERSION,
    'curlAvailable' => function_exists('curl_init'),
    'timestamp' => gmdate('c')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
