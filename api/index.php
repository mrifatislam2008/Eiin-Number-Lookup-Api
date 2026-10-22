<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function jsonResponse(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

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

function buildImageUrl(string $oldPath, $empId): string
{
    if ($oldPath === '' || $empId === null || $empId === '') {
        return $oldPath;
    }
    $parts = explode('/', $oldPath);
    $fileName = (string)end($parts);
    return 'https://apps.emis.gov.bd:9701/api/public/hrm/file/view/'
        . rawurlencode((string)$empId) . '/'
        . rawurlencode($fileName) . '/10';
}

function apiRequest(string $url, string $method, array $headers, ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        return ['response' => false, 'httpCode' => 0, 'error' => 'PHP cURL extension is unavailable'];
    }

    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $headers,
    ];

    if (strtoupper($method) === 'PUT') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    } else {
        $opts[CURLOPT_HTTPGET] = true;
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    // Do not call curl_close(): it is deprecated in PHP 8.5 and unnecessary in PHP 8+.
    return ['response' => $response, 'httpCode' => $httpCode, 'error' => $error];
}

function decodeResponse(array $result, string $label): array
{
    if ($result['response'] === false) {
        jsonResponse(502, [
            'status' => false,
            'message' => $label . ' API connection error',
            'details' => $result['error'] ?: 'Unknown cURL error'
        ]);
    }
    if ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        jsonResponse(502, [
            'status' => false,
            'message' => $label . ' API returned an HTTP error',
            'upstream_http_code' => $result['httpCode']
        ]);
    }
    $data = json_decode((string)$result['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        jsonResponse(502, [
            'status' => false,
            'message' => $label . ' API returned invalid JSON'
        ]);
    }
    return $data;
}

$commonHeaders = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
    'Accept: application/json, text/plain, */*',
    'Origin: https://apps.emis.gov.bd',
    'Referer: https://apps.emis.gov.bd/',
    'Accept-Language: en-US,en;q=0.9',
];

/* 1) Head / Principal list */
$urlHead = 'https://apps.emis.gov.bd:9701/api/public/hrm/emp-info/get-head-by-institute-id/0/'
    . rawurlencode($eiin) . '/0';
$headData = decodeResponse(apiRequest($urlHead, 'GET', $commonHeaders), 'Head');

if (isset($headData['data']) && is_array($headData['data'])) {
    foreach ($headData['data'] as &$item) {
        if (is_array($item) && !empty($item['image'])) {
            $empId = $item['id'] ?? $item['empRefId'] ?? null;
            $item['image'] = buildImageUrl((string)$item['image'], $empId);
        }
    }
    unset($item);
}

/* 2) Employee list.
 * Try the original intParam2=1 filter first. If it returns no rows, retry
 * with other common filter states. The upstream API's parameter semantics
 * are undocumented, so report the successful parameter instead of guessing.
 * Pagination is followed only when the upstream response reports more pages.
 */
$urlList = 'https://apps.emis.gov.bd:9701/api/public/hrm/emp-info/get-pageable-emp-list-by-institute';
$listHeaders = array_merge($commonHeaders, ['Content-Type: application/json']);

$filterCandidates = [1, null, 0, 2];
$employeeList = null;
$selectedFilter = null;
$attemptSummary = [];

foreach ($filterCandidates as $filter) {
    $allContent = [];
    $firstPageData = null;
    $page = 0;
    $pageSize = 1000;
    $reportedTotalPages = 1;
    $reportedTotalElements = null;
    $requestFailed = false;

    do {
        $bodyArray = [
            'listIntParam1' => [],
            'page' => $page,
            'size' => $pageSize,
            'searchValue' => '',
            'intParam1' => null,
            'intParam2' => $filter,
            'stringParam1' => $eiin,
        ];
        $body = json_encode($bodyArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            jsonResponse(500, ['status' => false, 'message' => 'Could not encode employee-list request']);
        }

        $result = apiRequest($urlList, 'PUT', $listHeaders, $body);

        // A filter attempt that fails upstream is recorded; do not expose raw upstream HTML.
        if ($result['response'] === false || $result['httpCode'] < 200 || $result['httpCode'] >= 300) {
            $requestFailed = true;
            $attemptSummary[] = [
                'intParam2' => $filter,
                'page' => $page,
                'upstream_http_code' => $result['httpCode'],
                'error' => $result['error'] ?: null
            ];
            break;
        }

        $pageData = json_decode((string)$result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($pageData)) {
            $requestFailed = true;
            $attemptSummary[] = [
                'intParam2' => $filter,
                'page' => $page,
                'error' => 'Upstream returned invalid JSON'
            ];
            break;
        }

        if ($firstPageData === null) {
            $firstPageData = $pageData;
        }

        $content = $pageData['data']['content'] ?? [];
        if (is_array($content)) {
            foreach ($content as $row) {
                if (is_array($row)) $allContent[] = $row;
            }
        }

        $reportedTotalPages = max(1, (int)($pageData['data']['totalPages'] ?? 1));
        $reportedTotalElements = isset($pageData['data']['totalElements'])
            ? (int)$pageData['data']['totalElements'] : null;

        $page++;
    } while ($page < $reportedTotalPages && $page < 20);

    $attemptSummary[] = [
        'intParam2' => $filter,
        'rows_received' => count($allContent),
        'reported_total_elements' => $reportedTotalElements,
        'reported_total_pages' => $reportedTotalPages,
        'request_failed' => $requestFailed
    ];

    if (!$requestFailed && count($allContent) > 0 && is_array($firstPageData)) {
        $firstPageData['data']['content'] = $allContent;
        $firstPageData['data']['totalElements'] = $reportedTotalElements ?? count($allContent);
        $firstPageData['data']['returnedByApi'] = count($allContent);
        $employeeList = $firstPageData;
        $selectedFilter = $filter;
        break;
    }

    // Keep the last valid response for diagnostics if no filter returns rows.
    if (is_array($firstPageData)) {
        $employeeList = $firstPageData;
        $selectedFilter = $filter;
    }
}

if (!is_array($employeeList)) {
    $employeeList = [
        'status' => false,
        'message' => 'No valid employee-list response received',
        'data' => ['content' => [], 'totalElements' => 0]
    ];
}

if (isset($employeeList['data']['content']) && is_array($employeeList['data']['content'])) {
    foreach ($employeeList['data']['content'] as &$item) {
        if (is_array($item) && !empty($item['image'])) {
            $empId = $item['id'] ?? $item['empRefId'] ?? null;
            $item['image'] = buildImageUrl((string)$item['image'], $empId);
        }
    }
    unset($item);
}

$employeeCount = count($employeeList['data']['content'] ?? []);

jsonResponse(200, [
    'status' => true,
    'eiin' => $eiin,
    'headInfo' => $headData,
    'employeeList' => $employeeList,
    'employeeListCount' => $employeeCount,
    'employeeListFilterUsed' => $selectedFilter,
    'employeeListDiagnostics' => $attemptSummary,
    'note' => $employeeCount === 0
        ? 'EMIS employee-list endpoint returned no rows for the tested filter values. The endpoint may use another required parameter, or the public endpoint may not expose employee rows for this EIIN.'
        : null,
    'Api_Owner' => '@Al_Nayeem756',
    'Telegram' => '@Cyber_Force756',
    'Developer' => '@Al_Nayeem756',
]);
