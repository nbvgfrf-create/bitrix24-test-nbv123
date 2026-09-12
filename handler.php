<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

use BX\BXConnector;
use BX\Logger;

require_once __DIR__ . '/BXConnector.php';
require_once __DIR__ . '/Logger.php';

$config = require __DIR__ . '/config.php';

if (!$config['bitrix_webhook']) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'BITRIX_WEBHOOK is not configured',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}

$logger = new Logger('bitrix24-handler');

$bx = new BXConnector($config['bitrix_webhook']);


/**
 * Отправка JSON-ответа.
 */
function response(array $data, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );

    exit;
}


/**
 * Получение входящих данных.
 *
 * Поддерживает как JSON, так и обычный POST.
 */
function getRequestData(): array
{
    $raw = file_get_contents('php://input');

    if ($raw) {
        $json = json_decode($raw, true);

        if (is_array($json)) {
            return $json;
        }
    }

    return $_POST;
}


/**
 * Получение ID задачи из события ONTASKUPDATE.
 */
function getTaskIdFromEvent(array $data): ?int
{
    $id =
        $data['data']['FIELDS_AFTER']['ID']
        ?? $data['data']['FIELDS_AFTER']['id']
        ?? $data['data']['ID']
        ?? null;

    if ($id === null) {
        return null;
    }

    return (int)$id;
}


/**
 * Создание задачи.
 */
function createTask(
    BXConnector $bx,
    string $title,
    string $description,
    int $responsibleId
): int {
    $result = $bx->request(
        'tasks.task.add',
        [
            'fields' => [
                'TITLE' => $title,
                'DESCRIPTION' => $description,
                'RESPONSIBLE_ID' => $responsibleId,
            ],
        ]
    );

    if (!isset($result['task']['id'])) {
        throw new RuntimeException(
            'Не удалось создать задачу: ' .
            json_encode($result, JSON_UNESCAPED_UNICODE)
        );
    }

    return (int)$result['task']['id'];
}


/**
 * Получение задачи.
 */
function getTask(
    BXConnector $bx,
    int $taskId
): ?array {
    $result = $bx->request(
        'tasks.task.get',
        [
            'taskId' => $taskId,
            'select' => [
                'ID',
                'TITLE',
                'DESCRIPTION',
                'STATUS',
                'RESPONSIBLE_ID',
            ],
        ]
    );

    if (!isset($result['task'])) {
        return null;
    }

    return $result['task'];
}


/**
 * Получение элемента универсального списка.
 */
function getListElement(
    BXConnector $bx,
    int $listId,
    int $elementId
): ?array {
    $result = $bx->request(
        'lists.element.get',
        [
            'IBLOCK_TYPE_ID' => 'lists',
            'IBLOCK_ID' => $listId,
            'ELEMENT_ID' => $elementId,
        ]
    );

    if (empty($result)) {
        return null;
    }

    return $result[0] ?? null;
}


/**
 * Получение полей универсального списка.
 */
function getListFields(
    BXConnector $bx,
    int $listId
): array {
    return $bx->request(
        'lists.field.get',
        [
            'IBLOCK_TYPE_ID' => 'lists',
            'IBLOCK_ID' => $listId,
        ]
    );
}


/**
 * Преобразование значения поля в строку.
 */
function normalizeValue(mixed $value): string
{
    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $values[] = implode(
                    ': ',
                    array_map('strval', $item)
                );
            } else {
                $values[] = (string)$item;
            }
        }

        return implode(', ', $values);
    }

    if ($value === null) {
        return '';
    }

    return (string)$value;
}


/**
 * Формирование HTML-таблицы
 * с кодами полей и значениями.
 */
