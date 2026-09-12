<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

use BX\BXConnector;

require_once __DIR__ . '/BXConnector.php';

$config = require __DIR__ . '/config.php';

if (empty($config['bitrix_webhook'])) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'BITRIX_WEBHOOK is not configured',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}

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
 * Логирование в Render.
 */
function logMessage(string $message, array $data = []): void
{
    error_log(
        '[bitrix24-handler] ' .
        $message .
        (
            $data
                ? ' ' . json_encode(
                    $data,
                    JSON_UNESCAPED_UNICODE
                )
                : ''
        )
    );
}


/**
 * Получение входящих данных.
 *
 * Bitrix24 может передавать:
 * - JSON в теле запроса;
 * - обычный POST;
 * - параметры в URL.
 *
 * Поэтому объединяем все три варианта.
 */
function getRequestData(): array
{
    $data = [];

    /*
     * JSON body.
     */
    $raw = file_get_contents('php://input');

    if ($raw) {
        $json = json_decode($raw, true);

        if (is_array($json)) {
            $data = $json;
        }
    }

    /*
     * POST.
     */
    if (!empty($_POST)) {
        $data = array_merge(
            $data,
            $_POST
        );
    }

    /*
     * GET / query string.
     *
     * Например:
     *
     * ?event=LIST_ELEMENT_ADD&element_id=4
     */
    if (!empty($_GET)) {
        $data = array_merge(
            $data,
            $_GET
        );
    }

    return $data;
}


/**
 * Получение ID элемента списка.
 */
function getElementIdFromRequest(array $data): int
{
    /*
     * Наш параметр из URL БП.
     */
    if (!empty($data['element_id'])) {
        return (int)$data['element_id'];
    }

    if (!empty($data['ELEMENT_ID'])) {
        return (int)$data['ELEMENT_ID'];
    }

    /*
     * Bitrix24 может передавать:
     *
     * document_id:
     * [
     *     "lists",
     *     "Bitrix\\Lists\\BizprocDocumentLists",
     *     "4"
     * ]
     */
    foreach ([
        'document_id',
        'DOCUMENT_ID',
    ] as $key) {

        if (!isset($data[$key])) {
            continue;
        }

        $documentId = $data[$key];

        if (
            is_array($documentId) &&
            !empty($documentId)
        ) {
            $last = end($documentId);

            if (is_numeric($last)) {
                return (int)$last;
            }
        }

        if (is_numeric($documentId)) {
            return (int)$documentId;
        }
    }

    return 0;
}


/**
 * Получение ID задачи из события ONTASKUPDATE.
 */
function getTaskIdFromEvent(array $data): int
{
    $id =
        $data['data']['FIELDS_AFTER']['ID']
        ?? $data['data']['FIELDS_AFTER']['id']
        ?? $data['data']['ID']
        ?? $data['task_id']
        ?? $data['TASK_ID']
        ?? 0;

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
            json_encode(
                $result,
                JSON_UNESCAPED_UNICODE
            )
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

    if (
        !is_array($result) ||
        empty($result)
    ) {
        return null;
    }

    return $result[0] ?? null;
}


/**
 * Получение полей списка.
 */
function getListFields(
    BXConnector $bx,
    int $listId
): array {
    $result = $bx->request(
        'lists.field.get',
        [
            'IBLOCK_TYPE_ID' => 'lists',
            'IBLOCK_ID' => $listId,
        ]
    );

    return is_array($result)
        ? $result
        : [];
}


/**
 * Преобразование значения поля в строку.
 */
function normalizeValue(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_scalar($value)) {
        return (string)$value;
    }

    if (is_array($value)) {

        $values = [];

        foreach ($value as $item) {

            if (is_array($item)) {

                $parts = [];

                foreach ($item as $key => $itemValue) {
                    $parts[] =
                        $key . ': ' . (string)$itemValue;
                }

                $values[] =
                    implode(', ', $parts);

            } else {
                $values[] = (string)$item;
            }
        }

        return implode(', ', $values);
    }

    return '';
}


/**
 * Формирование HTML-таблицы.
 *
 * В таблице:
 *
 * Код поля | Значение
 */
