<?php

declare(strict_types=1);

return [
    // На Render используется уже заданная Environment Variable BITRIX_HOOK.
    // Секрет вебхука намеренно не хранится в Git-репозитории.
    'BITRIX_HOOK' => getenv('BITRIX_HOOK') ?: '',

    'XLSX_FILE' => __DIR__ . '/companies (5).xlsx',

    'RESPONSIBLE_FIRST_NAME' => 'Тимофей',
    'RESPONSIBLE_LAST_NAME' => 'Жмаев',

    'FIELDS' => [
        'country' => 'UF_CRM_COUNTRY_IMPORT',
        'old_responsible' => 'UF_CRM_OLD_RESPONSIBLE',
        'distributor' => 'UF_CRM_DISTRIBUTOR',
        'competitor_software' => 'UF_CRM_COMPETITOR_SOFTWARE',
        'license_expiration' => 'UF_CRM_LICENSE_EXPIRATION_DATE',
    ],

    'WORKER_BUDGET_SECONDS' => 70,
    'BATCH_SIZE' => 5,
    'ADDRESS_BATCH_SIZE' => 20,
    'MAX_ATTEMPTS' => 5,

    'HTTP_CONNECT_TIMEOUT' => 5,
    'HTTP_TIMEOUT' => 80,
    'REQUEST_INTERVAL' => 0.10,

    'QUEUES_DIR' => __DIR__ . '/queues',
    'LOGS_DIR' => __DIR__ . '/logs',
    'LOCKS_DIR' => __DIR__ . '/locks',
    'RUNTIME_FILE' => __DIR__ . '/runtime.json',
    'RUN_LOCK' => __DIR__ . '/locks/worker.lock',

    'QUEUES' => [
        'distributors' => __DIR__ . '/queues/distributors.json',
        'contacts' => __DIR__ . '/queues/contacts.json',
        'companies' => __DIR__ . '/queues/companies.json',
    ],
];