function buildTable(
    array $element,
    array $fields
): string {
    $html = '<table border="1" cellpadding="6" cellspacing="0">';

    $html .= '<tr>';
    $html .= '<th>Код поля</th>';
    $html .= '<th>Значение</th>';
    $html .= '</tr>';

    foreach ($fields as $fieldId => $field) {

        if (!isset($field['CODE'])) {
            continue;
        }

        $code = $field['CODE'];

        $value = $element[$fieldId] ?? '';

        $value = normalizeValue($value);

        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($code) . '</td>';
        $html .= '<td>' . htmlspecialchars($value) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</table>';

    return $html;
}


/**
 * Получение ID файлов из элемента списка.
 *
 * Пока только собираем ID.
 * Прикрепление к задаче сделаем следующим этапом.
 */
function extractFileIds(
    array $element,
    array $fields
): array {
    $files = [];

    foreach ($fields as $fieldId => $field) {

        if (!isset($field['CODE'])) {
            continue;
        }

        if (($field['TYPE'] ?? '') !== 'F') {
            continue;
        }

        if (!isset($element[$fieldId])) {
            continue;
        }

        $values = $element[$fieldId];

        if (!is_array($values)) {
            continue;
        }

        foreach ($values as $fileId) {

            if (is_numeric($fileId)) {
                $files[] = (int)$fileId;
            }
        }
    }

    return array_values(array_unique($files));
}


$data = getRequestData();

$event = $data['event'] ?? $data['EVENT'] ?? '';

$logger->write([
    'event' => $event,
    'request' => $data,
]);


/*
|--------------------------------------------------------------------------
| 1. СОЗДАНИЕ ЭЛЕМЕНТА СПИСКА
|--------------------------------------------------------------------------
|
| Бизнес-процесс Bitrix24 должен отправить:
|
| event=LIST_ELEMENT_ADD
| element_id=123
|
*/

if ($event === 'LIST_ELEMENT_ADD') {

    $elementId = (int)($data['element_id'] ?? 0);

    if (!$elementId) {
        response([
            'success' => false,
            'error' => 'element_id is required',
        ], 400);
    }


    /*
     * Получаем созданный элемент.
     */

    $element = getListElement(
        $bx,
        $config['list_id'],
        $elementId
    );

    if (!$element) {
        response([
            'success' => false,
            'error' => 'List element not found',
            'element_id' => $elementId,
        ], 404);
    }


    $title = $element['NAME']
        ?? "Элемент списка #{$elementId}";


    /*
     * Первая задача.
     */

    $description1 = <<<HTML
<p>
Задача создана автоматически.
</p>

<p>
<b>Универсальный список:</b> {$config['list_id']}<br>
<b>Элемент:</b> {$elementId}<br>
<b>Название:</b> {$title}
</p>

<p>
PAIR_ELEMENT_ID={$elementId}
PAIR_TASK=1
</p>
HTML;


    /*
     * Вторая задача.
     */

    $description2 = <<<HTML
<p>
Задача создана автоматически.
</p>

<p>
<b>Универсальный список:</b> {$config['list_id']}<br>
<b>Элемент:</b> {$elementId}<br>
<b>Название:</b> {$title}
</p>

<p>
PAIR_ELEMENT_ID={$elementId}
PAIR_TASK=2
</p>
HTML;


    $task1 = createTask(
        $bx,
        "[LIST {$config['list_id']}:{$elementId}] Задача 1",
        $description1,
        $config['responsible_id']
    );


    $task2 = createTask(
        $bx,
        "[LIST {$config['list_id']}:{$elementId}] Задача 2",
        $description2,
        $config['responsible_id']
    );


    /*
     * Записываем ID второй задачи в первую
     * и ID первой во вторую.
     */

    $bx->request(
        'tasks.task.update',
        [
            'taskId' => $task1,
            'fields' => [
                'DESCRIPTION' =>
                    $description1 .
                    "<p>PAIR_TASK_ID={$task2}</p>",
            ],
        ]
    );


    $bx->request(
        'tasks.task.update',
        [
            'taskId' => $task2,
            'fields' => [
                'DESCRIPTION' =>
                    $description2 .
                    "<p>PAIR_TASK_ID={$task1}</p>",
            ],
        ]
    );


    response([
        'success' => true,
        'event' => 'LIST_ELEMENT_ADD',
        'element_id' => $elementId,
        'tasks' => [
            'task1' => $task1,
            'task2' => $task2,
        ],
    ]);
}


/*
|--------------------------------------------------------------------------
| 2. ИЗМЕНЕНИЕ ЗАДАЧИ
|--------------------------------------------------------------------------
|
| Bitrix24 отправляет событие ONTASKUPDATE.
|
*/

if ($event === 'ONTASKUPDATE') {

    $taskId = getTaskIdFromEvent($data);

    if (!$taskId) {
        response([
            'success' => false,
            'error' => 'Task ID not found',
        ], 400);
    }


    /*
     * Получаем задачу.
     */

    $task = getTask(
        $bx,
        $taskId
    );

    if (!$task) {
        response([
            'success' => false,
            'error' => 'Task not found',
            'task_id' => $taskId,
        ], 404);
    }


    /*
     * STATUS = 5 означает завершённую задачу.
     */

    if ((string)($task['status'] ?? '') !== '5') {
        response([
            'success' => true,
            'ignored' => true,
            'reason' => 'Task is not completed',
            'task_id' => $taskId,
        ]);
    }


    $description = $task['description'] ?? '';


    /*
     * Проверяем, что задача создана нашим скриптом.
     */

    if (!preg_match(
        '/PAIR_ELEMENT_ID=(\d+)/',
        $description,
        $elementMatch
    )) {
        response([
            'success' => true,
            'ignored' => true,
            'reason' => 'Not our task',
            'task_id' => $taskId,
        ]);
    }


    $elementId = (int)$elementMatch[1];


    /*
     * Получаем ID второй задачи.
     */

    if (!preg_match(
        '/PAIR_TASK_ID=(\d+)/',
        $description,
        $pairMatch
    )) {
        response([
            'success' => false,
            'error' => 'Second task ID not found',
            'task_id' => $taskId,
        ], 500);
    }


    $pairTaskId = (int)$pairMatch[1];


    /*
     * Получаем вторую задачу.
     */

    $pairTask = getTask(
        $bx,
        $pairTaskId
    );

    if (!$pairTask) {
        response([
            'success' => false,
            'error' => 'Second task not found',
            'task_id' => $pairTaskId,
        ], 404);
    }


    /*
     * Если вторая ещё не завершена,
     * просто ждём.
     */

    if ((string)($pairTask['status'] ?? '') !== '5') {
        response([
            'success' => true,
            'waiting' => true,
            'element_id' => $elementId,
            'completed_task' => $taskId,
            'waiting_task' => $pairTaskId,
        ]);
    }


    /*
     * Обе задачи завершены.
     *
     * Проверяем, не создавали ли третью раньше.
     */

    $resultTitle =
        "[LIST {$config['list_id']}:{$elementId}] Итоговая задача";


    $existing = $bx->request(
        'tasks.task.list',
        [
            'filter' => [
                'TITLE' => $resultTitle,
            ],
            'select' => [
                'ID',
                'TITLE',
                'STATUS',
            ],
        ]
    );


    if (!empty($existing['tasks'])) {
        response([
            'success' => true,
            'already_created' => true,
            'element_id' => $elementId,
        ]);
    }


    /*
     * Получаем элемент списка.
     */

    $element = getListElement(
        $bx,
        $config['list_id'],
        $elementId
    );

    if (!$element) {
        response([
            'success' => false,
            'error' => 'List element not found',
            'element_id' => $elementId,
        ], 404);
    }


    /*
     * Получаем поля списка.
     */

    $fields = getListFields(
        $bx,
        $config['list_id']
    );


    /*
     * Формируем таблицу.
     */

    $table = buildTable(
        $element,
        $fields
    );


    $elementName = htmlspecialchars(
        (string)($element['NAME'] ?? '')
    );


    $description = <<<HTML
<h3>Данные элемента универсального списка</h3>

<p>
<b>Список:</b> {$config['list_id']}<br>
<b>Элемент:</b> {$elementId}<br>
<b>Название:</b> {$elementName}
</p>

{$table}
HTML;


    /*
     * Создаём третью задачу.
     */

    $task3 = createTask(
        $bx,
        $resultTitle,
        $description,
        $config['responsible_id']
    );


    /*
     * Получаем ID файлов.
     */

    $fileIds = extractFileIds(
        $element,
        $fields
    );


    $logger->write([
        'event' => 'FINAL_TASK_CREATED',
        'element_id' => $elementId,
        'task_id' => $task3,
        'file_ids' => $fileIds,
    ]);


    response([
        'success' => true,
        'final_task_created' => true,
        'element_id' => $elementId,
        'task_id' => $task3,
        'file_ids' => $fileIds,
    ]);
}


/*
|--------------------------------------------------------------------------
| Неизвестное или пока неиспользуемое событие
|--------------------------------------------------------------------------
*/

response([
    'success' => true,
    'ignored' => true,
    'event' => $event,
]);