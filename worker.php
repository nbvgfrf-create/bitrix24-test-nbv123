<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

require_once __DIR__ . '/BXConnector.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/JsonStore.php';
require_once __DIR__ . '/ImportHelpers.php';
require_once __DIR__ . '/distributors.php';
require_once __DIR__ . '/contacts.php';
require_once __DIR__ . '/companies.php';

use BX\BXConnector;
use BX\Logger;


/*
|--------------------------------------------------------------------------
| Режим работы
|--------------------------------------------------------------------------
|
| /worker.php
|   -> HTML-страница с автоматическим запуском
|
| /worker.php?run=1
|   -> один рабочий цикл
|
| CLI
|   -> один рабочий цикл
|
*/

$isWeb = PHP_SAPI !== 'cli';

$isRunRequest =
    !$isWeb ||
    isset($_GET['run']);

if ($isRunRequest && $isWeb) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}


/*
|--------------------------------------------------------------------------
| Вывод
|--------------------------------------------------------------------------
*/

function worker_out(string $message): void
{
    echo $message . PHP_EOL;

    if (function_exists('ob_flush')) {
        @ob_flush();
    }

    if (function_exists('flush')) {
        @flush();
    }
}


/*
|--------------------------------------------------------------------------
| Поиск ответственного
|--------------------------------------------------------------------------
*/