function buildTable(
    array $element,
    array $fields
): string {

    $html = '
<table border="1" cellpadding="6" cellspacing="0">
    <tr>
        <th>Код поля</th>
        <th>Значение</th>
    </tr>
';


    foreach ($fields as $fieldId => $field) {

        if (!isset($field['CODE'])) {
            continue;
        }

        $code = (string)$field['CODE'];

        /*
         * Основной вариант.
         */
        $value = $element[$fieldId] ?? '';


        /*
         * Запасной вариант:
         *
         * PROPERTY_106
         */
        if (
            ($value === '' || $value === null) &&
            isset($element['PROPERTY_' . $fieldId])
        ) {
            $value =
                $element['PROPERTY_' . $fieldId];
        }


        $value =
            normalizeValue($value);


        $html .= '<tr>';

        $html .= '<td>' .
            htmlspecialchars(
                $code,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) .
            '</td>';

        $html .= '<td>' .
            nl2br(
                htmlspecialchars(
                    $value,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                )
            ) .
            '</td>';

        $html .= '</tr>';
    }


    $html .= '</table>';

    return $html;
}


/**
 * Поиск файловых полей.
 *
 * Пока только определяем их ID.
 *
 * Реальное прикрепление файлов сделаем
 * отдельным этапом через Битрикс24 Диск.
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


        /*
         * F = файл.
         */
        if (($field['TYPE'] ?? '') !== 'F') {
            continue;
        }


        $value =
            $element[$fieldId]
            ?? $element['PROPERTY_' . $fieldId]
            ?? null;


        if (
            $value === null ||
            $value === ''
        ) {
            continue;
        }


        $values =
            is_array($value)
                ? $value
                : [$value];


        foreach ($values as $file) {

            if (is_array($file)) {

                foreach ([
                    'ID',
                    'id',
                    'VALUE',
                    'value',
                ] as $key) {

                    if (
                        isset($file[$key]) &&
                        is_numeric($file[$key])
                    ) {
                        $files[] =
                            (int)$file[$key];

                        break;
                    }
                }

            } elseif (is_numeric($file)) {

                $files[] = (int)$file;
            }
        }
    }


    return array_values(
        array_unique($files)
    );
}


/**
 * Проверка существования итоговой задачи.
 */
function finalTaskExists(
    BXConnector $bx,
    string $title
): bool {

    $result = $bx->request(
        'tasks.task.list',
        [
            'filter' => [
                'TITLE' => $title,
            ],
            'select' => [
                'ID',
                'TITLE',
                'STATUS',
            ],
        ]
    );


    return !empty($result['tasks']);
}


/*
|--------------------------------------------------------------------------
| Получаем запрос
|--------------------------------------------------------------------------
*/

$data = getRequestData();


$event =
    $data['event']
    ?? $data['EVENT']
    ?? '';


logMessage(
    'Получен запрос',
    [
        'event' => $event,
        'data' => $data,
    ]
);


/*
|--------------------------------------------------------------------------
| СОЗДАНИЕ ЭЛЕМЕНТА
|--------------------------------------------------------------------------
|
| БП вызывает:
|
| handler.php
| ?event=LIST_ELEMENT_ADD
| &element_id={=Document:ID}
|
|--------------------------------------------------------------------------
*/

