<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Bitrix.php';

header('Content-Type: text/plain; charset=utf-8');

function repair_company_fields(array $config, array $data, array $distributorMap): array
{
    $fields = [
        'title' => $data['name'],
        'assignedById' => (int)$config['RESPONSIBLE_ID'],
        $config['FIELDS']['country'] => clean((string)($data['country'] ?? '')),
        $config['FIELDS']['old_responsible'] => clean(implode(', ', $data['old_responsible'] ?? [])),
    ];

    $type = map_repair_value($config['COMPANY_TYPES'], (string)($data['type'] ?? ''));
    if ($type !== '') {
        $fields['typeId'] = $type;
    }

    $industry = map_repair_value($config['INDUSTRIES'], (string)($data['industry'] ?? ''));
    if ($industry !== '') {
        $fields['industry'] = $industry;
    }

    $software = [];
    foreach (($data['competitor_software'] ?? []) as $name) {
        $id = map_repair_value($config['COMPETITOR_OPTIONS'], (string)$name);
        if ($id !== '') {
            $software[] = (string)$id;
        }
    }
    $fields[$config['FIELDS']['competitor_software']] = array_values(array_unique($software));

    $license = clean((string)($data['license_expiration'] ?? ''));
    $fields[$config['FIELDS']['license_expiration']] = $license;

    $distributors = [];
    foreach (($data['distributors'] ?? []) as $name) {
        $id = $distributorMap[key_name((string)$name)] ?? 0;
        if (!$id) {
            throw new RuntimeException('Не найден ID дистрибьютора: ' . $name);
        }
        $distributors[] = 'CO_' . (int)$id;
    }
    $fields[$config['FIELDS']['distributor']] = array_values(array_unique($distributors));

    return $fields;
}

function map_repair_value(array $map, string $value): string
{
    $key = key_name($value);
    foreach ($map as $name => $id) {
        if (key_name((string)$name) === $key) {
            return (string)$id;
        }
    }
    return '';
}

function find_company_id(Bitrix $bx, string $name): int
{
    $items = $bx->call('crm.company.list', [
        'filter' => ['=TITLE' => $name],
        'select' => ['ID', 'TITLE'],
    ]) ?? [];

    if (!$items) {
        return 0;
    }

    return (int)($items[0]['ID'] ?? 0);
}

function ensure_distributor_ids(Bitrix $bx, array &$queue, array $config): array
{
    $map = [];

    foreach ($queue['items'] as $index => &$item) {
        $name = clean((string)($item['data']['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $id = (int)($item['bitrix_id'] ?? 0);
        if (!$id) {
            $id = find_company_id($bx, $name);
        }

        if (!$id) {
            $result = $bx->call('crm.item.add', [
                'entityTypeId' => 4,
                'fields' => [
                    'title' => $name,
                    'assignedById' => (int)$config['RESPONSIBLE_ID'],
                    'typeId' => 'DISTRIBUTOR',
                ],
                'useOriginalUfNames' => true,
            ]);
            $id = (int)($result['item']['id'] ?? 0);
        }

        if ($id) {
            $item['bitrix_id'] = $id;
            $item['status'] = 'done';
            $map[key_name($name)] = $id;
        }
    }
    unset($item);

    save_json(queue_path($config, 'distributors'), $queue);
    return $map;
}

function repair_one_company(Bitrix $bx, array &$item, array $config, array $distributorMap): void
{
    $name = clean((string)($item['data']['name'] ?? ''));
    if ($name === '') {
        return;
    }

    $id = (int)($item['bitrix_id'] ?? 0);
    if (!$id) {
        $id = find_company_id($bx, $name);
        if ($id) {
            $item['bitrix_id'] = $id;
        }
    }

    if (!$id) {
        throw new RuntimeException('Компания не найдена в Bitrix24: ' . $name);
    }

    $fields = repair_company_fields($config, $item['data'], $distributorMap);
    $bx->call('crm.item.update', [
        'entityTypeId' => 4,
        'id' => $id,
        'fields' => $fields,
        'useOriginalUfNames' => true,
    ]);

    $item['status'] = 'done';
    $item['last_error'] = null;
}

try {
    $companiesPath = queue_path($config, 'companies');
    $distributorsPath = queue_path($config, 'distributors');

    if (!file_exists($companiesPath) || !file_exists($distributorsPath)) {
        throw new RuntimeException('Сначала нажми «Подготовить».');
    }

    $companies = load_json($companiesPath);
    $distributors = load_json($distributorsPath);

    $bx = new Bitrix(
        (string)$config['BITRIX_HOOK'],
        (float)$config['REQUEST_INTERVAL'],
        (int)$config['HTTP_CONNECT_TIMEOUT'],
        (int)$config['HTTP_TIMEOUT']
    );

    $map = ensure_distributor_ids($bx, $distributors, $config);

    $statePath = $config['DATA_DIR'] . '/repair_state.json';
    $state = file_exists($statePath) ? load_json($statePath) : ['index' => 0];
    $index = (int)($state['index'] ?? 0);
    $limit = 20;
    $end = min(count($companies['items']), $index + $limit);

    for ($i = $index; $i < $end; $i++) {
        try {
            repair_one_company($bx, $companies['items'][$i], $config, $map);
            echo 'OK: ' . $companies['items'][$i]['data']['name'] . PHP_EOL;
        } catch (Throwable $e) {
            $companies['items'][$i]['last_error'] = $e->getMessage();
            echo 'ERROR: ' . $companies['items'][$i]['data']['name'] . ' -> ' . $e->getMessage() . PHP_EOL;
        }
    }

    save_json($companiesPath, $companies);

    if ($end >= count($companies['items'])) {
        save_json($statePath, ['index' => 0, 'finished_at' => date('c')]);
        echo 'ГОТОВО: исправление компаний завершено.' . PHP_EOL;
    } else {
        save_json($statePath, ['index' => $end]);
        echo 'Продолжение с компании ' . ($end + 1) . ' из ' . count($companies['items']) . PHP_EOL;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ОШИБКА: ' . $e->getMessage() . PHP_EOL;
}
