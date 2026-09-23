<?php

declare(strict_types=1);

function clean_value(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    $value = preg_replace('/\s+/u', ' ', trim((string)$value));
    if ($value === null) {
        return '';
    }

    return trim($value);
}

function lower_string(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function normalize_key(string $value): string
{
    return lower_string(clean_value($value));
}

function split_multi_value(string $value): array
{
    $value = clean_value($value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/[,;\n]+/u', $value) ?: [];
    $result = [];
    $seen = [];

    foreach ($parts as $part) {
        $part = clean_value($part);
        if ($part === '') {
            continue;
        }
        $key = normalize_key($part);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result[] = $part;
        }
    }

    return $result;
}

function excel_serial_to_date(mixed $value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    $value = clean_value($value);
    if ($value === '') {
        return '';
    }

    foreach (['Y-m-d', 'Y-m-d H:i:s', 'd.m.Y', 'd/m/Y'] as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    if (is_numeric($value)) {
        $serial = (float)$value;
        if ($serial > 0 && $serial < 100000) {
            $base = new DateTime('1899-12-30');
            $base->modify('+' . (int)$serial . ' days');
            return $base->format('Y-m-d');
        }
    }

    return $value;
}

function unique_append(array &$array, string $value): void
{
    $key = normalize_key($value);
    if ($key === '') {
        return;
    }

    foreach ($array as $existing) {
        if (normalize_key((string)$existing) === $key) {
            return;
        }
    }
    $array[] = $value;
}

function unique_address_append(array &$addresses, string $address, string $type): void
{
    $address = clean_value($address);
    if ($address === '') {
        return;
    }

    $type = clean_value($type);
    if ($type === '') {
        $type = 'Actual';
    }

    foreach ($addresses as $item) {
        if (normalize_key((string)($item['value'] ?? '')) === normalize_key($address)
            && normalize_key((string)($item['type'] ?? 'Actual')) === normalize_key($type)) {
            return;
        }
    }

    $addresses[] = [
        'value' => $address,
        'type' => $type,
        'status' => 'pending',
        'bitrix_id' => null,
        'last_error' => null,
    ];
}

function ensure_dir(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Не удалось создать каталог: ' . $path);
    }
}

function save_runtime(string $path, array $runtime): void
{
    $json = json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Не удалось записать runtime.json: ' . json_last_error_msg());
    }
    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать runtime.json.');
    }
}

function load_runtime(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException('Не найден runtime.json. Запусти xlsx_to_json.php для подготовки импорта.');
    }

    $runtime = json_decode((string)file_get_contents($path), true);
    if (!is_array($runtime)) {
        throw new RuntimeException('runtime.json повреждён. Запусти xlsx_to_json.php заново.');
    }
    return $runtime;
}
