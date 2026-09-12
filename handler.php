<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

/*
 * ============================================================
 * CONFIG
 * ============================================================
 */

$webhook = (string)($config['bitrix_webhook'] ?? '');

$listId = (int)($config['list_id'] ?? 0);

if ($listId <= 0) {
    $listId = (int)(getenv('BITRIX_LIST_ID') ?: 28);
}

$responsibleId = (int)($config['responsible_id'] ?? 0);

if ($webhook === '') {
    respond([
        'success' => false,
        'error' => 'BITRIX_WEBHOOK is not configured',
    ], 500);
}

/*
 * ============================================================
 * CONSTANTS
 * ============================================================
 */

const LIST_TYPE_ID = 'lists';
const COMPLETED_TASK_STATUS = 5;
const FILE_MAX_SIZE = 20 * 1024 * 1024;

const TASK_1_TITLE = 'Задача 1';
const TASK_2_TITLE = 'Задача 2';
const FINAL_TASK_TITLE = 'Итоговая задача';

/*
 * ============================================================
 * MAIN
 * ============================================================
 */

try {
    $request = getRequestData();

    $event = detectEvent($request);

    logInfo('Incoming event', [
        'event' => $event,
    ]);

    if ($event === 'LIST_ELEMENT_ADD') {
        handleListElementAdd(
            $request,
            $listId,
            $responsibleId,
            $webhook
        );
    } elseif ($event === 'ONTASKUPDATE') {
        handleTaskUpdate(
            $request,
            $listId,
            $webhook
        );
    } else {
        logInfo('Event ignored', [
            'event' => $event,
        ]);
    }

    respond([
        'success' => true,
    ]);
} catch (Throwable $e) {
    logError('Unhandled exception', [
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);

    respond([
        'success' => false,
        'error' => $e->getMessage(),
    ], 500);
}

/*
 * ============================================================
 * EVENT: LIST_ELEMENT_ADD
 * ============================================================
 */

function handleListElementAdd(
    array $request,
    int $listId,
    int $configuredResponsibleId,
    string $webhook
): void {
    $elementId = extractElementId($request);

    /*
     * Если Bitrix24 прислал element_id=0 или вообще
     * не прислал ID, пытаемся определить последний элемент.
     */
    if ($elementId <= 0) {
        logInfo('Element ID is zero or missing, finding last list element', [
            'list_id' => $listId,
        ]);

        $elementId = getLastListElementId(
            $webhook,
            $listId
        );

        if ($elementId <= 0) {
            throw new RuntimeException(
                'Could not determine last list element ID'
            );
        }

        logInfo('Last list element selected', [
            'list_id' => $listId,
            'element_id' => $elementId,
        ]);
    }

    $responsibleId = $configuredResponsibleId;

    if ($responsibleId <= 0) {
        $responsibleId = getCurrentUserId($webhook);
    }

    if ($responsibleId <= 0) {
        throw new RuntimeException(
            'Responsible user ID could not be determined'
        );
    }

    logInfo('Processing new list element', [
        'list_id' => $listId,
        'element_id' => $elementId,
        'responsible_id' => $responsibleId,
    ]);

    /*
     * Создаём первую задачу.
     */
    $task1 = createTask(
        $webhook,
        buildInitialTaskDescription(
            $elementId,
            1,
            null
        ),
        '[' . $listId . ':' . $elementId . '] ' . TASK_1_TITLE,
        $responsibleId
    );

    $task1Id = extractTaskId($task1);

    if ($task1Id <= 0) {
        throw new RuntimeException(
            'Bitrix24 did not return ID for task 1'
        );
    }

    /*
     * Создаём вторую задачу.
     */
    $task2 = createTask(
        $webhook,
        buildInitialTaskDescription(
            $elementId,
            2,
            $task1Id
        ),
        '[' . $listId . ':' . $elementId . '] ' . TASK_2_TITLE,
        $responsibleId
    );

    $task2Id = extractTaskId($task2);

    if ($task2Id <= 0) {
        throw new RuntimeException(
            'Bitrix24 did not return ID for task 2'
        );
    }

    /*
     * Теперь дописываем ID второй задачи в первую.
     */
    updateTaskDescription(
        $webhook,
        $task1Id,
        buildInitialTaskDescription(
            $elementId,
            1,
            $task2Id
        )
    );

    logInfo('Two tasks created', [
        'element_id' => $elementId,
        'task1_id' => $task1Id,
        'task2_id' => $task2Id,
    ]);
}

/*
 * ============================================================
 * FIND LAST LIST ELEMENT
 * ============================================================
 */

function getLastListElementId(
    string $webhook,
    int $listId
): int {
    /*
     * Получаем элементы списка.
     *
     * Сортируем по ID по убыванию и берём первый.
     *
     * В зависимости от версии REST API Bitrix24
     * ответ может содержать элементы непосредственно
     * в result либо внутри result.items.
     */
    $result = bitrixRequest(
        $webhook,
        'lists.element.get',
        [
            'IBLOCK_TYPE_ID' => LIST_TYPE_ID,
            'IBLOCK_ID' => $listId,
        ]
    );

    $items = $result['result'] ?? [];

    if (
        is_array($items) &&
        isset($items['items']) &&
        is_array($items['items'])
    ) {
        $items = $items['items'];
    }

    if (!is_array($items) || $items === []) {
        logError('No list elements returned while finding last element', [
            'list_id' => $listId,
        ]);

        return 0;
    }

    $maxId = 0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $id = (int)($item['ID'] ?? 0);

        if ($id > $maxId) {
            $maxId = $id;
        }
    }

    return $maxId;
}

