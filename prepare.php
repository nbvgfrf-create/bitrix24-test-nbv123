<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: text/plain; charset=utf-8');

try {
    foreach (['distributors', 'contacts', 'companies'] as $name) {
        if (file_exists(queue_path($config, $name))) {
            throw new RuntimeException('Импорт уже подготовлен. Для нового импорта удали папку data/queues.');
        }
    }

    $spreadsheet = IOFactory::load($config['XLSX_FILE']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray('', true, true, false);

    if (!$rows) {
        throw new RuntimeException('XLSX пуст.');
    }

    $headers = array_map(static fn($value) => clean($value), $rows[0]);
    $required = [
        'Название', 'Основной контакт', 'Тип', 'Отрасль', 'Страна',
        'Ответственный', 'Дистрибьютор', 'Address', 'Address type',
        'Competitor software', 'License expiration date',
    ];

    foreach ($required as $header) {
        if (!in_array($header, $headers, true)) {
            throw new RuntimeException('В XLSX нет колонки: ' . $header);
        }
    }

    $index = array_flip($headers);
    $companies = [];
    $contacts = [];

    foreach (array_slice($rows, 1) as $row) {
        $get = static function (string $name) use ($row, $index): string {
            $value = clean($row[$index[$name]] ?? '');
            return $value === '14' ? '' : $value;
        };

        $name = $get('Название');
        if ($name === '') {
            continue;
        }

        $companyKey = 'company:' . sha1(key_name($name));
        if (!isset($companies[$companyKey])) {
            $companies[$companyKey] = [
                'source_key' => $companyKey,
                'name' => $name,
                'type' => '',
                'industry' => '',
                'country' => '',
                'old_responsible' => [],
                'distributors' => [],
                'contacts' => [],
                'addresses' => [],
                'competitor_software' => [],
                'license_expiration' => '',
            ];
        }

        $company =& $companies[$companyKey];
        $type = $get('Тип');
        $industry = $get('Отрасль');
        $country = $get('Страна');
        $responsible = $get('Ответственный');
        $distributor = $get('Дистрибьютор');
        $contact = $get('Основной контакт');
        $address = $get('Address');
        $addressType = $get('Address type');
        $competitors = multi_values($get('Competitor software'));
        $license = excel_date($row[$index['License expiration date']] ?? '');

        if ($company['type'] === '' && $type !== '') $company['type'] = $type;
        if ($company['industry'] === '' && $industry !== '') $company['industry'] = $industry;
        if ($company['country'] === '' && $country !== '') $company['country'] = $country;
        if ($responsible !== '') add_unique($company['old_responsible'], $responsible);

        if ($distributor !== '') {
            add_unique($company['distributors'], $distributor);
        }

        if ($contact !== '') {
            add_unique($company['contacts'], $contact);
            $contacts[key_name($contact)] = $contact;
        }

        if ($address !== '') {
            $duplicate = false;
            foreach ($company['addresses'] as $oldAddress) {
                if (key_name($oldAddress['value']) === key_name($address)
                    && key_name($oldAddress['type']) === key_name($addressType === '' ? 'Actual' : $addressType)) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $company['addresses'][] = [
                    'value' => $address,
                    'type' => $addressType === '' ? 'Actual' : $addressType,
                    'status' => 'pending',
                    'bitrix_id' => null,
                ];
            }
        }

        foreach ($competitors as $value) {
            add_unique($company['competitor_software'], $value);
        }

        if ($license !== '' && ($company['license_expiration'] === '' || $license > $company['license_expiration'])) {
            $company['license_expiration'] = $license;
        }
        unset($company);
    }

    $distributorNames = [];
    foreach ($companies as $company) {
        foreach ($company['distributors'] as $name) {
            $distributorNames[key_name($name)] = $name;
        }
    }

    $distributorItems = [];
    foreach ($distributorNames as $name) {
        $companyKey = 'company:' . sha1(key_name($name));
        $data = $companies[$companyKey] ?? [
            'source_key' => '',
            'name' => $name,
            'type' => 'Distributor',
            'industry' => '',
            'country' => '',
            'old_responsible' => [],
            'distributors' => [],
            'contacts' => [],
            'addresses' => [],
            'competitor_software' => [],
            'license_expiration' => '',
        ];

        $distributorItems[] = [
            'source_key' => 'distributor:' . sha1(key_name($name)),
            'status' => 'pending',
            'bitrix_id' => null,
            'data' => $data,
        ];
    }

    $contactItems = [];
    foreach ($contacts as $name) {
        $contactItems[] = [
            'source_key' => 'contact:' . sha1(key_name($name)),
            'status' => 'pending',
            'bitrix_id' => null,
            'data' => ['name' => $name],
        ];
    }

    $companyItems = [];
    foreach ($companies as $company) {
        $companyItems[] = [
            'source_key' => $company['source_key'],
            'status' => 'pending',
            'bitrix_id' => null,
            'requisite_id' => null,
            'data' => $company,
        ];
    }

    make_dir($config['QUEUES_DIR']);
    $meta = ['created_at' => date('c'), 'source_file' => basename($config['XLSX_FILE'])];
    save_json(queue_path($config, 'distributors'), $meta + ['queue' => 'distributors', 'items' => $distributorItems]);
    save_json(queue_path($config, 'contacts'), $meta + ['queue' => 'contacts', 'items' => $contactItems]);
    save_json(queue_path($config, 'companies'), $meta + ['queue' => 'companies', 'items' => $companyItems]);

    echo "Готово.\n";
    echo 'Строк XLSX: ' . (count($rows) - 1) . "\n";
    echo 'Уникальных компаний: ' . count($companyItems) . "\n";
    echo 'Дистрибьюторов: ' . count($distributorItems) . "\n";
    echo 'Уникальных контактов: ' . count($contactItems) . "\n";
    echo 'Компаниям адресов: ' . count(array_filter($companyItems, static fn($item) => !empty($item['data']['addresses']))) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ОШИБКА: ' . $e->getMessage() . "\n";
}
