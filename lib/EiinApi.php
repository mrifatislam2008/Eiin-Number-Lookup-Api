
<?php
declare(strict_types=1);

/*
 * EIIN Information API
 * Original upstream: Bangladesh EMIS
 * Original credits: @Al_Nayeem756 / @Cyber_Force756
 */

const EMIS_BASE = 'https://apps.emis.gov.bd:9701/api/public/hrm';
const API_OWNER = '@Al_Nayeem756';
const TELEGRAM  = 'https://t.me/Cyber_Force756';
const DEVELOPER  = '@Cyber_Force756';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function requireApiKey(): void
{
    $requiredKey = getenv('API_KEY');

    // API_KEY সেট না থাকলে authentication বন্ধ থাকবে।
    if ($requiredKey === false || $requiredKey === '') {
        return;
    }

    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if (!is_string($providedKey) || !hash_equals($requiredKey, $providedKey)) {
        jsonResponse([
            'success' => false,
            'error' => 'Unauthorized. A valid X-API-Key header is required.'
        ], 401);
    }
}

function apiRequest(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init();

    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    $headers = [
        'Accept: application/json',
        'User-Agent: EIIN-Information-API/3.1'
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
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
    ];

    if ($body !== null) {
        $jsonBody = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($jsonBody === false) {
            unset($ch);
            throw new RuntimeException('Could not encode upstream request.');
        }

        $options[CURLOPT_POSTFIELDS] = $jsonBody;
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);

    if ($raw === false) {
        throw new RuntimeException('Upstream connection failed: ' . $curlError);
    }

    $decoded = json_decode((string) $raw, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'Upstream returned invalid JSON. HTTP ' . $httpCode
        );
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            'Upstream returned HTTP ' . $httpCode
        );
    }

    return $decoded;
}

function getHeadInfo(string $eiin): array
{
    $url = EMIS_BASE .
        '/emp-info/get-head-by-institute-id/0/' .
        rawurlencode($eiin) .
        '/0';

    return apiRequest('GET', $url);
}

function getEmployeePage(
    string $eiin,
    int $page,
    int $size,
    string $upstreamSearch = ''
): array {
    $url = EMIS_BASE . '/emp-info/get-pageable-emp-list-by-institute';

    $body = [
        'listIntParam1' => [],
        'page' => $page,
        'size' => $size,
        'searchValue' => $upstreamSearch,
        'intParam1' => null,
        'intParam2' => 1,
        'stringParam1' => $eiin
    ];

    return apiRequest('PUT', $url, $body);
}

function getEmployeeContent(array $response): array
{
    $content = $response['data']['content'] ?? [];

    return is_array($content) ? $content : [];
}

function getTotalPages(array $response): ?int
{
    $value = $response['data']['totalPages'] ?? null;

    return is_numeric($value) ? max(0, (int) $value) : null;
}

function getLastPageFlag(array $response): ?bool
{
    $value = $response['data']['last'] ?? null;

    return is_bool($value) ? $value : null;
}

function buildImageUrl(array $employee): ?string
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

/*
 * Only search through fields that are useful for professional
 * institute information. Does not inspect private contact or ID fields.
 */
function employeeSearchText(array $employee): string
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

    return mb_strtolower(implode(' ', $parts), 'UTF-8');
}

function filterEmployees(
    array $employees,
    string $search,
    string $designation
): array {
    $search = mb_strtolower(trim($search), 'UTF-8');
    $designation = mb_strtolower(trim($designation), 'UTF-8');

    if ($search === '' && $designation === '') {
        return $employees;
    }

    return array_values(array_filter(
        $employees,
        function ($employee) use ($search, $designation): bool {
            if (!is_array($employee)) {
                return false;
            }

            $text = employeeSearchText($employee);

            if ($search !== '' && !str_contains($text, $search)) {
                return false;
            }

            if ($designation !== '') {
                $designationText = mb_strtolower(
                    (string) (
                        $employee['designation']
                        ?? $employee['designationName']
                        ?? ''
                    ),
                    'UTF-8'
                );

                if (!str_contains($designationText, $designation)) {
                    return false;
                }
            }

            return true;
        }
    ));
}

/*
 * CSV exports a strict allowlist of professional fields only.
 * Unknown and private fields are deliberately excluded.
 */
function exportEmployeesCsv(array $employees): never
{
    $columns = [
        'empId' => 'Employee ID',
        'employeeId' => 'Employee ID',
        'name' => 'Name',
        'employeeName' => 'Name',
        'empName' => 'Name',
        'designation' => 'Designation',
        'designationName' => 'Designation',
        'subject' => 'Subject',
        'subjectName' => 'Subject',
        'instituteName' => 'Institute',
        'imageUrl' => 'Image URL'
    ];

    $used = [];

    foreach ($employees as $employee) {
        if (!is_array($employee)) {
            continue;
        }

        foreach (array_keys($columns) as $key) {
            if (array_key_exists($key, $employee)) {
                $used[$key] = true;
            }
        }
    }

    $selectedColumns = array_keys($used);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="eiin-employees.csv"');
    header('Cache-Control: no-store');

    $output = fopen('php://output', 'w');

    if ($output === false) {
        exit;
    }

    // UTF-8 BOM for spreadsheet compatibility.
    fwrite($output, "\xEF\xBB\xBF");

    $labels = [];
    foreach ($selectedColumns as $key) {
        $labels[] = $columns[$key];
    }

    fputcsv($output, $labels);

    foreach ($employees as $employee) {
        if (!is_array($employee)) {
            continue;
        }

        $row = [];

        foreach ($selectedColumns as $key) {
            $value = $employee[$key] ?? '';

            if (!is_scalar($value)) {
                $value = '';
            }

            $value = (string) $value;

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
