<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'success' => true,
    'service' => 'EIIN Information API',
    'status' => 'online',
    'version' => '4.0.0',
    'timestamp' => gmdate('c')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
