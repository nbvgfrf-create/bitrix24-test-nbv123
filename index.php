<?php

declare(strict_types=1);

require_once __DIR__ . '/BXConnector.php';
require_once __DIR__ . '/Logger.php';

use BX\BXConnector;
use BX\Logger;

$config = require __DIR__ . '/config.php';

$bx = new BXConnector($config['bitrix_webhook']);

$logger = new Logger('events');

$result = $bx->request('events', [
    'FULL' => true,
], 'full');

$logger->saveFile($result, 'events.json');

header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
);