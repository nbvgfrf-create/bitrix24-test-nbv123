<?php

declare(strict_types=1);

function searchGiphy(string $query, string $apiKey): ?string
{
    $query = trim($query);

    if ($query === '' || $apiKey === '') {
        return null;
    }

    $query = mb_substr($query, 0, 50);

    $url = 'https://api.giphy.com/v1/gifs/search?' . http_build_query([
            'api_key' => $apiKey,
            'q' => $query,
            'limit' => 1,
            'rating' => 'g',
            'lang' => 'ru',
        ]);

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        curl_close($curl);
        return null;
    }

    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);

    if (!is_array($data) || empty($data['data'][0])) {
        return null;
    }

    return $data['data'][0]['images']['original']['url'] ?? null;
}