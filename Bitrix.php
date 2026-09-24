<?php

declare(strict_types=1);

class Bitrix
{
    public function __construct(
        private string $hook,
        private float $interval = 0.1,
        private int $connectTimeout = 5,
        private int $timeout = 60,
    ) {
        $this->hook = rtrim($hook, '/') . '/';
    }

    public function call(string $method, array $params = []): mixed
    {
        static $lastRequest = 0.0;
        $wait = $this->interval - (microtime(true) - $lastRequest);
        if ($wait > 0) {
            usleep((int)($wait * 1000000));
        }

        $curl = curl_init($this->hook . $method);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $lastRequest = microtime(true);

        if ($raw === false) {
            throw new RuntimeException('cURL: ' . $error);
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Bitrix вернул не JSON: HTTP ' . $httpCode);
        }
        if (isset($data['error'])) {
            throw new RuntimeException((string)($data['error_description'] ?? $data['error']));
        }

        return $data['result'] ?? null;
    }

    public function batch(array $commands): array
    {
        if (!$commands) {
            return ['result' => ['result' => [], 'result_error' => []]];
        }
        if (count($commands) > 50) {
            throw new RuntimeException('В batch можно передать не больше 50 команд.');
        }

        $cmd = [];
        foreach ($commands as $key => $command) {
            $query = http_build_query($command['params'] ?? [], '', '&', PHP_QUERY_RFC3986);
            $cmd[(string)$key] = $command['method'] . ($query !== '' ? '?' . $query : '');
        }

        return $this->call('batch', ['halt' => 0, 'cmd' => $cmd]) ?? [];
    }
}
