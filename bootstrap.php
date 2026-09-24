<?php

declare(strict_types=1);

function bx_find_responsible(BXConnector $bx, string $firstName, string $lastName): int
{
    $users = $bx->request('user.get', [
        'filter' => [
            'NAME' => $firstName,
            'LAST_NAME' => $lastName,
        ],
        'select' => ['ID', 'NAME', 'LAST_NAME', 'ACTIVE'],
    ]);

    if (!is_array($users)) {
        $users = [];
    }

    foreach ($users as $user) {
        $name = normalize_key((string)($user['NAME'] ?? '') . ' ' . (string)($user['LAST_NAME'] ?? ''));
        if ($name === normalize_key($firstName . ' ' . $lastName)) {
            return (int)$user['ID'];
        }
    }

    throw new RuntimeException('Не найден пользователь: ' . $firstName . ' ' . $lastName);
}

function bx_load_status_map(BXConnector $bx, string $entityId): array
{
    $items = $bx->getList('crm.status.list', [
        'filter' => ['ENTITY_ID' => $entityId],
        'select' => ['STATUS_ID', 'NAME'],
    ]);

    $map = [];
    foreach ((array)$items as $item) {
        $name = clean_value((string)($item['NAME'] ?? ''));
        $statusId = clean_value((string)($item['STATUS_ID'] ?? ''));
        if ($name !== '' && $statusId !== '') {
            $map[normalize_key($name)] = $statusId;
        }
    }
    return $map;
}

function bx_require_status_values(array $map, array $names, string $kind): array
{
    $result = [];
    foreach ($names as $name) {
        $name = clean_value($name);
        if ($name === '') {
            continue;
        }
        $key = normalize_key($name);
        if (!isset($map[$key])) {
            throw new RuntimeException('В Bitrix24 не найдено значение ' . $kind . ': ' . $name);
        }
        $result[$name] = $map[$key];
    }
    return $result;
}

function bx_load_company_userfields(BXConnector $bx): array
{
    $items = $bx->getList('crm.company.userfield.list', [
        'select' => [
            'ID',
            'FIELD_NAME',
            'USER_TYPE_ID',
            'MULTIPLE',
            'LIST',
        ],
    ]);

    $result = [];
    foreach ((array)$items as $item) {
        $fieldName = clean_value((string)($item['FIELD_NAME'] ?? ''));
        if ($fieldName !== '') {
            $result[$fieldName] = $item;
        }
    }
    return $result;
}

