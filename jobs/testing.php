<?php
require_once __DIR__ . '/../bootstrap.php';

$rssList = [];

$allOrgs = Organizations::getAll();
foreach ($allOrgs as $org) {
    if ($org->rssfeed and $org->sport)
        $rssList[] = [
            "url" => $org->rssfeed,
            "sport" => $org->sport,
        ];
}

const CACHE_TIME = 1800;
$db = Database::getInstance()->getConnection();
$news = getFeeds($db, $rssList);
