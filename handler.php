<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

use BX\BXConnector;

require_once __DIR__ . '/BXConnector.php';

$config = require __DIR__ . '/config.php';


/*
|--------------------------------------------------------------------------
| Проверка конфигурации
|--------------------------------------------------------------------------
*/

if (empty($config['bitrix_webhook'])) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'BITRIX_WEBHOOK is not configured',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| Bitrix connector
|--------------------------------------------------------------------------
*/

$bx = new BXConnector(
    $config['bitrix_webhook']
);


/*
|--------------------------------------------------------------------------
| Вспомогательные функции
|--------------------------------------------------------------------------
*/


/**
 * JSON-ответ.
 */
function response(
    array $data,
    int $status = 200
): never {

    http_response_code($status);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/**
 * Запись в лог Render.
 */
function logMessage(
    string $message,
    array $data = []
): void {

    $suffix = '';

    if (!empty($data)) {
        $suffix = ' ' . json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    error_log(
        '[bitrix24-handler] ' .
        $message .
        $suffix
    );
}


/**
 * Получение данных входящего запроса.
 *
 * Поддерживаем:
 * - JSON
 * - POST
 * - GET
 */
function getRequestData(): array
{
    $data = [];


    /*
     * JSON body.
     */
    $raw = file_get_contents(
        'php://input'
    );


    if ($raw !== false && $raw !== '') {

        $json = json_decode(
            $raw,
            true
        );


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
     *
     * Например:
     *
     * ?event=LIST_ELEMENT_ADD
     * &element_id=14
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
 * Получение result из ответа REST.
 *
 * BXConnector может вернуть как:
 *
 * [
 *     'result' => ...
 * ]
 *
 * так и уже распакованный результат.
 */
function unwrapResult(
    mixed $result
): mixed {

    if (
        is_array($result) &&
        array_key_exists(
            'result',
            $result
        )
    ) {
        return $result['result'];
    }


    return $result;
}


/**
 * Получение ID элемента.
 */
function getElementIdFromRequest(
    array $data
): int {

    /*
     * Наш параметр из URL.
     */
    if (
        isset($data['element_id']) &&
        is_numeric($data['element_id'])
    ) {
        return (int)$data['element_id'];
    }


    if (
        isset($data['ELEMENT_ID']) &&
        is_numeric($data['ELEMENT_ID'])
    ) {
        return (int)$data['ELEMENT_ID'];
    }


    /*
     * document_id от Bitrix24.
     *
     * Например:
     *
     * [
     *     "lists",
     *     "Bitrix\\Lists\\BizprocDocumentLists",
     *     "14"
     * ]
     */
    foreach ([
        'document_id',
        'DOCUMENT_ID',
    ] as $key) {

        if (!isset($data[$key])) {
            continue;
        }


        $documentId =
            $data[$key];


        if (
            is_array($documentId) &&
            !empty($documentId)
        ) {

            $last = end(
                $documentId
            );


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


/*
|--------------------------------------------------------------------------
| Ответственные
|--------------------------------------------------------------------------
*/


/**
 * Получение ID пользователя,
 * которому назначаются задачи.
 *
 * Приоритет:
 *
 * 1. BITRIX_RESPONSIBLE_ID
 * 2. user.current
 */
function getResponsibleId(
    BXConnector $bx,
    array $config
): int {

    if (
        !empty($config['responsible_id'])
    ) {
        return (int)$config['responsible_id'];
    }


    /*
     * Если переменная окружения
     * не задана, используем текущего
     * пользователя вебхука.
     */
    $result = $bx->request(
        'user.current'
    );


    $unwrapped =
        unwrapResult($result);


    if (
        is_array($unwrapped) &&
        isset($unwrapped['ID']) &&
        is_numeric($unwrapped['ID'])
    ) {

        return (int)$unwrapped['ID'];
    }


    /*
     * В твоём тесте это пользователь ID 1.
     */
    return 1;
}


/*
|--------------------------------------------------------------------------
| Работа с задачами
|--------------------------------------------------------------------------
*/


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
                'TITLE' =>
                    $title,

                'DESCRIPTION' =>
                    $description,

                'RESPONSIBLE_ID' =>
                    $responsibleId,
            ],
        ]
    );


    logMessage(
        'Ответ tasks.task.add',
        [
            'title' =>
                $title,

            'responsible_id' =>
                $responsibleId,

            'response' =>
                $result,
        ]
    );


    /*
     * Вариант:
     *
     * result.task.id
     */
    $unwrapped =
        unwrapResult($result);


    if (
        is_array($unwrapped) &&
        isset($unwrapped['task']['id']) &&
        is_numeric($unwrapped['task']['id'])
    ) {

        return (int)$unwrapped['task']['id'];
    }


    /*
     * Иногда API/обёртка может вернуть
     * уже распакованный task.
     */
    if (
        is_array($result) &&
        isset($result['task']['id']) &&
        is_numeric($result['task']['id'])
    ) {

        return (int)$result['task']['id'];
    }


    throw new RuntimeException(
        'Bitrix24 не вернул ID созданной задачи. Ответ: ' .
        json_encode(
            $result,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
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
            'taskId' =>
                $taskId,

            'select' => [
                'ID',
                'TITLE',
                'DESCRIPTION',
                'STATUS',
                'RESPONSIBLE_ID',
            ],
        ]
    );


    $unwrapped =
        unwrapResult($result);


    if (
        is_array($unwrapped) &&
        isset($unwrapped['task']) &&
        is_array($unwrapped['task'])
    ) {

        return $unwrapped['task'];
    }


    if (
        is_array($result) &&
        isset($result['task']) &&
        is_array($result['task'])
    ) {

        return $result['task'];
    }


    return null;
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
                'TITLE' =>
                    $title,
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
        isset($unwrapped['tasks']) &&
        !empty($unwrapped['tasks'])
    ) {
        return true;
    }


    if (
        is_array($result) &&
        isset($result['tasks']) &&
        !empty($result['tasks'])
    ) {
        return true;
    }


    return false;
}


/*
|--------------------------------------------------------------------------
| Работа со списком
|--------------------------------------------------------------------------
*/


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
            'IBLOCK_TYPE_ID' =>
                'lists',

            'IBLOCK_ID' =>
                $listId,

            'ELEMENT_ID' =>
                $elementId,
        ]
    );


    logMessage(
        'Ответ lists.element.get',
        [
            'element_id' =>
                $elementId,

            'response' =>
                $result,
        ]
    );


    $unwrapped =
        unwrapResult($result);


    /*
     * Обычный ответ:
     *
     * [
     *     0 => [...]
     * ]
     */
    if (
        is_array($unwrapped) &&
        isset($unwrapped[0]) &&
        is_array($unwrapped[0])
    ) {

        return $unwrapped[0];
    }


    /*
     * Запасной вариант:
     * сам объект элемента.
     */
    if (
        is_array($unwrapped) &&
        (
            isset($unwrapped['ID']) ||
            isset($unwrapped['NAME'])
        )
    ) {

        return $unwrapped;
    }


    return null;
}


