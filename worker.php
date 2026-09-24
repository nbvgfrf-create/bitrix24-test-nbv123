<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/BXConnector.php';
require_once __DIR__ . '/JsonStore.php';
require_once __DIR__ . '/ImportHelpers.php';

function worker_out(string $message): void
{
    echo $message . PHP_EOL;
    if (function_exists('flush')) {
        @ob_flush();
        @flush();
    }
}

function queue_stage(array $queues): ?string
{
    foreach (['distributors', 'contacts', 'companies'] as $stage) {
        if (JsonStore::hasPending($queues[$stage])) {
            return $stage;
        }
    }

    foreach ($queues['companies']['items'] as $item) {
        foreach ((array)($item['data']['addresses'] ?? []) as $address) {
            if (($address['status'] ?? 'pending') === 'pending') {
                return 'addresses';
            }
        }
    }

    return null;
}

function normalize_processing_status(array &$queue): void
{
    foreach ($queue['items'] as &$item) {
        if (($item['status'] ?? '') === 'processing') {
            $item['status'] = 'pending';
        }
    }
    unset($item);
}

function company_fields(array $data, array $runtime, array $distributorMap, array $contactMap, bool $includeRelationships = true): array
{
    $fields = [
        'title' => $data['name'],
        'assignedById' => (int)$runtime['responsible_id'],
        'originatorId' => 'xlsx_import_v2',
        'originId' => $data['source_key'],
    ];

    if (!empty($data['type'])) {
        $key = normalize_key($data['type']);
        foreach ($runtime['type_map'] as $name => $statusId) {
            if (normalize_key($name) === $key) {
                $fields['typeId'] = $statusId;
                break;
            }
        }
    }

    if (!empty($data['industry'])) {
        $key = normalize_key($data['industry']);
        foreach ($runtime['industry_map'] as $name => $statusId) {
            if (normalize_key($name) === $key) {
                $fields['industry'] = $statusId;
                break;
            }
        }
    }

    $fields[$runtime['fields']['country']['FIELD_NAME']] = clean_value((string)($data['country'] ?? ''));
    $fields[$runtime['fields']['old_responsible']['FIELD_NAME']] = clean_value((string)($data['old_responsible'] ?? ''));

    $competitorIds = [];
    foreach ((array)($data['competitor_software'] ?? []) as $software) {
        $key = normalize_key((string)$software);
        if (isset($runtime['competitor_option_map'][$key])) {
            $competitorIds[] = (string)$runtime['competitor_option_map'][$key];
        }
    }
    $fields[$runtime['fields']['competitor_software']['FIELD_NAME']] = array_values(array_unique($competitorIds));

    $license = clean_value((string)($data['license_expiration_date'] ?? ''));
    if ($license !== '') {
        $fields[$runtime['fields']['license_expiration']['FIELD_NAME']] = $license;
    }

    if ($includeRelationships) {
        $distributorIds = [];
        foreach ((array)($data['distributors'] ?? []) as $distName) {
            $key = normalize_key((string)$distName);
            if (!isset($distributorMap[$key])) {
                throw new RuntimeException('Не найден ID дистрибьютора: ' . $distName);
            }
            $distributorIds[] = 'CO_' . (int)$distributorMap[$key];
        }
        $fields[$runtime['fields']['distributor']['FIELD_NAME']] = array_values(array_unique($distributorIds));

        $contactIds = [];
        foreach ((array)($data['contact_keys'] ?? []) as $contactKey) {
            if (isset($contactMap[$contactKey])) {
                $contactIds[] = (int)$contactMap[$contactKey];
            }
        }
        if ($contactIds) {
            $fields['contactIds'] = array_values(array_unique($contactIds));
        }
    }

    return $fields;
}

