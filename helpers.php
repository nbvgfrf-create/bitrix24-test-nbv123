<?php

declare(strict_types=1);

function clean(string|int|float|null $value): string
{
    if ($value === null) {
        return '';
    }
    $value = preg_replace('/\s+/u', ' ', trim((string)$value));
    return trim($value ?? '');
}

function key_name(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower(clean($value), 'UTF-8') : strtolower(clean($value));
}

function multi_values(string $value): array
{
    $value = clean($value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/[,;\n]+/u', $value) ?: [];
    $result = [];
    $seen = [];

    foreach ($parts as $part) {
        $part = clean($part);
        if ($part === '') {
            continue;
        }
        $key = key_name($part);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result[] = $part;
        }
    }

    return $result;
}

function add_unique(array &$list, string $value): void
{
    $value = clean($value);
    if ($value === '') {
        return;
    }

    $key = key_name($value);
    foreach ($list as $old) {
        if (key_name((string)$old) === $key) {
            return;
        }
    }
    $list[] = $value;
}

function excel_date(mixed $value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    $value = clean($value);
    if ($value === '') {
        return '';
    }

    foreach (['Y-m-d', 'Y-m-d H:i:s', 'd.m.Y', 'd/m/Y'] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    if (is_numeric($value)) {
        $serial = (float)$value;
        if ($serial > 0 && $serial < 100000) {
            $date = new DateTime('1899-12-30');
            $date->modify('+' . (int)$serial . ' days');
            return $date->format('Y-m-d');
        }
    }

    return $value;
}

function make_dir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function queue_path(array $config, string $name): string
{
    return $config['QUEUES_DIR'] . '/' . $name . '.json';
}

function save_json(string $path, array $data): void
{
    make_dir(dirname($path));
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить ' . $path);
    }
}

function load_json(string $path): array
{
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException('Некорректный JSON: ' . $path);
    }
    return $data;
}

function pending_indexes(array $queue, int $limit): array
{
    $indexes = [];
    foreach ($queue['items'] as $index => $item) {
        if (($item['status'] ?? 'pending') === 'pending') {
            $indexes[] = (int)$index;
            if (count($indexes) >= $limit) {
                break;
            }
        }
    }
    return $indexes;
}

function has_pending(array $queue): bool
{
    foreach ($queue['items'] as $item) {
        if (($item['status'] ?? 'pending') === 'pending') {
            return true;
        }
    }
    return false;
}

function queue_counts(array $queue): array
{
    $counts = ['pending' => 0, 'done' => 0, 'failed' => 0];
    foreach ($queue['items'] as $item) {
        $status = $item['status'] ?? 'pending';
        $counts[$status] = ($counts[$status] ?? 0) + 1;
    }
    return $counts;
}

function has_failed(array $queue): bool
{
    foreach ($queue['items'] as $item) {
        if (($item['status'] ?? 'pending') === 'failed') {
            return true;
        }
        foreach (($item['data']['addresses'] ?? []) as $address) {
            if (($address['status'] ?? 'pending') === 'failed') {
                return true;
            }
        }
    }
    return false;
}
