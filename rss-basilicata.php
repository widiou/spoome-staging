<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/bootstrap.php';

$db = Database::getInstance()->getConnection();

// URL reale del feed (usato per atom:link)
$feedUrl = "https://www.spoome.it/rss-basilicata.php";

// Header RSS
header('Content-Type: application/rss+xml; charset=UTF-8');

// Metadati del feed
$feedTitle       = 'Spoome – Notizie sportive sulla Basilicata';
$feedLink        = 'https://www.spoome.it/';
$feedDescription = 'Feed RSS di Spoome.it con le notizie sportive che riguardano la Basilicata.';
$lastBuildDate   = gmdate(DATE_RSS);

/**
 * LISTA KEYWORD PER RICONOSCERE LA BASILICATA
 * (le useremo come "parole/frasi intere", non come substring)
 */
$basilicataKeywords = [
        'basilicata',
        'lucania', 'lucano', 'lucana', 'lucani', 'lucane',
        'sport lucano', 'regione basilicata',

        'potenza', 'matera',

    // principali aree/centri
        'melfi', 'venosa', 'rionero in vulture', 'rionero', 'vulture',
        'pisticci', 'marconia', 'policoro', 'nova siri', 'tursi', 'metaponto',
        'bernalda', 'bernaldese',  // bernalda + demònimo
        'tricarico', 'viggiano', 'val d\'agri', 'valdagri',
        'lagonegro', 'tito', 'avigliano', 'grassano', 'stigliano',
        'lavello', 'genzano di lucania',
];

/**
 * Recupero ultime news da rss_cache
 */
$stmt = $db->prepare("SELECT * FROM rss_cache ORDER BY pub_date DESC LIMIT 300");
$stmt->execute();

$items = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $newsItem = [
            'id'             => $row['id'],
            'title'          => $row['source'] . ': ' . $row['title'],
            'original_title' => $row['title'],
            'link'           => $row['link'],
            'description'    => $row['description'],
            'pubDate'        => $row['pub_date'] ?? '',
            'source'         => $row['source'],
    ];

    if (isBasilicataRelatedWholeWord($newsItem, $basilicataKeywords)) {
        $items[] = $newsItem;
    }
}

/**
 * Normalizza una stringa:
 * - minuscolo
 * - sostituisce tutto ciò che non è a-z0-9 con spazio
 * - comprime spazi multipli
 * - aggiunge spazio all'inizio e alla fine (" testo ")
 */
function normalize_for_word_match(string $text): string
{
    $text = strtolower($text);
    // tutto ciò che NON è a-z o 0-9 diventa spazio
    $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
    // comprime gli spazi multipli
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    // padding con spazi per semplificare la ricerca " keyword "
    return ' ' . $text . ' ';
}

/**
 * Verifica se la news riguarda la Basilicata con match "a parola intera"
 * (le keyword sono trattate come parole/frasi intere, non substring secche)
 */
function isBasilicataRelatedWholeWord(array $newsItem, array $keywords): bool
{
    // titolo + titolo originale + descrizione (senza tag)
    $rawText = ($newsItem['title'] ?? '') . ' ' .
            ($newsItem['original_title'] ?? '') . ' ' .
            strip_tags($newsItem['description'] ?? '');

    $haystack = normalize_for_word_match($rawText);

    foreach ($keywords as $kw) {
        $kwNorm = normalize_for_word_match($kw);
        // se dopo la normalizzazione la keyword è vuota, salta
        $kwNorm = trim($kwNorm);
        if ($kwNorm === '') {
            continue;
        }

        // aggiungo spazi intorno alla keyword per cercarla come " parola/frase intera "
        $needle = ' ' . $kwNorm . ' ';

        if (strpos($haystack, $needle) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Rimuove eventuali <img> dal contenuto per tenere il feed pulito
 */
function removeImages(string $html): string
{
    return preg_replace('/<img[^>]*>/i', '', $html);
}

// Output RSS (RSS 2.0 + atom:link self)
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title><![CDATA[<?= $feedTitle ?>]]></title>
        <link><?= htmlspecialchars($feedLink, ENT_QUOTES, 'UTF-8') ?></link>
        <description><![CDATA[<?= $feedDescription ?>]]></description>
        <language>it-IT</language>
        <lastBuildDate><?= $lastBuildDate ?></lastBuildDate>
        <pubDate><?= $lastBuildDate ?></pubDate>

        <!-- ATOM SELF LINK -->
        <atom:link href="<?= htmlspecialchars($feedUrl, ENT_QUOTES, 'UTF-8') ?>"
                   rel="self"
                   type="application/rss+xml" />

        <?php foreach ($items as $item): ?>
            <?php
            $pubTimestamp = strtotime($item['pubDate'] ?: 'now');
            $pubDateRss   = gmdate(DATE_RSS, $pubTimestamp);

            // Pulisco la descrizione + link alla fonte
            $descriptionClean  = removeImages($item['description'] ?? '');
            $descriptionClean .= '<br><br><a href="' .
                    htmlspecialchars($item['link'], ENT_QUOTES, 'UTF-8') .
                    '" target="_blank" rel="nofollow noopener noreferrer">Leggi l\'articolo completo</a>';
            ?>
            <item>
                <title><![CDATA[<?= $item['title'] ?>]]></title>
                <link><?= htmlspecialchars($item['link'], ENT_QUOTES, 'UTF-8') ?></link>
                <guid isPermaLink="false">spoome-rss-<?= (int)$item['id'] ?></guid>
                <pubDate><?= $pubDateRss ?></pubDate>
                <description><![CDATA[<?= $descriptionClean ?>]]></description>
                <source url="<?= htmlspecialchars($feedLink, ENT_QUOTES, 'UTF-8') ?>"><![CDATA[<?= $item['source'] ?> via Spoome.it]]></source>
            </item>
        <?php endforeach; ?>

    </channel>
</rss>
