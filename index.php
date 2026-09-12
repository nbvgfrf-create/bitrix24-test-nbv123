<?php

declare(strict_types=1);

use BX\BXConnector;
use BX\Logger;

require_once __DIR__ . '/BXConnector.php';
require_once __DIR__ . '/Logger.php';

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$logger = new Logger('bitrix24-test');

function response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

function getRequestData(): array
{
    $raw = file_get_contents('php://input');

    if (!$raw) {
        return $_POST;
    }

    $json = json_decode($raw, true);

    if (is_array($json)) {
        return $json;
    }

    parse_str($raw, $data);

    return is_array($data) ? $data : [];
}

/*
 * Проверка работы Render.
 *
 * GET /
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    response([
        'success' => true,
        'service' => 'bitrix24-test-nbv123',
        'message' => 'PHP server is working',
    ]);
}

/*
 * Получаем POST от Bitrix24.
 */
$data = getRequestData();

$logger->saveFile([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'data' => $data,
], 'requests.log');

if (!$config['bitrix_webhook']) {
    response([
        'success' => false,
        'error' => 'BITRIX_WEBHOOK is not configured',
    ], 500);
}

/*
 * Создаём подключение к Bitrix24.
 */
$bx = new BXConnector($config['bitrix_webhook']);

/*
 * Пока делаем простой тест REST-соединения.
 */
$result = $bx->request('profile');

$logger->saveFile($result, 'bitrix.log');

response([
    'success' => true,
    'message' => 'Request received and Bitrix24 connection tested',
    'bitrix' => $result,
]);