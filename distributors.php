<?php

function importDistributors(Bitrix $b, array &$queue, int &$done, float $started)
{
    foreach ($queue as &$item) {
        if ($item['status'] === 'done') {
            continue;
        }
        if (microtime(true) - $started >= WORK_TIME) {
            break;
        }

        try {
            $result = $b->call('crm.company.add', ['fields' => [
                'TITLE' => $item['name'],
                'COMPANY_TYPE' => 'DISTRIBUTOR',
            ]]);

            $item['bitrix_id'] = (int)$result;
            $item['status'] = 'done';
            $done++;
            logMessage("Дистрибьютор создан: {$item['name']} => {$item['bitrix_id']}");
        } catch (Throwable $e) {
            $item['status'] = 'error';
            $item['error'] = $e->getMessage();
            logMessage("Ошибка дистрибьютора {$item['name']}: {$e->getMessage()}");
        }
    }
    unset($item);
}
