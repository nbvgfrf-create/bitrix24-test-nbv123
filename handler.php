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

if (empty($config['responsible_id'])) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'BITRIX_RESPONSIBLE_ID is not configured',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}

$bx = new BXConnector($config['bitrix_webhook']);


/**
 * Ответ JSON.
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
function logMessage(
    string $message,
    array $data = []
): void {
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
 * Поддерживает:
 * - JSON body
 * - POST
 * - GET / query string
 */
function getRequestData(): array
{
    $data = [];

    /*
     * JSON.
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
     * GET.
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
function getElementIdFromRequest(
    array $data
): int {

    /*
     * Наш собственный параметр.
     */
    if (!empty($data['element_id'])) {
        return (int)$data['element_id'];
    }

    if (!empty($data['ELEMENT_ID'])) {
        return (int)$data['ELEMENT_ID'];
    }


    /*
     * document_id от Bitrix24:
     *
     * [
     *     "lists",
     *     "Bitrix\\Lists\\BizprocDocumentLists",
     *     "8"
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
 * Получение ID задачи из ONTASKUPDATE.
 */
function getTaskIdFromEvent(
    array $data
): int {

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
 * Извлекаем result из возможного ответа BXConnector.
 */
function unwrapResult(mixed $result): mixed
{
    if (
        is_array($result) &&
        array_key_exists('result', $result)
    ) {
        return $result['result'];
    }

    return $result;
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

    logMessage(
        'Ответ tasks.task.add',
        [
            'title' => $title,
            'response' => $result,
        ]
    );


    /*
     * Возможный формат:
     *
     * [
     *     'task' => [
     *         'id' => 123
     *     ]
     * ]
     */
    if (
        isset($result['task']['id']) &&
        is_numeric($result['task']['id'])
    ) {
        return (int)$result['task']['id'];
    }


    /*
     * Возможный формат:
     *
     * [
     *     'result' => [
     *         'task' => [
     *             'id' => 123
     *         ]
     *     ]
     * ]
     */
    $unwrapped = unwrapResult($result);

    if (
        is_array($unwrapped) &&
        isset($unwrapped['task']['id']) &&
        is_numeric($unwrapped['task']['id'])
    ) {
        return (int)$unwrapped['task']['id'];
    }


    throw new RuntimeException(
        'Не удалось создать задачу: ' .
        json_encode(
            $result,
            JSON_UNESCAPED_UNICODE
        )
    );
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


    if (
        isset($result['task']) &&
        is_array($result['task'])
    ) {
        return $result['task'];
    }


    $unwrapped = unwrapResult($result);

    if (
        is_array($unwrapped) &&
        isset($unwrapped['task']) &&
        is_array($unwrapped['task'])
    ) {
        return $unwrapped['task'];
    }


    return null;
}


/**
 * Получение элемента списка.
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


    logMessage(
        'Ответ lists.element.get',
        [
            'list_id' => $listId,
            'element_id' => $elementId,
            'response' => $result,
        ]
    );


    /*
     * Вариант:
     *
     * [
     *     0 => [...]
     * ]
     */
    if (
        is_array($result) &&
        isset($result[0]) &&
        is_array($result[0])
    ) {
        return $result[0];
    }


    /*
     * Вариант:
     *
     * [
     *     'result' => [
     *         0 => [...]
     *     ]
     * ]
     */
    $unwrapped = unwrapResult($result);

    if (
        is_array($unwrapped) &&
        isset($unwrapped[0]) &&
        is_array($unwrapped[0])
    ) {
        return $unwrapped[0];
    }


    /*
     * На некоторых обёртках результат может
     * оказаться самим элементом.
     */
    if (
        is_array($unwrapped) &&
        (
            isset($unwrapped['ID']) ||
            isset($unwrapped['id']) ||
            isset($unwrapped['NAME'])
        )
    ) {
        return $unwrapped;
    }


    return null;
}


/**
 * Получение элемента с несколькими попытками.
 *
 * Это защищает от ситуации, когда БП вызвал
 * обработчик сразу в момент создания элемента.
 */
function getListElementWithRetry(
    BXConnector $bx,
    int $listId,
    int $elementId
): ?array {

    $attempts = 5;
    $delayMicroseconds = 700000;


    for ($attempt = 1; $attempt <= $attempts; $attempt++) {

        $element = getListElement(
            $bx,
            $listId,
            $elementId
        );


        if ($element !== null) {
            return $element;
        }


        if ($attempt < $attempts) {
            usleep($delayMicroseconds);
        }
    }


    return null;
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


    $unwrapped = unwrapResult($result);

    return is_array($unwrapped)
        ? $unwrapped
        : [];
}


/**
 * Преобразование значения поля в строку.
 */
function normalizeValue(
    mixed $value
): string {

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
                        $key .
                        ': ' .
                        (string)$itemValue;
                }

                $values[] =
                    implode(', ', $parts);

            } else {
                $values[] =
                    (string)$item;
            }
        }


        return implode(
            ', ',
            $values
        );
    }


    return '';
}