function create_batch_commands(array $indexes, array &$queue, string $stage, array $runtime, array $distributorMap, array $contactMap): array
{
    $commands = [];
    foreach ($indexes as $index) {
        $queue['items'][$index]['status'] = 'processing';
        $queue['items'][$index]['attempts'] = (int)($queue['items'][$index]['attempts'] ?? 0) + 1;
        $queue['items'][$index]['last_error'] = null;

        $data = $queue['items'][$index]['data'];

        if ($stage === 'contacts') {
            $fields = [
                'name' => $data['name'],
                'assignedById' => (int)$runtime['responsible_id'],
                'originatorId' => 'xlsx_import_v2',
                'originId' => $queue['items'][$index]['source_key'],
            ];

            $commands[(string)$index] = [
                'method' => 'crm.item.add',
                'params' => [
                    'entityTypeId' => 3,
                    'fields' => $fields,
                ],
            ];
        } else {
            $isDistributorStage = $stage === 'distributors';

            if (!$isDistributorStage) {
                $existingDistributorId = $distributorMap[normalize_key((string)$data['name'])] ?? 0;
                if ($existingDistributorId > 0) {
                    $fields = company_fields($data, $runtime, $distributorMap, $contactMap, true);
                    $commands[(string)$index] = [
                        'method' => 'crm.item.update',
                        'params' => [
                            'entityTypeId' => 4,
                            'id' => $existingDistributorId,
                            'fields' => $fields,
                            'useOriginalUfNames' => true,
                        ],
                    ];
                    $queue['items'][$index]['reuse_distributor_id'] = $existingDistributorId;
                    continue;
                }
            }

            $fields = company_fields($data, $runtime, $distributorMap, $contactMap, !$isDistributorStage);

            $commands[(string)$index] = [
                'method' => 'crm.item.add',
                'params' => [
                    'entityTypeId' => 4,
                    'fields' => $fields,
                    'useOriginalUfNames' => true,
                ],
            ];
        }
    }
    return $commands;
}

function extract_batch_result(array $response): array
{
    $root = $response['result'] ?? [];
    return [
        'ok' => is_array($root['result'] ?? null) ? $root['result'] : [],
        'errors' => is_array($root['result_error'] ?? null) ? $root['result_error'] : [],
    ];
}

function process_create_stage(
    BXConnector $bx,
    array &$queue,
    string $stage,
    array $runtime,
    array $distributorMap,
    array $contactMap,
    array $config
): int {
    $limit = (int)$config['BATCH_SIZE'];
    $indexes = JsonStore::pendingIndexes($queue, $limit);
    if (!$indexes) {
        return 0;
    }

    $commands = create_batch_commands($indexes, $queue, $stage, $runtime, $distributorMap, $contactMap);

    try {
        $apiStarted = microtime(true);
        $response = $bx->batch($commands, false);
        $apiElapsed = round(microtime(true) - $apiStarted, 2);
        worker_out('Batch ' . $stage . ': ' . count($indexes) . ' элементов, API ' . $apiElapsed . ' сек.');
        $parts = extract_batch_result($response);
    } catch (Throwable $e) {
        foreach ($indexes as $index) {
            $queue['items'][$index]['status'] = 'pending';
            $queue['items'][$index]['last_error'] = $e->getMessage();
        }
        throw $e;
    }

    foreach ($indexes as $index) {
        $key = (string)$index;
        $item =& $queue['items'][$index];

        if (isset($parts['errors'][$key])) {
            $error = $parts['errors'][$key];
            $message = (string)($error['error_description'] ?? $error['error'] ?? 'Неизвестная ошибка batch');
            if ((int)$item['attempts'] >= (int)$config['MAX_ATTEMPTS']) {
                $item['status'] = 'failed';
            } else {
                $item['status'] = 'pending';
            }
            $item['last_error'] = $message;
            unset($item);
            continue;
        }

        $result = $parts['ok'][$key] ?? null;
        if ($stage === 'contacts') {
            $id = (int)($result['item']['id'] ?? 0);
        } else {
            $id = (int)($item['reuse_distributor_id'] ?? 0);
            if ($id <= 0) {
                $id = (int)($result['item']['id'] ?? $result ?? 0);
            }
            unset($item['reuse_distributor_id']);
        }

        if ($id <= 0) {
            $item['status'] = 'pending';
            $item['last_error'] = 'Bitrix не вернул ID объекта.';
            unset($item);
            continue;
        }

        $item['bitrix_id'] = $id;
        $item['status'] = 'done';
        $item['last_error'] = null;
        $item['updated_at'] = date('c');
        unset($item);
    }

    return count($indexes);
}