function bx_field_description(array $field): string
{
    $parts = [];
    foreach (['ID', 'FIELD_NAME', 'USER_TYPE_ID', 'MULTIPLE'] as $key) {
        if (array_key_exists($key, $field)) {
            $parts[] = $key . '=' . (is_scalar($field[$key]) ? (string)$field[$key] : json_encode($field[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
    if (!empty($field['EDIT_FORM_LABEL'])) {
        $parts[] = 'EDIT_FORM_LABEL=' . (is_scalar($field['EDIT_FORM_LABEL']) ? (string)$field['EDIT_FORM_LABEL'] : json_encode($field['EDIT_FORM_LABEL'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    return implode(', ', $parts);
}

function bx_validate_fields(array $allFields, array $wantedFields): array
{
    $result = [];
    foreach ($wantedFields as $key => $fieldName) {
        if (!isset($allFields[$fieldName])) {
            $available = [];
            foreach ($allFields as $availableName => $field) {
                $available[] = bx_field_description($field);
            }
            sort($available, SORT_NATURAL | SORT_FLAG_CASE);

            $message = "Не найдено пользовательское поле компании {$fieldName} ({$key}).";
            if ($available) {
                $message .= "\n\nПоля, которые реально вернул Bitrix24:\n- " . implode("\n- ", $available);
            } else {
                $message .= "\n\nBitrix24 не вернул ни одного пользовательского поля компании.";
            }
            $message .= "\n\nОткрой /diagnostic.php и пришли его вывод — по нему подберём правильные коды.";
            throw new RuntimeException($message);
        }
        $result[$key] = $allFields[$fieldName];
    }
    return $result;
}

function bx_load_competitor_options(array $field): array
{
    $map = [];
    foreach ((array)($field['LIST'] ?? []) as $option) {
        $value = clean_value((string)($option['VALUE'] ?? ''));
        $id = clean_value((string)($option['ID'] ?? ''));
        if ($value !== '' && $id !== '') {
            $map[normalize_key($value)] = $id;
        }
    }
    return $map;
}

function bx_load_address_types(BXConnector $bx): array
{
    $items = $bx->request('crm.enum.addresstype');
    $map = ['Actual' => null, 'Legal' => null, 'Shipping' => null];

    foreach ((array)$items as $item) {
        $id = (int)($item['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = lower_string((string)($item['NAME'] ?? ''));
        $symbol = lower_string((string)($item['SYMBOL_CODE'] ?? '') . ' ' . (string)($item['SYMBOL_CODE_SHORT'] ?? ''));

        if ($map['Actual'] === null && (strpos($name, 'actual') !== false || strpos($name, 'фактич') !== false || strpos($symbol, 'actual') !== false)) {
            $map['Actual'] = $id;
        }
        if ($map['Legal'] === null && (strpos($name, 'legal') !== false || strpos($name, 'юрид') !== false || strpos($symbol, 'legal') !== false)) {
            $map['Legal'] = $id;
        }
        if ($map['Shipping'] === null && (strpos($name, 'shipping') !== false || strpos($name, 'достав') !== false || strpos($symbol, 'shipping') !== false)) {
            $map['Shipping'] = $id;
        }
    }

    if ($map['Actual'] === null) {
        throw new RuntimeException('Не найден тип адреса Actual.');
    }

    if ($map['Legal'] === null) {
        $map['Legal'] = $map['Actual'];
    }
    if ($map['Shipping'] === null) {
        $map['Shipping'] = $map['Actual'];
    }

    return $map;
}

function bx_load_country_presets(BXConnector $bx): array
{
    $countries = $bx->request('crm.requisite.preset.countries');
    $countryByName = [];
    $countryById = [];

    foreach ((array)$countries as $country) {
        $id = (int)($country['ID'] ?? 0);
        $code = clean_value((string)($country['CODE'] ?? ''));
        $name = clean_value((string)($country['NAME'] ?? ''));
        if ($id > 0) {
            $countryById[$id] = [
                'code' => $code,
                'name' => $name,
            ];
            if ($name !== '') {
                $countryByName[normalize_key($name)] = [
                    'id' => $id,
                    'code' => $code,
                    'name' => $name,
                ];
            }
        }
    }

    $presets = $bx->getList('crm.requisite.preset.list', [
        'filter' => ['ENTITY_TYPE_ID' => 8],
        'select' => ['ID', 'NAME', 'COUNTRY_ID', 'ENTITY_TYPE_ID', 'ACTIVE'],
    ]);

    $presetByCountryId = [];
    $fallback = 0;
    foreach ((array)$presets as $preset) {
        if (($preset['ACTIVE'] ?? 'Y') !== 'Y') {
            continue;
        }
        $id = (int)($preset['ID'] ?? 0);
        $countryId = (int)($preset['COUNTRY_ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if ($fallback === 0) {
            $fallback = $id;
        }
        if ($countryId > 0 && !isset($presetByCountryId[$countryId])) {
            $presetByCountryId[$countryId] = $id;
        }
    }

    if ($fallback === 0) {
        throw new RuntimeException('Не найден ни один активный шаблон реквизита.');
    }

    return [
        'countries' => $countryByName,
        'country_by_id' => $countryById,
        'preset_by_country_id' => $presetByCountryId,
        'fallback_preset_id' => $fallback,
    ];
}

function build_runtime(BXConnector $bx, array $config): array
{
    $responsibleId = bx_find_responsible(
        $bx,
        $config['RESPONSIBLE_FIRST_NAME'],
        $config['RESPONSIBLE_LAST_NAME']
    );

    $typeMap = bx_require_status_values(
        bx_load_status_map($bx, 'COMPANY_TYPE'),
        ['Prospect', 'Customer', 'Possible partner', "Competitor's customer", 'Distributor', 'Partner', 'Competitor', 'Our company'],
        'Тип компании'
    );

    $industryNames = [
        'Hot Forging', 'Research and Education', 'Extrusion', 'Cold Forming', 'Forging',
        'Rolling', 'Ring Rolling', 'Consulting', 'Open Die Forging', 'Equipment', 'Stamping',
        'Rotary Swaging', 'Flow Forming', 'Software development', 'Cross rolling', 'Wheel Rolling',
        'Association',
    ];
    $industryMap = bx_require_status_values(
        bx_load_status_map($bx, 'INDUSTRY'),
        $industryNames,
        'Отрасль'
    );

    $allFields = bx_load_company_userfields($bx);
    $fields = bx_validate_fields($allFields, $config['FIELDS']);

    $competitorOptionMap = bx_load_competitor_options($fields['competitor_software']);
    $addressTypes = bx_load_address_types($bx);
    $countryPresets = bx_load_country_presets($bx);

    return [
        'created_at' => date('c'),
        'responsible_id' => $responsibleId,
        'responsible_name' => $config['RESPONSIBLE_FIRST_NAME'] . ' ' . $config['RESPONSIBLE_LAST_NAME'],
        'type_map' => $typeMap,
        'industry_map' => $industryMap,
        'fields' => $fields,
        'competitor_option_map' => $competitorOptionMap,
        'address_types' => $addressTypes,
        'country_presets' => $countryPresets,
    ];
}
