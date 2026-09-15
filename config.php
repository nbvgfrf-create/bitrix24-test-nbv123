<?php

declare(strict_types=1);

return [
    'bitrix_webhook' => getenv('BITRIX_WEBHOOK') ?: '',

    'giphy_api_key' => getenv('GIPHY_API_KEY') ?: '',

    'bot_webhook_url' => getenv('BOT_WEBHOOK_URL') ?: '',

    'bot_code' => getenv('BOT_CODE') ?: '',

    'bot_token' => getenv('BOT_TOKEN') ?: '',
];