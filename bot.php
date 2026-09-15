<?php

require_once __DIR__ . '/BXConnector.php';
use BX\BXConnector;

$config = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
$raw = file_get_contents('php://input');
$data = [];
parse_str($raw, $data);
$event = $data['event'] ?? '';

if ($event !== 'ONIMBOTV2MESSAGEADD') {
    echo json_encode([
        'status' => 'ignored',
        'event' => $event
    ]);
    exit;
}

$message = $data['data']['message'] ?? [];
$chat = $data['data']['chat'] ?? [];
$bot = $data['data']['bot'] ?? [];
$text = trim($message['text'] ?? '');
$dialogId = $chat['dialogId'] ?? '';
$botId = (int)($bot['id'] ?? 0);

if (!$botId || !$dialogId) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Не удалось получить данные бота или чата'
    ]);
    exit;
}

$bx = new BXConnector($config['bitrix_webhook']);

function sendMessage($bx, $config, $botId, $dialogId, $message)
{
    $result = $bx->request(
        'imbot.v2.Chat.Message.send',
        [
            'botId' => $botId,
            'botToken' => $config['bot_token'],
            'dialogId' => $dialogId,
            'fields' => [
                'message' => $message
            ]
        ],
        'full'
    );
}

if ($text === '/start') {
    sendMessage(
        $bx,
        $config,
        $botId,
        $dialogId,
        "Доступные команды:\n\n" .
        "/gif <текст> - найти GIF\n" .
        "/multiply <число> <число> - умножить два числа"
    );
    echo json_encode(['status' => 'ok']);
    exit;
}

if (str_starts_with($text, '/gif')) {
    $query = trim(substr($text, 4));
    if ($query === '') {
        sendMessage(
            $bx,
            $config,
            $botId,
            $dialogId,
            "Укажите запрос.\nПример: /gif кот"
        );
        echo json_encode(['status' => 'ok']);
        exit;
    }

    $url = 'https://api.giphy.com/v1/gifs/search?' . http_build_query([
            'api_key' => $config['giphy_api_key'],
            'q' => $query,
            'limit' => 1,
            'rating' => 'g',
            'lang' => 'ru'
        ]);

    $response = file_get_contents($url);

    if ($response === false) {
        sendMessage(
            $bx,
            $config,
            $botId,
            $dialogId,
            'Не удалось обратиться к Giphy'
        );
        echo json_encode(['status' => 'ok']);
        exit;
    }

    $giphy = json_decode($response, true);
    $gifUrl = $giphy['data'][0]['images']['original']['url'] ?? '';

    if (!$gifUrl) {
        sendMessage(
            $bx,
            $config,
            $botId,
            $dialogId,
            "По запросу «{$query}» ничего не найдено"
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

if (str_starts_with($text, '/multiply')) {
    $arguments = trim(substr($text, 9));
    $parts = preg_split('/\s+/', $arguments);
    if (count($parts) != 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
        sendMessage(
            $bx,
            $config,
            $botId,
            $dialogId,
            "Нужно указать два числа.\nПример: /multiply 5 10"
        );

        echo json_encode(['status' => 'ok']);
        exit;
    }

    $a = (float)$parts[0];
    $b = (float)$parts[1];

    $result = $a * $b;

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

sendMessage(
    $bx,
    $config,
    $botId,
    $dialogId,
    "Неизвестная команда.\n\n" .
    "Доступные команды:\n" .
    "/gif <текст> - найти GIF\n" .
    "/multiply <число> <число> - умножить два числа"
);

echo json_encode(['status' => 'ok']);