/**
 * Создание HTML-таблицы.
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

        if (!is_array($field)) {
            continue;
        }


        if (!isset($field['CODE'])) {
            continue;
        }


        $code =
            (string)$field['CODE'];


        /*
         * Основной формат элемента:
         *
         * PROPERTY_106
         */
        $propertyKey =
            'PROPERTY_' . $fieldId;


        $value =
            $element[$propertyKey]
            ?? $element[$fieldId]
            ?? '';


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
 * Получение файловых ID.
 *
 * Пока только собираем ID.
 * Прикрепление будет отдельным этапом.
 */
function extractFileIds(
    array $element,
    array $fields
): array {

    $files = [];


    foreach ($fields as $fieldId => $field) {

        if (!is_array($field)) {
            continue;
        }


        if (
            ($field['TYPE'] ?? '') !== 'F'
        ) {
            continue;
        }


        $value =
            $element['PROPERTY_' . $fieldId]
            ?? $element[$fieldId]
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

                $files[] =
                    (int)$file;
            }
        }
    }


    return array_values(
        array_unique($files)
    );
}


/**
 * Проверяем наличие итоговой задачи.
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


    $unwrapped =
        unwrapResult($result);


    if (
        is_array($unwrapped) &&
        !empty($unwrapped['tasks'])
    ) {
        return true;
    }


    if (
        is_array($result) &&
        !empty($result['tasks'])
    ) {
        return true;
    }


    return false;
}


/**
 * Прикрепление файла Диска.
 *
 * Пока не используется:
 * для обычного поля "Файл" сначала
 * потребуется получить/перенести файл
 * на Диск.
 */
function attachFileToTask(
    BXConnector $bx,
    int $taskId,
    int $fileId
): array {

    return $bx->request(
        'tasks.task.files.attach',
        [
            'taskId' => $taskId,
            'fileId' => $fileId,
        ]
    );
}


/*
|--------------------------------------------------------------------------
| Входящий запрос
|--------------------------------------------------------------------------
*/

$data =
    getRequestData();


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
| 1. СОЗДАНИЕ ЭЛЕМЕНТА
|--------------------------------------------------------------------------
|
| БП:
|
| ?event=LIST_ELEMENT_ADD
| &element_id={=Document:ID}
|
|--------------------------------------------------------------------------
*/

if (
    $event === 'LIST_ELEMENT_ADD'
) {

    $elementId =
        getElementIdFromRequest($data);


    if (!$elementId) {

        response([
            'success' => false,
            'error' =>
                'Не удалось определить ID элемента',
            'request' => $data,
        ], 400);
    }


    /*
     * ВАЖНО:
     *
     * Здесь мы БОЛЬШЕ НЕ вызываем
     * lists.element.get.
     *
     * Элемент только что создаётся,
     * поэтому REST может ещё не видеть его.
     *
     * Для создания задач нам нужен
     * только elementId.
     */


    $task1Title =
        "[LIST {$config['list_id']}:{$elementId}] Задача 1";


    $task2Title =
        "[LIST {$config['list_id']}:{$elementId}] Задача 2";


    /*
     * Первая задача.
     */

    $description1 = <<<HTML
<p>Задача создана автоматически.</p>

<p>
<b>Универсальный список:</b> {$config['list_id']}<br>
<b>Элемент:</b> {$elementId}
</p>

<p>
PAIR_ELEMENT_ID={$elementId}<br>
PAIR_TASK=1
</p>
HTML;


    try {

        $task1 =
            createTask(
                $bx,
                $task1Title,
                $description1,
                $config['responsible_id']
            );


        /*
         * Вторая задача.
         *
         * Сразу знаем ID первой.
         */

        $description2 = <<<HTML
<p>Задача создана автоматически.</p>

<p>
<b>Универсальный список:</b> {$config['list_id']}<br>
<b>Элемент:</b> {$elementId}
</p>

<p>
PAIR_ELEMENT_ID={$elementId}<br>
PAIR_TASK=2<br>
PAIR_TASK_ID={$task1}
</p>
HTML;


        $task2 =
            createTask(
                $bx,
                $task2Title,
                $description2,
                $config['responsible_id']
            );


        /*
         * Добавляем ID второй задачи
         * в описание первой.
         */

        $description1 .=
            "<p>PAIR_TASK_ID={$task2}</p>";


        $updateResult =
            $bx->request(
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
            'Созданы две задачи',
            [
                'element_id' => $elementId,
                'task1' => $task1,
                'task2' => $task2,
                'update_task1' =>
                    $updateResult,
                'responsible_id' =>
                    $config['responsible_id'],
            ]
        );


        response([
            'success' => true,
            'event' =>
                'LIST_ELEMENT_ADD',
            'element_id' =>
                $elementId,
            'responsible_id' =>
                $config['responsible_id'],
            'tasks' => [
                'task1' => $task1,
                'task2' => $task2,
            ],
        ]);


    } catch (Throwable $e) {

        logMessage(
            'Ошибка создания задач',
            [
                'element_id' =>
                    $elementId,
                'error' =>
                    $e->getMessage(),
            ]
        );


        response([
            'success' => false,
            'event' =>
                'LIST_ELEMENT_ADD',
            'element_id' =>
                $elementId,
            'error' =>
                $e->getMessage(),
            'responsible_id' =>
                $config['responsible_id'],
        ], 500);
    }
}