function build_distributor_map(array $queue): array
{
    $map = [];
    foreach ($queue['items'] as $item) {
        if (($item['status'] ?? '') !== 'done' || empty($item['bitrix_id'])) {
            continue;
        }
        $name = normalize_key((string)($item['data']['distributor_name'] ?? $item['data']['name'] ?? ''));
        if ($name !== '') {
            $map[$name] = (int)$item['bitrix_id'];
        }
    }
    return $map;
}

function build_contact_map(array $queue): array
{
    $map = [];
    foreach ($queue['items'] as $item) {
        if (($item['status'] ?? '') === 'done' && !empty($item['bitrix_id'])) {
            $map[$item['source_key']] = (int)$item['bitrix_id'];
        }
    }
    return $map;
}

function address_type_id(array $runtime, string $type): int
{
    $type = clean_value($type);
    if ($type === '') {
        $type = 'Actual';
    }

    foreach (['Actual', 'Legal', 'Shipping'] as $key) {
        if (normalize_key($type) === normalize_key($key)) {
            return (int)$runtime['address_types'][$key];
        }
    }
    return (int)$runtime['address_types']['Actual'];
}

function resolve_country_preset(array $runtime, string $country): array
{
    /*
     * По требованиям текущего импорта реквизиты создаются по российскому
     * шаблону независимо от значения поля «Страна» в исходном XLSX.
     * Само значение «Страна» компании при этом сохраняется отдельно.
     */
    return [
        'preset_id' => (int)$runtime['country_presets']['fallback_preset_id'],
        'country_code' => (string)$runtime['country_presets']['countries'][normalize_key('Россия')]['code'],
        'country_name' => (string)$runtime['country_presets']['countries'][normalize_key('Россия')]['name'],
    ];
}

function ensure_requisites_for_addresses(BXConnector $bx, array &$queue, array $runtime, array $config): int
{
    $targets = [];
    foreach ($queue['items'] as $index => $item) {
        if (($item['status'] ?? '') !== 'done' || empty($item['bitrix_id'])) {
            continue;
        }
        $addresses = (array)($item['data']['addresses'] ?? []);
        if (!$addresses) {
            continue;
        }
        if (!empty($item['requisite_id'])) {
            continue;
        }
        $targets[(int)$index] = (int)$item['bitrix_id'];
        if (count($targets) >= (int)$config['ADDRESS_BATCH_SIZE']) {
            break;
        }
    }

    if (!$targets) {
        return 0;
    }

    // Сначала смотрим существующие реквизиты — это защищает от дублей после
    // обрыва связи между Bitrix и worker.
    $commands = [];
    foreach ($targets as $index => $companyId) {
        $commands[(string)$index] = [
            'method' => 'crm.requisite.list',
            'params' => [
                'filter' => [
                    'ENTITY_TYPE_ID' => 4,
                    'ENTITY_ID' => $companyId,
                ],
                'select' => ['ID', 'PRESET_ID', 'ADDRESS_ONLY', 'ACTIVE'],
            ],
        ];
    }

    $response = $bx->batch($commands, false);
    $parts = extract_batch_result($response);
    $missing = [];

    foreach ($targets as $index => $companyId) {
        $key = (string)$index;
        $existing = $parts['ok'][$key] ?? [];
        $found = 0;
        foreach ((array)$existing as $rq) {
            if (($rq['ADDRESS_ONLY'] ?? 'N') === 'Y' && !empty($rq['ID'])) {
                $found = (int)$rq['ID'];
                break;
            }
        }
        if ($found > 0) {
            $queue['items'][$index]['requisite_id'] = $found;
            $queue['items'][$index]['requisite_status'] = 'done';
            continue;
        }
        $missing[$index] = $companyId;
    }

    if ($missing) {
        $commands = [];
        foreach ($missing as $index => $companyId) {
            $item = $queue['items'][$index];
            $preset = resolve_country_preset($runtime, (string)($item['data']['country'] ?? ''));
            $country = $preset['country_name'];
            $commands[(string)$index] = [
                'method' => 'crm.requisite.add',
                'params' => [
                    'fields' => [
                        'ENTITY_TYPE_ID' => 4,
                        'ENTITY_ID' => $companyId,
                        'PRESET_ID' => $preset['preset_id'],
                        'NAME' => 'Import address ' . $item['data']['name'],
                        'ACTIVE' => 'Y',
                        'ADDRESS_ONLY' => 'Y',
                        'SORT' => 500,
                        'XML_ID' => 'xlsx_import_v2:' . $item['source_key'],
                    ],
                ],
            ];
        }

        $response = $bx->batch($commands, false);
        $parts = extract_batch_result($response);

        foreach ($missing as $index => $companyId) {
            $key = (string)$index;
            if (isset($parts['errors'][$key])) {
                $queue['items'][$index]['requisite_error'] = (string)($parts['errors'][$key]['error_description'] ?? $parts['errors'][$key]['error'] ?? 'Ошибка реквизита');
                continue;
            }
            $requisiteId = (int)($parts['ok'][$key] ?? 0);
            if ($requisiteId > 0) {
                $queue['items'][$index]['requisite_id'] = $requisiteId;
                $queue['items'][$index]['requisite_status'] = 'done';
                $queue['items'][$index]['requisite_error'] = null;
            }
        }
    }

    return count($targets);
}

