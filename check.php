<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/BXConnector.php';
require_once __DIR__ . '/JsonStore.php';
require_once __DIR__ . '/ImportHelpers.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $runtime = load_runtime($config['RUNTIME_FILE']);
    $hook = trim((string)$config['BITRIX_HOOK']);
    $bx = new BXConnector($hook);
    $bx->setRequestInterval((float)$config['REQUEST_INTERVAL']);
    $bx->setTimeouts((int)$config['HTTP_CONNECT_TIMEOUT'], (int)$config['HTTP_TIMEOUT']);

    foreach ($config['QUEUES'] as $name => $path) {
        $queue = JsonStore::load($path);
        $counts = JsonStore::counts($queue);
        echo $name . ': ' . json_encode($counts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }

    $companies = JsonStore::load($config['QUEUES']['companies']);
    $addressTotal = 0;
    $addressDone = 0;
    $countryNonEmpty = 0;
    $oldResponsibleNonEmpty = 0;

    foreach ($companies['items'] as $item) {
        $data = $item['data'] ?? [];
        if (clean_value((string)($data['country'] ?? '')) !== '') {
            $countryNonEmpty++;
        }
        if (clean_value((string)($data['old_responsible'] ?? '')) !== '') {
            $oldResponsibleNonEmpty++;
        }
        foreach ((array)($data['addresses'] ?? []) as $address) {
            $addressTotal++;
            if (($address['status'] ?? '') === 'done') {
                $addressDone++;
            }
        }
    }

    echo 'Компаниям с заполненной страной в очереди: ' . $countryNonEmpty . PHP_EOL;
    echo 'Компаниям с заполненным старым ответственным в очереди: ' . $oldResponsibleNonEmpty . PHP_EOL;
    echo 'Адресов в очереди: ' . $addressTotal . ', обработано: ' . $addressDone . PHP_EOL;
    echo 'Ответственный ID: ' . (int)$runtime['responsible_id'] . PHP_EOL;

    $fieldNames = [];
    foreach ((array)$runtime['fields'] as $key => $field) {
        $fieldNames[$key] = $field['FIELD_NAME'] ?? '';
    }
    echo 'Поля: ' . json_encode($fieldNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    $sample = [];
    foreach ($companies['items'] as $item) {
        if (($item['status'] ?? '') === 'done' && !empty($item['bitrix_id'])) {
            $sample[] = $item;
            if (count($sample) >= 5) {
                break;
            }
        }
    }

    echo PHP_EOL . 'Проверка 5 первых готовых компаний в Bitrix:' . PHP_EOL;
    foreach ($sample as $item) {
        $result = $bx->request('crm.item.get', [
            'entityTypeId' => 4,
            'id' => (int)$item['bitrix_id'],
            'useOriginalUfNames' => true,
        ]);
        $company = $result['item'] ?? [];
        echo '- ' . ($company['title'] ?? $item['data']['name']) . ' #' . (int)$item['bitrix_id'] . PHP_EOL;
        echo '  ' . ($runtime['fields']['country']['FIELD_NAME']) . ': ' . ($company[$runtime['fields']['country']['FIELD_NAME']] ?? '') . PHP_EOL;
        echo '  ' . ($runtime['fields']['old_responsible']['FIELD_NAME']) . ': ' . ($company[$runtime['fields']['old_responsible']['FIELD_NAME']] ?? '') . PHP_EOL;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ОШИБКА: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
