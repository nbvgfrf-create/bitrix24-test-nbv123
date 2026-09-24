<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Bitrix.php';

function out(string $text): void
{
    echo $text . PHP_EOL;
}

function company_type(array $config, string $value): string
{
    foreach ($config['COMPANY_TYPES'] as $name => $id) {
        if (key_name($name) === key_name($value)) return $id;
    }
    return '';
}

function industry(array $config, string $value): string
{
    foreach ($config['INDUSTRIES'] as $name => $id) {
        if (key_name($name) === key_name($value)) return $id;
    }
    return '';
}

function company_fields(array $config, array $data, array $distributorMap, array $contactMap, bool $linkContacts = true): array
{
    $fields = [
        'title' => $data['name'],
        'assignedById' => (int)$config['RESPONSIBLE_ID'],
        'originatorId' => 'xlsx_import',
        'originId' => $data['source_key'] ?: sha1(key_name($data['name'])),
    ];

    $typeId = company_type($config, (string)$data['type']);
    if ($typeId !== '') $fields['typeId'] = $typeId;

    $industryId = industry($config, (string)$data['industry']);
    if ($industryId !== '') $fields['industry'] = $industryId;

    if (($value = clean(implode(', ', $data['old_responsible'] ?? []))) !== '') {
        $fields[$config['FIELDS']['old_responsible']] = $value;
    }
    if (($value = clean((string)($data['country'] ?? ''))) !== '') {
        $fields[$config['FIELDS']['country']] = $value;
    }

    $competitors = [];
    foreach (($data['competitor_software'] ?? []) as $name) {
        foreach ($config['COMPETITOR_OPTIONS'] as $option => $id) {
            if (key_name($option) === key_name((string)$name)) {
                $competitors[] = (int)$id;
            }
        }
    }
    if ($competitors) {
        $fields[$config['FIELDS']['competitor_software']] = array_values(array_unique($competitors));
    }

    if (!empty($data['license_expiration'])) {
        $fields[$config['FIELDS']['license_expiration']] = $data['license_expiration'];
    }

    $distributors = [];
    foreach (($data['distributors'] ?? []) as $name) {
        $key = key_name((string)$name);
        if (isset($distributorMap[$key])) {
            $distributors[] = 'CO_' . $distributorMap[$key];
        }
    }
    if ($distributors) {
        $fields[$config['FIELDS']['distributor']] = array_values(array_unique($distributors));
    }

    if ($linkContacts) {
        $contacts = [];
        foreach (($data['contacts'] ?? []) as $name) {
            $key = 'contact:' . sha1(key_name((string)$name));
            if (isset($contactMap[$key])) $contacts[] = (int)$contactMap[$key];
        }
        if ($contacts) $fields['contactIds'] = array_values(array_unique($contacts));
    }

    return $fields;
}

function parse_batch(array $response): array
{
    $result = $response['result'] ?? [];
    return [
        'ok' => $result['result'] ?? [],
        'error' => $result['result_error'] ?? [],
    ];
}

function create_stage(Bitrix $bx, array &$queue, string $stage, array $config, array $runtime): int
{
    $indexes = pending_indexes($queue, (int)$config['BATCH_SIZE']);
    if (!$indexes) return 0;

    $distributorMap = $runtime['distributors'];
    $contactMap = $runtime['contacts'];
    $commands = [];
    $fixedIds = [];

    foreach ($indexes as $index) {
        $item =& $queue['items'][$index];
        $data = $item['data'];

        if ($stage === 'contacts') {
            $commands[(string)$index] = [
                'method' => 'crm.item.add',
                'params' => [
                    'entityTypeId' => 3,
                    'fields' => [
                        'name' => $data['name'],
                        'assignedById' => (int)$config['RESPONSIBLE_ID'],
                        'originatorId' => 'xlsx_import',
                        'originId' => $item['source_key'],
                    ],
                ],
            ];
        } else {
            $existingId = 0;
            if ($stage === 'companies') {
                $existingId = (int)($distributorMap[key_name($data['name'])] ?? 0);
            }

            if ($existingId > 0) {
                $commands[(string)$index] = [
                    'method' => 'crm.item.update',
                    'params' => [
                        'entityTypeId' => 4,
                        'id' => $existingId,
                        'fields' => company_fields($config, $data, $distributorMap, $contactMap),
                        'useOriginalUfNames' => 'Y',
                    ],
                ];
                $fixedIds[$index] = $existingId;
            } else {
                if ($stage === 'distributors') {
                    $data['contacts'] = [];
                    $data['distributors'] = [];
                }
                $commands[(string)$index] = [
                    'method' => 'crm.item.add',
                    'params' => [
                        'entityTypeId' => 4,
                        'fields' => company_fields($config, $data, $distributorMap, $contactMap, $stage === 'companies'),
                        'useOriginalUfNames' => 'Y',
                    ],
                ];
            }
        }
        unset($item);
    }

    $parts = parse_batch($bx->batch($commands));
    $done = 0;

    foreach ($indexes as $index) {
        $item =& $queue['items'][$index];
        $key = (string)$index;

        if (isset($parts['error'][$key])) {
            $item['status'] = 'failed';
            $item['last_error'] = (string)($parts['error'][$key]['error_description'] ?? $parts['error'][$key]['error'] ?? 'Ошибка Bitrix');
            unset($item);
            continue;
        }

        $id = (int)($fixedIds[$index] ?? 0);
        if ($id === 0) {
            $result = $parts['ok'][$key] ?? [];
            $id = (int)($result['item']['id'] ?? $result ?? 0);
        }
        if ($id <= 0) {
            $item['status'] = 'failed';
            $item['last_error'] = 'Bitrix не вернул ID.';
        } else {
            $item['bitrix_id'] = $id;
            $item['status'] = 'done';
            $done++;
        }
        unset($item);
    }

    return $done;
}

