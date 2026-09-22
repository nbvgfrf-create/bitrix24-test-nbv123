<?php

function importContacts(Bitrix $b, array &$queue, array $companies, int &$done, float $started)
{
    foreach ($queue as &$item) {
        if ($item['status'] === 'done') {
            continue;
        }
        if (microtime(true) - $started >= WORK_TIME) {
            break;
        }

        try {
            // Контакт создаётся отдельно. Связь с компанией сделаем, когда компании будут созданы.
            $name = $item['name'];
            $result = $b->call('crm.contact.add', ['fields' => [
                'NAME' => $name,
            ]]);

            $item['bitrix_id'] = (int)$result;
            $item['status'] = 'done';
            $done++;
            logMessage("Контакт создан: {$name} => {$item['bitrix_id']}");
        } catch (Throwable $e) {
            $item['status'] = 'error';
            $item['error'] = $e->getMessage();
            logMessage("Ошибка контакта {$item['name']}: {$e->getMessage()}");
        }
    }
    unset($item);
}
