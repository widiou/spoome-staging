<?php
function fetchLatestNews($limit = 4)
{
    $rss = simplexml_load_file('https://www.gazzetta.it/dynamic-feed/rss/section/olimpiadi.xml');
    if ($rss === false) {
        throw new Exception("Errore nel caricamento del feed RSS.");
    }
    $news = [];
    $items = $rss->channel->item;
    for ($i = 0; $i < min(count($items), $limit); $i++) {
        $item = $items[$i];
        $title = (string)$item->title;
        $link = (string)$item->link;
        $pubDate = (string)$item->pubDate;
        $description = (string)$item->description;
        $news[] = [
            'title' => $title,
            'link' => $link,
            'pubDate' => $pubDate,
            'description' => $description,
            'category' => $item->category,
        ];
    }
    return $news;
}