/**
 * Получение всех полей списка.
 */
function getListFields(
    BXConnector $bx,
    int $listId
): array {

    $result = $bx->request(
        'lists.field.get',
        [
            'IBLOCK_TYPE_ID' =>
                'lists',

            'IBLOCK_ID' =>
                $listId,
        ]
    );


    $unwrapped =
        unwrapResult($result);


    return is_array($unwrapped)
        ? $unwrapped
        : [];
}


/*
|--------------------------------------------------------------------------
| Значения полей
|--------------------------------------------------------------------------
*/


/**
 * Преобразование значения в строку.
 *
 * У тебя списки возвращают, например:
 *
 * PROPERTY_106:
 * {
 *     "2": "Строка 1"
 * }
 *
 * Для множественных:
 *
 * PROPERTY_110:
 * {
 *     "6": "Строка 3 1",
 *     "8": "Строка 3 2",
 *     "10": "Строка 3 3"
 * }
 *
 * Поэтому здесь берём именно значения.
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

                /*
                 * Если вложенный массив,
                 * собираем его значения.
                 */
                $nested = [];


                foreach ($item as $nestedValue) {

                    if (
                        is_scalar($nestedValue) &&
                        $nestedValue !== ''
                    ) {
                        $nested[] =
                            (string)$nestedValue;
                    }
                }


                if (!empty($nested)) {
                    $values[] =
                        implode(', ', $nested);
                }

            } else {

                if (
                    $item !== null &&
                    $item !== ''
                ) {
                    $values[] =
                        (string)$item;
                }
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
 * Получение фактического значения
 * свойства из элемента.
 */
function getElementFieldValue(
    array $element,
    string $fieldKey
): mixed {

    if (
        !array_key_exists(
            $fieldKey,
            $element
        )
    ) {
        return '';
    }


    return $element[$fieldKey];
}


/**
 * Формирование таблицы.
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


    /*
     * NAME — тоже поле списка,
     * но у него нет CODE.
     */
    $html .= '<tr>';

    $html .= '<td>NAME</td>';

    $html .= '<td>' .
        htmlspecialchars(
            normalizeValue(
                $element['NAME'] ?? ''
            ),
            ENT_QUOTES |
            ENT_SUBSTITUTE,
            'UTF-8'
        ) .
        '</td>';

    $html .= '</tr>';


    /*
     * Пользовательские поля.
     */
    foreach ($fields as $fieldKey => $field) {

        if (!is_array($field)) {
            continue;
        }


        if (
            !isset($field['CODE'])
        ) {
            continue;
        }


        $code =
            (string)$field['CODE'];


        /*
         * fieldKey уже имеет вид:
         *
         * PROPERTY_106
         */
        $value =
            getElementFieldValue(
                $element,
                (string)$fieldKey
            );


        $value =
            normalizeValue(
                $value
            );


        $html .= '<tr>';


        $html .= '<td>' .
            htmlspecialchars(
                $code,
                ENT_QUOTES |
                ENT_SUBSTITUTE,
                'UTF-8'
            ) .
            '</td>';


        $html .= '<td>' .
            nl2br(
                htmlspecialchars(
                    $value,
                    ENT_QUOTES |
                    ENT_SUBSTITUTE,
                    'UTF-8'
                )
            ) .
            '</td>';


        $html .= '</tr>';
    }


    $html .= '</table>';


    return $html;
}