/*
 * ============================================================
 * EVENT: ONTASKUPDATE
 * ============================================================
 */

function handleTaskUpdate(
    array $request,
    int $listId,
    string $webhook
): void {
    $taskId = extractTaskIdFromEvent($request);

    if ($taskId <= 0) {
        logInfo('ONTASKUPDATE ignored: task ID not found');
        return;
    }

    $task = getTask(
        $webhook,
        $taskId
    );

    if ($task === null) {
        logError('Task could not be loaded', [
            'task_id' => $taskId,
        ]);
        return;
    }

    $status = extractTaskStatus($task);
    $description = extractTaskDescription($task);

    logInfo('Task updated', [
        'task_id' => $taskId,
        'status' => $status,
    ]);

    /*
     * Нас интересует только статус "Завершена".
     */
    if ($status !== COMPLETED_TASK_STATUS) {
        return;
    }

    /*
     * Получаем технические данные из описания задачи.
     */
    $meta = parseTaskMetadata($description);

    if (
        $meta['element_id'] <= 0 ||
        $meta['pair_task_id'] <= 0
    ) {
        logInfo(
            'Completed task is not one of the automation tasks',
            [
                'task_id' => $taskId,
            ]
        );

        return;
    }

    $elementId = $meta['element_id'];
    $pairTaskId = $meta['pair_task_id'];

    /*
     * Получаем вторую задачу.
     */
    $pairTask = getTask(
        $webhook,
        $pairTaskId
    );

    if ($pairTask === null) {
        logError('Pair task could not be loaded', [
            'task_id' => $taskId,
            'pair_task_id' => $pairTaskId,
        ]);

        return;
    }

    $pairStatus = extractTaskStatus($pairTask);

    logInfo('Pair task status checked', [
        'task_id' => $taskId,
        'pair_task_id' => $pairTaskId,
        'pair_status' => $pairStatus,
    ]);

    /*
     * Пока вторая задача не завершена,
     * третью не создаём.
     */
    if ($pairStatus !== COMPLETED_TASK_STATUS) {
        return;
    }

    logInfo('Both tasks are completed', [
        'element_id' => $elementId,
        'task_id' => $taskId,
        'pair_task_id' => $pairTaskId,
    ]);

    /*
     * Проверяем, не существует ли уже итоговая задача.
     */
    $finalTitle =
        '[' . $listId . ':' . $elementId . '] ' .
        FINAL_TASK_TITLE;

    if (
        finalTaskAlreadyExists(
            $webhook,
            $finalTitle
        )
    ) {
        logInfo('Final task already exists', [
            'element_id' => $elementId,
            'title' => $finalTitle,
        ]);

        return;
    }

    /*
     * Получаем элемент списка.
     */
    $element = getListElement(
        $webhook,
        $listId,
        $elementId
    );

    if ($element === null) {
        throw new RuntimeException(
            'List element could not be loaded: ID ' .
            $elementId
        );
    }

    /*
     * Получаем поля списка.
     */
    $fields = getListFields(
        $webhook,
        $listId
    );

    /*
     * Формируем текст третьей задачи.
     */
    $finalDescription = buildFinalDescription(
        $element,
        $fields,
        $listId,
        $elementId
    );

    /*
     * Ответственный тот же, что у исходной задачи.
     */
    $finalResponsibleId = extractResponsibleId($task);

    if ($finalResponsibleId <= 0) {
        $finalResponsibleId = getCurrentUserId($webhook);
    }

    if ($finalResponsibleId <= 0) {
        throw new RuntimeException(
            'Responsible user ID could not be determined ' .
            'for final task'
        );
    }

    /*
     * Создаём третью задачу.
     */
    $finalTask = createTask(
        $webhook,
        $finalDescription,
        $finalTitle,
        $finalResponsibleId
    );

    $finalTaskId = extractTaskId($finalTask);

    if ($finalTaskId <= 0) {
        throw new RuntimeException(
            'Bitrix24 did not return ID for final task'
        );
    }

    logInfo('Final task created', [
        'element_id' => $elementId,
        'final_task_id' => $finalTaskId,
    ]);

    /*
     * Пытаемся добавить файлы.
     */
    $attachedFiles = attachElementFilesToTask(
        $webhook,
        $listId,
        $elementId,
        $fields,
        $finalTaskId
    );

    logInfo('Final task processing completed', [
        'element_id' => $elementId,
        'final_task_id' => $finalTaskId,
        'attached_files' => $attachedFiles,
    ]);
}

