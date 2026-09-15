<?php

declare(strict_types=1);

require_once __DIR__ . '/BXConnector.php';

use BX\BXConnector;

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    echo json_encode(['status' => 'error']);
    exit;
}

/*
 * Получаем данные сообщения
 */
$message = $data['data']['message'] ?? [];
$text = trim((string)($message['text'] ?? ''));

$dialogId = (string)($data['data']['chat']['dialogId'] ?? '');

$botId = (int)($data['data']['bot']['id'] ?? 14);

if ($dialogId === '') {
    echo json_encode(['status' => 'error', 'message' => 'dialogId not found']);
    exit;
}

/*
 * Подключаемся к Bitrix24
 */
$bx = new BXConnector($config['bitrix_webhook']);

/*
 * Функция отправки сообщения
 */
function sendMessage(
    BXConnector $bx,
    array $config,
    int $botId,
    string $dialogId,
    string $message
): void {
    $bx->request(
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
}


/*
 * /start
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

    echo json_encode(['status' => 'ok']);
    exit;
}


/*
 * /gif
 *
 * Пример:
 * /gif кот
 */
if (str_starts_with($text, '/gif')) {

    $query = trim(substr($text, 4));

    if ($query === '') {
        sendMessage(
            $bx,
            $config,
            $botId,
            $dialogId,
            "Укажите запрос для GIF.\n\nПример:\n/gif кот"
        );

        echo json_encode(['status' => 'ok']);
        exit;
    }

    /*
     * Запрашиваем GIF у Giphy
     */
    $url = 'https://api.giphy.com/v1/gifs/search?' . http_build_query([
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

        echo json_encode(['status' => 'ok']);
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

        echo json_encode(['status' => 'ok']);
        exit;
    }

    sendMessage(
        $bx,
        $config,
        $botId,
        $dialogId,
        $gifUrl
    );

    echo json_encode(['status' => 'ok']);
    exit;
}


/*
 * /multiply
 *
 * Пример:
 * /multiply 5 10
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

        echo json_encode(['status' => 'ok']);
        exit;
    }

    $a = (float)$parts[0];
    $b = (float)$parts[1];

    $result = $a * $b;

    /*
     * Если результат целый — показываем без .0
     */
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

    echo json_encode(['status' => 'ok']);
    exit;
}


/*
 * Неизвестная команда / обычный текст
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
    "Для начала можно написать /start."
);

echo json_encode(['status' => 'ok']);