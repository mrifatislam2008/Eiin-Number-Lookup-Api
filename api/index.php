<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/EiinApi.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse([
        'success' => false,
        'error' => 'Only GET requests are allowed.'
    ], 405);
}

requireApiKey();

$eiin = trim((string) ($_GET['eiin'] ?? ''));

if ($eiin === '' || !preg_match('/^[0-9]{1,15}$/', $eiin)) {
    jsonResponse([
        'success' => false,
        'error' => 'A valid numeric EIIN parameter is required.',
        'example' => '?eiin=104532'
    ], 400);
}

$type = strtolower(trim((string) ($_GET['type'] ?? 'all')));

if (!in_array($type, ['all', 'head', 'employees'], true)) {
    jsonResponse([
        'success' => false,
        'error' => 'Invalid type. Use all, head, or employees.'
    ], 400);
}

$page = filter_var($_GET['page'] ?? 0, FILTER_VALIDATE_INT);
$size = filter_var($_GET['size'] ?? 100, FILTER_VALIDATE_INT);
$maxPages = filter_var($_GET['maxPages'] ?? 1, FILTER_VALIDATE_INT);

if ($page === false || $page < 0) {
    jsonResponse(['success' => false, 'error' => 'page must be 0 or greater.'], 400);
}

if ($size === false || $size < 1 || $size > 1000) {
    jsonResponse(['success' => false, 'error' => 'size must be between 1 and 1000.'], 400);
}

if ($maxPages === false || $maxPages < 1 || $maxPages > 5) {
    jsonResponse(['success' => false, 'error' => 'maxPages must be between 1 and 5.'], 400);
}

$search = trim((string) ($_GET['search'] ?? ''));
$designation = trim((string) ($_GET['designation'] ?? ''));
$format = strtolower(trim((string) ($_GET['format'] ?? 'json')));

if (!in_array($format, ['json', 'csv'], true)) {
    jsonResponse(['success' => false, 'error' => 'format must be json or csv.'], 400);
}

if ($format === 'csv' && $type === 'head') {
    jsonResponse([
        'success' => false,
        'error' => 'CSV export is available for employee data, not head information.'
    ], 400);
}

$headInfo = null;
$employeeResponse = null;
$employees = [];
$pagesFetched = 0;
$totalPages = null;
$upstreamTotalElements = null;
$isLastPage = null;
$paginationComplete = false;

try {
    if ($type === 'all' || $type === 'head') {
        $headInfo = getHeadInfo($eiin);
    }

    if ($type === 'all' || $type === 'employees') {
        $requestedPage = (int) $page;
        $pagesToFetch = ($format === 'csv') ? (int) $maxPages : 1;

        for ($i = 0; $i < $pagesToFetch; $i++) {
            $currentPage = $requestedPage + $i;

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
            $upstreamTotalElements =
                $employeeResponse['data']['totalElements'] ?? null;

            // Stop when the upstream confirms there are no more pages.
            if ($isLastPage === true) {
                break;
            }

            if ($totalPages !== null && ($currentPage + 1) >= $totalPages) {
                break;
            }

            // If the upstream does not supply pagination metadata,
            // a short page is treated as the final page.
            if ($totalPages === null && $isLastPage === null &&
                count($pageEmployees) < (int) $size) {
                break;
            }
        }

        if ($totalPages !== null) {
            $paginationComplete =
                ($requestedPage === 0 && $pagesFetched >= $totalPages);
        } elseif ($isLastPage === true) {
            $paginationComplete = ($requestedPage === 0);
        } else {
            $paginationComplete = false;
        }
    }

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

    $filteredEmployees = filterEmployees(
        $employees,
        $search,
        $designation
    );

    if ($format === 'csv') {
        exportEmployeesCsv($filteredEmployees);
    }

    if ($employeeResponse !== null) {
        // Keep the upstream response structure, replacing content
        // with the records fetched and filtered by this API.
        if (!isset($employeeResponse['data']) ||
            !is_array($employeeResponse['data'])) {
            $employeeResponse['data'] = [];
        }

        $employeeResponse['data']['content'] = $filteredEmployees;
    }

    $response = [
        'success' => true,
        'eiin' => $eiin,
        'headInfo' => $headInfo,
        'employeeList' => $employeeResponse,
        'pagination' => [
            'requestedPage' => (int) $page,
            'size' => (int) $size,
            'pagesFetched' => $pagesFetched,
            'totalPages' => $totalPages,
            'upstreamTotalElements' => $upstreamTotalElements,
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
    ];

    jsonResponse($response);

} catch (Throwable $e) {
    // Detailed errors stay in server logs, not in public API output.
    error_log('[EIIN API] ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'error' => 'Unable to retrieve information from the upstream EMIS service.',
        'eiin' => $eiin,
        'hint' => 'Please try again later. If the problem continues, check the Vercel function logs.'
    ], 502);
}