if ($event === 'LIST_ELEMENT_ADD') {

    $elementId =
        getElementIdFromRequest($data);


    if (!$elementId) {

        response([
            'success' => false,
            'error' =>
                'Не удалось определить ID элемента списка',
            'request' => $data,
        ], 400);
    }


    /*
     * Получаем элемент.
     */

    $element = getListElement(
        $bx,
        $config['list_id'],
        $elementId
    );


    if (!$element) {

        response([
            'success' => false,
            'error' =>
                'Элемент универсального списка не найден',
            'element_id' => $elementId,
        ], 404);
    }


    $elementName =
        (string)(
            $element['NAME']
            ?? "Элемент #{$elementId}"
        );


    /*
     * Названия двух задач.
     */

    $task1Title =
        "[LIST {$config['list_id']}:{$elementId}] Задача 1";

    $task2Title =
        "[LIST {$config['list_id']}:{$elementId}] Задача 2";


    /*
     * Первая задача.
     *
     * Она пока содержит только ID элемента
     * и специальный маркер.
     */

    $description1 = <<<HTML
<p>Задача создана автоматически.</p>

<p>
<b>Универсальный список:</b> {$config['list_id']}<br>
<b>Элемент:</b> {$elementId}<br>
<b>Название:</b> {$elementName}
</p>

<p>
PAIR_ELEMENT_ID={$elementId}<br>
PAIR_TASK=1
</p>
HTML;


    $task1 = createTask(
        $bx,
        $task1Title,
        $description1,
        $config['responsible_id']
    );


    /*
     * Вторая задача сразу знает ID первой.
     */

    $description2 = <<<HTML
<p>Задача создана автоматически.</p>

<p>
<b>Универсальный список:</b> {$config['list_id']}<br>
<b>Элемент:</b> {$elementId}<br>
<b>Название:</b> {$elementName}
</p>

<p>
PAIR_ELEMENT_ID={$elementId}<br>
PAIR_TASK=2<br>
PAIR_TASK_ID={$task1}
</p>
HTML;


    $task2 = createTask(
        $bx,
        $task2Title,
        $description2,
        $config['responsible_id']
    );


    /*
     * Добавляем ID второй задачи в первую.
     */

    $description1 .=
        "<p>PAIR_TASK_ID={$task2}</p>";


    $updateResult = $bx->request(
        'tasks.task.update',
        [
            'taskId' => $task1,
            'fields' => [
                'DESCRIPTION' =>
                    $description1,
            ],
        ]
    );


    logMessage(
        'Созданы первые две задачи',
        [
            'element_id' => $elementId,
            'task1' => $task1,
            'task2' => $task2,
            'update_task1' => $updateResult,
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
| ИЗМЕНЕНИЕ ЗАДАЧИ
|--------------------------------------------------------------------------
|
| Сюда приходит:
|
| ONTASKUPDATE
|
|--------------------------------------------------------------------------
*/

if ($event === 'ONTASKUPDATE') {

    $taskId =
        getTaskIdFromEvent($data);


    if (!$taskId) {

        response([
            'success' => false,
            'error' => 'ID задачи не найден',
            'request' => $data,
        ], 400);
    }


    /*
     * Получаем актуальную задачу.
     */

    $task = getTask(
        $bx,
        $taskId
    );


    if (!$task) {

        response([
            'success' => false,
            'error' => 'Задача не найдена',
            'task_id' => $taskId,
        ], 404);
    }


    /*
     * Нас интересуют только завершённые задачи.
     *
     * STATUS = 5.
     */

    if (
        (string)(
            $task['status'] ?? ''
        ) !== '5'
    ) {

        response([
            'success' => true,
            'ignored' => true,
            'reason' =>
                'Задача ещё не завершена',
            'task_id' => $taskId,
            'status' =>
                $task['status'] ?? null,
        ]);
    }


    $description =
        (string)(
            $task['description'] ?? ''
        );


    /*
     * Проверяем, что это задача,
     * созданная нашим скриптом.
     */

    if (
        !preg_match(
            '/PAIR_ELEMENT_ID=(\d+)/',
            $description,
            $elementMatch
        )
    ) {

        response([
            'success' => true,
            'ignored' => true,
            'reason' =>
                'Это не задача нашего обработчика',
            'task_id' => $taskId,
        ]);
    }


    $elementId =
        (int)$elementMatch[1];


    /*
     * Из описания берём ID второй задачи.
     */

    if (
        !preg_match(
            '/PAIR_TASK_ID=(\d+)/',
            $description,
            $pairMatch
        )
    ) {

        response([
            'success' => false,
            'error' =>
                'PAIR_TASK_ID не найден',
            'task_id' => $taskId,
        ], 500);
    }


    $pairTaskId =
        (int)$pairMatch[1];


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
            'error' =>
                'Вторая задача не найдена',
            'pair_task_id' =>
                $pairTaskId,
        ], 404);
    }


    /*
     * Если вторая задача ещё не завершена,
     * ждём её.
     */

    if (
        (string)(
            $pairTask['status'] ?? ''
        ) !== '5'
    ) {

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
     */

    $finalTaskTitle =
        "[LIST {$config['list_id']}:{$elementId}] Итоговая задача";


    /*
     * Не создаём третью повторно.
     */

    if (
        finalTaskExists(
            $bx,
            $finalTaskTitle
        )
    ) {

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
            'error' =>
                'Элемент универсального списка не найден',
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


    /*
     * Имя элемента.
     */

    $elementName =
        htmlspecialchars(
            (string)(
                $element['NAME'] ?? ''
            ),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );


    /*
     * Описание итоговой задачи.
     */

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
        $finalTaskTitle,
        $description,
        $config['responsible_id']
    );


    /*
     * Пока только определяем файлы.
     *
     * Прикрепление будет отдельным этапом.
     */

    $fileIds = extractFileIds(
        $element,
        $fields
    );


    logMessage(
        'Создана итоговая задача',
        [
            'element_id' => $elementId,
            'task_id' => $task3,
            'file_ids' => $fileIds,
        ]
    );


    response([
        'success' => true,
        'final_task_created' => true,
        'element_id' => $elementId,
        'task_id' => $task3,
        'file_ids' => $fileIds,
        'files_attached' => false,
        'files_message' =>
            'Прикрепление файлов будет настроено отдельным этапом',
    ]);
}


/*
|--------------------------------------------------------------------------
| Неизвестное событие
|--------------------------------------------------------------------------
*/

response([
    'success' => true,
    'ignored' => true,
    'event' => $event,
]);