/*
 * ============================================================
 * BITRIX REST
 * ============================================================
 */

function bitrixRequest(
    string $webhook,
    string $method,
    array $params = []
): array {
    $url =
        rtrim($webhook, '/') .
        '/' .
        $method .
        '.json';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException(
            'Bitrix24 REST transport error: ' .
            $curlError
        );
    }

    $data = json_decode(
        $response,
        true
    );

    if (!is_array($data)) {
        throw new RuntimeException(
            'Bitrix24 returned invalid JSON ' .
            '(HTTP ' . $httpCode . ')'
        );
    }

    if (isset($data['error'])) {
        $error = (string)$data['error'];

        $description = (string)(
            $data['error_description'] ?? ''
        );

        throw new RuntimeException(
            'Bitrix24 API error: ' .
            $error .
            (
                $description !== ''
                    ? ' - ' . $description
                    : ''
            )
        );
    }

    return $data;
}

function createTask(
    string $webhook,
    string $description,
    string $title,
    int $responsibleId
): array {
    $result = bitrixRequest(
        $webhook,
        'tasks.task.add',
        [
            'fields' => [
                'TITLE' => $title,
                'DESCRIPTION' => $description,
                'RESPONSIBLE_ID' => $responsibleId,
            ],
        ]
    );

    logInfo('Task created', [
        'title' => $title,
        'responsible_id' => $responsibleId,
    ]);

    return $result;
}

function updateTaskDescription(
    string $webhook,
    int $taskId,
    string $description
): void {
    bitrixRequest(
        $webhook,
        'tasks.task.update',
        [
            'taskId' => $taskId,
            'fields' => [
                'DESCRIPTION' => $description,
            ],
        ]
    );

    logInfo('Task description updated', [
        'task_id' => $taskId,
    ]);
}

