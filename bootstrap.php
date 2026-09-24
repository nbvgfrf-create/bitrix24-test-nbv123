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

function bx_validate_fields(array $allFields, array $wantedFields): array
{
    $result = [];

    foreach ($wantedFields as $key => $fieldName) {
        if (!isset($allFields[$fieldName])) {
            throw new RuntimeException(
                'Не найдено пользовательское поле компании ' . $fieldName . ' (' . $key . ').'
            );
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
    $map = [
        'Actual' => null,
        'Legal' => null,
        'Shipping' => null,
    ];

    foreach ((array)$items as $item) {
        $id = (int)($item['ID'] ?? 0);

        if ($id <= 0) {
            continue;
        }

        $name = lower_string((string)($item['NAME'] ?? ''));
        $symbol = lower_string(
            (string)($item['SYMBOL_CODE'] ?? '') . ' ' .
            (string)($item['SYMBOL_CODE_SHORT'] ?? '')
        );

        if (
            $map['Actual'] === null &&
            (
                strpos($name, 'actual') !== false ||
                strpos($name, 'фактич') !== false ||
                strpos($symbol, 'actual') !== false
            )
        ) {
            $map['Actual'] = $id;
        }

        if (
            $map['Legal'] === null &&
            (
                strpos($name, 'legal') !== false ||
                strpos($name, 'юрид') !== false ||
                strpos($symbol, 'legal') !== false
            )
        ) {
            $map['Legal'] = $id;
        }

        if (
            $map['Shipping'] === null &&
            (
                strpos($name, 'shipping') !== false ||
                strpos($name, 'достав') !== false ||
                strpos($symbol, 'shipping') !== false
            )
        ) {
            $map['Shipping'] = $id;
        }
    }

    if ($map['Actual'] === null) {
        throw new RuntimeException('Не найден тип адреса «Фактический адрес».');
    }

    if ($map['Legal'] === null) {
        $map['Legal'] = $map['Actual'];
    }

    if ($map['Shipping'] === null) {
        $map['Shipping'] = $map['Actual'];
    }

    return [
        'Actual' => (int)$map['Actual'],
        'Legal' => (int)$map['Legal'],
        'Shipping' => (int)$map['Shipping'],
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
        [
            'Prospect',
            'Customer',
            'Possible partner',
            "Competitor's customer",
            'Distributor',
            'Partner',
            'Competitor',
            'Our company',
        ],
        'Тип компании'
    );

    $industryNames = [
        'Hot Forging',
        'Research and Education',
        'Extrusion',
        'Cold Forming',
        'Forging',
        'Rolling',
        'Ring Rolling',
        'Consulting',
        'Open Die Forging',
        'Equipment',
        'Stamping',
        'Rotary Swaging',
        'Flow Forming',
        'Software development',
        'Cross rolling',
        'Wheel Rolling',
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

    /*
     * Не вызываем crm.requisite.preset.countries и
     * crm.requisite.preset.list во время подготовки runtime.
     * Bitrix24 ограничивает эти методы более узкими правами, чем
     * обычные CRM-операции вебхука.
     *
     * По уже проверенной диагностике:
     * Россия = COUNTRY_ID 1, код RU,
     * шаблон реквизита «Организация» = PRESET_ID 1.
     */
    $countryPresets = [
        'countries' => [
            normalize_key($config['COUNTRY']) => [
                'id' => (int)$config['REQUISITE_COUNTRY_ID'],
                'code' => (string)$config['COUNTRY_CODE'],
                'name' => (string)$config['COUNTRY'],
            ],
        ],
        'preset_by_country_id' => [
            (int)$config['REQUISITE_COUNTRY_ID'] => (int)$config['REQUISITE_PRESET_ID'],
        ],
        'fallback_preset_id' => (int)$config['REQUISITE_PRESET_ID'],
    ];

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