/*
|--------------------------------------------------------------------------
| Файлы
|--------------------------------------------------------------------------
*/


/**
 * Получаем ID файлов из элемента списка.
 *
 * В реальном ответе Bitrix24:
 *
 * PROPERTY_118:
 * {
 *     "22": "130"
 * }
 *
 * Где:
 *
 * 22  = ID значения свойства
 * 130 = ID файла.
 */
function extractFileIds(
    array $element,
    array $fields
): array {

    $files = [];


    foreach ($fields as $fieldKey => $field) {

        if (!is_array($field)) {
            continue;
        }


        if (
            ($field['TYPE'] ?? '') !== 'F'
        ) {
            continue;
        }


        if (
            !array_key_exists(
                $fieldKey,
                $element
            )
        ) {
            continue;
        }


        $value =
            $element[$fieldKey];


        if (!is_array($value)) {
            continue;
        }


        foreach ($value as $fileId) {

            if (
                is_numeric($fileId)
            ) {
                $files[] =
                    (int)$fileId;
            }
        }
    }


    return array_values(
        array_unique($files)
    );
}


/*
|--------------------------------------------------------------------------
| Общий exception handler
|--------------------------------------------------------------------------
*/

set_exception_handler(
    function (Throwable $e): void {

        logMessage(
            'Неперехваченное исключение',
            [
                'error' =>
                    $e->getMessage(),

                'file' =>
                    $e->getFile(),

                'line' =>
                    $e->getLine(),
            ]
        );


        response(
            [
                'success' => false,
                'error' =>
                    $e->getMessage(),
            ],
            500
        );
    }
);


/*
|--------------------------------------------------------------------------
| Получаем запрос
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
        'event' =>
            $event,

        'data' =>
            $data,
    ]
);


/*
|--------------------------------------------------------------------------
| 1. СОЗДАНИЕ ЭЛЕМЕНТА
|--------------------------------------------------------------------------
*/