function getTask(
    string $webhook,
    int $taskId
): ?array {
    $result = bitrixRequest(
        $webhook,
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

    return $result['result']['task'] ?? null;
}

function getCurrentUserId(
    string $webhook
): int {
    $result = bitrixRequest(
        $webhook,
        'user.current'
    );

    return (int)(
        $result['result']['ID'] ?? 0
    );
}

function getListElement(
    string $webhook,
    int $listId,
    int $elementId
): ?array {
    $result = bitrixRequest(
        $webhook,
        'lists.element.get',
        [
            'IBLOCK_TYPE_ID' => LIST_TYPE_ID,
            'IBLOCK_ID' => $listId,
            'ELEMENT_ID' => $elementId,
        ]
    );

    $items = $result['result'] ?? [];

    if (
        isset($items[0]) &&
        is_array($items[0])
    ) {
        return $items[0];
    }

    if (
        is_array($items) &&
        isset($items['ID'])
    ) {
        return $items;
    }

    return null;
}

function getListFields(
    string $webhook,
    int $listId
): array {
    $result = bitrixRequest(
        $webhook,
        'lists.field.get',
        [
            'IBLOCK_TYPE_ID' => LIST_TYPE_ID,
            'IBLOCK_ID' => $listId,
        ]
    );

    return is_array(
        $result['result'] ?? null
    )
        ? $result['result']
        : [];
}

/*
 * ============================================================
 * FILES
 * ============================================================
 */

function attachElementFilesToTask(
    string $webhook,
    int $listId,
    int $elementId,
    array $fields,
    int $taskId
): int {
    $attached = 0;

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $code = (string)(
            $field['CODE'] ?? ''
        );

        if ($code === '') {
            continue;
        }

        if (!str_starts_with(
            $code,
            'FAYL_'
        )) {
            continue;
        }

        $fieldIdRaw =
            $field['ID'] ??
            $field['FIELD_ID'] ??
            null;

        if ($fieldIdRaw === null) {
            continue;
        }

        $fieldId = (int)$fieldIdRaw;

        if ($fieldId <= 0) {
            continue;
        }

        try {
            $urls = getListFileUrls(
                $webhook,
                $listId,
                $elementId,
                $fieldId
            );

            foreach ($urls as $url) {
                if (
                    !is_string($url) ||
                    trim($url) === ''
                ) {
                    continue;
                }

                $downloaded = downloadFile($url);

                if ($downloaded === null) {
                    logError('File download failed', [
                        'field_code' => $code,
                        'url' => $url,
                    ]);

                    continue;
                }

                $driveFileId =
                    uploadFileToDrive(
                        $webhook,
                        $downloaded['name'],
                        $downloaded['content']
                    );

                if ($driveFileId <= 0) {
                    logError(
                        'Drive upload returned invalid file ID',
                        [
                            'field_code' => $code,
                            'file_name' => $downloaded['name'],
                        ]
                    );

                    continue;
                }

                attachDriveFileToTask(
                    $webhook,
                    $taskId,
                    $driveFileId
                );

                $attached++;

                logInfo(
                    'File attached to final task',
                    [
                        'field_code' => $code,
                        'file_name' =>
                            $downloaded['name'],
                        'drive_file_id' =>
                            $driveFileId,
                        'task_id' => $taskId,
                    ]
                );
            }
        } catch (Throwable $e) {
            logError(
                'File processing failed',
                [
                    'field_code' => $code,
                    'message' => $e->getMessage(),
                ]
            );
        }
    }

    return $attached;
}

function getListFileUrls(
    string $webhook,
    int $listId,
    int $elementId,
    int $fieldId
): array {
    $result = bitrixRequest(
        $webhook,
        'lists.element.get.file.url',
        [
            'IBLOCK_TYPE_ID' => LIST_TYPE_ID,
            'IBLOCK_ID' => $listId,
            'ELEMENT_ID' => $elementId,
            'FIELD_ID' => $fieldId,
        ]
    );

    $urls = $result['result'] ?? [];

    if (is_string($urls)) {
        return [$urls];
    }

    if (!is_array($urls)) {
        return [];
    }

    return array_values(
        array_filter(
            $urls,
            static function ($url): bool {
                return is_string($url) &&
                    trim($url) !== '';
            }
        )
    );
}

