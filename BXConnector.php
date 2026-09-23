<?php

declare(strict_types=1);

class BXConnector
{
    private string $hook;
    private float $lastRequestAt = 0.0;
    private float $minRequestInterval = 0.10;
    private int $connectTimeout = 5;
    private int $timeout = 80;

    public function __construct(string $hook)
    {
        $this->hook = rtrim($hook, '/') . '/';
    }

    public function setRequestInterval(float $seconds): void
    {
        $this->minRequestInterval = max(0.0, $seconds);
    }

    public function setTimeouts(int $connectTimeout, int $timeout): void
    {
        $this->connectTimeout = max(1, $connectTimeout);
        $this->timeout = max(1, $timeout);
    }

    public function request(string $method, array $params = [], string $return = 'result'): mixed
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP extension curl не установлена.');
        }

        $wait = $this->minRequestInterval - (microtime(true) - $this->lastRequestAt);
        if ($wait > 0) {
            usleep((int)round($wait * 1_000_000));
        }

        $url = $this->hook . ltrim($method, '/');
        $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $started = microtime(true);
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);

        $json = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $this->lastRequestAt = microtime(true);

        $elapsed = microtime(true) - $started;

        if ($json === false) {
            throw new RuntimeException('cURL ошибка: ' . $curlError . ' (' . round($elapsed, 2) . ' сек.)');
        }

        $answer = json_decode((string)$json, true);
        if (!is_array($answer)) {
            throw new RuntimeException('Bitrix вернул не JSON. HTTP ' . $httpCode . ': ' . substr((string)$json, 0, 500));
        }

        if (isset($answer['error'])) {
            $message = $answer['error_description'] ?? $answer['error'];
            throw new RuntimeException('Bitrix REST: ' . $message);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Bitrix HTTP ' . $httpCode . ': ' . ($answer['error_description'] ?? 'неизвестная ошибка'));
        }

        if ($return === 'full') {
            return $answer;
        }

        return $answer['result'] ?? null;
    }


    public function getList(string $method, array $params = []): array
    {
        $result = [];
        $start = 0;

        while (true) {
            $pageParams = $params;
            $pageParams['start'] = $start;
            $response = $this->request($method, $pageParams, 'full');
            $page = $response['result'] ?? [];
            if (is_array($page)) {
                foreach ($page as $item) {
                    $result[] = $item;
                }
            }

            if (!isset($response['next'])) {
                break;
            }
            $start = (int)$response['next'];
        }

        return $result;
    }

    /**
     * Старый batch endpoint Bitrix: до 50 подзапросов в одном HTTP-запросе.
     * Каждый элемент commands: ['method' => 'crm.item.add', 'params' => [...]]
     */
    public function batch(array $commands, bool $halt = false): array
    {
        if (!$commands) {
            return [
                'result' => ['result' => [], 'result_error' => []],
            ];
        }

        if (count($commands) > 50) {
            throw new InvalidArgumentException('Один batch не может содержать больше 50 подзапросов.');
        }

        $cmd = [];
        foreach ($commands as $key => $command) {
            $method = (string)($command['method'] ?? '');
            $params = $command['params'] ?? [];
            if ($method === '') {
                throw new InvalidArgumentException('В batch отсутствует method для команды ' . $key);
            }

            $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $cmd[(string)$key] = $method . ($query !== '' ? '?' . $query : '');
        }

        return $this->request('batch', [
            'halt' => $halt ? 1 : 0,
            'cmd' => $cmd,
        ], 'full');
    }
}
