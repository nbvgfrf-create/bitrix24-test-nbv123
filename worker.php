<?php
require __DIR__ . '/config.php';
require __DIR__ . '/distributors.php';
require __DIR__ . '/contacts.php';
require __DIR__ . '/companies.php';

$started = microtime(true);
$b = new Bitrix();
$setup = loadJson(__DIR__ . '/setup.json');

if (!$setup) {
    exit("Сначала открой setup.php\n");
}

$distributors = loadJson(QUEUE_DIR . '/distributors.json');
$contacts = loadJson(QUEUE_DIR . '/contacts.json');
$companies = loadJson(QUEUE_DIR . '/companies.json');

$stage = loadJson(__DIR__ . '/worker_stage.json', ['stage' => 'distributors']);
$current = $stage['stage'];
$done = 0;

try {
    if ($current === 'distributors') {
        importDistributors($b, $distributors, $done, $started);
        saveJson(QUEUE_DIR . '/distributors.json', $distributors);
        if (!hasPending($distributors)) {
            $current = 'contacts';
        }
    }

    if ($current === 'contacts' && microtime(true) - $started < WORK_TIME) {
        importContacts($b, $contacts, $companies, $done, $started);
        saveJson(QUEUE_DIR . '/contacts.json', $contacts);
        if (!hasPending($contacts)) {
            $current = 'companies';
        }
    }

    if ($current === 'companies' && microtime(true) - $started < WORK_TIME) {
        importCompanies($b, $companies, $contacts, $distributors, $setup, $done, $started);
        saveJson(QUEUE_DIR . '/companies.json', $companies);
        if (!hasPending($companies)) {
            $current = 'finished';
        }
    }

    $stage['stage'] = $current;
    saveJson(__DIR__ . '/worker_stage.json', $stage);

    $message = "Worker: этап={$current}, обработано={$done}, время=" . round(microtime(true) - $started, 2) . " сек.";
    logMessage($message);
    echo $message . PHP_EOL;
} catch (Throwable $e) {
    logMessage('Критическая ошибка worker: ' . $e->getMessage());
    echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;
}

function hasPending($items)
{
    foreach ($items as $item) {
        if (($item['status'] ?? '') !== 'done') {
            return true;
        }
    }
    return false;
}