function downloadFile(
    string $url
): ?array {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADER => true,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);

        curl_close($ch);

        logError('HTTP file download error', [
            'message' => $error,
        ]);

        return null;
    }

    $headerSize = (int)curl_getinfo(
        $ch,
        CURLINFO_HEADER_SIZE
    );

    $contentType = (string)curl_getinfo(
        $ch,
        CURLINFO_CONTENT_TYPE
    );

    $headers = substr(
        $response,
        0,
        $headerSize
    );

    $content = substr(
        $response,
        $headerSize
    );

    curl_close($ch);

    if (
        $content === '' ||
        $content === false
    ) {
        return null;
    }

    if (
        strlen($content) > FILE_MAX_SIZE
    ) {
        logError(
            'File skipped because it is too large',
            [
                'size' => strlen($content),
                'max_size' => FILE_MAX_SIZE,
            ]
        );

        return null;
    }

    $name = extractFilenameFromHeaders(
        $headers
    );

    if ($name === '') {
        $name =
            'list_file_' .
            substr(
                sha1($url),
                0,
                10
            );

        $extension =
            extensionFromContentType(
                $contentType
            );

        if ($extension !== '') {
            $name .= '.' . $extension;
        }
    }

    return [
        'name' => sanitizeFilename($name),
        'content' => $content,
    ];
}

function uploadFileToDrive(
    string $webhook,
    string $filename,
    string $content
): int {
    $storageId =
        findCurrentUserStorageId(
            $webhook
        );

    if ($storageId <= 0) {
        throw new RuntimeException(
            'User Drive storage could not be found'
        );
    }

    $result = bitrixRequest(
        $webhook,
        'disk.storage.uploadFile',
        [
            'id' => $storageId,
            'data' => [
                'NAME' => $filename,
            ],
            'fileContent' => [
                $filename,
                base64_encode($content),
            ],
            'generateUniqueName' => true,
        ]
    );

    return (int)(
        $result['result']['ID'] ?? 0
    );
}

function attachDriveFileToTask(
    string $webhook,
    int $taskId,
    int $driveFileId
): void {
    bitrixRequest(
        $webhook,
        'tasks.task.files.attach',
        [
            'taskId' => $taskId,
            'fileId' => $driveFileId,
        ]
    );
}

function findCurrentUserStorageId(
    string $webhook
): int {
    $currentUserId =
        getCurrentUserId($webhook);

    if ($currentUserId <= 0) {
        return 0;
    }

    $result = bitrixRequest(
        $webhook,
        'disk.storage.getList'
    );

    $storages = $result['result'] ?? [];

    if (!is_array($storages)) {
        return 0;
    }

    foreach ($storages as $storage) {
        if (!is_array($storage)) {
            continue;
        }

        if (
            ($storage['ENTITY_TYPE'] ?? '') === 'user' &&
            (int)(
                $storage['ENTITY_ID'] ?? 0
            ) === $currentUserId
        ) {
            return (int)(
                $storage['ID'] ?? 0
            );
        }
    }

    return 0;
}

/*
 * ============================================================
 * FINAL TASK DESCRIPTION
 * ============================================================
 */

function buildFinalDescription(
    array $element,
    array $fields,
    int $listId,
    int $elementId
): string {
    $lines = [];

    $lines[] =
        'Итог по элементу универсального списка';

    $lines[] = '';

    $lines[] =
        'Универсальный список: ' .
        $listId;

    $lines[] =
        'Элемент: ' .
        $elementId;

    $lines[] = '';

    $lines[] = 'Поля:';

    $lines[] =
        '----------------------------------------';

    $lines[] =
        'Код поля | Значение';

    $lines[] =
        '----------------------------------------';

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $fieldCode = (string)(
            $field['CODE'] ?? ''
        );

        if ($fieldCode === '') {
            continue;
        }

        $fieldId = (int)(
            $field['ID'] ?? 0
        );

        if ($fieldId <= 0) {
            continue;
        }

        $propertyName =
            'PROPERTY_' . $fieldId;

        $value = '';

        if (
            array_key_exists(
                $propertyName,
                $element
            )
        ) {
            $value = normalizeListValue(
                $element[$propertyName]
            );
        }

        $lines[] =
            $fieldCode .
            ' | ' .
            $value;
    }

    $lines[] =
        '----------------------------------------';

    return implode(
        "\n",
        $lines
    );
}

