<?php

function getJsonFromApi($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Errore cURL: ' . curl_error($ch);
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    return json_decode($response);
}