<?php

function getDomain($url)
{
    $url = preg_replace('#^https?://#', '', $url);
    $url = preg_replace('#^www\.#', '', $url);
    $domain = explode('/', $url)[0];
    return $domain;
}

function checkFeedAvailability($url)
{
    $headers = @get_headers($url);
    if ($headers && strpos($headers[0], '200')) {
        return true; // Il feed è accessibile
    } else {
        return false; // Il feed non è accessibile o c'è un errore
    }
}

function getFeedWithCache($db, $url, $sport)
{
    if (!checkFeedAvailability($url)) {
        //error_log("Errore nel caricamento del feed RSS: " . $url);
        return false;
    }
    $currentTime = time();
    $cachedFeeds = [];

    // Recupero dei feed dalla cache
    $stmt = $db->prepare("SELECT * FROM rss_cache WHERE url = ? AND cache_time > NOW() - INTERVAL ? SECOND ORDER BY pub_date DESC");
    $stmt->execute([$url, CACHE_TIME]);

    if ($stmt->rowCount() > 0) {
        while ($row = $stmt->fetch()) {
            $cachedFeeds[] = [
                'title' => $row['title'],
                'link' => $row['link'],
                'description' => $row['description'],
                'pubDate' => new DateTime($row['pub_date']),
                'source' => $row['source']
            ];
        }
        return $cachedFeeds; // Restituisci i dati dalla cache
    }

    // Se non ci sono dati in cache, carica il feed RSS
    $rss = simplexml_load_file($url);
    if ($rss === false) {
        error_log("Errore nel caricamento del feed RSS: " . $url);
        return false;
    }

    $feedsToCache = [];

    // Verifica che il feed abbia il formato RSS 2.0
    if (isset($rss->channel->item)) {
        foreach ($rss->channel->item as $item) {
            $pubDate = new DateTime((string)$item->pubDate);
            $description = (string)$item->description;

            // Estrai l'immagine dalla descrizione, se presente
            preg_match('/<img[^>]+src="([^">]+)"/', $description, $matches);
            $image = isset($matches[1]) ? $matches[1] : null;

            // Pulisci la descrizione, mantenendo solo alcuni tag HTML
            $cleanDescription = strip_tags($description, '<p><a><br><strong><em><img>');

            // Assicurati che tutti i campi siano definiti
            $newsItem = [
                'title' => (string)$item->title ?: 'Titolo non disponibile',
                'link' => (string)$item->link ?: '',
                'description' => $cleanDescription ?: 'Descrizione non disponibile',
                'pubDate' => $pubDate,
                'source' => $sport ?? '',
            ];

            // Inserisci i dati nella cache (database)
            $stmt = $db->prepare("INSERT INTO rss_cache (url, title, link, description, pub_date, source, cache_time) VALUES (?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE cache_time = NOW()");

            $stmt->execute([
                $url,
                $newsItem['title'],
                $newsItem['link'],
                $newsItem['description'],
                $newsItem['pubDate']->format('Y-m-d H:i:s'),
                $newsItem['source']
            ]);

            // Aggiungi il feed all'array di feed da cache
            $feedsToCache[] = $newsItem;
        }
    } else {
        return false; // Il feed non è nel formato atteso
    }

    return $feedsToCache; // Restituisci i feed scaricati e salvati
}

function getFeeds($db, $sources)
{
    $allFeeds = [];
    foreach ($sources as $source) {
        $rssFeeds = getFeedWithCache($db, $source['url'], $source['sport']);
        if ($rssFeeds === false) {
            continue;
        }
        $allFeeds = array_merge($allFeeds, $rssFeeds);
    }

    usort($allFeeds, function ($a, $b) {
        return $b['pubDate'] <=> $a['pubDate'];
    });

    return array_slice($allFeeds, 0, 100);
}

function getFeedStored($db, $num = 20, $sport = "")
{
    $feeds = [];
    if ($sport) {
        $stmt = $db->prepare("SELECT * FROM rss_cache where source like '$sport%' order by pub_date desc limit $num");
    } else {
        $stmt = $db->prepare("SELECT * FROM rss_cache order by pub_date desc limit $num");
    }

    $stmt->execute();
    while ($row = $stmt->fetch()) {
        $feeds[] = [
            'title' => $row['title'],
            'link' => $row['link'],
            'description' => $row['description'],
            'pubDate' => new DateTime($row['pub_date']),
            'source' => $row['source']
        ];
    }
    return $feeds;
}


function getFeedAthlete($db, $athlete)
{
    $feeds = [];
    if ($athlete) {
        $stmt = $db->prepare("SELECT * FROM rss_cache WHERE title LIKE :title OR description LIKE :description ORDER BY pub_date DESC LIMIT 10");
        $stmt->execute([
            ':title' => '%' . $athlete . '%',
            ':description' => '%' . $athlete . '%'
        ]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $feeds[] = [
                'title' => $row['title'],
                'link' => $row['link'],
                'description' => $row['description'],
                'pubDate' => new DateTime($row['pub_date']),
                'source' => $row['source']
            ];
        }
    }
    return $feeds;
}




function getSportByRssFeed($rssUrl)
{
    $domainsToSports = [
        'fpi.it' => 'Pugilato',
        'uits.it' => 'Tiro a Segno',
        'federmoto.it' => 'Motociclismo',
        'federvela.it' => 'Vela',
        'federvolley.it' => 'Pallavolo',
        'fitri.it' => 'Triathlon',
        'fitp.it' => 'Tennis',
        'fitet.org' => 'Tennistavolo',
        'fitav.it' => 'Tiro a volo',
        'fitarco.it' => 'Tiro con l\'arco',
        'taekwondoitalia.it' => 'Taekwondo',
        'fissw.com' => 'Sci Nautico',
        'fisr.it' => 'Sport Rotellistici',
        'fisi.org' => 'Sport invernali',
        'fisg.it' => 'Sport del Ghiaccio',
        'fise.it' => 'Sport Equestri',
        'federscherma.it' => 'Scherma',
        'federugby.it' => 'Rugby',
        'fipsas.it' => 'Pesca Sportiva',
        'fipm.it' => 'Pentathlon Moderno',
        'fip.it' => 'Pallacanestro',
        'federnuoto.it' => 'Nuoto',
        'fimconi.it' => 'Motonautica',
        'fijlkam.it' => 'Judo, Lotta, Karate, Arti Marziali',
        'federhockey.it' => 'Hockey su prato',
        'federhandball.it' => 'Pallamano',
        'figc.it' => 'Calcio',
        'federgolf.it' => 'Golf',
        'federdanza.it' => 'Danza Sportiva',
        'fidal.it' => 'Atletica',
        'ficr.it' => 'Cronometristi',
        'canottaggio.org' => 'Canottaggio',
        'fibs.it' => 'Baseball, Softball',
        'badmintonitalia.it' => 'Badminton',
        'federginnastica.it' => 'Ginnastica',
        'federkombat.it' => 'Sport da Combattimento',
        'federciclismo.it' => 'Ciclismo',
        'federclimb.it' => 'Arrampicata sportiva',
        'cusi.it' => 'Sport Universitario',
        'feeds.feedburner.com' => 'Atletica',
        'sportesalute.eu' => 'Sport e Salute',
        'fitds.it' => 'Tiro Dinamico',
        'federbocce.it' => 'Bocce',
        'coni.it' => 'Coni',
        'fidasc.it' => 'Armi Sportive da Caccia'
    ];

    if (array_key_exists($rssUrl, $domainsToSports)) {
        return $domainsToSports[$rssUrl];
    } else {
        return $rssUrl;
    }
}