/*
 * ============================================================
 * VALUE NORMALIZATION
 * ============================================================
 */

function normalizeListValue(
    mixed $value
): string {
    if ($value === null) {
        return '';
    }

    if (is_scalar($value)) {
        return trim((string)$value);
    }

    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $text = trim(
                    (string)$item
                );

                if ($text !== '') {
                    $values[] = $text;
                }
            } elseif (is_array($item)) {
                $nested =
                    normalizeListValue($item);

                if ($nested !== '') {
                    $values[] = $nested;
                }
            }
        }

        return implode(
            '; ',
            array_unique($values)
        );
    }

    return '';
}

/*
 * ============================================================
 * TASK METADATA
 * ============================================================
 */

function buildInitialTaskDescription(
    int $elementId,
    int $taskNumber,
    ?int $pairTaskId
): string {
    $lines = [];

    $lines[] =
        'Задача создана автоматически.';

    $lines[] = '';

    $lines[] =
        'Элемент универсального списка: ' .
        $elementId;

    $lines[] =
        'Тип задачи: ' .
        $taskNumber;

    if (
        $pairTaskId !== null &&
        $pairTaskId > 0
    ) {
        $lines[] =
            'Связанная задача: ' .
            $pairTaskId;
    } else {
        $lines[] =
            'Связанная задача: ожидает создания.';
    }

    /*
     * Технические данные для обработчика.
     */
    $lines[] = '';
    $lines[] = '[AUTOMATION]';

    $lines[] =
        'ELEMENT_ID=' .
        $elementId;

    $lines[] =
        'TASK_NUMBER=' .
        $taskNumber;

    if (
        $pairTaskId !== null &&
        $pairTaskId > 0
    ) {
        $lines[] =
            'PAIR_TASK_ID=' .
            $pairTaskId;
    }

    return implode(
        "\n",
        $lines
    );
}

function parseTaskMetadata(
    string $description
): array {
    $elementId = 0;
    $pairTaskId = 0;

    if (
        preg_match(
            '/ELEMENT_ID=(\d+)/',
            $description,
            $matches
        )
    ) {
        $elementId = (int)$matches[1];
    }

    if (
        preg_match(
            '/PAIR_TASK_ID=(\d+)/',
            $description,
            $matches
        )
    ) {
        $pairTaskId = (int)$matches[1];
    }

    return [
        'element_id' => $elementId,
        'pair_task_id' => $pairTaskId,
    ];
}

/*
 * ============================================================
 * TASK HELPERS
 * ============================================================
 */

function extractTaskId(
    array $response
): int {
    return (int)(
        $response['result']['task']['id']
        ?? $response['result']['task']['ID']
        ?? $response['result']['id']
        ?? 0
    );
}

function extractTaskIdFromEvent(
    array $request
): int {
    $values = [
        $request['data']['data']['FIELDS_AFTER']['ID']
            ?? null,

        $request['data']['FIELDS_AFTER']['ID']
            ?? null,

        $request['data']['task']['id']
            ?? null,

        $request['task_id']
            ?? null,

        $request['taskId']
            ?? null,
    ];

    foreach ($values as $value) {
        $id = (int)$value;

        if ($id > 0) {
            return $id;
        }
    }

    return 0;
}

function extractTaskStatus(
    array $task
): int {
    return (int)(
        $task['status']
        ?? $task['STATUS']
        ?? 0
    );
}

function extractTaskDescription(
    array $task
): string {
    $description =
        $task['description']
        ?? $task['DESCRIPTION']
        ?? '';

    return is_string($description)
        ? $description
        : '';
}

function extractResponsibleId(
    array $task
): int {
    return (int)(
        $task['responsibleId']
        ?? $task['responsible_id']
        ?? $task['RESPONSIBLE_ID']
        ?? 0
    );
}

