<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Bitrix.php';

function out(string $text): void
{
    echo $text . PHP_EOL;
}

function map_value(array $map, string $value): string
{
    $key = key_name($value);
    foreach ($map as $name => $id) {
        if (key_name((string)$name) === $key) {
            return (string)$id;
        }
    }
    return '';
}

function company_fields(array $config, array $data, array $distributorMap, array $contactMap, bool $withLinks): array
{
    $fields = [
        'title' => $data['name'],
        'assignedById' => (int)$config['RESPONSIBLE_ID'],
        $config['FIELDS']['country'] => clean((string)($data['country'] ?? '')),
        $config['FIELDS']['old_responsible'] => clean(implode(', ', $data['old_responsible'] ?? [])),
    ];

    $type = map_value($config['COMPANY_TYPES'], (string)($data['type'] ?? ''));
    if ($type !== '') {
        $fields['typeId'] = $type;
    }

    $industry = map_value($config['INDUSTRIES'], (string)($data['industry'] ?? ''));
    if ($industry !== '') {
        $fields['industry'] = $industry;
    }

    $software = [];
    foreach (($data['competitor_software'] ?? []) as $name) {
        $id = map_value($config['COMPETITOR_OPTIONS'], (string)$name);
        if ($id !== '') {
            $software[] = (string)$id;
        }
    }
    if ($software) {
        $fields[$config['FIELDS']['competitor_software']] = array_values(array_unique($software));
    }

    if (!empty($data['license_expiration'])) {
        $fields[$config['FIELDS']['license_expiration']] = $data['license_expiration'];
    }

    $distributors = [];
    foreach (($data['distributors'] ?? []) as $name) {
        $id = $distributorMap[key_name((string)$name)] ?? 0;
        if (!$id) {
            throw new RuntimeException('Не найден ID дистрибьютора: ' . $name);
        }
        $distributors[] = 'CO_' . (int)$id;
    }
    if ($distributors) {
        $fields[$config['FIELDS']['distributor']] = array_values(array_unique($distributors));
    }

    if ($withLinks) {
        $contacts = [];
        foreach (($data['contacts'] ?? []) as $name) {
            $key = 'contact:' . sha1(key_name((string)$name));
            if (!empty($contactMap[$key])) {
                $contacts[] = (int)$contactMap[$key];
            }
        }
        if ($contacts) {
            $fields['contactIds'] = array_values(array_unique($contacts));
        }
    }

    return $fields;
}

function distributor_map(array $queue): array
{
    $map = [];
    foreach ($queue['items'] as $item) {
        if (($item['status'] ?? '') !== 'done' || empty($item['bitrix_id'])) {
            continue;
        }
        $map[key_name((string)$item['data']['name'])] = (int)$item['bitrix_id'];
    }
    return $map;
}

function contact_map(array $queue): array
{
    $map = [];
    foreach ($queue['items'] as $item) {
        if (($item['status'] ?? '') !== 'done' || empty($item['bitrix_id'])) {
            continue;
        }
        $map[(string)$item['source_key']] = (int)$item['bitrix_id'];
    }
    return $map;
}

function retry_failed(array &$queue): void
{
    foreach ($queue['items'] as &$item) {
        if (($item['status'] ?? '') === 'failed') {
            $item['status'] = 'pending';
        }
        foreach (($item['data']['addresses'] ?? []) as &$address) {
            if (($address['status'] ?? '') === 'failed') {
                $address['status'] = 'pending';
            }
        }
        unset($address);
    }
    unset($item);
}

function first_pending(array $queue): ?int
{
    foreach ($queue['items'] as $index => $item) {
        if (($item['status'] ?? 'pending') !== 'done') {
            return (int)$index;
        }
    }
    return null;
}

function address_type_id(array $config, string $type): int
{
    $key = key_name($type === '' ? 'Actual' : $type);
    foreach ($config['ADDRESS_TYPES'] as $name => $id) {
        if (key_name($name) === $key) {
            return (int)$id;
        }
    }
    return (int)$config['ADDRESS_TYPES']['Actual'];
}