/*
|--------------------------------------------------------------------------
| 2. ИЗМЕНЕНИЕ ЗАДАЧИ
|--------------------------------------------------------------------------
|
| Сюда приходит ONTASKUPDATE.
|--------------------------------------------------------------------------
*/

if (
    $event === 'ONTASKUPDATE'
) {

    $taskId =
        getTaskIdFromEvent($data);


    if (!$taskId) {

        response([
            'success' => false,
            'error' =>
                'ID задачи не найден',
            'request' => $data,
        ], 400);
    }


    /*
     * Получаем задачу.
     */

    $task =
        getTask(
            $bx,
            $taskId
        );


    if (!$task) {

        response([
            'success' => false,
            'error' =>
                'Задача не найдена',
            'task_id' =>
                $taskId,
        ], 404);
    }


    /*
     * Нас интересует только завершение.
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
                'Задача не завершена',
            'task_id' =>
                $taskId,
            'status' =>
                $task['status'] ?? null,
        ]);
    }


    $description =
        (string)(
            $task['description'] ?? ''
        );


    /*
     * Проверяем, что задача наша.
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
            'task_id' =>
                $taskId,
        ]);
    }


    $elementId =
        (int)$elementMatch[1];


    /*
     * Получаем ID второй задачи.
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
            'task_id' =>
                $taskId,
        ], 500);
    }


    $pairTaskId =
        (int)$pairMatch[1];


    /*
     * Получаем вторую задачу.
     */

    $pairTask =
        getTask(
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
     * Вторая задача ещё не завершена.
     */

    if (
        (string)(
            $pairTask['status'] ?? ''
        ) !== '5'
    ) {

        response([
            'success' => true,
            'waiting' => true,
            'element_id' =>
                $elementId,
            'completed_task' =>
                $taskId,
            'waiting_task' =>
                $pairTaskId,
        ]);
    }


    /*
     * ОБЕ ЗАДАЧИ ЗАВЕРШЕНЫ.
     */

    $finalTaskTitle =
        "[LIST {$config['list_id']}:{$elementId}] Итоговая задача";


    /*
     * Не создаём повторно.
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
            'element_id' =>
                $elementId,
        ]);
    }


    /*
     * Теперь элемент уже должен существовать.
     *
     * Всё равно используем retry.
     */

    $element =
        getListElementWithRetry(
            $bx,
            $config['list_id'],
            $elementId
        );


    if (!$element) {

        response([
            'success' => false,
            'error' =>
                'Элемент списка не найден после нескольких попыток',
            'element_id' =>
                $elementId,
        ], 404);
    }


    /*
     * Получаем поля.
     */

    $fields =
        getListFields(
            $bx,
            $config['list_id']
        );


    /*
     * Формируем таблицу.
     */

    $table =
        buildTable(
            $element,
            $fields
        );


    $elementName =
        htmlspecialchars(
            (string)(
                $element['NAME'] ?? ''
            ),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );


    /*
     * Описание третьей задачи.
     */

    $finalDescription = <<<HTML
<h3>Данные элемента универсального списка</h3>

<p>
<b>Список:</b> {$config['list_id']}<br>
<b>Элемент:</b> {$elementId}<br>
<b>Название:</b> {$elementName}
</p>

{$table}
HTML;


    try {

        $task3 =
            createTask(
                $bx,
                $finalTaskTitle,
                $finalDescription,
                $config['responsible_id']
            );


    } catch (Throwable $e) {

        logMessage(
            'Ошибка создания итоговой задачи',
            [
                'element_id' =>
                    $elementId,
                'error' =>
                    $e->getMessage(),
            ]
        );


        response([
            'success' => false,
            'error' =>
                $e->getMessage(),
            'element_id' =>
                $elementId,
        ], 500);
    }


    /*
     * Получаем ID файлов.
     *
     * Пока не прикрепляем.
     */

    $fileIds =
        extractFileIds(
            $element,
            $fields
        );


    logMessage(
        'Создана итоговая задача',
        [
            'element_id' =>
                $elementId,
            'task_id' =>
                $task3,
            'file_ids' =>
                $fileIds,
        ]
    );


    response([
        'success' => true,
        'final_task_created' => true,
        'element_id' =>
            $elementId,
        'task_id' =>
            $task3,
        'file_ids' =>
            $fileIds,
        'files_attached' =>
            false,
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