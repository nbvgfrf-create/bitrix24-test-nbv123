<?php

header('Content-Type: application/json; charset=utf-8');

$webhook = getenv('BITRIX_WEBHOOK');

if (!$webhook) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'BITRIX_WEBHOOK is not configured'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}

function bitrixRequest(string $webhook, string $method, array $params = []): array
{
    $url = rtrim($webhook, '/') . '/' . $method . '.json';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {
        return [
            'success' => false,
            'error' => $error
        ];
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return [
            'success' => false,
            'error' => 'Invalid JSON response',
            'raw' => $response
        ];
    }

    return $data;
}

$tests = [
    'user' => [
        'method' => 'user.current',
    ],

    'lists' => [
        'method' => 'lists.get',
        'params' => [
            'IBLOCK_TYPE_ID' => 'lists',
        ],
    ],

    'tasks' => [
        'method' => 'tasks.task.list',
        'params' => [
            'filter' => [],
            'select' => ['ID'],
            'start' => 0,
        ],
    ],

    'disk' => [
        'method' => 'disk.storage.getlist',
    ],

    'fields' => [
        'method' => 'lists.field.get',
        'params' => [
            'IBLOCK_TYPE_ID' => 'lists',
            'IBLOCK_ID' => 28,
        ],
    ],

    'element' => [
        'method' => 'lists.element.get',
        'params' => [
            'IBLOCK_TYPE_ID' => 'lists',
            'IBLOCK_ID' => 28,
        ],
    ],
];

$result = [];

foreach ($tests as $name => $test) {
    $result[$name] = bitrixRequest(
        $webhook,
        $test['method'],
        $test['params'] ?? []
    );
}

echo json_encode([
    'success' => true,
    'tests' => $result
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);