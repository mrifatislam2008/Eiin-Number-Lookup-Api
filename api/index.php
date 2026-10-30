<?php

require_once __DIR__ . '/../lib/EiinApi.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse([
        'success' => false,
        'error' => 'Only GET requests are supported'
    ], 405);
}

requireApiKey();

$eiin = trim((string) ($_GET['eiin'] ?? ''));

if ($eiin === '' || !preg_match('/^[0-9]{1,20}$/', $eiin)) {
    jsonResponse([
        'success' => false,
        'error' => 'A valid numeric EIIN is required',
        'example' => '?eiin=120617'
    ], 400);
}

$type = strtolower((string) ($_GET['type'] ?? 'all'));

if (!in_array($type, ['all', 'head', 'employees'], true)) {
    jsonResponse([
        'success' => false,
        'error' => 'Invalid type',
        'allowed' => ['all', 'head', 'employees']
    ], 400);
}

$page = max(0, (int) ($_GET['page'] ?? 0));
$size = min(100, max(1, (int) ($_GET['size'] ?? 100)));
$maxPages = min(5, max(1, (int) ($_GET['maxPages'] ?? 3)));

$search = trim((string) ($_GET['search'] ?? ''));
$designation = trim((string) ($_GET['designation'] ?? ''));

$format = strtolower((string) ($_GET['format'] ?? 'json'));

if (!in_array($format, ['json', 'csv'], true)) {
    jsonResponse([
        'success' => false,
        'error' => 'Invalid format. Use json or csv.'
    ], 400);
}

$headResult = null;
$employeeResult = null;

$headInfo = null;
$employeeList = null;

$headOk = null;
$employeeOk = null;

if ($type === 'all' || $type === 'head') {
    $headResult = getHeadInfo($eiin);
    $headOk = $headResult['ok'];

    if ($headOk) {
        $headInfo = removePrivateFields($headResult['data']);
    }
}

$allEmployees = [];
$lastEmployeeData = [];
$totalPages = null;
$totalElements = null;
$pagesFetched = 0;
$employeeError = null;
$employeeHttpStatus = null;

if ($type === 'all' || $type === 'employees') {
    $employeeOk = true;

    for ($i = 0; $i < $maxPages; $i++) {
        $currentPage = $page + $i;

        $employeeResult = getEmployeePage(
            $eiin,
            $currentPage,
            $size
        );

        $employeeHttpStatus = $employeeResult['status'];

        if (!$employeeResult['ok']) {
            $employeeOk = false;
            $employeeError = $employeeResult['error'];
            break;
        }

        $lastEmployeeData = $employeeResult['data'];
        $pagesFetched++;

        $content = getEmployeeContent($lastEmployeeData);

        foreach ($content as $employee) {
            if (is_array($employee)) {
                $allEmployees[] = removePrivateFields($employee);
            }
        }

        $paginationData = $lastEmployeeData['data'] ?? [];

        $totalPages = isset($paginationData['totalPages'])
            ? (int) $paginationData['totalPages']
            : null;

        $totalElements = isset($paginationData['totalElements'])
            ? (int) $paginationData['totalElements']
            : null;

        // Stop when upstream says there are no more pages.
        if ($totalPages !== null && $currentPage + 1 >= $totalPages) {
            break;
        }

        if ($totalPages === null && count($content) < $size) {
            break;
        }

        if (count($content) === 0) {
            break;
        }
    }
}

$filteredEmployees = filterEmployees(
    $allEmployees,
    $search,
    $designation
);

if ($format === 'csv') {
    if ($type === 'head') {
        jsonResponse([
            'success' => false,
            'error' => 'CSV export is available for employee records'
        ], 400);
    }

    if ($employeeOk === false) {
        jsonResponse([
            'success' => false,
            'error' => 'Could not retrieve employee records',
            'upstreamStatus' => $employeeHttpStatus
        ], 502);
    }

    exportEmployeesCsv($filteredEmployees, $eiin);
}

if ($type === 'all' || $type === 'employees') {
    $employeeList = $lastEmployeeData;

    if (!is_array($employeeList)) {
        $employeeList = [];
    }

    if (!isset($employeeList['data']) ||
        !is_array($employeeList['data'])) {
        $employeeList['data'] = [];
    }

    $employeeList['data']['content'] = $filteredEmployees;
    $employeeList = removePrivateFields($employeeList);
}

$paginationComplete = (
    $page === 0 &&
    $totalPages !== null &&
    ($totalPages === 0 || $pagesFetched >= $totalPages)
);

$requestedDataSucceeded = (
    ($type === 'head' && $headOk === true) ||
    ($type === 'employees' && $employeeOk === true) ||
    (
        $type === 'all' &&
        ($headOk === true || $employeeOk === true)
    )
);

if (!$requestedDataSucceeded) {
    jsonResponse([
        'success' => false,
        'error' => 'Could not retrieve requested EMIS information',
        'diagnostics' => [
            'headHttpStatus' => $headResult['status'] ?? null,
            'employeeHttpStatus' => $employeeHttpStatus,
            'headError' => $headResult['error'] ?? null,
            'employeeError' => $employeeError
        ]
    ], 502);
}

$response = [
    'success' => true,
    'eiin' => $eiin,
    'headInfo' => $headInfo,
    'employeeList' => $employeeList,
    'pagination' => [
        'requestedPage' => $page,
        'size' => $size,
        'maxPages' => $maxPages,
        'pagesFetched' => $pagesFetched,
        'totalPages' => $totalPages,
        'totalElements' => $totalElements,
        'fetchedEmployees' => count($allEmployees),
        'returnedEmployees' => count($filteredEmployees),
        'isLastPage' => $totalPages !== null
            ? ($page + $pagesFetched >= $totalPages)
            : null,
        'completeFromFirstPage' => $paginationComplete,
        'noEmployeesReportedByUpstream' => $totalElements === 0
    ],
    'filters' => [
        'search' => $search,
        'designation' => $designation,
        'appliedTo' => 'fetchedRecordsOnly'
    ],
    'diagnostics' => [
        'headHttpStatus' => $headResult['status'] ?? null,
        'employeeHttpStatus' => $employeeHttpStatus,
        'headRequestSucceeded' => $headOk,
        'employeeRequestSucceeded' => $employeeOk,
        'employeeError' => $employeeError
    ],
    'Api_Owner' => '@Al_Nayeem756',
    'Telegram' => 'https://t.me/Cyber_Force756',
    'Developer' => '@Cyber_Force756'
];

if ($type === 'head') {
    $response['employeeList'] = null;
}

if ($type === 'employees') {
    $response['headInfo'] = null;
}

if (
    $type === 'all' &&
    ($headOk === false || $employeeOk === false)
) {
    $response['partial'] = true;
}

jsonResponse($response);
