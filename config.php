<?php

declare(strict_types=1);

return [
    'BITRIX_HOOK' => 'https://YOUR-DOMAIN.bitrix24.ru/rest/USER_ID/WEBHOOK_KEY/',
    'XLSX_FILE' => __DIR__ . '/companies (5).xlsx',

    'RESPONSIBLE_FIRST_NAME' => 'Тимофей',
    'RESPONSIBLE_LAST_NAME' => 'Жмаев',

    // ВАЖНО: это именно названия уже существующих пользовательских полей компаний.
    'FIELDS' => [
        'country' => 'UF_CRM_IMPORT_COUNTRY',
        'old_responsible' => 'UF_CRM_IMPORT_OLD_RESPONSIBLE',
        'distributor' => 'UF_CRM_IMPORT_DISTRIBUTOR',
        'competitor_software' => 'UF_CRM_IMPORT_COMPETITOR_SOFTWARE',
        'license_expiration' => 'UF_CRM_IMPORT_LICENSE_EXPIRATION',
    ],

    // Параметры worker.
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