function finalTaskAlreadyExists(
    string $webhook,
    string $title
): bool {
    $result = bitrixRequest(
        $webhook,
        'tasks.task.list',
        [
            'filter' => [
                '=TITLE' => $title,
            ],
            'select' => [
                'ID',
                'TITLE',
            ],
            'start' => 0,
        ]
    );

    $tasks =
        $result['result']['tasks']
        ?? [];

    return is_array($tasks) &&
        count($tasks) > 0;
}

/*
 * ============================================================
 * REQUEST / EVENT
 * ============================================================
 */

function getRequestData(): array
{
    $json =
        file_get_contents('php://input');

    $jsonData = [];

    if (
        is_string($json) &&
        trim($json) !== ''
    ) {
        $decoded =
            json_decode(
                $json,
                true
            );

        if (is_array($decoded)) {
            $jsonData = $decoded;
        }
    }

    $queryData = $_GET;

    if (!empty($_POST)) {
        $jsonData = array_replace_recursive(
            $_POST,
            $jsonData
        );
    }

    return array_replace_recursive(
        $queryData,
        $jsonData
    );
}

function detectEvent(
    array $request
): string {
    if (
        isset($request['event']) &&
        is_string($request['event']) &&
        $request['event'] !== ''
    ) {
        return strtoupper(
            $request['event']
        );
    }

    if (
        isset($request['data']['event']) &&
        is_string(
            $request['data']['event']
        ) &&
        $request['data']['event'] !== ''
    ) {
        return strtoupper(
            $request['data']['event']
        );
    }

    return '';
}

function extractElementId(
    array $request
): int {
    $values = [
        $request['element_id'] ?? null,

        $request['data']['element_id']
            ?? null,

        $request['data']['data']['element_id']
            ?? null,

        $request['data']['document_id'][2]
            ?? null,
    ];

    foreach ($values as $value) {
        $id = (int)$value;

        if ($id > 0) {
            return $id;
        }
    }

    /*
     * Если ID отсутствует или равен 0,
     * вызывающая функция определит последний элемент.
     */
    return 0;
}

/*
 * ============================================================
 * FILE NAME HELPERS
 * ============================================================
 */

function extractFilenameFromHeaders(
    string $headers
): string {
    if (
        preg_match(
            '/filename\*=[^;]*UTF-8\'\'([^;\r\n]+)/i',
            $headers,
            $matches
        )
    ) {
        return urldecode(
            trim(
                $matches[1],
                "\"' "
            )
        );
    }

    if (
        preg_match(
            '/filename="?([^";\r\n]+)"?/i',
            $headers,
            $matches
        )
    ) {
        return trim(
            $matches[1]
        );
    }

    return '';
}

function extensionFromContentType(
    string $contentType
): string {
    $contentType =
        strtolower(
            trim(
                explode(
                    ';',
                    $contentType
                )[0]
            )
        );

    return match ($contentType) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
        'application/json' => 'json',
        default => '',
    };
}

function sanitizeFilename(
    string $filename
): string {
    $filename = trim($filename);

    if ($filename === '') {
        return 'file';
    }

    $filename = preg_replace(
        '/[^\p{L}\p{N}\._\- ()]+/u',
        '_',
        $filename
    );

    return $filename !== ''
        ? $filename
        : 'file';
}

/*
 * ============================================================
 * LOGGING
 * ============================================================
 */

function logInfo(
    string $message,
    array $context = []
): void {
    writeLog(
        'INFO',
        $message,
        $context
    );
}

function logError(
    string $message,
    array $context = []
): void {
    writeLog(
        'ERROR',
        $message,
        $context
    );
}

function writeLog(
    string $level,
    string $message,
    array $context = []
): void {
    $suffix = '';

    if ($context !== []) {
        $json = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($json !== false) {
            $suffix = ' ' . $json;
        }
    }

    /*
     * ASCII-only log messages, чтобы Apache
     * не показывал русские буквы как \xd0\x...
     */
    error_log(
        '[bitrix24-handler] ' .
        $level .
        ': ' .
        $message .
        $suffix
    );
}

/*
 * ============================================================
 * RESPONSE
 * ============================================================
 */

function respond(
    array $data,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    exit;
}