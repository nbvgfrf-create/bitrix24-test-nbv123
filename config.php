<?php

declare(strict_types=1);

return [
    'bitrix_webhook' => getenv('BITRIX_WEBHOOK') ?: '',
    'list_id' => (int)(getenv('BITRIX_LIST_ID') ?: 0),

    // ID пользователя Bitrix24, которому назначаем задачи.
    'responsible_id' => (int)(getenv('BITRIX_RESPONSIBLE_ID') ?: 0),
];