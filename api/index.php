<?php
declare(strict_types=1);

// EIIN EMIS API proxy — Vercel PHP runtime
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

// eiin parameter validation
if (!isset($_GET['eiin']) || trim((string)$_GET['eiin']) === '') {
    jsonResponse(400, [
        'status' => false,
        'message' => 'eiin প্যারামিটার প্রয়োজন। উদাহরণ: ?eiin=104532'
    ]);
}

$eiin = trim((string)$_GET['eiin']);

if (!ctype_digit($eiin)) {
    jsonResponse(400, [
        'status' => false,
        'message' => 'eiin প্যারামিটার অবশ্যই একটি সংখ্যা হতে হবে।'
    ]);
}

/**
 * Build the full EMIS employee image URL from the old image path.
 */
function buildImageUrl(string $oldPath, $empId): string
{
    if ($oldPath === '' || $empId === null || $empId === '') {
        return $oldPath;
    }

    $parts = explode('/', $oldPath);
    $fileName = end($parts);

    // Encode path segments to avoid malformed URLs.
    return 'https://apps.emis.gov.bd:9701/api/public/hrm/file/view/'
        . rawurlencode((string)$empId) . '/'
        . rawurlencode((string)$fileName) . '/10';
}

/**
 * Make an HTTP request to the upstream EMIS API.
 */
function apiRequest(string $url, string $method, array $headers, ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        return [
            'response' => false,
            'httpCode' => 0,
            'error' => 'PHP cURL extension is not available in this runtime.'
        ];
    }

    $ch = curl_init();

    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        // Keep TLS certificate validation enabled.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        // Let cURL negotiate/decompress supported encodings.
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $headers,
    ];

    $method = strtoupper($method);

    if ($method === 'PUT') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
    } elseif ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
    } else {
        $opts[CURLOPT_HTTPGET] = true;
    }

    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [
        'response' => $response,
        'httpCode' => $httpCode,
        'error' => $curlErr,
    ];
}

function decodeUpstreamJson(array $result, string $label): array
{
    if ($result['response'] === false) {
        jsonResponse(502, [
            'status' => false,
            'message' => $label . ' API cURL error: ' . ($result['error'] ?: 'Unknown cURL error')
        ]);
    }

    $httpCode = (int)($result['httpCode'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
        jsonResponse(502, [
            'status' => false,
            'message' => $label . ' API returned an upstream HTTP error.',
            'upstream_http_code' => $httpCode
        ]);
    }

    $decoded = json_decode((string)$result['response'], true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        jsonResponse(502, [
            'status' => false,
            'message' => $label . ' API returned invalid JSON.'
        ]);
    }

    return $decoded;
}

/* ============================================================
   1) Head / Principal / Vice Principal list (GET)
   ============================================================ */
$urlHead = 'https://apps.emis.gov.bd:9701/api/public/hrm/emp-info/get-head-by-institute-id/0/'
    . rawurlencode($eiin) . '/0';

$headHeaders = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
    'Accept: application/json, text/plain, */*',
    'Origin: https://apps.emis.gov.bd',
    'Referer: https://apps.emis.gov.bd/',
    'Accept-Language: en-US,en;q=0.9',
];

$headResult = apiRequest($urlHead, 'GET', $headHeaders);
$headData = decodeUpstreamJson($headResult, 'Head');

// Normalize head list image URLs.
if (isset($headData['data']) && is_array($headData['data'])) {
    foreach ($headData['data'] as &$item) {
        if (is_array($item) && !empty($item['image'])) {
            $empId = $item['id'] ?? $item['empRefId'] ?? null;
            $item['image'] = buildImageUrl((string)$item['image'], $empId);
        }
    }
    unset($item);
}

/* ============================================================
   2) Full employee list (PUT)
   ============================================================ */
$urlList = 'https://apps.emis.gov.bd:9701/api/public/hrm/emp-info/get-pageable-emp-list-by-institute';

$listHeaders = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
    'Accept: application/json, text/plain, */*',
    'Content-Type: application/json',
    'Origin: https://apps.emis.gov.bd',
    'Referer: https://apps.emis.gov.bd/',
    'Accept-Language: en-US,en;q=0.9',
];

$listBody = json_encode([
    'listIntParam1' => [],
    'page' => 0,
    'size' => 1000,
    'searchValue' => '',
    'intParam1' => null,
    'intParam2' => 1,
    'stringParam1' => $eiin,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($listBody === false) {
    jsonResponse(500, [
        'status' => false,
        'message' => 'Could not encode the employee-list request.'
    ]);
}

$listResult = apiRequest($urlList, 'PUT', $listHeaders, $listBody);
$listData = decodeUpstreamJson($listResult, 'List');

// Normalize employee list image URLs.
if (
    isset($listData['data']['content'])
    && is_array($listData['data']['content'])
) {
    foreach ($listData['data']['content'] as &$item) {
        if (is_array($item) && !empty($item['image'])) {
            $empId = $item['id'] ?? $item['empRefId'] ?? null;
            $item['image'] = buildImageUrl((string)$item['image'], $empId);
        }
    }
    unset($item);
}

/* ============================================================
   3) Combined JSON response + original credits
   ============================================================ */
jsonResponse(200, [
    'status' => true,
    'eiin' => $eiin,
    'headInfo' => $headData,
    'employeeList' => $listData,
    'Api_Owner' => '@Al_Nayeem756',
    'Telegram' => '@Cyber_Force756',
    'Developer' => '@Al_Nayeem756',
]);
