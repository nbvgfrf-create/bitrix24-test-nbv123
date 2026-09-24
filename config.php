<?php

declare(strict_types=1);

return [
    // Временный вебхук для разработки. После импорта его нужно заменить.
    'BITRIX_HOOK' => 'https://b24-ef9noe.bitrix24.ru/rest/1/55yppqh1b90kq575/',
    'XLSX_FILE' => __DIR__ . '/companies (5).xlsx',

    // Все созданные записи назначаем на Тимофея Жмаева.
    'RESPONSIBLE_ID' => 1,

    // Пользовательские поля компаний из твоего Bitrix24.
    'FIELDS' => [
        'country' => 'UF_CRM_COUNTRY_IMPORT',
        'old_responsible' => 'UF_CRM_OLD_RESPONSIBLE',
        'distributor' => 'UF_CRM_DISTRIBUTOR',
        'competitor_software' => 'UF_CRM_COMPETITOR_SOFTWARE',
        'license_expiration' => 'UF_CRM_LICENSE_EXPIRATION_DATE',
    ],

    // Варианты поля Competitor software.
    'COMPETITOR_OPTIONS' => [
        'Simufact' => '94',
        'Deform' => '96',
        'Forge' => '98',
        'HyperXtrude' => '100',
        'Hyper Extrude' => '102',
    ],

    // Значения списков Bitrix24, уже проверенные на этом портале.
    'COMPANY_TYPES' => [
        'Prospect' => 'PROSPECT',
        'Possible partner' => 'POSSIBLE_PARTNER',
        'Partner' => 'PARTNER_2',
        'Our company' => 'OUR_COMPANY',
        'Distributor' => 'DISTRIBUTOR',
        'Customer' => 'CUSTOMER_2',
        "Competitor's customer" => 'COMPETITOR_S_CUSTOMER',
        'Competitor' => 'COMPETITOR_2',
    ],

    'INDUSTRIES' => [
        'Hot Forging' => 'HOT_FORGING',
        'Research and Education' => 'RESEARCH_AND_EDUCATION',
        'Extrusion' => 'EXTRUSION',
        'Cold Forming' => 'COLD_FORMING',
        'Forging' => 'FORGING',
        'Rolling' => 'ROLLING',
        'Ring Rolling' => 'RING_ROLLING',
        'Consulting' => 'CONSULTING_2',
        'Open Die Forging' => 'OPEN_DIE_FORGING',
        'Equipment' => 'EQUIPMENT',
        'Stamping' => 'STAMPING',
        'Rotary Swaging' => 'ROTARY_SWAGING',
        'Flow Forming' => 'FLOW_FORMING',
        'Software development' => 'SOFTWARE_DEVELOPMENT',
        'Cross rolling' => 'CROSS_ROLLING',
        'Wheel Rolling' => 'WHEEL_ROLLING',
        'Association' => 'ASSOCIATION',
    ],

    // ID типов адресов на этом портале.
    'ADDRESS_TYPES' => [
        'Actual' => 1,
        'Legal' => 6,
        'Shipping' => 11,
    ],

    // Реквизиты для адресов: Россия / Организация.
    'REQUISITE_COUNTRY_NAME' => 'Россия',
    'REQUISITE_COUNTRY_CODE' => 'RU',
    'REQUISITE_COUNTRY_ID' => 1,
    'REQUISITE_PRESET_ID' => 1,

    // Один worker держим недолго, затем браузер запускает следующий цикл.
    'WORKER_SECONDS' => 50,
    'BATCH_SIZE' => 50,
    'REQUEST_INTERVAL' => 0.10,
    'HTTP_CONNECT_TIMEOUT' => 5,
    'HTTP_TIMEOUT' => 60,

    'DATA_DIR' => __DIR__ . '/data',
    'QUEUES_DIR' => __DIR__ . '/data/queues',
    'LOCK_FILE' => __DIR__ . '/data/worker.lock',
];
