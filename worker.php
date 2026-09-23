<?php

require __DIR__ . '/config.php';
require __DIR__ . '/distributors.php';
require __DIR__ . '/contacts.php';
require __DIR__ . '/companies.php';

$isRun = (($_GET['run'] ?? '') === '1');

/*
 * Обычное открытие worker.php:
 * показываем страницу автоматического запуска.
 */
if (!$isRun) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Bitrix Worker</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
                background: #f5f5f5;
            }

            h2 {
                margin-bottom: 10px;
            }

            #status {
                margin-bottom: 15px;
                font-weight: bold;
            }

            pre {
                background: #111;
                color: #eee;
                padding: 15px;
                border-radius: 8px;
                white-space: pre-wrap;
                overflow-wrap: anywhere;
            }
        </style>
    </head>
    <body>

    <h2>Bitrix Worker</h2>
    <div id="status">Запуск...</div>
    <pre id="log"></pre>

    <script>
        const INTERVAL = 120000; // 2 минуты

        const statusEl = document.getElementById('status');
        const logEl = document.getElementById('log');

        function addLog(text) {
            logEl.textContent += text + "\n";
            logEl.scrollTop = logEl.scrollHeight;
        }

        async function runWorker() {
            const started = Date.now();

            statusEl.textContent = 'Worker выполняется...';
            addLog('========================================');
            addLog(new Date().toLocaleString());
            addLog('Запуск worker...');

            try {
                const response = await fetch(
                    'worker.php?run=1&_=' + Date.now(),
                    {
                        cache: 'no-store'
                    }
                );

                const text = await response.text();

                addLog(text);

                if (!response.ok) {
                    statusEl.textContent = 'Ошибка HTTP: ' + response.status;
                } else {
                    statusEl.textContent = 'Worker завершён.';
                }
            } catch (error) {
                addLog('Ошибка запуска: ' + error);
                statusEl.textContent = 'Ошибка соединения.';
            }

            /*
             * Следующий запуск планируем через 2 минуты
             * от момента начала предыдущего.
             *
             * Если worker работал 60 секунд —
             * ждём ещё 60 секунд.
             *
             * Если worker внезапно работал дольше 2 минут —
             * следующий запуск начинается сразу после него.
             */
            const elapsed = Date.now() - started;
            const wait = Math.max(0, INTERVAL - elapsed);

            const nextRun = new Date(Date.now() + wait);

            addLog(
                'Следующий запуск: ' +
                nextRun.toLocaleString()
            );

            statusEl.textContent =
                'Следующий запуск через ' +
                Math.ceil(wait / 1000) +
                ' сек.';

            setTimeout(runWorker, wait);
        }

        runWorker();
    </script>

    </body>
    </html>
    <?php
    exit;
}

/*
 * ============================================================
 * ОДИН ЗАПУСК WORKER
 * ============================================================
 */

$started = microtime(true);
$b = new Bitrix();

$distributors = loadJson(QUEUE_DIR . '/distributors.json');
$contacts = loadJson(QUEUE_DIR . '/contacts.json');
$companies = loadJson(QUEUE_DIR . '/companies.json');

$stage = loadJson(
    __DIR__ . '/worker_stage.json',
    ['stage' => 'distributors']
);

$current = $stage['stage'];
$done = 0;

try {

    /*
     * --------------------------------------------------------
     * Получаем данные, которые раньше брал setup.php
     * --------------------------------------------------------
     */

    // Ответственный: Тимофей Жмаев.
    $users = $b->list('user.get', [
        'FILTER' => [
            'NAME' => 'Тимофей',
            'LAST_NAME' => 'Жмаев',
        ],
    ]);

    if (!$users) {
        throw new Exception(
            'Не найден пользователь Тимофей Жмаев в Bitrix24.'
        );
    }

    $responsibleId = (int)$users[0]['ID'];

    // Существующие типы компаний.
    $types = $b->list('crm.status.list', [
        'filter' => [
            'ENTITY_ID' => 'COMPANY_TYPE',
        ],
    ]);

    $companyTypes = [];

    foreach ($types as $type) {
        $name = $type['NAME'] ?? '';
        $id = $type['STATUS_ID'] ?? '';

        if ($name !== '' && $id !== '') {
            $companyTypes[$name] = $id;
        }
    }

    /*
     * Формируем тот же массив, который раньше лежал в setup.json.
     *
     * Сам setup.php больше вообще не нужен.
     */
    $setup = [
        'responsible_id' => $responsibleId,
        'company_types' => $companyTypes,
    ];

    /*
     * --------------------------------------------------------
     * Этапы
     * --------------------------------------------------------
     */

    if ($current === 'distributors') {

        importDistributors(
            $b,
            $distributors,
            $done,
            $started
        );

        saveJson(
            QUEUE_DIR . '/distributors.json',
            $distributors
        );

        if (!hasPending($distributors)) {
            $current = 'contacts';
        }
    }

    if (
        $current === 'contacts' &&
        microtime(true) - $started < WORK_TIME
    ) {

        importContacts(
            $b,
            $contacts,
            $companies,
            $done,
            $started
        );

        saveJson(
            QUEUE_DIR . '/contacts.json',
            $contacts
        );

        if (!hasPending($contacts)) {
            $current = 'companies';
        }
    }

    if (
        $current === 'companies' &&
        microtime(true) - $started < WORK_TIME
    ) {

        importCompanies(
            $b,
            $companies,
            $contacts,
            $distributors,
            $setup,
            $done,
            $started
        );

        saveJson(
            QUEUE_DIR . '/companies.json',
            $companies
        );

        if (!hasPending($companies)) {
            $current = 'finished';
        }
    }

    /*
     * --------------------------------------------------------
     * Сохраняем текущий этап
     * --------------------------------------------------------
     */

    $stage['stage'] = $current;

    saveJson(
        __DIR__ . '/worker_stage.json',
        $stage
    );

    $message =
        "Worker: этап={$current}, " .
        "обработано={$done}, " .
        "время=" .
        round(microtime(true) - $started, 2) .
        " сек.";

    logMessage($message);

    echo $message . PHP_EOL;

} catch (Throwable $e) {

    logMessage(
        'Критическая ошибка worker: ' .
        $e->getMessage()
    );

    echo 'Ошибка: ' .
        $e->getMessage() .
        PHP_EOL;
}


/*
 * ============================================================
 * ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ
 * ============================================================
 */

function hasPending($items)
{
    foreach ($items as $item) {
        if (($item['status'] ?? '') !== 'done') {
            return true;
        }
    }

    return false;
}