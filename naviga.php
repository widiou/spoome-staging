<?php

$url = $_GET['url'] ?? '';
$context = stream_context_create(array(
    'http' => array(
        'method' => "GET",
        'header' =>
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
            "Accept-Language: it-IT,it;q=0.8\r\n" . "Keep-Alive: timeout=3, max=10\r\n",
        "Connection: keep-alive",
        'user_agent' => "User-Agent: Mozilla/5.0 (Windows NT 6.1) AppleWebKit/535.11 (KHTML, like Gecko) Chrome/17.0.963.66 Safari/535.11",
        "ignore_errors" => true,
        "timeout" => 3
    )
));
echo file_get_contents($url, false, $context);

