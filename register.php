<?php

declare(strict_types=1);

require_once __DIR__ . '/BXConnector.php';

use BX\BXConnector;

$config = require __DIR__ . '/config.php';

$bx = new BXConnector($config['bitrix_webhook']);

$result = $bx->request(
    'imbot.v2.Bot.register',
    [
        'fields' => [
            'code' => $config['bot_code'],

            'botToken' => $config['bot_token'],

            'type' => 'bot',

            'eventMode' => 'webhook',

            'webhookUrl' => $config['bot_webhook_url'],

            'properties' => [
                'name' => 'GIF + Math Bot',
                'workPosition' => 'Помощник',
                'color' => 'azure',
            ],
        ],
    ],
    'full'
);

header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
);