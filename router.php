<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

$blockedPrefixes = [
    '/queues/',
    '/logs/',
    '/locks/',
    '/vendor/',
    '/.git/',
];

foreach ($blockedPrefixes as $prefix) {
    if (strpos($path, $prefix) === 0) {
        http_response_code(404);
        exit('Not found');
    }
}

$blockedNames = [
    '/config.php',
    '/bootstrap.php',
    '/BXConnector.php',
    '/ImportHelpers.php',
    '/JsonStore.php',
    '/XlsxReader.php',
    '/worker.php',
    '/composer.json',
    '/composer.lock',
    '/Dockerfile',
    '/render.yaml',
    '/README.md',
];

if (in_array($path, $blockedNames, true)) {
    http_response_code(404);
    exit('Not found');
}

if (preg_match('/\.(json|xlsx|lock)$/i', $path)) {
    http_response_code(404);
    exit('Not found');
}

$allowedPhp = [
    '/run.php',
    '/diagnostic.php',
    '/xlsx_to_json.php',
    '/check.php',
];

if (substr($path, -4) === '.php' && !in_array($path, $allowedPhp, true)) {
    http_response_code(404);
    exit('Not found');
}

if ($path === '/') {
    require __DIR__ . '/run.php';
    exit;
}

return false;
