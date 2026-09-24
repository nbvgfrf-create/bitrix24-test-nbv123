<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/BXConnector.php';
require_once __DIR__ . '/ImportHelpers.php';
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

function diagnostic_section(string $title, callable $callback): void
{
    echo "\n===== {$title} =====\n";
    try {
        $result = $callback();
        if ($result !== null) {
            echo $result . "\n";
        }
    } catch (Throwable $e) {
        echo 'ОШИБКА: ' . $e->getMessage() . "\n";
    }
}

try {
    $hook = trim((string)$config['BITRIX_HOOK']);
    if ($hook === '' || strpos($hook, 'YOUR-DOMAIN') !== false) {
        throw new RuntimeException('BITRIX_HOOK в config.php не настроен.');
    }

    $bx = new BXConnector($hook);
    $bx->setRequestInterval((float)$config['REQUEST_INTERVAL']);
    $bx->setTimeouts((int)$config['HTTP_CONNECT_TIMEOUT'], (int)$config['HTTP_TIMEOUT']);

    echo "Bitrix24 diagnostic\n";
    echo 'Время: ' . date('c') . "\n";

    diagnostic_section('Пользователь ответственный', function () use ($bx, $config): string {
        $id = bx_find_responsible(
            $bx,
            $config['RESPONSIBLE_FIRST_NAME'],
            $config['RESPONSIBLE_LAST_NAME']
        );
        return 'Найден: ' . $config['RESPONSIBLE_FIRST_NAME'] . ' ' . $config['RESPONSIBLE_LAST_NAME'] . ', ID=' . $id;
    });

    diagnostic_section('ПОЛЯ КОМПАНИИ', function () use ($bx, $config): string {
        $items = $bx->getList('crm.company.userfield.list', [
            'select' => ['ID', 'FIELD_NAME', 'USER_TYPE_ID', 'MULTIPLE', 'LIST'],
        ]);

        $configured = array_values($config['FIELDS']);
        echo 'Настроены в config.php: ' . implode(', ', $configured) . "\n\n";
        echo 'Bitrix24 вернул полей: ' . count($items) . "\n";

        foreach ($items as $field) {
            $fieldName = (string)($field['FIELD_NAME'] ?? '');
            $marker = in_array($fieldName, $configured, true) ? '  <-- НУЖНОЕ' : '';
            echo 'ID=' . ($field['ID'] ?? '')
                . ' | FIELD_NAME=' . $fieldName
                . ' | TYPE=' . ($field['USER_TYPE_ID'] ?? '')
                . ' | MULTIPLE=' . ($field['MULTIPLE'] ?? '')
                . $marker . "\n";

            if (!empty($field['LIST'])) {
                $options = [];
                foreach ((array)$field['LIST'] as $option) {
                    $options[] = (string)($option['ID'] ?? '') . '=' . (string)($option['VALUE'] ?? '');
                }
                echo '    LIST: ' . implode(' | ', $options) . "\n";
            }
        }
        return '';
    });

    diagnostic_section('Типы компаний COMPANY_TYPE', function () use ($bx): string {
        $items = $bx->getList('crm.status.list', [
            'filter' => ['ENTITY_ID' => 'COMPANY_TYPE'],
            'select' => ['STATUS_ID', 'NAME'],
        ]);
        foreach ($items as $item) {
            echo ($item['STATUS_ID'] ?? '') . ' = ' . ($item['NAME'] ?? '') . "\n";
        }
        return '';
    });

    diagnostic_section('Отрасли INDUSTRY', function () use ($bx): string {
        $items = $bx->getList('crm.status.list', [
            'filter' => ['ENTITY_ID' => 'INDUSTRY'],
            'select' => ['STATUS_ID', 'NAME'],
        ]);
        foreach ($items as $item) {
            echo ($item['STATUS_ID'] ?? '') . ' = ' . ($item['NAME'] ?? '') . "\n";
        }
        return '';
    });

    diagnostic_section('Типы адресов', function () use ($bx): string {
        $items = $bx->request('crm.enum.addresstype');
        foreach ((array)$items as $item) {
            echo 'ID=' . ($item['ID'] ?? '')
                . ' | NAME=' . ($item['NAME'] ?? '')
                . ' | SYMBOL_CODE=' . ($item['SYMBOL_CODE'] ?? '')
                . ' | SYMBOL_CODE_SHORT=' . ($item['SYMBOL_CODE_SHORT'] ?? '')
                . "\n";
        }
        return '';
    });

    diagnostic_section('Страны и шаблон реквизитов', function () use ($config): string {
        echo 'Используются заранее проверенные значения, без вызова методов с расширенными правами:' . "\n";
        echo 'Россия: COUNTRY_ID=' . $config['REQUISITE_COUNTRY_ID']
            . ' | CODE=' . $config['COUNTRY_CODE']
            . ' | NAME=' . $config['COUNTRY'] . "\n";
        echo 'Шаблон реквизита «Организация»: PRESET_ID=' . $config['REQUISITE_PRESET_ID'] . "\n";
        return '';
    });

    echo "\n===== ГОТОВО =====\n";
    echo "Если здесь есть реальные FIELD_NAME пользовательских полей, пришли весь вывод страницы.\n";
    echo "По нему я подставлю правильные коды в config.php и поправлю runtime.\n";
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'КРИТИЧЕСКАЯ ОШИБКА: ' . $e->getMessage() . PHP_EOL;
}
