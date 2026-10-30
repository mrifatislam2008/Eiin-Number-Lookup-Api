<?php
/*
 * EIIN Information API
 * Original credits: @Al_Nayeem756
 * Telegram: https://t.me/Cyber_Force756
 * Developer: @Cyber_Force756
 */

const EMIS_BASE = 'https://apps.emis.gov.bd:9701/api/public/hrm';

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
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

function emisRequest(
    string $url,
    string $method = 'GET',
    ?array $body = null
): array {
    $ch = curl_init($url);

    if (!$ch) {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => 'Could not initialize cURL'
        ];
    }

    $headers = [
        'Accept: application/json',
        'Accept-Encoding: identity',
        'User-Agent: EIIN-Information-API/1.0'
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers
    ];

    if ($method === 'PUT') {
        $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
        $options[CURLOPT_POSTFIELDS] = json_encode(
            $body ?? [],
            JSON_UNESCAPED_UNICODE
        );
        $options[CURLOPT_HTTPHEADER][] =
            'Content-Type: application/json';
    }

    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => $httpCode,
            'data' => null,
            'error' => $curlError ?: 'Connection failed'
        ];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $httpCode,
            'data' => null,
            'error' => 'Upstream returned invalid JSON'
        ];
    }

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'data' => $decoded,
        'error' => $httpCode >= 200 && $httpCode < 300
            ? null
            : 'Upstream HTTP error'
    ];
}

function removePrivateFields($value)
{
    $privateKeys = [
        'mobileno',
        'mobile',
        'phone',
        'phonenumber',
        'email',
        'nid',
        'nationalid',
        'dateofbirth',
        'dob',
        'address',
        'personaladdress'
    ];

    if (!is_array($value)) {
        return $value;
    }

    $clean = [];

    foreach ($value as $key => $item) {
        $normalized = strtolower(
            preg_replace('/[^a-zA-Z0-9]/', '', (string) $key)
        );

        if (in_array($normalized, $privateKeys, true)) {
            continue;
        }

        $clean[$key] = removePrivateFields($item);
    }

    return $clean;
}

function getHeadInfo(string $eiin): array
{
    $url = EMIS_BASE .
        '/emp-info/get-head-by-institute-id/0/' .
        rawurlencode($eiin) . '/0';

    return emisRequest($url);
}

function getEmployeePage(string $eiin, int $page, int $size): array
{
    $url = EMIS_BASE .
        '/emp-info/get-pageable-emp-list-by-institute';

    $body = [
        'listIntParam1' => [],
        'page' => $page,
        'size' => $size,
        'searchValue' => '',
        'intParam1' => null,
        'intParam2' => 1,
        'stringParam1' => $eiin
    ];

    return emisRequest($url, 'PUT', $body);
}

function safeLower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function getEmployeeContent(array $response): array
{
    $data = $response['data'] ?? [];

    if (isset($data['content']) && is_array($data['content'])) {
        return $data['content'];
    }

    if (is_array($data) && array_is_list($data)) {
        return $data;
    }

    return [];
}

function filterEmployees(
    array $employees,
    string $search,
    string $designation
): array {
    return array_values(array_filter(
        $employees,
        function ($employee) use ($search, $designation) {
            if (!is_array($employee)) {
                return false;
            }

            $name = safeLower(
                (string) ($employee['empName'] ?? '') . ' ' .
                (string) ($employee['empNameBn'] ?? '')
            );

            $job = safeLower(
                (string) ($employee['designationName'] ?? '') . ' ' .
                (string) ($employee['designationNameBn'] ?? '')
            );

            if (
                $search !== '' &&
                strpos($name . ' ' . $job, safeLower($search)) === false
            ) {
                return false;
            }

            if (
                $designation !== '' &&
                strpos($job, safeLower($designation)) === false
            ) {
                return false;
            }

            return true;
        }
    ));
}

function csvCell($value): string
{
    $value = (string) ($value ?? '');

    if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
        return "'" . $value;
    }

    return $value;
}

function exportEmployeesCsv(array $employees, string $eiin): void
{
    $filename = 'eiin-' .
        preg_replace('/[^0-9]/', '', $eiin) .
        '-employees.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename . '"'
    );
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM for spreadsheet compatibility.
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Employee ID',
        'Employee Name',
        'Employee Name (Bangla)',
        'Designation',
        'Designation (Bangla)'
    ]);

    foreach ($employees as $employee) {
        fputcsv($out, [
            csvCell($employee['empRefId'] ?? $employee['id'] ?? ''),
            csvCell($employee['empName'] ?? ''),
            csvCell($employee['empNameBn'] ?? ''),
            csvCell($employee['designationName'] ?? ''),
            csvCell($employee['designationNameBn'] ?? '')
        ]);
    }

    fclose($out);
    exit;
}

function requireApiKey(): void
{
    $expected = getenv('API_KEY');

    // Authentication remains optional unless API_KEY is configured.
    if (!$expected) {
        return;
    }

    $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if (!hash_equals($expected, $provided)) {
        jsonResponse([
            'success' => false,
            'error' => 'Unauthorized'
        ], 401);
    }
}
