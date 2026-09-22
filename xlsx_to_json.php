<?php
require __DIR__ . '/config.php';

if (!file_exists(EXCEL_FILE)) {
    exit("Не найден файл: " . EXCEL_FILE . PHP_EOL);
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    exit("Нет PhpSpreadsheet. Запусти Docker: docker compose build --no-cache && docker compose up -d" . PHP_EOL);
}
require $autoload;

use PhpOffice\PhpSpreadsheet\IOFactory;

$spreadsheet = IOFactory::load(EXCEL_FILE);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);
$headers = array_shift($rows);
$headers = array_map('trim', $headers);

$companies = [];
$contacts = [];
$distributors = [];

foreach ($rows as $row) {
    $data = [];
    foreach ($headers as $column => $header) {
        $data[$header] = $row[$column] ?? '';
    }

    $companyName = clean($data['Название'] ?? '');
    if ($companyName === '') {
        continue;
    }

    // Все компании в этом импорте получают страну Россия.
    $companyKey = normalize($companyName);
    if (!isset($companies[$companyKey])) {
        $companies[$companyKey] = [
            'key' => $companyKey,
            'status' => 'pending',
            'bitrix_id' => null,
            'name' => $companyName,
            'type' => clean($data['Тип'] ?? ''),
            'industry' => clean($data['Отрасль'] ?? ''),
            'country' => 'Россия',
            'old_responsible' => clean($data['Ответственный'] ?? ''),
            'distributors' => [],
            'contact_keys' => [],
            'address' => clean($data['Address'] ?? ''),
            'address_type' => clean($data['Address type'] ?? ''),
            'competitor_software' => [],
            'license_expiration_date' => dateValue($data['License expiration date'] ?? ''),
        ];
    }

    $company =& $companies[$companyKey];

    $distributor = clean($data['Дистрибьютор'] ?? '');
    if ($distributor !== '') {
        $dKey = normalize($distributor);
        $distributors[$dKey] = [
            'key' => $dKey,
            'status' => 'pending',
            'bitrix_id' => null,
            'name' => $distributor,
        ];
        if (!in_array($dKey, $company['distributors'], true)) {
            $company['distributors'][] = $dKey;
        }
    }

    $software = splitValues($data['Competitor software'] ?? '');
    foreach ($software as $value) {
        if (!in_array($value, $company['competitor_software'], true)) {
            $company['competitor_software'][] = $value;
        }
    }

    $date = dateValue($data['License expiration date'] ?? '');
    if ($date !== '') {
        $company['license_expiration_date'] = $date;
    }

    if ($company['address'] === '' && clean($data['Address'] ?? '') !== '') {
        $company['address'] = clean($data['Address']);
        $company['address_type'] = clean($data['Address type'] ?? '');
    }

    $contactName = clean($data['Основной контакт'] ?? '');
    if ($contactName !== '') {
        $contactKey = personKey($contactName);
        if ($contactKey !== '') {
            if (!isset($contacts[$contactKey])) {
                $contacts[$contactKey] = [
                    'key' => $contactKey,
                    'status' => 'pending',
                    'bitrix_id' => null,
                    'name' => $contactName,
                    'company_keys' => [],
                ];
            }
            if (!in_array($companyKey, $contacts[$contactKey]['company_keys'], true)) {
                $contacts[$contactKey]['company_keys'][] = $companyKey;
            }
            if (!in_array($contactKey, $company['contact_keys'], true)) {
                $company['contact_keys'][] = $contactKey;
            }
        }
    }
    unset($company);
}

saveJson(QUEUE_DIR . '/distributors.json', array_values($distributors));
saveJson(QUEUE_DIR . '/contacts.json', array_values($contacts));
saveJson(QUEUE_DIR . '/companies.json', array_values($companies));

$result = sprintf(
    "Готово. Дистрибьюторов: %d, контактов: %d, компаний: %d\n",
    count($distributors), count($contacts), count($companies)
);
logMessage(trim($result));
echo $result;
