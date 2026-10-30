
<?php

require_once __DIR__ . '/../lib/EiinApi.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'GET') {
    jsonResponse([
        'success' => false,
        'error' => 'Only GET requests are allowed.'
    ], 405);
}

requireApiKey();

// Validate EIIN.
$eiin = trim((string) ($_GET['eiin'] ?? ''));

if ($eiin === '' || !preg_match('/^[0-9]{1,15}$/', $eiin)) {
    jsonResponse([
        'success' => false,
        'error' => 'A valid numeric EIIN parameter is required.',
        'example' => '?eiin=104532'
    ], 400);
}

// Validate type.
$type = strtolower(trim((string) ($_GET['type'] ?? 'all')));

if (!in_array($type, ['all', 'head', 'employees'], true)) {
    jsonResponse([
        'success' => false,
        'error' => 'Invalid type. Use all, head, or employees.'
    ], 400);
}

// Validate pagination.
$page = filter_var($_GET['page'] ?? 0, FILTER_VALIDATE_INT);
$size = filter_var($_GET['size'] ?? 100, FILTER_VALIDATE_INT);
$maxPages = filter_var($_GET['maxPages'] ?? 1, FILTER_VALIDATE_INT);

if ($page === false || $page < 0) {
    jsonResponse([
        'success' => false,
        'error' => 'page must be 0 or greater.'
    ], 400);
}

if ($size === false || $size < 1 || $size > 1000) {
    jsonResponse([
        'success' => false,
        'error' => 'size must be between 1 and 1000.'
    ], 400);
}

if ($maxPages === false || $maxPages < 1 || $maxPages > 5) {
    jsonResponse([
        'success' => false,
        'error' => 'maxPages must be between 1 and 5.'
    ], 400);
}

$search = trim((string) ($_GET['search'] ?? ''));
$designation = trim((string) ($_GET['designation'] ?? ''));
$format = strtolower(trim((string) ($_GET['format'] ?? 'json')));

if (!in_array($format, ['json', 'csv'], true)) {
    jsonResponse([
        'success' => false,
        'error' => 'format must be json or csv.'
    ], 400);
}

if ($format === 'csv' && $type === 'head') {
    jsonResponse([
        'success' => false,
        'error' => 'CSV export is available for employee data only.'
    ], 400);
}

$headInfo = null;
$employeeResponse = null;
$employees = [];

$pagesFetched = 0;
$totalPages = null;
$totalElements = null;
$isLastPage = null;
$paginationComplete = false;

try {
    // Fetch institute head information.
    if ($type === 'all' || $type === 'head') {
        $headInfo = getHeadInfo($eiin);
    }

    // Fetch employee information.
    if ($type === 'all' || $type === 'employees') {
        $pagesToFetch = ($format === 'csv')
            ? (int) $maxPages
            : 1;

        for ($i = 0; $i < $pagesToFetch; $i++) {
            $currentPage = (int) $page + $i;

            $employeeResponse = getEmployeePage(
                $eiin,
                $currentPage,
                (int) $size
            );

            $pageEmployees = getEmployeeContent($employeeResponse);
            $pagesFetched++;

            foreach ($pageEmployees as $employee) {
                if (!is_array($employee)) {
                    continue;
                }

                $imageUrl = buildImageUrl($employee);

                if ($imageUrl !== null) {
                    $employee['imageUrl'] = $imageUrl;
                }

                $employees[] = $employee;
            }

            $totalPages = getTotalPages($employeeResponse);
            $isLastPage = getLastPageFlag($employeeResponse);

            $totalElements =
                $employeeResponse['data']['totalElements'] ?? null;

            if ($isLastPage === true) {
                break;
            }

            if (
                $totalPages !== null &&
                ($currentPage + 1) >= $totalPages
            ) {
                break;
            }

            if (
                $totalPages === null &&
                $isLastPage === null &&
                count($pageEmployees) < (int) $size
            ) {
                break;
            }
        }

        $paginationComplete = (
            (int) $page === 0 &&
            $totalPages !== null &&
            $pagesFetched >= $totalPages
        );
    }

    // Head-only response.
    if ($type === 'head') {
        jsonResponse([
            'success' => true,
            'eiin' => $eiin,
            'headInfo' => $headInfo,
            'Api_Owner' => API_OWNER,
            'Telegram' => TELEGRAM,
            'Developer' => DEVELOPER
        ]);
    }

    // Apply local filters to fetched records.
    $filteredEmployees = filterEmployees(
        $employees,
        $search,
        $designation
    );

    // CSV response.
    if ($format === 'csv') {
        exportEmployeesCsv($filteredEmployees);
    }

    // Preserve upstream response structure.
    if ($employeeResponse !== null) {
        if (
            !isset($employeeResponse['data']) ||
            !is_array($employeeResponse['data'])
        ) {
            $employeeResponse['data'] = [];
        }

        $employeeResponse['data']['content'] = $filteredEmployees;
    }

    jsonResponse([
        'success' => true,
        'eiin' => $eiin,
        'headInfo' => $headInfo,
        'employeeList' => $employeeResponse,

        'pagination' => [
            'requestedPage' => (int) $page,
            'size' => (int) $size,
            'pagesFetched' => $pagesFetched,
            'totalPages' => $totalPages,
            'totalElements' => $totalElements,
            'fetchedEmployees' => count($employees),
            'returnedEmployees' => count($filteredEmployees),
            'isLastPage' => $isLastPage,
            'completeFromFirstPage' => $paginationComplete
        ],

        'filters' => [
            'search' => $search,
            'designation' => $designation,
            'appliedTo' => 'fetchedRecordsOnly'
        ],

        'Api_Owner' => API_OWNER,
        'Telegram' => TELEGRAM,
        'Developer' => DEVELOPER
    ]);

} catch (Throwable $e) {
    // Detailed error goes to Vercel function logs.
    error_log('[EIIN API] ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'error' => 'Unable to retrieve information from EMIS.',
        'eiin' => $eiin,
        'hint' => 'Check the Vercel function logs or try again later.'
    ], 502);
}
