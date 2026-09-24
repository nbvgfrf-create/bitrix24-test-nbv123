<?php

declare(strict_types=1);

if (isset($_GET['run'])) {
    require __DIR__ . '/worker.php';
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Bitrix import v2</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        #log { white-space: pre-wrap; border: 1px solid #ccc; padding: 16px; min-height: 300px; }
    </style>
</head>
<body>
    <h1>Bitrix import v2</h1>
    <p><a href="diagnostic.php" target="_blank">Диагностика Bitrix24</a> | <a href="check.php" target="_blank">Проверка импорта</a> | <a href="xlsx_to_json.php" target="_blank">Подготовить импорт</a></p>
    <div id="log">Запуск...</div>
<script>
const log = document.getElementById('log');
const INTERVAL = 120000; // 2 минуты от начала одного запуска до начала следующего

function stamp() {
    return new Date().toLocaleString('ru-RU');
}

async function runWorker() {
    const started = Date.now();
    log.textContent += `\n${'='.repeat(40)}\n${stamp()}\nЗапуск worker...\n`;

    try {
        const response = await fetch('run.php?run=1&_=' + Date.now(), { cache: 'no-store' });
        const text = await response.text();
        log.textContent += text + '\n';
        if (!response.ok) {
            log.textContent += 'HTTP ошибка: ' + response.status + '\n';
        }
    } catch (error) {
        log.textContent += 'Ошибка запроса worker: ' + error + '\n';
    }

    const elapsed = Date.now() - started;
    const wait = Math.max(1000, INTERVAL - elapsed);
    log.textContent += `Следующий запуск: ${new Date(Date.now() + wait).toLocaleString('ru-RU')}\n`;
    setTimeout(runWorker, wait);
}

runWorker();
</script>
</body>
</html>