function build_map(array $queue, string $field): array
{
    $map = [];
    foreach ($queue['items'] as $item) {
        if (($item['status'] ?? '') !== 'done' || empty($item['bitrix_id'])) continue;
        $value = $item['data'][$field] ?? $item['data']['name'] ?? '';
        if (clean((string)$value) === '') continue;

        $map[key_name((string)$value)] = (int)$item['bitrix_id'];

        if ($field === 'name' && str_starts_with((string)$item['source_key'], 'contact:')) {
            $map['contact:' . sha1(key_name((string)$value))] = (int)$item['bitrix_id'];
        }
    }
    return $map;
}

function ensure_requisites(Bitrix $bx, array &$queue, array $config): void
{
    $indexes = [];
    foreach ($queue['items'] as $index => $item) {
        if (($item['status'] ?? '') === 'done' && !empty($item['bitrix_id']) && !empty($item['data']['addresses']) && empty($item['requisite_id'])) {
            $indexes[] = (int)$index;
            if (count($indexes) >= (int)$config['BATCH_SIZE']) break;
        }
    }
    if (!$indexes) return;

    // Сначала ищем уже созданный реквизит. Это нужно только для защиты от дубля после перезапуска.
    $commands = [];
    foreach ($indexes as $index) {
        $commands[(string)$index] = [
            'method' => 'crm.requisite.list',
            'params' => [
                'filter' => ['ENTITY_TYPE_ID' => 4, 'ENTITY_ID' => (int)$queue['items'][$index]['bitrix_id']],
                'select' => ['ID', 'PRESET_ID', 'ADDRESS_ONLY'],
            ],
        ];
    }

    $parts = parse_batch($bx->batch($commands));
    $missing = [];

    foreach ($indexes as $index) {
        $found = 0;
        foreach (($parts['ok'][(string)$index] ?? []) as $requisite) {
            if ((string)($requisite['PRESET_ID'] ?? '') === (string)$config['REQUISITE_PRESET_ID'] && (string)($requisite['ADDRESS_ONLY'] ?? '') === 'Y') {
                $found = (int)$requisite['ID'];
                break;
            }
        }
        if ($found > 0) {
            $queue['items'][$index]['requisite_id'] = $found;
        } else {
            $missing[] = $index;
        }
    }

    if (!$missing) return;

    $commands = [];
    foreach ($missing as $index) {
        $company = $queue['items'][$index]['data'];
        $commands[(string)$index] = [
            'method' => 'crm.requisite.add',
            'params' => [
                'fields' => [
                    'ENTITY_TYPE_ID' => 4,
                    'ENTITY_ID' => (int)$queue['items'][$index]['bitrix_id'],
                    'PRESET_ID' => (int)$config['REQUISITE_PRESET_ID'],
                    'NAME' => 'Import address ' . $company['name'],
                    'ACTIVE' => 'Y',
                    'ADDRESS_ONLY' => 'Y',
                    'SORT' => 500,
                    'XML_ID' => 'xlsx_import:' . $queue['items'][$index]['source_key'],
                ],
            ],
        ];
    }

    $parts = parse_batch($bx->batch($commands));
    foreach ($missing as $index) {
        $id = (int)($parts['ok'][(string)$index] ?? 0);
        if ($id > 0) $queue['items'][$index]['requisite_id'] = $id;
    }
}

function address_type_id(array $config, string $type): int
{
    foreach ($config['ADDRESS_TYPES'] as $name => $id) {
        if (key_name($name) === key_name($type)) return (int)$id;
    }
    return (int)$config['ADDRESS_TYPES']['Actual'];
}

