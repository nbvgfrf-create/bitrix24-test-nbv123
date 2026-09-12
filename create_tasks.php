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

$elementId = isset($_GET['element_id'])
    ? (int)$_GET['element_id']
    : 0;

if ($elementId <= 0) {
    response([
        'success' => false,
        'error' => 'Передай element_id',
        'example' => '/create_tasks.php?element_id=2',
    ], 400);
}

if (!$config['bitrix_webhook']) {
    response([
        'success' => false,
        'error' => 'BITRIX_WEBHOOK is not configured',
    ], 500);
}

$bx = new BXConnector($config['bitrix_webhook']);

/*
 * Получаем элемент списка.
 */
$elementResponse = $bx->request(
    'lists.element.get',
    [
        'IBLOCK_TYPE_ID' => 'lists',
        'IBLOCK_ID' => $config['list_id'],
        'ELEMENT_ID' => $elementId,
    ]
);

$logger->saveFile($elementResponse, 'create_tasks_element.log');

if (
    !isset($elementResponse['result']) ||
    empty($elementResponse['result'])
) {
    response([
        'success' => false,
        'error' => 'Элемент списка не найден',
        'element_id' => $elementId,
        'bitrix_response' => $elementResponse,
    ], 404);
}

$element = $elementResponse['result'];

$elementName = $element['NAME'] ?? 'Без названия';

$responsibleId = $config['responsible_id'];

if ($responsibleId <= 0) {
    response([
        'success' => false,
        'error' => 'BITRIX_RESPONSIBLE_ID is not configured',
    ], 500);
}

/*
 * Задача №1
 */
$task1 = $bx->request(
    'tasks.task.add',
    [
        'fields' => [
            'TITLE' => 'Задача 1 — ' . $elementName,
            'DESCRIPTION' =>
                'Автоматически созданная задача №1' . PHP_EOL .
                'Элемент списка: ' . $elementName . PHP_EOL .
                'ID элемента: ' . $elementId,
            'RESPONSIBLE_ID' => $responsibleId,
        ],
    ]
);

$logger->saveFile($task1, 'task1.log');

if (empty($task1['result']['task']['id'])) {
    response([
        'success' => false,
        'error' => 'Не удалось создать задачу №1',
        'bitrix_response' => $task1,
    ], 500);
}

$task1Id = (int)$task1['result']['task']['id'];

/*
 * Задача №2
 */
$task2 = $bx->request(
    'tasks.task.add',
    [
        'fields' => [
            'TITLE' => 'Задача 2 — ' . $elementName,
            'DESCRIPTION' =>
                'Автоматически созданная задача №2' . PHP_EOL .
                'Элемент списка: ' . $elementName . PHP_EOL .
                'ID элемента: ' . $elementId,
            'RESPONSIBLE_ID' => $responsibleId,
        ],
    ]
);

$logger->saveFile($task2, 'task2.log');

if (empty($task2['result']['task']['id'])) {
    response([
        'success' => false,
        'error' => 'Не удалось создать задачу №2',
        'task1_id' => $task1Id,
        'bitrix_response' => $task2,
    ], 500);
}

$task2Id = (int)$task2['result']['task']['id'];

/*
 * Пока просто возвращаем созданные ID.
 */
response([
    'success' => true,
    'element_id' => $elementId,
    'task1_id' => $task1Id,
    'task2_id' => $task2Id,
]);