function process_addresses(BXConnector $bx, array &$queue, array $runtime, array $config): int
{
    ensure_requisites_for_addresses($bx, $queue, $runtime, $config);

    $commands = [];
    $refs = [];
    $limit = (int)$config['ADDRESS_BATCH_SIZE'];

    foreach ($queue['items'] as $companyIndex => $item) {
        if (($item['status'] ?? '') !== 'done' || empty($item['bitrix_id']) || empty($item['requisite_id'])) {
            continue;
        }

        foreach ((array)($item['data']['addresses'] ?? []) as $addressIndex => $address) {
            if (($address['status'] ?? 'pending') !== 'pending') {
                continue;
            }

            $typeId = address_type_id($runtime, (string)($address['type'] ?? 'Actual'));
            $preset = resolve_country_preset($runtime, (string)($item['data']['country'] ?? ''));
            $country = $preset['country_name'];

            $key = $companyIndex . '_' . $addressIndex;
            $commands[$key] = [
                'method' => 'crm.address.add',
                'params' => [
                    'fields' => [
                        'TYPE_ID' => $typeId,
                        'ENTITY_TYPE_ID' => 8,
                        'ENTITY_ID' => (int)$item['requisite_id'],
                        'ADDRESS_1' => $address['value'],
                        'COUNTRY' => $country,
                        'COUNTRY_CODE' => $preset['country_code'],
                    ],
                ],
            ];
            $refs[$key] = [$companyIndex, $addressIndex];

            if (count($commands) >= $limit) {
                break 2;
            }
        }
    }

    if (!$commands) {
        return 0;
    }

    $apiStarted = microtime(true);
    $response = $bx->batch($commands, false);
    $apiElapsed = round(microtime(true) - $apiStarted, 2);
    worker_out('Batch addresses: ' . count($commands) . ' адресов, API ' . $apiElapsed . ' сек.');
    $parts = extract_batch_result($response);

    foreach ($refs as $key => [$companyIndex, $addressIndex]) {
        if (isset($parts['errors'][$key])) {
            $queue['items'][$companyIndex]['data']['addresses'][$addressIndex]['last_error'] = (string)($parts['errors'][$key]['error_description'] ?? $parts['errors'][$key]['error'] ?? 'Ошибка адреса');
            continue;
        }

        $id = (int)($parts['ok'][$key] ?? 0);
        if ($id > 0) {
            $queue['items'][$companyIndex]['data']['addresses'][$addressIndex]['bitrix_id'] = $id;
            $queue['items'][$companyIndex]['data']['addresses'][$addressIndex]['status'] = 'done';
            $queue['items'][$companyIndex]['data']['addresses'][$addressIndex]['last_error'] = null;
        }
    }

    return count($refs);
}

