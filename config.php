<?php

const BITRIX_HOOK = 'https://b24-ef9noe.bitrix24.ru/rest/1/55yppqh1b90kq575/';
const EXCEL_FILE = __DIR__ . '/companies (5).xlsx';
const QUEUE_DIR = __DIR__ . '/queues';
const LOG_FILE = __DIR__ . '/logs/import.log';
const WORK_TIME = 60; // worker старается работать не дольше минуты

class Bitrix
{
    public function call($method, $params = [])
    {
        $ch = curl_init(BITRIX_HOOK . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 50,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('cURL: ' . $error);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('Bitrix вернул не JSON: ' . $response);
        }

        if (isset($data['error'])) {
            throw new Exception($data['error'] . ': ' . ($data['error_description'] ?? ''));
        }

        return $data['result'] ?? null;
    }

    public function list($method, $params = [])
    {
        $all = [];
        $start = 0;

        do {
            $params['start'] = $start;
            $result = $this->call($method, $params);
            if (!is_array($result)) {
                break;
            }
            $all = array_merge($all, $result);
            $start += 50;
        } while (count($result) === 50);

        return $all;
    }
}

function logMessage($message)
{
    if (!is_dir(dirname(LOG_FILE))) {
        mkdir(dirname(LOG_FILE), 0777, true);
    }
    file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL, FILE_APPEND);
}

function loadJson($file, $default = [])
{
    if (!file_exists($file)) {
        return $default;
    }

    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function saveJson($file, $data)
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }

    file_put_contents(
        $file,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function clean($value)
{
    $value = trim((string)$value);
    if ($value === '' || $value === '14') {
        return '';
    }
    return $value;
}

function splitValues($value)
{
    $value = clean($value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/\s*[,;]\s*/u', $value);
    $result = [];
    foreach ($parts as $part) {
        $part = clean($part);
        if ($part !== '' && !in_array($part, $result, true)) {
            $result[] = $part;
        }
    }
    return $result;
}

function normalize($value)
{
    $value = mb_strtolower(trim((string)$value));
    $value = str_replace('ё', 'е', $value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    return trim(preg_replace('/\s+/u', ' ', $value));
}

function personKey($name)
{
    $name = normalize($name);
    if ($name === '') {
        return '';
    }

    $parts = preg_split('/\s+/u', $name);
    $parts = array_values(array_filter($parts, function ($x) {
        return mb_strlen($x) >= 1;
    }));
    sort($parts, SORT_STRING);

    return implode(' ', $parts);
}

function dateValue($value)
{
    if ($value === null || $value === '') {
        return '';
    }

    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    if (is_numeric($value)) {
        $date = new DateTime('1899-12-30');
        $date->modify('+' . (int)$value . ' days');
        return $date->format('Y-m-d');
    }

    $time = strtotime((string)$value);
    return $time ? date('Y-m-d', $time) : '';
}

function slug($value)
{
    $value = normalize($value);
    $value = preg_replace('/[^a-z0-9а-я]+/u', '_', $value);
    return trim($value, '_');
}