function process_addresses(Bitrix $bx, array &$queue, array $config): int
{
    ensure_requisites($bx, $queue, $config);
    $commands = [];
    $refs = [];

    foreach ($queue['items'] as $companyIndex => $item) {
        if (($item['status'] ?? '') !== 'done' || empty($item['requisite_id'])) continue;
        foreach (($item['data']['addresses'] ?? []) as $addressIndex => $address) {
            if (($address['status'] ?? 'pending') !== 'pending') continue;

            $key = $companyIndex . '_' . $addressIndex;
            $commands[$key] = [
                'method' => 'crm.address.add',
                'params' => [
                    'fields' => [
                        'TYPE_ID' => address_type_id($config, (string)$address['type']),
                        'ENTITY_TYPE_ID' => 8,
                        'ENTITY_ID' => (int)$item['requisite_id'],
                        'ADDRESS_1' => $address['value'],
                        'COUNTRY' => $config['REQUISITE_COUNTRY_NAME'],
                        'COUNTRY_CODE' => $config['REQUISITE_COUNTRY_CODE'],
                    ],
                ],
            ];
            $refs[$key] = [$companyIndex, $addressIndex];

            if (count($commands) >= (int)$config['BATCH_SIZE']) break 2;
        }
    }

    if (!$commands) return 0;
    $parts = parse_batch($bx->batch($commands));

    foreach ($refs as $key => [$companyIndex, $addressIndex]) {
        if (isset($parts['error'][$key])) {
            $queue['items'][$companyIndex]['data']['addresses'][$addressIndex]['status'] = 'failed';
            $queue['items'][$companyIndex]['data']['addresses'][$addressIndex]['last_error'] = (string)($parts['error'][$key]['error_description'] ?? $parts['error'][$key]['error'] ?? 'Ошибка адреса');
            continue;
        }
        $queue['items'][$companyIndex]['data']['addresses'][$addressIndex]['status'] = 'done';
        $queue['items'][$companyIndex]['data']['addresses'][$addressIndex]['bitrix_id'] = $parts['ok'][$key] ?? true;
    }

    return count($refs);
}

function all_done(array $distributors, array $contacts, array $companies): bool
{
    foreach ([$distributors, $contacts, $companies] as $queue) {
        if (has_pending($queue)) return false;
    }
    foreach ($companies['items'] as $item) {
        foreach (($item['data']['addresses'] ?? []) as $address) {
            if (($address['status'] ?? 'pending') === 'pending') return false;
        }
    }
    return true;
}

try {
    @set_time_limit(60);
    make_dir($config['DATA_DIR']);

    $handle = fopen($config['LOCK_FILE'], 'c');
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        out('Другой worker уже работает.');
        exit;
    }

    $distributors = load_json(queue_path($config, 'distributors'));
    $contacts = load_json(queue_path($config, 'contacts'));
    $companies = load_json(queue_path($config, 'companies'));

    $bx = new Bitrix(
        (string)$config['BITRIX_HOOK'],
        (float)$config['REQUEST_INTERVAL'],
        (int)$config['HTTP_CONNECT_TIMEOUT'],
        (int)$config['HTTP_TIMEOUT']
    );

    $started = microtime(true);

    // Сначала создаём дистрибьюторов, потом контакты, потом компании.
    // Адреса — в самом конце, потому что для них нужен ID компании и реквизита.
    if (has_pending($distributors)) {
        create_stage($bx, $distributors, 'distributors', $config, ['distributors' => [], 'contacts' => []]);
    } elseif (has_pending($contacts)) {
        create_stage($bx, $contacts, 'contacts', $config, ['distributors' => [], 'contacts' => []]);
    } else {
        $distributorMap = build_map($distributors, 'distributor_name');
        // Для картируемого имени дистрибьютора в очереди data.name и так совпадает.
        $contactMap = build_map($contacts, 'name');
        create_stage($bx, $companies, 'companies', $config, ['distributors' => $distributorMap, 'contacts' => $contactMap]);
    }

    // Карты нужны также между отдельными worker-запусками.
    $distributorMap = build_map($distributors, 'name');
    $contactMap = build_map($contacts, 'name');

    $elapsed = microtime(true) - $started;
    if (!has_pending($distributors) && !has_pending($contacts) && !has_pending($companies)) {
        process_addresses($bx, $companies, $config);
    }

    save_json(queue_path($config, 'distributors'), $distributors);
    save_json(queue_path($config, 'contacts'), $contacts);
    save_json(queue_path($config, 'companies'), $companies);

    if (all_done($distributors, $contacts, $companies)) {
        if (has_failed($distributors) || has_failed($contacts) || has_failed($companies)) {
            out('ГОТОВО: импорт завершён с ошибками.');
        } else {
            out('ГОТОВО: импорт завершён.');
        }
    } else {
        out('Worker завершён за ' . round($elapsed, 1) . ' сек.');
        out('Дистрибьюторы: ' . json_encode(queue_counts($distributors), JSON_UNESCAPED_UNICODE));
        out('Контакты: ' . json_encode(queue_counts($contacts), JSON_UNESCAPED_UNICODE));
        out('Компании: ' . json_encode(queue_counts($companies), JSON_UNESCAPED_UNICODE));
    }

    flock($handle, LOCK_UN);
    fclose($handle);
} catch (Throwable $e) {
    http_response_code(500);
    out('ОШИБКА: ' . $e->getMessage());
}
