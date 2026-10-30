
<?php

/*
 * EIIN Information API
 * Upstream: Bangladesh EMIS
 * Original credits: @Al_Nayeem756 / @Cyber_Force756
 */

const EMIS_BASE = 'https://apps.emis.gov.bd:9701/api/public/hrm';
const API_OWNER = '@Al_Nayeem756';
const TELEGRAM = 'https://t.me/Cyber_Force756';
const DEVELOPER = '@Cyber_Force756';

/**
 * Send JSON response and stop execution.
 */
function jsonResponse($data, $status = 200)
{
    http_response_code((int) $status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * Unicode lowercase fallback.
 */
function safeLower($value)
{
    $value = (string) $value;

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

/**
 * Optional API key authentication.
 */
function requireApiKey()
{
    $requiredKey = getenv('API_KEY');

    if ($requiredKey === false || $requiredKey === '') {
        return;
    }

    $providedKey = isset($_SERVER['HTTP_X_API_KEY'])
        ? (string) $_SERVER['HTTP_X_API_KEY']
        : '';

    if (!hash_equals((string) $requiredKey, $providedKey)) {
        jsonResponse([
            'success' => false,
            'error' => 'Unauthorized. Invalid API key.'
        ], 401);
    }
}

/**
 * Send request to EMIS.
 */
function apiRequest($method, $url, $body = null)
{
    if (!function_exists('curl_init')) {
        throw new Exception('PHP cURL extension is unavailable.');
    }

    $ch = curl_init();

    if ($ch === false) {
        throw new Exception('Unable to initialize cURL.');
    }

    $headers = [
        'Accept: application/json',
        'User-Agent: EIIN-Information-API/3.2'
    ];

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method)
    ];

    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }

    if ($body !== null) {
        $jsonBody = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($jsonBody === false) {
            unset($ch);
            throw new Exception('Could not encode upstream request.');
        }

        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $jsonBody;
    }

    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);

    if ($raw === false) {
        throw new Exception('Upstream connection failed: ' . $curlError);
    }

    $decoded = json_decode((string) $raw, true);

    if (!is_array($decoded)) {
        throw new Exception(
            'Upstream returned invalid JSON. HTTP ' . $httpCode
        );
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception(
            'Upstream returned HTTP ' . $httpCode
        );
    }

    return $decoded;
}

/**
 * Fetch institute head information.
 */
function getHeadInfo($eiin)
{
    $url = EMIS_BASE .
        '/emp-info/get-head-by-institute-id/0/' .
        rawurlencode((string) $eiin) .
        '/0';

    return apiRequest('GET', $url);
}

/**
 * Fetch one employee page.
 */
function getEmployeePage($eiin, $page, $size)
{
    $url = EMIS_BASE .
        '/emp-info/get-pageable-emp-list-by-institute';

    $body = [
        'listIntParam1' => [],
        'page' => (int) $page,
        'size' => (int) $size,
        'searchValue' => '',
        'intParam1' => null,
        'intParam2' => 1,
        'stringParam1' => (string) $eiin
    ];

    return apiRequest('PUT', $url, $body);
}

/**
 * Extract employee content.
 */
function getEmployeeContent($response)
{
    $content = $response['data']['content'] ?? [];

    return is_array($content) ? $content : [];
}

/**
 * Read total pages from upstream.
 */
function getTotalPages($response)
{
    $value = $response['data']['totalPages'] ?? null;

    return is_numeric($value) ? max(0, (int) $value) : null;
}

/**
 * Read last-page flag from upstream.
 */
function getLastPageFlag($response)
{
    $value = $response['data']['last'] ?? null;

    return is_bool($value) ? $value : null;
}

/**
 * Build employee image URL when source fields are available.
 */
function buildImageUrl($employee)
{
    $empId = $employee['empId']
        ?? $employee['employeeId']
        ?? $employee['id']
        ?? null;

    $filename = $employee['filename']
        ?? $employee['fileName']
        ?? $employee['photoName']
        ?? $employee['imageName']
        ?? null;

    if ($empId === null || $filename === null) {
        return null;
    }

    $empId = trim((string) $empId);
    $filename = trim((string) $filename);

    if ($empId === '' || $filename === '') {
        return null;
    }

    return EMIS_BASE . '/file/view/' .
        rawurlencode($empId) . '/' .
        rawurlencode($filename) . '/10';
}

/**
 * Create searchable text from professional fields only.
 */
function employeeSearchText($employee)
{
    $keys = [
        'name',
        'employeeName',
        'empName',
        'designation',
        'designationName',
        'subject',
        'subjectName',
        'instituteName'
    ];

    $parts = [];

    foreach ($keys as $key) {
        if (isset($employee[$key]) && is_scalar($employee[$key])) {
            $parts[] = (string) $employee[$key];
        }
    }

    return safeLower(implode(' ', $parts));
}

/**
 * Apply local search and designation filters.
 */
function filterEmployees($employees, $search, $designation)
{
    $search = safeLower(trim((string) $search));
    $designation = safeLower(trim((string) $designation));

    if ($search === '' && $designation === '') {
        return $employees;
    }

    $filtered = [];

    foreach ($employees as $employee) {
        if (!is_array($employee)) {
            continue;
        }

        $searchText = employeeSearchText($employee);

        if ($search !== '' && strpos($searchText, $search) === false) {
            continue;
        }

        if ($designation !== '') {
            $designationText = safeLower(
                (string) (
                    $employee['designation']
                    ?? $employee['designationName']
                    ?? ''
                )
            );

            if (strpos($designationText, $designation) === false) {
                continue;
            }
        }

        $filtered[] = $employee;
    }

    return $filtered;
}

/**
 * Export only approved professional fields to CSV.
 * Private personal information is deliberately excluded.
 */
function exportEmployeesCsv($employees)
{
    $fieldMap = [
        'Employee ID' => ['empId', 'employeeId'],
        'Name' => ['name', 'employeeName', 'empName'],
        'Designation' => ['designation', 'designationName'],
        'Subject' => ['subject', 'subjectName'],
        'Institute' => ['instituteName'],
        'Image URL' => ['imageUrl']
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="eiin-employees.csv"');
    header('Cache-Control: no-store');

    $output = fopen('php://output', 'w');

    if ($output === false) {
        exit;
    }

    // UTF-8 BOM for spreadsheet compatibility.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, array_keys($fieldMap));

    foreach ($employees as $employee) {
        if (!is_array($employee)) {
            continue;
        }

        $row = [];

        foreach ($fieldMap as $possibleKeys) {
            $value = '';

            foreach ($possibleKeys as $key) {
                if (isset($employee[$key]) && is_scalar($employee[$key])) {
                    $value = (string) $employee[$key];
                    break;
                }
            }

            // Prevent spreadsheet formula injection.
            if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
                $value = "'" . $value;
            }

            $row[] = $value;
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}