if (
    $event === 'LIST_ELEMENT_ADD'
) {

    $elementId =
        getElementIdFromRequest(
            $data
        );


    if (!$elementId) {

        response(
            [
                'success' => false,

                'error' =>
                    'Не удалось определить ID элемента',

                'request' =>
                    $data,
            ],
            400
        );
    }


    /*
     * Получаем ответственного.
     */
    $responsibleId =
        getResponsibleId(
            $bx,
            $config
        );


    /*
     * Формируем названия задач.
     */
    $task1Title =
        "[LIST {$config['list_id']}:{$elementId}] Задача 1";

    $task2Title =
        "[LIST {$config['list_id']}:{$elementId}] Задача 2";


    /*
     * Описание первой задачи.
     *
     * В нём пока неизвестен ID второй.
     */
    $description1 = <<<HTML
<p>
Задача создана автоматически.
</p>

<p>
<b>Универсальный список:</b> {$config['list_id']}<br>
<b>Элемент:</b> {$elementId}
</p>

<p>
PAIR_ELEMENT_ID={$elementId}<br>
PAIR_TASK=1
</p>
HTML;


    /*
     * Создаём первую задачу.
     */
    $task1 =
        createTask(
            $bx,
            $task1Title,
            $description1,
            $responsibleId
        );


    /*
     * Вторая задача сразу получает ID первой.
     */
    $description2 = <<<HTML
<p>
Задача создана автоматически.
</p>

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


    /*
     * Создаём вторую.
     */
    $task2 =
        createTask(
            $bx,
            $task2Title,
            $description2,
            $responsibleId
        );


    /*
     * Теперь дописываем ID второй задачи
     * в первую.
     */
    $description1 .=
        "<p>PAIR_TASK_ID={$task2}</p>";


    $updateResult =
        $bx->request(
            'tasks.task.update',
            [
                'taskId' =>
                    $task1,

                'fields' => [
                    'DESCRIPTION' =>
                        $description1,
                ],
            ]
        );


    logMessage(
        'Созданы две задачи',
        [
            'element_id' =>
                $elementId,

            'task1' =>
                $task1,

            'task2' =>
                $task2,

            'responsible_id' =>
                $responsibleId,

            'update_task1' =>
                $updateResult,
        ]
    );


    response([
        'success' => true,

        'event' =>
            'LIST_ELEMENT_ADD',

        'element_id' =>
            $elementId,

        'responsible_id' =>
            $responsibleId,

        'tasks' => [
            'task1' =>
                $task1,

            'task2' =>
                $task2,
        ],
    ]);
}


/*
|--------------------------------------------------------------------------
| 2. ИЗМЕНЕНИЕ ЗАДАЧИ
|--------------------------------------------------------------------------
*/

if (
    $event === 'ONTASKUPDATE'
) {

    $taskId =
        getTaskIdFromEvent(
            $data
        );


    if (!$taskId) {

        response(
            [
                'success' => false,

                'error' =>
                    'Не удалось определить ID задачи',

                'request' =>
                    $data,
            ],
            400
        );
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

        response(
            [
                'success' => false,

                'error' =>
                    'Задача не найдена',

                'task_id' =>
                    $taskId,
            ],
            404
        );
    }


    /*
     * Нас интересуют только завершённые задачи.
     *
     * В Bitrix24 STATUS=5 — завершена.
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
     * Проверяем, что задача создана
     * нашим обработчиком.
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

        response(
            [
                'success' => false,

                'error' =>
                    'PAIR_TASK_ID не найден',

                'task_id' =>
                    $taskId,
            ],
            500
        );
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

        response(
            [
                'success' => false,

                'error' =>
                    'Вторая задача не найдена',

                'pair_task_id' =>
                    $pairTaskId,
            ],
            404
        );
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

            'element_id' =>
                $elementId,
        ]);
    }


    /*
     * Получаем элемент.
     *
     * Здесь он уже точно существует.
     */
    $element =
        getListElement(
            $bx,
            $config['list_id'],
            $elementId
        );


    if (!$element) {

        response(
            [
                'success' => false,

                'error' =>
                    'Элемент списка не найден',

                'element_id' =>
                    $elementId,
            ],
            404
        );
    }


    /*
     * Получаем поля списка.
     */
    $fields =
        getListFields(
            $bx,
            $config['list_id']
        );


    /*
     * Строим таблицу.
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
            ENT_QUOTES |
            ENT_SUBSTITUTE,
            'UTF-8'
        );


    /*
     * Формируем описание.
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


    /*
     * Получаем ответственного.
     */
    $responsibleId =
        getResponsibleId(
            $bx,
            $config
        );


    /*
     * Создаём третью задачу.
     */
    $task3 =
        createTask(
            $bx,
            $finalTaskTitle,
            $finalDescription,
            $responsibleId
        );


    /*
     * Получаем ID файлов.
     *
     * Пока только для диагностики.
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

            'responsible_id' =>
                $responsibleId,

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

        'responsible_id' =>
            $responsibleId,

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

    'event' =>
        $event,
]);