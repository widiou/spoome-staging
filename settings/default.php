<?php

require_once __DIR__ . '/../config/env.php';

// Path base (definito anche in bootstrap; qui per quando settings è incluso da solo)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', '/' . trim((string) env('BASE_PATH', '/network/'), '/') . '/');
}

define('SUB_ROOT', rtrim(BASE_PATH, '/'));
define('SQUARE_PLACEHOLDER', SUB_ROOT . '/assets/spoome-placeholder.webp');

// Chiave Google: da .env (niente segreti hardcoded nel codice)
define('GOOGLE_API_KEY', (string) env('GOOGLE_API_KEY', ''));
define('API_BEARER', 'Bearer ' . GOOGLE_API_KEY);

const RSS_SOURCES_FES = [
    'https://www.fpi.it/news/fpi.feed?type=rss',
    'https://www.uits.it/homepage/news.feed?type=rss',
    'https://www.federmoto.it/feed/',
    'https://www.federvela.it/index.php/news?format=feed&type=rss',
    'https://www.fitri.it/it/news/fitri.html?format=feed&type=rss',
    'https://www.fitp.it/RSS-Feeds/News-RSS-Feed/',
    'https://www.fitet.org/news.feed?type=rss',
    'https://www.fitav.it/feed/',
    'https://www.fitarco.it/media-fitarco/news.feed?type=rss',
    'https://www.taekwondoitalia.it/news.feed?type=rss',
    'https://www.fissw.com/feed/',
    'https://www.fisr.it/news.feed?type=rss',
    'https://fisi.org/feed/',
    'https://www.fisg.it/feed/',
    'https://www.fise.it/federazione/news-la-federazione.feed?type=rss',
    'https://federscherma.it/feed/',
    'https://federugby.it/feed/',
    'https://www.fipsas.it/news?format=feed&type=rss',
    'https://fipm.it/feed/',
    'https://www.federvolley.it/news-feed',
    'https://fip.it/feed/',
    'https://www.federnuoto.it/feed-rss-fin.feed?type=rss',
    'https://www.fimconi.it/feed/',
    'https://www.fijlkam.it/feed-rss-fijlkam.feed?type=rss',
    'https://www.federhockey.it/home/fih/comunicati-stampa/comunicati-stampa-blog.feed?type=rss',
    'https://www.federhandball.it/news?format=feed&type=rss',
    'https://www.figc.it/it/rss/tutto-il-sito/news/',
    //'https://www.federgolf.it/feed/',
    'https://www.federdanza.it/news?format=feed&type=rss',
    'https://feeds.feedburner.com/fidalit',
    'https://www.ficr.it/news/feed/',
    'https://canottaggio.org/feed/',
    'https://www.fibs.it/feed/rss',
    'https://www.badmintonitalia.it/it/news/azzurri.feed?type=rss',
    'https://www.federginnastica.it/news.feed?type=rss',
    'https://www.federkombat.it/home/news.feed?type=rss',
    'https://www.federclimb.it/news.feed?type=rss',
    'https://www.cusi.it/feed/',
    'https://www.federciclismo.it/feed/',
    'https://www.sportesalute.eu/primo-piano.feed?type=rss',
    'https://fitds.it/feed/',
    'https://www.federbocce.it/news.feed?type=rss',
    'https://www.coni.it/it/news.feed?type=rss',
    'https://www.fidasc.it/it/news.feed?type=rss',
];