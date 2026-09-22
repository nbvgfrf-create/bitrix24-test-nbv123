<?php

require_once __DIR__ . '/config.php';

$bx = new Bitrix();

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
            line-height: 1.5;
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
     * Получить существующие значения справочника
     * ---------------------------------------------------------
     */
    function getStatuses(Bitrix $bx, string $entityId): array
    {
        return $bx->list('crm.status.list', [
            'filter' => [
                'ENTITY_ID' => $entityId,
            ],
            'order' => [
                'SORT' => 'ASC',
            ],
        ]);
    }


    /*
     * ---------------------------------------------------------
     * Получить STATUS_ID по названию.
     *
     * Если значения нет — создать.
     *
     * ВАЖНО:
     * сначала проверяем и NAME, и STATUS_ID.
     * Поэтому повторный запуск setup.php не создаёт
     * дубликаты.
     * ---------------------------------------------------------
     */
    function getOrCreateStatus(
        Bitrix $bx,
        string $entityId,
        string $name
    ): string {

        $name = trim($name);

        if ($name === '') {
            return '';
        }

        $statuses = getStatuses($bx, $entityId);

        foreach ($statuses as $status) {

            $statusName = trim((string)($status['NAME'] ?? ''));

            if (
                mb_strtolower($statusName) ===
                mb_strtolower($name)
            ) {
                return (string)$status['STATUS_ID'];
            }
        }


        /*
         * Создаём уникальный STATUS_ID.
         *
         * Для наших справочников можно использовать
         * простой латинский код.
         */
        $baseId = slug($name);

        if ($baseId === '') {
            $baseId = 'VALUE';
        }

        $statusId = strtoupper($baseId);

        $existingIds = [];

        foreach ($statuses as $status) {
            if (!empty($status['STATUS_ID'])) {
                $existingIds[] = strtoupper(
                    (string)$status['STATUS_ID']
                );
            }
        }

        /*
         * Если такой STATUS_ID уже существует,
         * добавляем номер.
         */
        $originalId = $statusId;
        $number = 2;

        while (in_array($statusId, $existingIds, true)) {
            $statusId = $originalId . '_' . $number;
            $number++;
        }


        $result = $bx->call('crm.status.add', [
            'fields' => [
                'ENTITY_ID' => $entityId,
                'STATUS_ID' => $statusId,
                'NAME' => $name,
                'SORT' => 500,
            ],
        ]);

        return (string)$statusId;
    }


    /*
     * ---------------------------------------------------------
     * 1. Получаем реальные значения из Excel
     * ---------------------------------------------------------
     *
     * Типы и отрасли не придумываем вручную.
     * Берём их из companies (5).xlsx.
     */

    $excelFile = EXCEL_FILE;

    if (!file_exists($excelFile)) {
        throw new Exception(
            'Не найден Excel-файл: ' . $excelFile
        );
    }


    /*
     * PhpSpreadsheet установлен в Docker.
     */
    require_once __DIR__ . '/vendor/autoload.php';

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(
        $excelFile
    );

    $sheet = $spreadsheet->getActiveSheet();

    $highestRow = $sheet->getHighestRow();


    $companyTypes = [];
    $industries = [];
    $competitorValues = [];


    /*
     * Колонки Excel:
     *
     * A = Название
     * B = Основной контакт
     * C = Тип
     * D = Отрасль
     * ...
     * L = Competitor software
     */
    for ($row = 2; $row <= $highestRow; $row++) {

        $type = clean(
            $sheet->getCell('C' . $row)->getValue()
        );

        $industry = clean(
            $sheet->getCell('D' . $row)->getValue()
        );

        $competitor = clean(
            $sheet->getCell('L' . $row)->getValue()
        );


        if ($type !== '' && !in_array(
                $type,
                $companyTypes,
                true
            )) {
            $companyTypes[] = $type;
        }


        if ($industry !== '' && !in_array(
                $industry,
                $industries,
                true
            )) {
            $industries[] = $industry;
        }


        foreach (splitValues($competitor) as $value) {

            if (!in_array(
                $value,
                $competitorValues,
                true
            )) {
                $competitorValues[] = $value;
            }
        }
    }


    echo '<p class="info">';
    echo 'Найдено типов компаний: ' . count($companyTypes);
    echo '<br>';
    echo 'Найдено отраслей: ' . count($industries);
    echo '<br>';
    echo 'Найдено значений Competitor software: ' .
        count($competitorValues);
    echo '</p>';


    /*
     * ---------------------------------------------------------
     * 2. COMPANY_TYPE
     * ---------------------------------------------------------
     */
    $companyTypeMap = [];

    foreach ($companyTypes as $name) {

        $statusId = getOrCreateStatus(
            $bx,
            'COMPANY_TYPE',
            $name
        );

        $companyTypeMap[$name] = $statusId;
    }

    echo '<p class="ok">';
    echo '✓ COMPANY_TYPE проверен';
    echo '</p>';


    /*
     * ---------------------------------------------------------
     * 3. INDUSTRY
     * ---------------------------------------------------------
     */
    $industryMap = [];

    foreach ($industries as $name) {

        $statusId = getOrCreateStatus(
            $bx,
            'INDUSTRY',
            $name
        );

        $industryMap[$name] = $statusId;
    }

    echo '<p class="ok">';
    echo '✓ INDUSTRY проверен';
    echo '</p>';


    /*
     * ---------------------------------------------------------
     * 4. Получаем существующие пользовательские поля
     * ---------------------------------------------------------
     */
    $existingFields = $bx->list(
        'crm.company.userfield.list',
        [
            'order' => [
                'ID' => 'ASC',
            ],
        ]
    );

    $fieldMap = [];

    foreach ($existingFields as $field) {

        if (!empty($field['FIELD_NAME'])) {

            $fieldMap[
            $field['FIELD_NAME']
            ] = $field;
        }
    }


    /*
     * ---------------------------------------------------------
     * Создание пользовательского поля
     * ---------------------------------------------------------
     */
    function createCompanyField(
        Bitrix $bx,
        array &$fieldMap,
        string $fieldName,
        string $label,
        string $type,
        bool $multiple = false,
        array $list = []
    ): array {

        /*
         * Bitrix сам добавляет UF_CRM_.
         *
         * Поэтому в API передаём только имя поля
         * без UF_CRM_.
         */
        $fullName = 'UF_CRM_' . $fieldName;


        /*
         * Уже существует?
         */
        if (isset($fieldMap[$fullName])) {

            echo '<p class="ok">';
            echo '✓ Поле «' .
                htmlspecialchars($label) .
                '» уже существует';
            echo '</p>';

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


        /*
         * Список.
         */
        if (
            $type === 'enumeration' &&
            !empty($list)
        ) {

            $fields['LIST'] = $list;

            $fields['SETTINGS'] = [
                'DISPLAY' => 'UI',
                'LIST_HEIGHT' => 5,
            ];
        }


        /*
         * CRM-связь с компаниями.
         */
        if ($type === 'crm') {

            $fields['SETTINGS'] = [
                'CONTACT' => 'N',
                'COMPANY' => 'Y',
                'LEAD' => 'N',
                'DEAL' => 'N',
            ];
        }


        $id = $bx->call(
            'crm.company.userfield.add',
            [
                'fields' => $fields,
            ]
        );


        /*
         * После создания снова получаем поле,
         * чтобы сохранить его реальные данные.
         */
        $createdFields = $bx->list(
            'crm.company.userfield.list',
            [
                'filter' => [
                    'ID' => $id,
                ],
            ]
        );


        if (!empty($createdFields[0])) {

            $field = $createdFields[0];

        } else {

            $field = [
                'ID' => $id,
                'FIELD_NAME' => $fullName,
                'EDIT_FORM_LABEL' => [
                    'ru' => $label,
                ],
            ];
        }


        $fieldMap[$fullName] = $field;


        echo '<p class="ok">';
        echo '✓ Поле «' .
            htmlspecialchars($label) .
            '» создано';
        echo '</p>';


        return $field;
    }


    /*
     * ---------------------------------------------------------
     * 5. Страна
     * ---------------------------------------------------------
     */
    $countryField = createCompanyField(
        $bx,
        $fieldMap,
        'COUNTRY_IMPORT',
        'Страна',
        'string'
    );


    /*
     * ---------------------------------------------------------
     * 6. Старый ответственный
     * ---------------------------------------------------------
     */
    $oldResponsibleField = createCompanyField(
        $bx,
        $fieldMap,
        'OLD_RESPONSIBLE',
        'Старый ответственный',
        'string'
    );


    /*
     * ---------------------------------------------------------
     * 7. Дистрибьютор
     * ---------------------------------------------------------
     */
    $distributorField = createCompanyField(
        $bx,
        $fieldMap,
        'DISTRIBUTOR',
        'Дистрибьютор',
        'crm',
        true
    );


    /*
     * ---------------------------------------------------------
     * 8. Competitor software
     * ---------------------------------------------------------
     */
    $competitorList = [];

    foreach (
        $competitorValues as $index => $value
    ) {

        $competitorList[] = [
            'VALUE' => $value,
            'SORT' => ($index + 1) * 10,
            'XML_ID' => md5($value),
        ];
    }


    $competitorField = createCompanyField(
        $bx,
        $fieldMap,
        'COMPETITOR_SOFTWARE',
        'Competitor software',
        'enumeration',
        true,
        $competitorList
    );


    /*
     * ---------------------------------------------------------
     * 9. License expiration date
     * ---------------------------------------------------------
     */
    $licenseDateField = createCompanyField(
        $bx,
        $fieldMap,
        'LICENSE_EXPIRATION_DATE',
        'License expiration date',
        'date'
    );


    /*
     * ---------------------------------------------------------
     * 10. Ищем Тимофея Жмаева
     * ---------------------------------------------------------
     */
    $users = $bx->list(
        'user.search',
        [
            'FILTER' => [
                'NAME' => 'Тимофей',
                'LAST_NAME' => 'Жмаев',
            ],
        ]
    );


    $responsibleId = '';

    foreach ($users as $user) {

        $fullName = trim(
            ($user['NAME'] ?? '') . ' ' .
            ($user['LAST_NAME'] ?? '')
        );

        if (
            mb_strtolower($fullName) ===
            mb_strtolower('Тимофей Жмаев')
        ) {
            $responsibleId = (string)$user['ID'];
            break;
        }
    }


    if ($responsibleId !== '') {

        echo '<p class="ok">';
        echo '✓ Тимофей Жмаев найден. ID: ' .
            htmlspecialchars($responsibleId);
        echo '</p>';

    } else {

        echo '<p class="info">';
        echo '⚠ Тимофей Жмаев не найден автоматически.';
        echo '</p>';
    }


    /*
     * ---------------------------------------------------------
     * 11. Сохраняем setup.json
     * ---------------------------------------------------------
     */
    $setup = [
        'company_types' => $companyTypeMap,

        'industries' => $industryMap,

        'fields' => [
            'country' =>
                $countryField['FIELD_NAME'],

            'old_responsible' =>
                $oldResponsibleField['FIELD_NAME'],

            'distributor' =>
                $distributorField['FIELD_NAME'],

            'competitor_software' =>
                $competitorField['FIELD_NAME'],

            'license_expiration_date' =>
                $licenseDateField['FIELD_NAME'],
        ],

        'responsible' => [
            'name' => 'Тимофей Жмаев',
            'id' => $responsibleId,
        ],

        'competitor_values' =>
            $competitorValues,

        'created_at' =>
            date('Y-m-d H:i:s'),
    ];


    saveJson(
        __DIR__ . '/setup.json',
        $setup
    );


    echo '<hr>';

    echo '<h2 class="ok">';
    echo 'Настройка завершена';
    echo '</h2>';

    echo '<p>';
    echo 'Файл setup.json сохранён.';
    echo '</p>';

    echo '<p>';
    echo 'Следующий шаг: ';
    echo '<a href="xlsx_to_json.php">';
    echo 'xlsx_to_json.php';
    echo '</a>';
    echo '</p>';

    echo '<p class="info">';
    echo 'После проверки настройки setup.php можно удалить.';
    echo '</p>';


} catch (Throwable $e) {

    echo '<h2 class="error">';
    echo 'ОШИБКА';
    echo '</h2>';

    echo '<pre class="error">';
    echo htmlspecialchars(
        $e->getMessage(),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    echo '</pre>';
}

echo '</body>';
echo '</html>';