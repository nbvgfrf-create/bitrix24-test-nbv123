<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/BXConnector.php';
require_once __DIR__ . '/XlsxReader.php';
require_once __DIR__ . '/JsonStore.php';
require_once __DIR__ . '/ImportHelpers.php';
require_once __DIR__ . '/bootstrap.php';

function out_line(string $message): void
{
    echo $message . PHP_EOL;
    if (function_exists('flush')) {
        @ob_flush();
        @flush();
    }
}

try {
    foreach ($config['QUEUES'] as $queuePath) {
        if (file_exists($queuePath)) {
            throw new RuntimeException('Очередь уже существует: ' . basename($queuePath) . '. Удали старые очереди перед новым импортом.');
        }
    }

    if (!file_exists($config['XLSX_FILE'])) {
        throw new RuntimeException('Не найден XLSX: ' . $config['XLSX_FILE']);
    }

    ensure_dir($config['QUEUES_DIR']);
    ensure_dir($config['LOGS_DIR']);
    ensure_dir($config['LOCKS_DIR']);

    $hook = trim((string)$config['BITRIX_HOOK']);
    if ($hook === '' || strpos($hook, 'YOUR-DOMAIN') !== false) {
        throw new RuntimeException('Сначала укажи настоящий BITRIX_HOOK в config.php.');
    }

    $bx = new BXConnector($hook);
    $bx->setRequestInterval((float)$config['REQUEST_INTERVAL']);
    $bx->setTimeouts((int)$config['HTTP_CONNECT_TIMEOUT'], (int)$config['HTTP_TIMEOUT']);

    out_line('Подготовка runtime из Bitrix24...');
    $runtime = build_runtime($bx, $config);
    save_runtime($config['RUNTIME_FILE'], $runtime);
    out_line('OK: runtime.json подготовлен.');

    $rows = (new XlsxReader())->read($config['XLSX_FILE']);
    if (!$rows) {
        throw new RuntimeException('XLSX пуст.');
    }

    $headers = array_map('clean_value', $rows[0]);
    $requiredHeaders = [
        'Название',
        'Основной контакт',
        'Тип',
        'Отрасль',
        'Страна',
        'Ответственный',
        'Дистрибьютор',
        'Address',
        'Address type',
        'Competitor software',
        'License expiration date',
    ];

    $missing = array_values(array_diff($requiredHeaders, $headers));
    if ($missing) {
        throw new RuntimeException('В XLSX нет колонок: ' . implode(', ', $missing));
    }

    $index = array_flip($headers);
    $companies = [];
    $contactNames = [];

    foreach (array_slice($rows, 1) as $rowNumber => $values) {
        $get = static function (string $name) use (&$values, &$index): string {
            $pos = $index[$name] ?? null;
            return $pos === null ? '' : clean_value($values[$pos] ?? '');
        };

        $name = $get('Название');
        if ($name === '') {
            continue;
        }

        $companyKey = 'company:' . sha1(normalize_key($name));
        $contactName = $get('Основной контакт');
        $type = $get('Тип');
        $industry = $get('Отрасль');
        $country = $get('Страна');
        $oldResponsible = $get('Ответственный');
        $distributor = $get('Дистрибьютор');
        $address = $get('Address');
        $addressType = $get('Address type');
        $competitors = split_multi_value($get('Competitor software'));
        $license = excel_serial_to_date($values[$index['License expiration date']] ?? '');

        if (!isset($companies[$companyKey])) {
            $companies[$companyKey] = [
                'source_key' => $companyKey,
                'name' => $name,
                'type' => $type,
                'industry' => $industry,
                'country' => $country,
                'old_responsible' => $oldResponsible,
                'distributors' => [],
                'contacts_source_names' => [],
                'addresses' => [],
                'competitor_software' => [],
                'license_expiration_date' => $license,
            ];
        }

        $company =& $companies[$companyKey];

        if ($company['type'] === '' && $type !== '') {
            $company['type'] = $type;
        }
        if ($company['industry'] === '' && $industry !== '') {
            $company['industry'] = $industry;
        }

        if ($company['country'] === '' && $country !== '') {
            $company['country'] = $country;
        } elseif ($country !== '' && normalize_key($company['country']) !== normalize_key($country)) {
            $company['country_conflict'] = true;
        }

        if ($oldResponsible !== '') {
            unique_append($company['old_responsible_values'], $oldResponsible);
        }

        if ($distributor !== '') {
            unique_append($company['distributors'], $distributor);
        }

        if ($contactName !== '') {
            unique_append($company['contacts_source_names'], $contactName);
            unique_append($contactNames, $contactName);
        }

        if ($address !== '') {
            unique_address_append($company['addresses'], $address, $addressType);
        }

        foreach ($competitors as $software) {
            unique_append($company['competitor_software'], $software);
        }

        if ($license !== '') {
            if ($company['license_expiration_date'] === '' || $license > $company['license_expiration_date']) {
                $company['license_expiration_date'] = $license;
            }
        }

        unset($company);
    }

    foreach ($companies as &$company) {
        $values = $company['old_responsible_values'] ?? [];
        $company['old_responsible'] = implode(', ', $values);
        unset($company['old_responsible_values']);

        $company['contact_keys'] = [];
        foreach ($company['contacts_source_names'] as $sourceName) {
            $personKey = 'contact:' . sha1(normalize_key($sourceName));
            $company['contact_keys'][] = $personKey;
        }
        unset($company['contacts_source_names']);
    }
    unset($company);

    $referencedDistributors = [];
    foreach ($companies as $company) {
        foreach ($company['distributors'] as $distName) {
            $referencedDistributors[normalize_key($distName)] = $distName;
        }
    }

    $distributorItems = [];
    foreach ($referencedDistributors as $distName) {
        $distCompanyKey = 'company:' . sha1(normalize_key($distName));
        if (isset($companies[$distCompanyKey])) {
            $source = $companies[$distCompanyKey];
            $data = $source;
        } else {
            $data = [
                'source_key' => '',
                'name' => $distName,
                'type' => 'Distributor',
                'industry' => '',
                'country' => '',
                'old_responsible' => '',
                'distributors' => [],
                'contact_keys' => [],
                'addresses' => [],
                'competitor_software' => [],
                'license_expiration_date' => '',
            ];
        }

        $data['distributor_name'] = $distName;
        $data['source_company_key'] = $distCompanyKey;
        $sourceKey = 'distributor:' . sha1(normalize_key($distName));

        $distributorItems[] = [
            'source_key' => $sourceKey,
            'status' => 'pending',
            'bitrix_id' => null,
            'data' => $data,
            'attempts' => 0,
            'last_error' => null,
            'updated_at' => date('c'),
        ];
    }

    $contactItems = [];
    foreach ($contactNames as $contactName) {
        $personKey = 'contact:' . sha1(normalize_key($contactName));
        $companyKeys = [];
        foreach ($companies as $company) {
            if (in_array($personKey, $company['contact_keys'], true)) {
                $companyKeys[] = $company['source_key'];
            }
        }

        $contactItems[] = [
            'source_key' => $personKey,
            'status' => 'pending',
            'bitrix_id' => null,
            'data' => [
                'name' => $contactName,
                'company_keys' => $companyKeys,
            ],
            'attempts' => 0,
            'last_error' => null,
            'updated_at' => date('c'),
        ];
    }

    $companyItems = [];
    foreach ($companies as $company) {
        $companyItems[] = [
            'source_key' => $company['source_key'],
            'status' => 'pending',
            'bitrix_id' => null,
            'data' => $company,
            'attempts' => 0,
            'last_error' => null,
            'updated_at' => date('c'),
        ];
    }

    $meta = [
        'version' => 2,
        'source_file' => basename($config['XLSX_FILE']),
        'created_at' => date('c'),
        'source_rows' => count($rows) - 1,
    ];

    JsonStore::save($config['QUEUES']['distributors'], $meta + ['queue' => 'distributors', 'items' => $distributorItems]);
    JsonStore::save($config['QUEUES']['contacts'], $meta + ['queue' => 'contacts', 'items' => $contactItems]);
    JsonStore::save($config['QUEUES']['companies'], $meta + ['queue' => 'companies', 'items' => $companyItems]);

    out_line('Готово.');
    out_line('Строк XLSX: ' . (count($rows) - 1));
    out_line('Уникальных компаний: ' . count($companyItems));
    out_line('Дистрибьюторов: ' . count($distributorItems));
    out_line('Уникальных контактов: ' . count($contactItems));
    out_line('Компаний с адресами: ' . count(array_filter($companies, static fn(array $c): bool => !empty($c['addresses']))));
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    out_line('ОШИБКА: ' . $e->getMessage());
    exit(1);
}