function ensure_requisites(Bitrix $bx, array &$item, array $config): void
{
    $addresses = $item['data']['addresses'] ?? [];
    if (!$addresses || empty($item['bitrix_id'])) {
        return;
    }

    $requisites = $bx->call('crm.requisite.list', [
        'filter' => [
            'ENTITY_TYPE_ID' => 4,
            'ENTITY_ID' => (int)$item['bitrix_id'],
        ],
        'select' => ['ID', 'PRESET_ID'],
    ]) ?? [];

    $requisiteId = 0;
    foreach ($requisites as $requisite) {
        if ((int)($requisite['PRESET_ID'] ?? 0) === (int)$config['REQUISITE_PRESET_ID']) {
            $requisiteId = (int)$requisite['ID'];
            break;
        }
    }

    if (!$requisiteId) {
        $requisiteId = (int)$bx->call('crm.requisite.add', [
            'fields' => [
                'ENTITY_TYPE_ID' => 4,
                'ENTITY_ID' => (int)$item['bitrix_id'],
                'PRESET_ID' => (int)$config['REQUISITE_PRESET_ID'],
                'NAME' => $item['data']['name'],
                'ACTIVE' => 'Y',
            ],
        ]);
    }

    if (!$requisiteId) {
        throw new RuntimeException('Не удалось создать реквизит для компании ' . $item['data']['name']);
    }

    $item['requisite_id'] = $requisiteId;

    foreach ($item['data']['addresses'] as $addressIndex => $address) {
        if (($address['status'] ?? 'pending') === 'done') {
            continue;
        }

        $typeId = address_type_id($config, (string)($address['type'] ?? 'Actual'));
        $existing = $bx->call('crm.address.list', [
            'filter' => [
                'ENTITY_TYPE_ID' => 8,
                'ENTITY_ID' => $requisiteId,
                'TYPE_ID' => $typeId,
            ],
        ]) ?? [];

        $fields = [
            'TYPE_ID' => $typeId,
            'ENTITY_TYPE_ID' => 8,
            'ENTITY_ID' => $requisiteId,
            'ADDRESS_1' => $address['value'],
            'COUNTRY' => $config['REQUISITE_COUNTRY_NAME'],
            'COUNTRY_CODE' => $config['REQUISITE_COUNTRY_CODE'],
        ];

        if ($existing && !empty($existing[0]['ID'])) {
            $bx->call('crm.address.update', [
                'id' => (int)$existing[0]['ID'],
                'fields' => $fields,
            ]);
            $item['data']['addresses'][$addressIndex]['bitrix_id'] = (int)$existing[0]['ID'];
        } else {
            $id = $bx->call('crm.address.add', ['fields' => $fields]);
            $item['data']['addresses'][$addressIndex]['bitrix_id'] = $id;
        }

        $item['data']['addresses'][$addressIndex]['status'] = 'done';
        $item['data']['addresses'][$addressIndex]['last_error'] = null;
    }
}

function process_distributor(Bitrix $bx, array &$queue, int $index, array $config): void
{
    $item =& $queue['items'][$index];
    $fields = company_fields($config, $item['data'], [], [], false);
    $fields['typeId'] = 'DISTRIBUTOR';

    if (empty($item['bitrix_id'])) {
        $result = $bx->call('crm.item.add', [
            'entityTypeId' => 4,
            'fields' => $fields,
            'useOriginalUfNames' => true,
        ]);
        $item['bitrix_id'] = (int)($result['item']['id'] ?? 0);
    } else {
        $bx->call('crm.item.update', [
            'entityTypeId' => 4,
            'id' => (int)$item['bitrix_id'],
            'fields' => $fields,
            'useOriginalUfNames' => true,
        ]);
    }

    if (empty($item['bitrix_id'])) {
        throw new RuntimeException('Bitrix не вернул ID дистрибьютора.');
    }

    $item['status'] = 'done';
    $item['last_error'] = null;
}

function process_contact(Bitrix $bx, array &$queue, int $index, array $config): void
{
    $item =& $queue['items'][$index];
    $fields = [
        'name' => $item['data']['name'],
        'assignedById' => (int)$config['RESPONSIBLE_ID'],
    ];

    if (empty($item['bitrix_id'])) {
        $result = $bx->call('crm.item.add', [
            'entityTypeId' => 3,
            'fields' => $fields,
        ]);
        $item['bitrix_id'] = (int)($result['item']['id'] ?? 0);
    } else {
        $bx->call('crm.item.update', [
            'entityTypeId' => 3,
            'id' => (int)$item['bitrix_id'],
            'fields' => $fields,
        ]);
    }

    if (empty($item['bitrix_id'])) {
        throw new RuntimeException('Bitrix не вернул ID контакта.');
    }

    $item['status'] = 'done';
    $item['last_error'] = null;
}

