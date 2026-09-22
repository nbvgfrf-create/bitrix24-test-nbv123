<?php
require __DIR__ . '/config.php';

$b = new Bitrix();
$message = [];

try {
    $users = $b->list('user.get', ['FILTER' => ['NAME' => 'Тимофей', 'LAST_NAME' => 'Жмаев']]);
    if (!$users) {
        throw new Exception('Не найден пользователь Тимофей Жмаев. Проверь имя и фамилию в Bitrix24.');
    }
    $config = ['responsible_id' => (int)$users[0]['ID']];

    $types = $b->list('crm.status.list', ['filter' => ['ENTITY_ID' => 'COMPANY_TYPE']]);
    $typeIds = [];
    foreach ($types as $type) {
        $typeIds[$type['NAME']] = $type['STATUS_ID'];
    }

    $neededTypes = [
        'Prospect', 'Customer', 'Possible partner', 'Competitor\'s customer',
        'Distributor', 'Partner', 'Competitor', 'Our company'
    ];

    foreach ($neededTypes as $name) {
        if (!isset($typeIds[$name])) {
            $id = strtoupper(slug($name));
            $b->call('crm.status.add', ['fields' => [
                'ENTITY_ID' => 'COMPANY_TYPE',
                'STATUS_ID' => $id,
                'NAME' => $name,
            ]]);
            $typeIds[$name] = $id;
        }
    }
    $config['company_types'] = $typeIds;

    $fields = $b->list('crm.company.userfield.list', ['filter' => ['LANG' => 'ru']]);
    $byName = [];
    foreach ($fields as $field) {
        $byName[$field['FIELD_NAME']] = $field;
    }

    $create = function ($code, $title, $type, $multiple = 'N', $settings = []) use (&$byName, $b, &$message) {
        $full = 'UF_CRM_' . $code;
        if (isset($byName[$full])) {
            $message[] = "Поле $full уже существует";
            return $byName[$full];
        }

        $params = [
            'FIELD_NAME' => $code,
            'USER_TYPE_ID' => $type,
            'MULTIPLE' => $multiple,
            'EDIT_FORM_LABEL' => ['ru' => $title],
            'LIST_COLUMN_LABEL' => ['ru' => $title],
            'LIST_FILTER_LABEL' => ['ru' => $title],
        ];
        if ($settings) {
            $params['SETTINGS'] = $settings;
        }

        $id = $b->call('crm.company.userfield.add', ['fields' => $params]);
        $message[] = "Создано поле $full (ID $id)";
        return ['ID' => $id, 'FIELD_NAME' => $full];
    };

    $create('IMPORT_COUNTRY', 'Страна', 'string');
    $create('OLD_RESPONSIBLE', 'Старый ответственный', 'string');
    $create('DISTRIBUTOR', 'Дистрибьютор', 'crm', 'Y', [
        'LEAD' => 'N', 'CONTACT' => 'N', 'COMPANY' => 'Y', 'DEAL' => 'N'
    ]);

    // Список Competitor software берём прямо из Excel.
    $software = ['Simufact', 'Deform', 'Forge', 'HyperXtrude', 'Hyper Extrude'];
    $list = [];
    foreach ($software as $value) {
        $list[] = ['VALUE' => $value, 'DEF' => 'N'];
    }
    $create('COMPETITOR_SOFTWARE', 'Competitor software', 'enumeration', 'Y', [
        'DISPLAY' => 'CHECKBOX',
        'LIST' => $list,
    ]);

    $create('LICENSE_EXPIRATION', 'License expiration date', 'date');

    saveJson(__DIR__ . '/setup.json', $config);
    $message[] = 'Настройка завершена. setup.php после проверки можно удалить.';
} catch (Throwable $e) {
    $message[] = 'ОШИБКА: ' . $e->getMessage();
}

if (PHP_SAPI !== 'cli') {
    echo '<pre>' . htmlspecialchars(implode("\n", $message)) . '</pre>';
}
