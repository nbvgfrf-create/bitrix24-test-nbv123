<?php

require_once __DIR__ . '/config.php';

echo '<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Bitrix24 Setup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .ok {
            color: green;
        }
        .error {
            color: red;
        }
        .info {
            color: #555;
        }
        pre {
            background: #f5f5f5;
            padding: 15px;
            overflow: auto;
        }
    </style>
</head>
<body>
<h1>Настройка Bitrix24</h1>';

try {
    /*
     * ---------------------------------------------------------
     * 1. Получаем существующие типы компаний
     * ---------------------------------------------------------
     *
     * Если значение уже существует — используем его.
     * Если нет — создаём.
     */
    function getOrCreateStatus(string $entityId, string $name): string
    {
        $statuses = bitrix('crm.status.list', [
            'filter' => [
                'ENTITY_ID' => $entityId,
            ],
            'order' => [
                'SORT' => 'ASC',
            ],
        ]);

        foreach ($statuses as $status) {
            if (
                mb_strtolower(trim($status['NAME'])) ===
                mb_strtolower(trim($name))
            ) {
                return $status['STATUS_ID'];
            }
        }

        $statusId = strtoupper(
            preg_replace(
                '/[^A-Za-z0-9_]+/',
                '_',
                transliterate($name)
            )
        );

        if ($statusId === '') {
            $statusId = 'VALUE_' . time();
        }

        $result = bitrix('crm.status.add', [
            'fields' => [
                'ENTITY_ID' => $entityId,
                'STATUS_ID' => $statusId,
                'NAME' => $name,
                'SORT' => 500,
            ],
        ]);

        return $result;
    }

    /*
     * Простая транслитерация для STATUS_ID.
     */
    function transliterate(string $text): string
    {
        $map = [
            'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E',
            'Ж'=>'ZH','З'=>'Z','И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M',
            'Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U',
            'Ф'=>'F','Х'=>'H','Ц'=>'C','Ч'=>'CH','Ш'=>'SH','Щ'=>'SCH',
            'Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'YU','Я'=>'YA',
        ];

        return strtr(mb_strtoupper($text), $map);
    }

    /*
     * ---------------------------------------------------------
     * 2. Типы компаний
     * ---------------------------------------------------------
     */
    $companyTypes = [
        'Клиент',
        'Партнер',
        'Поставщик',
        'Конкурент',
        'Инвестор',
        'Другое',
    ];

    $companyTypeMap = [];

    foreach ($companyTypes as $name) {
        $companyTypeMap[$name] = getOrCreateStatus(
            'COMPANY_TYPE',
            $name
        );
    }

    echo '<p class="ok">✓ Типы компаний проверены</p>';


    /*
     * ---------------------------------------------------------
     * 3. Отрасли
     * ---------------------------------------------------------
     *
     * Пока создаём только те значения, которые были нужны
     * в исходном импорте.
     *
     * Если значение уже есть — повторно не создаём.
     */
    $industries = [
        'Машиностроение',
        'Металлургия',
        'Авиационная промышленность',
        'Автомобильная промышленность',
        'Образование',
        'Наука',
        'IT',
        'Другое',
    ];

    $industryMap = [];

    foreach ($industries as $name) {
        $industryMap[$name] = getOrCreateStatus(
            'INDUSTRY',
            $name
        );
    }

    echo '<p class="ok">✓ Отрасли проверены</p>';


    /*
     * ---------------------------------------------------------
     * 4. Получаем существующие пользовательские поля
     * ---------------------------------------------------------
     */
    $existingFields = bitrix('crm.company.userfield.list', [
        'order' => [
            'ID' => 'ASC',
        ],
    ]);

    $fieldMap = [];

    foreach ($existingFields as $field) {
        if (!empty($field['FIELD_NAME'])) {
            $fieldMap[$field['FIELD_NAME']] = $field;
        }
    }


    /*
     * ---------------------------------------------------------
     * Создание пользовательского поля
     * ---------------------------------------------------------
     */
    function createCompanyField(
        string $fieldName,
        string $label,
        string $type,
        bool $multiple = false,
        array $list = [],
        array $settings = []
    ): array {
        global $fieldMap;

        $fullName = 'UF_CRM_' . $fieldName;

        if (isset($fieldMap[$fullName])) {
            return $fieldMap[$fullName];
        }

        $fields = [
            'FIELD_NAME' => $fieldName,
            'EDIT_FORM_LABEL' => [
                'ru' => $label,
            ],
            'LIST_COLUMN_LABEL' => [
                'ru' => $label,
            ],
            'LIST_FILTER_LABEL' => [
                'ru' => $label,
            ],
            'USER_TYPE_ID' => $type,
            'MULTIPLE' => $multiple ? 'Y' : 'N',
            'MANDATORY' => 'N',
            'SHOW_FILTER' => 'Y',
            'SHOW_IN_LIST' => 'Y',
            'EDIT_IN_LIST' => 'Y',
        ];

        if ($type === 'enumeration' && $list) {
            $fields['LIST'] = $list;
            $fields['SETTINGS'] = $settings ?: [
                'DISPLAY' => 'UI',
                'LIST_HEIGHT' => 5,
            ];
        }

        if ($type === 'crm') {
            $fields['SETTINGS'] = [
                'CONTACT' => 'N',
                'COMPANY' => 'Y',
                'LEAD' => 'N',
                'DEAL' => 'N',
            ];
        }

        $id = bitrix('crm.company.userfield.add', [
            'fields' => $fields,
        ]);

        $field['ID'] = $id;
        $field['FIELD_NAME'] = $fullName;
        $field['LABEL'] = $label;

        $fieldMap[$fullName] = $field;

        return $field;
    }


    /*
     * ---------------------------------------------------------
     * 5. Создаём наши пользовательские поля
     * ---------------------------------------------------------
     */

    $countryField = createCompanyField(
        'COUNTRY_IMPORT',
        'Страна',
        'string'
    );

    echo '<p class="ok">✓ Поле «Страна» проверено</p>';


    $oldResponsibleField = createCompanyField(
        'OLD_RESPONSIBLE',
        'Старый ответственный',
        'string'
    );

    echo '<p class="ok">✓ Поле «Старый ответственный» проверено</p>';


    $distributorField = createCompanyField(
        'DISTRIBUTOR',
        'Дистрибьютор',
        'crm',
        true
    );

    echo '<p class="ok">✓ Поле «Дистрибьютор» проверено</p>';


    /*
     * Competitor software
     *
     * Пока используем значения из исходного Excel.
     */
    $competitorValues = [
        'Simufact',
        'Deform',
        'Forge',
        'HyperXtrude',
        'Hyper Extrude',
        'Другие',
    ];

    $competitorList = [];

    foreach ($competitorValues as $index => $value) {
        $competitorList[] = [
            'VALUE' => $value,
            'SORT' => ($index + 1) * 10,
            'XML_ID' => md5($value),
        ];
    }

    $competitorField = createCompanyField(
        'COMPETITOR_SOFTWARE',
        'Competitor software',
        'enumeration',
        true,
        $competitorList
    );

    echo '<p class="ok">✓ Поле «Competitor software» проверено</p>';


    $licenseDateField = createCompanyField(
        'LICENSE_EXPIRATION_DATE',
        'License expiration date',
        'date'
    );

    echo '<p class="ok">✓ Поле «License expiration date» проверено</p>';


    /*
     * ---------------------------------------------------------
     * 6. Сохраняем настройки
     * ---------------------------------------------------------
     */
    $setup = [
        'company_types' => $companyTypeMap,
        'industries' => $industryMap,

        'fields' => [
            'country' => $countryField['FIELD_NAME'],
            'old_responsible' => $oldResponsibleField['FIELD_NAME'],
            'distributor' => $distributorField['FIELD_NAME'],
            'competitor_software' => $competitorField['FIELD_NAME'],
            'license_expiration_date' => $licenseDateField['FIELD_NAME'],
        ],

        'responsible_name' => 'Тимофей Жмаев',

        'created_at' => date('Y-m-d H:i:s'),
    ];

    file_put_contents(
        __DIR__ . '/setup.json',
        json_encode(
            $setup,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )
    );


    echo '<hr>';
    echo '<h2 class="ok">Настройка завершена</h2>';

    echo '<p>Теперь можно перейти к:</p>';

    echo '<ol>
        <li>xlsx_to_json.php</li>
        <li>worker.php</li>
    </ol>';

    echo '<p class="info">
        setup.php после этого можно удалить из проекта.
        Файл setup.json нужен для дальнейшей работы импорта.
    </p>';

} catch (Throwable $e) {

    echo '<h2 class="error">ОШИБКА</h2>';

    echo '<pre class="error">';
    echo htmlspecialchars($e->getMessage());
    echo '</pre>';
}

echo '</body></html>';