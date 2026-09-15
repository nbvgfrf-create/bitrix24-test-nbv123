<?php

declare(strict_types=1);

require_once __DIR__ . '/BXConnector.php';

use BX\BXConnector;

$config = require __DIR__ . '/config.php';

$bx = new BXConnector($config['bitrix_webhook']);

$user = $bx->request('user.current', [], 'full');

if (!empty($user['error'])) {
    die('<pre>' . htmlspecialchars(json_encode($user, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>');
}

$userId = (int)($user['result']['ID'] ?? 0);

if ($userId === 0) {
    die('Не удалось получить ID пользователя');
}

// Создаём чат с ботом
$result = $bx->request(
    'imbot.v2.Chat.add',
    [
        'botId' => 14,
        'botToken' => $config['bot_token'],
        'fields' => [
            'title' => 'GIF + Math Bot',
            'userIds' => [$userId],
            'message' => 'Привет! Я бот GIF + Math.',
        ],
    ],
    'full'
);

header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    [
        'user_id' => $userId,
        'chat_result' => $result,
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
);