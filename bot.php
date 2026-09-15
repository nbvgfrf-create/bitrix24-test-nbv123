<?php

declare(strict_types=1);

require_once __DIR__ . '/BXConnector.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/giphy.php';

use BX\BXConnector;
use BX\Logger;

$config = require __DIR__ . '/config.php';

$logger = new Logger('bot');

$raw = file_get_contents('php://input');

$data = json_decode($raw, true);

$logger->saveFile($data, 'incoming.json');

if (!is_array($data)) {
    http_response_code(400);
    exit;
}

/*
 * ============================================================
 * EVENT DATA
 * ============================================================
 */

$event = $data['event'] ?? '';

$eventData = $data['data'] ?? [];

$botData = $eventData['bot'] ?? [];

$chatData = $eventData['chat'] ?? [];

$botId = (int)($botData['id'] ?? 0);

$botToken = (string)($botData['auth'] ?? '');

$dialogId = (string)($chatData['dialogId'] ?? '');

/*
 * Если Bitrix не прислал необходимые данные,
 * пробуем взять их из config.php.
 */

if ($botToken === '') {
    $botToken = $config['bot_token'];
}

if ($botId === 0) {
    $botId = (int)($config['bot_id'] ?? 0);
}

if ($dialogId === '') {
    http_response_code(200);
    exit;
}

/*
 * ============================================================
 * ONLY MESSAGE EVENTS
 * ============================================================
 */

if ($event !== 'ONIMBOTV2MESSAGEADD') {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
    exit;
}

/*
 * ============================================================
 * MESSAGE
 * ============================================================
 */

$messageData = $eventData['message'] ?? [];

$message = trim(
    (string)(
        $messageData['message']
        ?? $eventData['message']
        ?? ''
    )
);

$messageId = (int)($messageData['id'] ?? 0);

/*
 * ============================================================
 * CONNECTOR
 * ============================================================
 */

$bx = new BXConnector($config['bitrix_webhook']);

/*
 * ============================================================
 * SEND FUNCTION
 * ============================================================
 */

function sendMessage(
    BXConnector $bx,
    int $botId,
    string $botToken,
    string $dialogId,
    string $message,
    array $extra = []
): void {
    $params = [
        'botId' => $botId,
        'botToken' => $botToken,
        'dialogId' => $dialogId,

        'fields' => array_merge([
            'message' => $message,
        ], $extra),
    ];

    $result = $bx->request(
        'imbot.v2.Chat.Message.send',
        $params,
        'full'
    );

    file_put_contents(
        __DIR__ . '/bot_response.log',
        print_r($result, true) . PHP_EOL,
        FILE_APPEND
    );
}

/*
 * ============================================================
 * MENU
 * ============================================================
 */

$menuKeyboard = [
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
];

/*
 * ============================================================
 * COMMANDS
 * ============================================================
 */

if (
    $message === '/start'
    || $message === 'start'
    || $message === 'меню'
    || $message === 'Меню'
) {
    sendMessage(
        $bx,
        $botId,
        $botToken,
        $dialogId,
        'Выберите действие:',
        [
            'keyboard' => $menuKeyboard,
        ]
    );

    http_response_code(200);
    exit;
}

/*
 * ============================================================
 * GIF MODE
 * ============================================================
 */

if (
    mb_strtolower($message) === 'gif'
    || mb_strtolower($message) === '🎬 gif'
) {
    sendMessage(
        $bx,
        $botId,
        $botToken,
        $dialogId,
        'Напишите, какую GIF найти.'
    );

    http_response_code(200);
    exit;
}

/*
 * ============================================================
 * MULTIPLICATION MODE
 * ============================================================
 */

if (
    mb_strtolower($message) === 'multiply'
    || mb_strtolower($message) === 'умножение'
    || mb_strtolower($message) === '✖️ умножение'
) {
    sendMessage(
        $bx,
        $botId,
        $botToken,
        $dialogId,
        'Введите два числа через пробел. Например: 12 5'
    );

    http_response_code(200);
    exit;
}

/*
 * ============================================================
 * MULTIPLICATION
 * ============================================================
 *
 * Формат:
 *
 * 12 5
 *
 * или:
 *
 * 12 * 5
 */

if (preg_match('/^\s*(-?\d+(?:[.,]\d+)?)\s+(-?\d+(?:[.,]\d+)?)\s*$/u', $message, $matches)) {

    $number1 = (float)str_replace(',', '.', $matches[1]);
    $number2 = (float)str_replace(',', '.', $matches[2]);

    $result = $number1 * $number2;

    if (floor($result) == $result) {
        $result = (int)$result;
    }

    sendMessage(
        $bx,
        $botId,
        $botToken,
        $dialogId,
        "Результат: {$result}"
    );

    http_response_code(200);
    exit;
}

/*
 * ============================================================
 * GIF SEARCH
 * ============================================================
 *
 * Если сообщение не является командой и не является
 * двумя числами — считаем его поиском GIF.
 */

if ($message !== '') {

    $gifUrl = searchGiphy(
        $message,
        $config['giphy_api_key']
    );

    if ($gifUrl === null) {

        sendMessage(
            $bx,
            $botId,
            $botToken,
            $dialogId,
            'Не удалось найти GIF 😔'
        );

        http_response_code(200);
        exit;
    }

    /*
     * Отправляем GIF как IMAGE attachment.
     */

    sendMessage(
        $bx,
        $botId,
        $botToken,
        $dialogId,
        '',
        [
            'attach' => [
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
        ]
    );

    http_response_code(200);
    exit;
}

/*
 * ============================================================
 * FALLBACK
 * ============================================================
 */

sendMessage(
    $bx,
    $botId,
    $botToken,
    $dialogId,
    'Не понял команду. Напишите /start'
);

http_response_code(200);