function has_pending_addresses(array $queue): bool
{
    foreach ($queue['items'] as $item) {
        foreach ((array)($item['data']['addresses'] ?? []) as $address) {
            if (($address['status'] ?? 'pending') === 'pending') {
                return true;
            }
        }
    }
    return false;
}

try {
    @set_time_limit(85);
    ensure_dir($config['LOCKS_DIR']);
    ensure_dir($config['LOGS_DIR']);

    $runtime = load_runtime($config['RUNTIME_FILE']);
    $hook = trim((string)$config['BITRIX_HOOK']);
    if ($hook === '' || strpos($hook, 'YOUR-DOMAIN') !== false) {
        throw new RuntimeException('Укажи настоящий BITRIX_HOOK в config.php.');
    }

    $lockHandle = fopen($config['RUN_LOCK'], 'c');
    if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        worker_out('Другой worker уже работает.');
        exit(0);
    }

    $started = microtime(true);
    $bx = new BXConnector($hook);
    $bx->setRequestInterval((float)$config['REQUEST_INTERVAL']);
    $bx->setTimeouts((int)$config['HTTP_CONNECT_TIMEOUT'], (int)$config['HTTP_TIMEOUT']);

    $queues = [
        'distributors' => JsonStore::load($config['QUEUES']['distributors']),
        'contacts' => JsonStore::load($config['QUEUES']['contacts']),
        'companies' => JsonStore::load($config['QUEUES']['companies']),
    ];

    foreach ($queues as &$queue) {
        normalize_processing_status($queue);
    }
    unset($queue);

    $processed = 0;
    while (microtime(true) - $started < (float)$config['WORKER_BUDGET_SECONDS']) {
        $stage = queue_stage($queues);
        if ($stage === null) {
            break;
        }

        worker_out('Этап: ' . $stage);

        $distributorMap = build_distributor_map($queues['distributors']);
        $contactMap = build_contact_map($queues['contacts']);

        if ($stage === 'addresses') {
            $count = process_addresses($bx, $queues['companies'], $runtime, $config);
            $processed += $count;
            JsonStore::save($config['QUEUES']['companies'], $queues['companies']);
        } else {
            $queueKey = $stage;
            $count = process_create_stage(
                $bx,
                $queues[$queueKey],
                $stage,
                $runtime,
                $distributorMap,
                $contactMap,
                $config
            );
            $processed += $count;
            JsonStore::save($config['QUEUES'][$queueKey], $queues[$queueKey]);
        }

        if ($count === 0) {
            break;
        }
    }

    foreach ($queues as &$queue) {
        // сохраняем только изменившиеся массивы; это также нормально, если цикл закончился пустым.
    }
    unset($queue);

    $elapsed = round(microtime(true) - $started, 2);
    $stage = queue_stage($queues);
    $failed = 0;
    foreach ($queues as $queue) {
        foreach ($queue['items'] as $item) {
            if (($item['status'] ?? '') === 'failed') {
                $failed++;
            }
        }
    }
    if ($stage === null) {
        if ($failed > 0) {
            worker_out('ИМПОРТ ЗАВЕРШЁН С ОШИБКАМИ. Failed: ' . $failed . '. Обработано элементов: ' . $processed . '. Время: ' . $elapsed . ' сек.');
        } else {
            worker_out('ВСЁ ГОТОВО. Обработано элементов: ' . $processed . '. Время: ' . $elapsed . ' сек.');
        }
    } else {
        worker_out('Следующий запуск продолжит этап: ' . $stage . '. Обработано элементов: ' . $processed . '. Время: ' . $elapsed . ' сек.');
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
} catch (Throwable $e) {
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    worker_out('ОШИБКА WORKER: ' . $e->getMessage());
    exit(1);
}