function process_company(Bitrix $bx, array &$queue, int $index, array $config, array $distributors, array $contacts): void
{
    $item =& $queue['items'][$index];
    $distributorMap = distributor_map($distributors);
    $contactMap = contact_map($contacts);
    $fields = company_fields($config, $item['data'], $distributorMap, $contactMap, true);

    if (empty($item['bitrix_id'])) {
        $existingId = $distributorMap[key_name($item['data']['name'])] ?? 0;
        if ($existingId) {
            $item['bitrix_id'] = $existingId;
            $bx->call('crm.item.update', [
                'entityTypeId' => 4,
                'id' => $existingId,
                'fields' => $fields,
                'useOriginalUfNames' => true,
            ]);
        } else {
            $result = $bx->call('crm.item.add', [
                'entityTypeId' => 4,
                'fields' => $fields,
                'useOriginalUfNames' => true,
            ]);
            $item['bitrix_id'] = (int)($result['item']['id'] ?? 0);
        }
    } else {
        $bx->call('crm.item.update', [
            'entityTypeId' => 4,
            'id' => (int)$item['bitrix_id'],
            'fields' => $fields,
            'useOriginalUfNames' => true,
        ]);
    }

    if (empty($item['bitrix_id'])) {
        throw new RuntimeException('Bitrix не вернул ID компании.');
    }

    ensure_requisites($bx, $item, $config);

    $item['status'] = 'done';
    $item['last_error'] = null;
}

function counts(array $queue): array
{
    $result = ['pending' => 0, 'done' => 0, 'failed' => 0];
    foreach ($queue['items'] as $item) {
        $status = $item['status'] ?? 'pending';
        $result[$status] = ($result[$status] ?? 0) + 1;
    }
    return $result;
}

try {
    @set_time_limit(70);
    make_dir($config['DATA_DIR']);

    $lock = fopen($config['LOCK_FILE'], 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        out('Другой worker уже работает.');
        exit;
    }

    $distributors = load_json(queue_path($config, 'distributors'));
    $contacts = load_json(queue_path($config, 'contacts'));
    $companies = load_json(queue_path($config, 'companies'));

    // Ошибки предыдущей версии повторяем автоматически.
    retry_failed($distributors);
    retry_failed($contacts);
    retry_failed($companies);

    $bx = new Bitrix(
        (string)$config['BITRIX_HOOK'],
        (float)$config['REQUEST_INTERVAL'],
        (int)$config['HTTP_CONNECT_TIMEOUT'],
        (int)$config['HTTP_TIMEOUT']
    );

    $started = microtime(true);
    $processed = 0;

    while ($processed < (int)$config['BATCH_SIZE'] && microtime(true) - $started < (float)$config['WORKER_SECONDS']) {
        if (($index = first_pending($distributors)) !== null) {
            $queue = 'distributors';
        } elseif (($index = first_pending($contacts)) !== null) {
            $queue = 'contacts';
        } elseif (($index = first_pending($companies)) !== null) {
            $queue = 'companies';
        } else {
            break;
        }

        try {
            if ($queue === 'distributors') {
                process_distributor($bx, $distributors, $index, $config);
            } elseif ($queue === 'contacts') {
                process_contact($bx, $contacts, $index, $config);
            } else {
                process_company($bx, $companies, $index, $config, $distributors, $contacts);
            }
            $processed++;
        } catch (Throwable $e) {
            if ($queue === 'distributors') {
                $item =& $distributors['items'][$index];
            } elseif ($queue === 'contacts') {
                $item =& $contacts['items'][$index];
            } else {
                $item =& $companies['items'][$index];
            }
            $item['status'] = 'failed';
            $item['last_error'] = $e->getMessage();
            $item['updated_at'] = date('c');
            out('Ошибка ' . $queue . ': ' . ($item['data']['name'] ?? $item['source_key']) . ' -> ' . $e->getMessage());
            unset($item);
            $processed++;
        }
    }

    save_json(queue_path($config, 'distributors'), $distributors);
    save_json(queue_path($config, 'contacts'), $contacts);
    save_json(queue_path($config, 'companies'), $companies);

    if (first_pending($distributors) === null && first_pending($contacts) === null && first_pending($companies) === null) {
        out('ГОТОВО: импорт завершён.');
    } else {
        out('Worker завершён за ' . round(microtime(true) - $started, 1) . ' сек.');
        out('Дистрибьюторы: ' . json_encode(counts($distributors), JSON_UNESCAPED_UNICODE));
        out('Контакты: ' . json_encode(counts($contacts), JSON_UNESCAPED_UNICODE));
        out('Компании: ' . json_encode(counts($companies), JSON_UNESCAPED_UNICODE));
    }

    flock($lock, LOCK_UN);
    fclose($lock);
} catch (Throwable $e) {
    http_response_code(500);
    out('ОШИБКА: ' . $e->getMessage());
}
