<?php

declare(strict_types=1);

require_once __DIR__ . '/BXConnector.php';

use BX\BXConnector;

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');


/*
 * ============================================================
 * Получаем событие Bitrix24
 * ============================================================
 */

$raw = file_get_contents('php://input');

$data = [];

parse_str($raw, $data);


/*
 * Лог входящего запроса
 */

file_put_contents(
    __DIR__ . '/bot_debug.log',
    "====================\n" .
    date('Y-m-d H:i:s') . "\n" .
    "RAW:\n" .
    $raw . "\n\n" .
    "PARSED:\n" .
    print_r($data, true) . "\n",
    FILE_APPEND
);


/*
 * Проверяем событие
 */

$event = $data['event'] ?? '';

if ($event !== 'ONIMBOTV2MESSAGEADD') {

    echo json_encode([
        'status' => 'ignored',
        'event' => $event
    ]);

    exit;
}


/*
 * ============================================================
 * Получаем данные сообщения
 * ============================================================
 */

$message = $data['data']['message'] ?? [];
$chat = $data['data']['chat'] ?? [];
$bot = $data['data']['bot'] ?? [];

$text = trim((string)($message['text'] ?? ''));

$dialogId = (string)($chat['dialogId'] ?? '');

$botId = (int)($bot['id'] ?? 0);


/*
 * Логируем основные данные
 */

file_put_contents(
    __DIR__ . '/bot_debug.log',
    "TEXT = " . $text . "\n" .
    "DIALOG_ID = " . $dialogId . "\n" .
    "BOT_ID = " . $botId . "\n\n",
    FILE_APPEND
);


/*
 * ============================================================
 * Проверяем данные
 * ============================================================
 */

if ($botId === 0) {

    echo json_encode([
        'status' => 'error',
        'message' => 'Bot ID not found'
    ]);

    exit;
}

if ($dialogId === '') {

    echo json_encode([
        'status' => 'error',
        'message' => 'Dialog ID not found'
    ]);

    exit;
}


/*
 * ============================================================
 * Подключение к Bitrix24
 * ============================================================
 */

$bx = new BXConnector($config['bitrix_webhook']);


/*
 * ============================================================
 * Отправка сообщения
 * ============================================================
 */

function sendMessage(
    BXConnector $bx,
    array $config,
    int $botId,
    string $dialogId,
    string $message
): void {

    $result = $bx->request(
        'imbot.v2.Chat.Message.send',
        [
            'botId' => $botId,
            'botToken' => $config['bot_token'],
            'dialogId' => $dialogId,

            'fields' => [
                'message' => $message,
            ],
        ],
        'full'
    );


    /*
     * Логируем ответ Bitrix24
     */

    file_put_contents(
        __DIR__ . '/bot_debug.log',
        "--------------------\n" .
        "SEND MESSAGE:\n" .
        $message . "\n\n" .
        "SEND RESULT:\n" .
        print_r($result, true) . "\n\n",
        FILE_APPEND
    );
}


/*
 * ============================================================
 * /start
 * ============================================================
 */

if ($text === '/start') {

    sendMessage(
        $bx,
        $config,
        $botId,
        $dialogId,

        "Доступные команды:\n\n" .
        "/gif <текст> — найти GIF\n" .
        "/multiply <число> <число> — умножить два числа"
    );

    echo json_encode([
        'status' => 'ok'
    ]);

    exit;
}


/*
 * ============================================================
 * /gif
 *
 * Пример:
 * /gif кот
 * ============================================================
 */

if (str_starts_with($text, '/gif')) {

    $query = trim(substr($text, 4));


    if ($query === '') {

        sendMessage(
            $bx,
            $config,
            $botId,
            $dialogId,

            "Укажите запрос для GIF.\n\n" .
            "Пример:\n" .
            "/gif кот"
        );

        echo json_encode([
            'status' => 'ok'
        ]);

        exit;
    }


    /*
     * Запрос к Giphy
     */

    $url = 'https://api.giphy.com/v1/gifs/search?' .
        http_build_query([
            'api_key' => $config['giphy_api_key'],
            'q' => $query,
            'limit' => 1,
            'rating' => 'g',
            'lang' => 'ru',
        ]);


    $response = file_get_contents($url);


    if ($response === false) {

        sendMessage(
            $bx,
            $config,
            $botId,
            $dialogId,

            'Не удалось обратиться к Giphy.'
        );

        echo json_encode([
            'status' => 'ok'
        ]);

        exit;
    }


    $giphy = json_decode($response, true);

    $gifUrl = $giphy['data'][0]['images']['original']['url'] ?? '';


    if ($gifUrl === '') {

        sendMessage(
            $bx,
            $config,
            $botId,
            $dialogId,

            "По запросу «{$query}» GIF не найден."
        );

        echo json_encode([
            'status' => 'ok'
        ]);

        exit;
    }


    /*
     * Пока отправляем URL GIF.
     */

    sendMessage(
        $bx,
        $config,
        $botId,
        $dialogId,
        $gifUrl
    );


    echo json_encode([
        'status' => 'ok'
    ]);

    exit;
}


/*
 * ============================================================
 * /multiply
 *
 * Пример:
 * /multiply 5 10
 * ============================================================
 */

if (str_starts_with($text, '/multiply')) {

    $arguments = trim(substr($text, 9));

    $parts = preg_split('/\s+/', $arguments);


    if (
        count($parts) !== 2 ||
        !is_numeric($parts[0]) ||
        !is_numeric($parts[1])
    ) {

        sendMessage(
            $bx,
            $config,
            $botId,
            $dialogId,

            "Нужно указать два числа.\n\n" .
            "Пример:\n" .
            "/multiply 5 10"
        );

        echo json_encode([
            'status' => 'ok'
        ]);

        exit;
    }


    $a = (float)$parts[0];
    $b = (float)$parts[1];

    $result = $a * $b;


    if (floor($result) == $result) {
        $result = (int)$result;
    }


    sendMessage(
        $bx,
        $config,
        $botId,
        $dialogId,

        "{$a} × {$b} = {$result}"
    );


    echo json_encode([
        'status' => 'ok'
    ]);

    exit;
}


/*
 * ============================================================
 * Неизвестная команда
 * ============================================================
 */

sendMessage(
    $bx,
    $config,
    $botId,
    $dialogId,

    "Неизвестная команда.\n\n" .
    "Доступные команды:\n" .
    "/gif <текст> — найти GIF\n" .
    "/multiply <число> <число> — умножить два числа\n\n" .
    "Для начала напишите /start."
);


echo json_encode([
    'status' => 'ok'
]);

exit;