function find_responsible(
    BXConnector $bx,
    string $firstName,
    string $lastName
): ?array {
    $users = $bx->getList('user.get', [
        'filter' => [
            'NAME' => $firstName,
            'LAST_NAME' => $lastName,
        ],
        'select' => [
            'ID',
            'NAME',
            'LAST_NAME',
            'ACTIVE',
        ],
    ]);

    if (!$users) {
        $users = $bx->getList('user.search', [
            'FILTER' => [
                'NAME_SEARCH' => $firstName . ' ' . $lastName,
            ],
        ]);
    }

    foreach ($users as $user) {
        $name = normalize_key(
            ($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? '')
        );

        $wanted = normalize_key(
            $firstName . ' ' . $lastName
        );

        if ($name === $wanted) {
            return $user;
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Получение существующих стандартных значений Bitrix
|--------------------------------------------------------------------------
|
| setup.php может создавать значения.
| worker.php этого НЕ делает.
|
*/

function resolve_existing_status_values(
    BXConnector $bx,
    string $entityId,
    array $names
): array {
    $existing = $bx->request(
        'crm.status.entity.items',
        [
            'entityId' => $entityId,
        ]
    );

    $byName = [];

    foreach ($existing as $item) {
        $name = normalize_key(
            (string)($item['NAME'] ?? '')
        );

        $statusId = (string)(
            $item['STATUS_ID'] ?? ''
        );

        if ($name !== '' && $statusId !== '') {
            $byName[$name] = $statusId;
        }
    }

    $result = [];

    foreach ($names as $name) {
        $name = clean_value($name);

        if ($name === '') {
            continue;
        }

        $key = normalize_key($name);

        if (!isset($byName[$key])) {
            throw new RuntimeException(
                'В Bitrix24 не найдено значение "' .
                $name .
                '" в справочнике ' .
                $entityId .
                '.'
            );
        }

        $result[$name] = $byName[$key];
    }

    return $result;
}


/*
|--------------------------------------------------------------------------
| Шаблон реквизита России
|--------------------------------------------------------------------------
*/

function resolve_russia_preset(
    BXConnector $bx,
    string $countryCode
): array {
    $countries = $bx->request(
        'crm.requisite.preset.countries'
    );

    $countryId = null;

    foreach ($countries as $country) {
        $code = strtoupper(
            (string)($country['CODE'] ?? '')
        );

        if ($code === strtoupper($countryCode)) {
            $countryId = (int)$country['ID'];
            break;
        }
    }

    if (!$countryId) {
        throw new RuntimeException(
            'Не найден COUNTRY_ID для страны ' .
            $countryCode
        );
    }

    $presets = $bx->getList(
        'crm.requisite.preset.list',
        [
            'filter' => [
                'COUNTRY_ID' => $countryId,
                'ENTITY_TYPE_ID' => 8,
            ],
            'select' => [
                'ID',
                'NAME',
                'COUNTRY_ID',
                'ENTITY_TYPE_ID',
                'ACTIVE',
            ],
        ]
    );

    foreach ($presets as $preset) {
        if (($preset['ACTIVE'] ?? 'Y') === 'Y') {
            return [
                'country_id' => $countryId,
                'preset_id' => (int)$preset['ID'],
                'preset_name' => (string)$preset['NAME'],
            ];
        }
    }

    throw new RuntimeException(
        'Не найден активный шаблон реквизита для России.'
    );
}


/*
|--------------------------------------------------------------------------
| Типы адресов
|--------------------------------------------------------------------------
*/

function resolve_address_types(
    BXConnector $bx
): array {
    $items = $bx->request(
        'crm.enum.addresstype'
    );

    $map = [
        'Actual' => null,
        'Legal' => null,
        'Shipping' => null,
    ];

    foreach ($items as $item) {
        $id = (int)(
            $item['ID'] ?? 0
        );

        if ($id <= 0) {
            continue;
        }

        $name = lower_string(
            (string)($item['NAME'] ?? '')
        );

        $symbol = lower_string(
            (string)($item['SYMBOL_CODE'] ?? '') .
            ' ' .
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

    /*
     * Если Bitrix не дал отдельные названия —
     * используем первый доступный тип как Actual.
     */
    if (
        $map['Actual'] === null &&
        isset($items[0]['ID'])
    ) {
        $map['Actual'] = (int)$items[0]['ID'];
    }

    if ($map['Actual'] === null) {
        throw new RuntimeException(
            'Не удалось определить тип адреса Actual.'
        );
    }

    if ($map['Legal'] === null) {
        $map['Legal'] = $map['Actual'];
    }

    if ($map['Shipping'] === null) {
        $map['Shipping'] = $map['Actual'];
    }

    return $map;
}


/*
|--------------------------------------------------------------------------
| Значения Competitor software
|--------------------------------------------------------------------------
*/

function resolve_competitor_option_map(
    BXConnector $bx,
    string $fieldName
): array {
    $fieldList = $bx->request(
        'crm.company.userfield.list',
        [
            'filter' => [
                'FIELD_NAME' => $fieldName,
            ],
        ]
    );

    $map = [];

    if (
        !$fieldList ||
        !isset($fieldList[0]['LIST']) ||
        !is_array($fieldList[0]['LIST'])
    ) {
        return $map;
    }

    foreach ($fieldList[0]['LIST'] as $option) {
        $value = clean_value(
            (string)($option['VALUE'] ?? '')
        );

        $id = (string)(
            $option['ID'] ?? ''
        );

        if ($value !== '' && $id !== '') {
            $map[
            normalize_key($value)
            ] = $id;
        }
    }

    return $map;
}


/*
|--------------------------------------------------------------------------
| Автоматическое восстановление runtime.json
|--------------------------------------------------------------------------
|
| ВАЖНО:
|
| Эта функция НЕ создаёт поля.
| НЕ создаёт значения справочников.
| НЕ вызывает setup.php.
|
| Она только читает то, что уже существует в Bitrix24.
|
*/

function rebuild_runtime(
    BXConnector $bx,
    array $config
): array {
    worker_out(
        'runtime.json отсутствует. ' .
        'Восстанавливаю служебные данные из Bitrix24...'
    );

    /*
     * Ответственный
     */
    $user = find_responsible(
        $bx,
        (string)$config['RESPONSIBLE_FIRST_NAME'],
        (string)$config['RESPONSIBLE_LAST_NAME']
    );

    if (!$user) {
        throw new RuntimeException(
            'Не найден ответственный: ' .
            $config['RESPONSIBLE_FIRST_NAME'] .
            ' ' .
            $config['RESPONSIBLE_LAST_NAME']
        );
    }

    $responsibleId = (int)$user['ID'];


    /*
     * Стандартные значения Типа компании
     */
    $companyTypes = [
        'Prospect',
        'Customer',
        'Possible partner',
        "Competitor's customer",
        'Distributor',
        'Partner',
        'Competitor',
        'Our company',
    ];

    $typeMap = resolve_existing_status_values(
        $bx,
        'COMPANY_TYPE',
        $companyTypes
    );


    /*
     * Стандартные значения Отрасли
     */
    $industries = [
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

    $industryMap = resolve_existing_status_values(
        $bx,
        'INDUSTRY',
        $industries
    );


    /*
     * Адреса
     */
    $addressTypes = resolve_address_types(
        $bx
    );


    /*
     * Реквизиты России
     */
    $requisite = resolve_russia_preset(
        $bx,
        (string)$config['COUNTRY_CODE']
    );


    /*
     * Competitor software
     */
    $competitorOptionMap =
        resolve_competitor_option_map(
            $bx,
            (string)$config['FIELDS']['competitor_software']
        );


    /*
     * Формируем runtime.json
     */
    $runtime = [
        'created_at' => date('c'),

        'responsible_id' => $responsibleId,

        'responsible_name' =>
            (string)($user['NAME'] ?? '') .
            ' ' .
            (string)($user['LAST_NAME'] ?? ''),

        'country' =>
            (string)$config['COUNTRY'],

        'country_code' =>
            (string)$config['COUNTRY_CODE'],

        'type_map' =>
            $typeMap,

        'industry_map' =>
            $industryMap,

        'address_types' =>
            $addressTypes,

        'requisite' =>
            $requisite,

        'competitor_option_map' =>
            $competitorOptionMap,
    ];


    /*
     * JSON без JSON_THROW_ON_ERROR
     */
    $json = json_encode(
        $runtime,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException(
            'Не удалось создать runtime.json: ' .
            json_last_error_msg()
        );
    }

    $result = file_put_contents(
        (string)$config['RUNTIME_FILE'],
        $json . PHP_EOL,
        LOCK_EX
    );

    if ($result === false) {
        throw new RuntimeException(
            'Не удалось записать runtime.json.'
        );
    }

    worker_out(
        'runtime.json восстановлен.'
    );

    return $runtime;
}


/*
|--------------------------------------------------------------------------
| Загрузка runtime.json
|--------------------------------------------------------------------------
*/

function load_runtime(
    string $path,
    BXConnector $bx,
    array $config
): array {
    if (file_exists($path)) {
        $raw = file_get_contents($path);

        if ($raw !== false) {
            $data = json_decode(
                $raw,
                true
            );

            if (
                is_array($data) &&
                isset(
                    $data['responsible_id'],
                    $data['country'],
                    $data['type_map'],
                    $data['industry_map'],
                    $data['address_types'],
                    $data['requisite']
                )
            ) {
                return $data;
            }
        }

        worker_out(
            'runtime.json повреждён. ' .
            'Восстанавливаю его...'
        );

    } else {
        worker_out(
            'runtime.json не найден. ' .
            'Восстанавливаю его...'
        );
    }

    return rebuild_runtime(
        $bx,
        $config
    );
}


/*
|--------------------------------------------------------------------------
| Один запуск worker
|--------------------------------------------------------------------------
*/

function run_worker(
    array $config
): int {
    $logger = null;
    $lockHandle = null;

    try {
        @set_time_limit(90);

        /*
         * Проверяем webhook.
         */
        $hook = trim(
            (string)$config['BITRIX_HOOK']
        );

        if (
            $hook === '' ||
            strpos($hook, 'YOUR-DOMAIN') !== false
        ) {
            throw new RuntimeException(
                'Укажи BITRIX_HOOK в config.php.'
            );
        }


        /*
         * Логгер
         */
        $logger = new Logger(
            $config['LOGS_DIR']
        );


        /*
         * Bitrix
         */
        $bx = new BXConnector(
            $hook
        );

        $bx->setRequestInterval(
            (float)$config['REQUEST_INTERVAL']
        );

        $bx->setTimeouts(
            (int)$config['HTTP_CONNECT_TIMEOUT'],
            (int)$config['HTTP_TIMEOUT']
        );


        /*
         * runtime.json
         *
         * setup.php здесь НЕ вызывается.
         */
        $runtime = load_runtime(
            (string)$config['RUNTIME_FILE'],
            $bx,
            $config
        );


        /*
         * Создаём папку locks,
         * если она отсутствует.
         */
        $lockDir = dirname(
            (string)$config['RUN_LOCK']
        );

        if (!is_dir($lockDir)) {
            mkdir(
                $lockDir,
                0775,
                true
            );
        }


        /*
         * Lock
         */
        $lockHandle = fopen(
            (string)$config['RUN_LOCK'],
            'c'
        );

        if (
            !$lockHandle ||
            !flock(
                $lockHandle,
                LOCK_EX | LOCK_NB
            )
        ) {
            worker_out(
                'Другой worker уже работает.'
            );

            return 0;
        }


        /*
         * Время запуска
         */
        $startedAt = microtime(true);

        $budget =
            (float)$config['WORKER_BUDGET_SECONDS'];


        /*
         * Загружаем очереди
         */
        $distributors = JsonStore::load(
            $config['QUEUES']['distributors']
        );

        $contacts = JsonStore::load(
            $config['QUEUES']['contacts']
        );

        $companies = JsonStore::load(
            $config['QUEUES']['companies']
        );


        /*
         * Определяем текущий этап
         */
        if (
            JsonStore::hasPending(
                $distributors
            )
        ) {
            $stage = 'distributors';

        } elseif (
            JsonStore::hasPending(
                $contacts
            )
        ) {
            $stage = 'contacts';

        } elseif (
            JsonStore::hasPending(
                $companies
            )
        ) {
            $stage = 'companies';

        } else {
            worker_out(
                'Импорт завершён: все очереди пусты.'
            );

            flock(
                $lockHandle,
                LOCK_UN
            );

            fclose(
                $lockHandle
            );

            return 0;
        }


        worker_out(
            'Этап: ' . $stage
        );


        /*
         * Основной цикл
         */
        while (
            microtime(true) - $startedAt <
            $budget
        ) {
            if (
                $stage === 'distributors'
            ) {
                $queue =& $distributors;

            } elseif (
                $stage === 'contacts'
            ) {
                $queue =& $contacts;

            } else {
                $queue =& $companies;
            }


            $queuePath =
                $config['QUEUES'][$stage];


            /*
             * Следующий элемент
             */
            $index =
                JsonStore::nextPending(
                    $queue
                );

            if ($index === null) {
                break;
            }


            $item =& $queue['items'][$index];


            /*
             * Попытка
             */
            $item['attempts'] =
                (int)(
                    $item['attempts'] ?? 0
                ) + 1;

            $item['last_error'] = null;


            JsonStore::saveItem(
                $queuePath,
                $queue,
                $index
            );


            try {

                if (
                    $stage === 'distributors'
                ) {

                    process_distributor(
                        $bx,
                        $queue,
                        $index,
                        $runtime,
                        $logger,
                        $queuePath
                    );

                } elseif (
                    $stage === 'contacts'
                ) {

                    process_contact(
                        $bx,
                        $queue,
                        $index,
                        $runtime,
                        $logger,
                        $queuePath
                    );

                } else {

                    process_company(
                        $bx,
                        $queue,
                        $index,
                        $runtime,
                        $distributors,
                        $contacts,
                        $logger,
                        $queuePath
                    );
                }


                JsonStore::saveItem(
                    $queuePath,
                    $queue,
                    $index
                );


                worker_out(
                    'OK: ' .
                    (
                        $item['data']['name']
                        ?? $item['source_key']
                    )
                );

            } catch (Throwable $e) {

                $item['last_error'] =
                    $e->getMessage();

                JsonStore::saveItem(
                    $queuePath,
                    $queue,
                    $index
                );

                $logger->error(
                    $stage .
                    ' ' .
                    ($item['source_key'] ?? '') .
                    ': ' .
                    $e->getMessage()
                );

                worker_out(
                    'ERROR: ' .
                    (
                        $item['data']['name']
                        ?? $item['source_key']
                    ) .
                    ' -> ' .
                    $e->getMessage()
                );

                break;
            }


            /*
             * Проверяем бюджет ещё раз.
             */
            if (
                microtime(true) - $startedAt >=
                $budget
            ) {
                break;
            }
        }

        unset($queue);


        /*
         * Время
         */
        $elapsed = round(
            microtime(true) - $startedAt,
            2
        );

        worker_out(
            'Время: ' .
            $elapsed .
            ' сек.'
        );


        /*
         * Проверяем, закончились ли ВСЕ очереди.
         */
        $allDone =
            !JsonStore::hasPending(
                $distributors
            ) &&
            !JsonStore::hasPending(
                $contacts
            ) &&
            !JsonStore::hasPending(
                $companies
            );


        if ($allDone) {

            worker_out(
                'ВСЁ ГОТОВО.'
            );

        } else {

            worker_out(
                'Следующий запуск продолжит импорт.'
            );
        }


        /*
         * Освобождаем lock
         */
        flock(
            $lockHandle,
            LOCK_UN
        );

        fclose(
            $lockHandle
        );

        return 0;

    } catch (Throwable $e) {

        if ($logger instanceof Logger) {
            $logger->error(
                $e->getMessage()
            );
        }

        if ($lockHandle) {
            @flock(
                $lockHandle,
                LOCK_UN
            );

            @fclose(
                $lockHandle
            );
        }

        worker_out(
            'ОШИБКА WORKER: ' .
            $e->getMessage()
        );

        return 1;
    }
}


/*
|--------------------------------------------------------------------------
| Обычный запуск одного цикла
|--------------------------------------------------------------------------
*/

if ($isRunRequest) {
    exit(
    run_worker(
        $config
    )
    );
}


/*
|--------------------------------------------------------------------------
| HTML-страница автоматического запуска
|--------------------------------------------------------------------------
*/

header(
    'Content-Type: text/html; charset=utf-8'
);

header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);

?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Bitrix24 Worker</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 10px;
        }

        h1 {
            margin-top: 0;
        }

        #status {
            margin: 15px 0;
            font-weight: bold;
        }

        #timer {
            margin-bottom: 15px;
        }

        #log {
            background: #111;
            color: #eee;
            padding: 15px;
            border-radius: 8px;
            min-height: 250px;
            max-height: 600px;
            overflow-y: auto;
            white-space: pre-wrap;
        }

        button {
            padding: 10px 16px;
            cursor: pointer;
            border-radius: 6px;
            border: 1px solid #ccc;
            background: #fff;
        }

        button:disabled {
            opacity: 0.5;
            cursor: default;
        }
    </style>
</head>

<body>

<div class="container">

    <h1>Bitrix24 Worker</h1>

    <div id="status">
        Запуск...
    </div>

    <div id="timer"></div>

    <button
        id="runNow"
        type="button"
    >
        Запустить сейчас
    </button>

    <pre id="log">Ожидание...</pre>

</div>


<script>

    (function () {

        var intervalMs = 120000;

        var running = false;

        var finished = false;

        var timerId = null;

        var countdownId = null;

        var nextRunAt = 0;


        var status =
            document.getElementById('status');

        var timer =
            document.getElementById('timer');

        var log =
            document.getElementById('log');

        var runNow =
            document.getElementById('runNow');


        function setLog(text) {
            log.textContent = text;
            log.scrollTop = log.scrollHeight;
        }


        function updateCountdown() {

            if (!nextRunAt) {
                timer.textContent = '';
                return;
            }

            var remaining =
                Math.max(
                    0,
                    nextRunAt - Date.now()
                );

            var seconds =
                Math.ceil(
                    remaining / 1000
                );

            var minutes =
                Math.floor(seconds / 60);

            var secs =
                seconds % 60;

            timer.textContent =
                'Следующий запуск через ' +
                minutes +
                ':' +
                String(secs).padStart(2, '0');


            if (remaining <= 0) {

                clearInterval(
                    countdownId
                );

                countdownId = null;
            }
        }


        function scheduleNext() {

            if (finished) {
                return;
            }

            nextRunAt =
                Date.now() +
                intervalMs;


            if (timerId) {
                clearTimeout(
                    timerId
                );
            }

            timerId =
                setTimeout(
                    function () {
                        runWorker();
                    },
                    intervalMs
                );


            if (countdownId) {
                clearInterval(
                    countdownId
                );
            }

            countdownId =
                setInterval(
                    updateCountdown,
                    1000
                );

            updateCountdown();
        }


        async function runWorker() {

            if (running || finished) {
                return;
            }

            running = true;

            runNow.disabled = true;

            status.textContent =
                'Worker работает...';


            try {

                var response =
                    await fetch(
                        'worker.php?run=1&_=' +
                        Date.now(),
                        {
                            method: 'GET',
                            cache: 'no-store'
                        }
                    );


                var text =
                    await response.text();


                setLog(text);


                /*
                 * Импорт полностью закончен.
                 */
                if (
                    text.indexOf('ВСЁ ГОТОВО.') !== -1 ||
                    text.indexOf(
                        'Импорт завершён: все очереди пусты.'
                    ) !== -1
                ) {

                    finished = true;

                    status.textContent =
                        'Импорт полностью завершён.';

                    timer.textContent = '';

                    return;
                }


                status.textContent =
                    'Запуск завершён.';

                scheduleNext();

            } catch (error) {

                setLog(
                    'Ошибка запуска worker:\n\n' +
                    String(error)
                );

                status.textContent =
                    'Ошибка. Повтор через 2 минуты.';

                scheduleNext();

            } finally {

                running = false;

                runNow.disabled = false;
            }
        }


        runNow.addEventListener(
            'click',
            function () {

                if (timerId) {
                    clearTimeout(
                        timerId
                    );

                    timerId = null;
                }

                if (countdownId) {
                    clearInterval(
                        countdownId
                    );

                    countdownId = null;
                }

                nextRunAt = 0;

                timer.textContent = '';

                runWorker();
            }
        );


        /*
         * Первый запуск сразу после открытия страницы.
         */
        runWorker();

    })();

</script>

</body>
</html>