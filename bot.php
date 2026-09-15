<?php

declare(strict_types=1);

file_put_contents(
    __DIR__ . '/bot_test.log',
    date('Y-m-d H:i:s') . "\n" .
    file_get_contents('php://input') .
    "\n\n",
    FILE_APPEND
);

require_once __DIR__ . '/BXConnector.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/giphy.php';

use BX\BXConnector;
use BX\Logger;

$config = require __DIR__ . '/config.php';

$logger = new Logger('bot');

$raw = file_get_contents('php://input');

$data = $_POST;

if (empty($data) && $raw !== '') {
    $json = json_decode($raw, true);

    if (is_array($json)) {
        $data = $json;
    }
}

$logger->saveFile($data, 'incoming.log');

if (!is_array($data)) {
    http_response_code(400);
    exit;
}

$event = (string)($data['event'] ?? '');

if ($event !== 'ONIMBOTV2MESSAGEADD') {
    http_response_code(200);
    exit('OK');
}

$botId = (int)($data['data']['bot']['id'] ?? 0);

$message = trim(
    (string)($data['data']['message']['text'] ?? '')
);

$dialogId = (string)(
    $data['data']['chat']['dialogId'] ?? ''
);

$userId = (string)(
    $data['data']['user']['id'] ?? ''
);

if ($botId === 0 || $dialogId === '' || $userId === '') {
    http_response_code(200);
    exit('OK');
}

/*
 * ------------------------------------------------------------
 * STATE
 * ------------------------------------------------------------
 */

$stateFile = __DIR__ . '/state.json';

$states = [];

if (file_exists($stateFile)) {
    $saved = json_decode(
        file_get_contents($stateFile),
        true
    );

    if (is_array($saved)) {
        $states = $saved;
    }
}

$mode = $states[$userId] ?? null;

/*
 * ------------------------------------------------------------
 * BITRIX CONNECTOR
 * ------------------------------------------------------------
 */

$bx = new BXConnector($config['bitrix_webhook']);

/*
 * ------------------------------------------------------------
 * SEND MESSAGE
 * ------------------------------------------------------------
 */

function sendMessage(
    BXConnector $bx,
    int $botId,
    string $botToken,
    string $dialogId,
    string $message,
    array $fields = []
): array {
    $fields['message'] = $message;

    return $bx->request(
        'imbot.v2.Chat.Message.send',
        [
            'botId' => $botId,
            'botToken' => $botToken,
            'dialogId' => $dialogId,
            'fields' => $fields,
        ],
        'full'
    );
}

/*
 * ------------------------------------------------------------
 * MENU
 * ------------------------------------------------------------
 */

function sendMenu(
    BXConnector $bx,
    int $botId,
    string $botToken,
    string $dialogId
): void {
    sendMessage(
        $bx,
        $botId,
        $botToken,
        $dialogId,
        'Выберите действие:',
        [
            'keyboard' => [
                [
                    [
                        'TEXT' => '🎬 GIF',
                        'COMMAND' => 'gif',
                        'BG_COLOR_TOKEN' => 'primary',
                    ],
                    [
                        'TEXT' => '✖️ Умножение',
                        'COMMAND' => 'multiply',
                        'BG_COLOR_TOKEN' => 'primary',
                    ],
                ],
            ],
        ]
    );
}

/*
 * ------------------------------------------------------------
 * COMMAND / MENU
 * ------------------------------------------------------------
 */

$messageLower = mb_strtolower($message);

if (
    $messageLower === '/start' ||
    $messageLower === 'меню' ||
    $messageLower === 'start'
) {
    $states[$userId] = null;

    file_put_contents(
        $stateFile,
        json_encode(
            $states,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        )
    );

    sendMenu(
        $bx,
        $botId,
        $config['bot_token'],
        $dialogId
    );

    http_response_code(200);
    exit('OK');
}

/*
 * ------------------------------------------------------------
 * GIF MODE
 * ------------------------------------------------------------
 */

if (
    $messageLower === 'gif' ||
    $messageLower === '🎬 gif'
) {
    $states[$userId] = 'gif';

    file_put_contents(
        $stateFile,
        json_encode(
            $states,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        )
    );

    sendMessage(
        $bx,
        $botId,
        $config['bot_token'],
        $dialogId,
        'Напишите, какую GIF найти.'
    );

    http_response_code(200);
    exit('OK');
}

/*
 * ------------------------------------------------------------
 * MULTIPLY MODE
 * ------------------------------------------------------------
 */

if (
    $messageLower === 'multiply' ||
    $messageLower === 'умножение' ||
    $messageLower === '✖️ умножение'
) {
    $states[$userId] = 'multiply';

    file_put_contents(
        $stateFile,
        json_encode(
            $states,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        )
    );

    sendMessage(
        $bx,
        $botId,
        $config['bot_token'],
        $dialogId,
        'Введите два числа через пробел. Например: 12 5'
    );

    http_response_code(200);
    exit('OK');
}

/*
 * ------------------------------------------------------------
 * GIF SEARCH
 * ------------------------------------------------------------
 */

if ($mode === 'gif') {

    $gifUrl = searchGiphy(
        $message,
        $config['giphy_api_key']
    );

    if ($gifUrl === null) {
        sendMessage(
            $bx,
            $botId,
            $config['bot_token'],
            $dialogId,
            'GIF не найдена 😔'
        );

        http_response_code(200);
        exit('OK');
    }

    sendMessage(
        $bx,
        $botId,
        $config['bot_token'],
        $dialogId,
        '',
        [
            'attach' => [
                [
                    'BLOCKS' => [
                        [
                            'IMAGE' => [
                                [
                                    'LINK' => $gifUrl,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]
    );

    http_response_code(200);
    exit('OK');
}

/*
 * ------------------------------------------------------------
 * MULTIPLICATION
 * ------------------------------------------------------------
 */

if ($mode === 'multiply') {

    if (
        !preg_match(
            '/^\s*(-?\d+(?:[.,]\d+)?)\s+(-?\d+(?:[.,]\d+)?)\s*$/u',
            $message,
            $matches
        )
    ) {
        sendMessage(
            $bx,
            $botId,
            $config['bot_token'],
            $dialogId,
            'Нужно ввести только два числа через пробел. Например: 12 5'
        );

        http_response_code(200);
        exit('OK');
    }

    $number1 = (float)str_replace(',', '.', $matches[1]);
    $number2 = (float)str_replace(',', '.', $matches[2]);

    $result = $number1 * $number2;

    if (floor($result) === $result) {
        $result = (int)$result;
    }

    sendMessage(
        $bx,
        $botId,
        $config['bot_token'],
        $dialogId,
        "Результат: {$result}"
    );

    http_response_code(200);
    exit('OK');
}

/*
 * ------------------------------------------------------------
 * UNKNOWN MESSAGE
 * ------------------------------------------------------------
 */

sendMessage(
    $bx,
    $botId,
    $config['bot_token'],
    $dialogId,
    'Сначала выберите действие через /start.'
);

http_response_code(200);

echo 'OK';