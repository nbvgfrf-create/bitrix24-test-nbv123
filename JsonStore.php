<?php

declare(strict_types=1);

class JsonStore
{
    public static function load(string $path): array
    {
        if (!file_exists($path)) {
            throw new RuntimeException('Файл очереди не найден: ' . $path);
        }

        $raw = file_get_contents($path);
        $data = json_decode((string)$raw, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('Некорректный JSON очереди: ' . $path);
        }

        return $data;
    }

    public static function save(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог: ' . $dir);
        }

        $data['updated_at'] = date('c');
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Не удалось сериализовать JSON: ' . json_last_error_msg());
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать временный файл очереди: ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            throw new RuntimeException('Не удалось заменить файл очереди: ' . $path);
        }
    }

    public static function nextPending(array $queue): ?int
    {
        foreach ($queue['items'] as $index => $item) {
            if (($item['status'] ?? 'pending') === 'pending') {
                return (int)$index;
            }
        }
        return null;
    }

    public static function pendingIndexes(array $queue, int $limit): array
    {
        $result = [];
        foreach ($queue['items'] as $index => $item) {
            if (($item['status'] ?? 'pending') === 'pending') {
                $result[] = (int)$index;
                if (count($result) >= $limit) {
                    break;
                }
            }
        }
        return $result;
    }

    public static function hasPending(array $queue): bool
    {
        return self::nextPending($queue) !== null;
    }

    public static function counts(array $queue): array
    {
        $result = ['pending' => 0, 'done' => 0, 'failed' => 0, 'processing' => 0];
        foreach ($queue['items'] as $item) {
            $status = (string)($item['status'] ?? 'pending');
            if (!isset($result[$status])) {
                $result[$status] = 0;
            }
            $result[$status]++;
        }
        return $result;
    }
}
