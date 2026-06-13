<?php

$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'settings/default.php';
require_once 'helpers/gFunctions.php';
require_once 'helpers/wFunctions.php';
require_once 'helpers/s2uFunctions.php';
require_once 'helpers/tdFunctions.php';
require_once 'helpers/rssFunctions.php';

function getWikipediaContent($page, $redirectCount = 0): array
{
    $maxRedirects = 10;
    $page = normalizePageTitle($page);

    $data = fetchWikipediaPage($page, 'it');

    // ❌ Nessuna risposta ⇒ errore immediato
    if (!is_array($data) || empty($data['query']['pages'])) {
        error_log("❌ Wikipedia: risposta vuota o invalida per '$page'");
        return [];
    }

    // ✅ Redirect se pageId = -1
    $pages = $data['query']['pages'];
    $pageId = array_key_first($pages);

    if ($pageId === -1) {
        $correctTitle = fetchCorrectTitleFromWikidata($page);
        if ($correctTitle) {
            return getWikipediaContent($correctTitle, $redirectCount + 1);
        } else {
            error_log("❌ Wikipedia: pagina non trovata né corretta per '$page'");
            return [];
        }
    }

    $pageData = $pages[$pageId] ?? null;

    // ❌ Redirect manuale tipo: #REDIRECT [[...]]
    if ($pageData && isset($pageData['revisions'][0]['*'])) {
        $revisionContent = $pageData['revisions'][0]['*'];
        if (stripos($revisionContent, '#redirect') !== false) {
            if ($redirectCount >= $maxRedirects) {
                error_log("🚫 Superato limite redirect ($maxRedirects) per '$page'");
                return [];
            }
            if (preg_match('/\[\[([^\]]+)\]\]/', $revisionContent, $matches)) {
                return getWikipediaContent($matches[1], $redirectCount + 1);
            }
        }
    }

    // ❌ Pagina non trovata
    if (empty($pageData) || isset($pageData['missing'])) {
        error_log("⚠️ Wikipedia: pagina non trovata per '$page' in lingua italiana.");
        return [];
    }

    // ✅ Parse finale
    return parseWikiData($pageData);
}

function checkSessionLive()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (array_key_exists("user_id", $_SESSION)) {
        return true;
    } else {
        return false;
    }
}

function checkLocal(): void
{
    $allowed_ips = ['35.214.143.9'];
    if (!in_array($_SERVER['SERVER_ADDR'], $allowed_ips)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
}

function printArray($data): void
{
    echo '<pre>';
    print_r($data);
    echo '</pre><br><br>';
}

function paragrafize($value): array
{
    $value = str_replace("[...]", "", $value);
    $value = str_replace("k.o.", "ko", $value);
    $value = explode(". ", $value);
    return $value;
}

function trovaNomiCognomi($testo)
{
    $pattern = '/\b[A-Z][a-z]+\s[A-Z][a-z]+\b/';
    preg_match_all($pattern, $testo, $matches);
    $nomiSostituiti = [];
    foreach ($matches[0] as $nomeCognome) {
        if (!in_array($nomeCognome, $nomiSostituiti)) {
            if (Athlete::findByTitle($nomeCognome)) {
                $encodedName = urlencode($nomeCognome);
                $link = SUB_ROOT . "/atleta.php?a=" . $encodedName;
                $replacement = '<a class="link-spoome" style="font-weight: 600" href="' . $link . '">' . $nomeCognome . '</a>';
                $testo = str_replace($nomeCognome, $replacement, $testo);
            }
            $nomiSostituiti[] = $nomeCognome;
        }
    }

    return replaceMultipleWithLinks($testo);
}

function replaceMultipleWithLinks($text)
{
    $descriptions = Event::listAllMatchs();
    foreach ($descriptions as $description) {
        $escapedMatch = preg_quote($description, '/');
        $encodedDescription = $description;
        $replacement = '<a class="link-spoome" href="/evento.php?description=' . $encodedDescription . '" target="_blank">' . ucwords($description) . '</a>';
        $text = preg_replace('/\b' . $escapedMatch . '\b/i', $replacement, $text);
    }
    $text = preg_replace('/\.\.$/', '.', $text);
    return $text;
}

function extractShortBio($fullBio): string
{
    if ($fullBio) {
        $maxlen = 150;
        $fullBio = trim($fullBio);
        $position = strpos($fullBio, '.');
        if ($position !== false && $position <= $maxlen) {
            $shortBio = substr($fullBio, 0, $position + 1);
        } else {
            $shortBio = substr($fullBio, 0, $maxlen);
        }
        $shortBio = str_replace('<br>', '. ', $shortBio);
        return $shortBio;
        //
    } else {
        return '';
    }

}


function addMetaTags(string $title, int $athleteId, string $athleteSlug, string $imageUrl = ''): void
{
    $athleteSlug = str_replace(" ", "-", $athleteSlug);
    $imageUrl = "https://www.spoome.it/network" . $imageUrl;
    $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $escapedDescription = htmlspecialchars("Scopri la carriera e i successi di " . $title . ": biografia, risultati, foto e video esclusivi. Segui gli aggiornamenti su Spoome", ENT_QUOTES, 'UTF-8');
    $escapedUrl = "https://www.spoome.it/network/atleti/{$athleteId}-" . htmlspecialchars($athleteSlug, ENT_QUOTES, 'UTF-8');

    $finalImageUrl = !empty($imageUrl) ? htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') : "https://www.spoome.it/network//assets/default-athlete.jpg";

    echo "<meta name='description' content='$escapedDescription'>\r\n";
    echo "<meta name='robots' content='index, follow'>\r\n";
    echo "<link rel='canonical' href='$escapedUrl'>\r\n";

    echo "<meta property='og:title' content='$escapedTitle'>\r\n";
    echo "<meta property='og:description' content='Tutto su " . $title . ": palmarès, gare e ultime novità dal mondo del ciclismo. Leggi di più su Spoome.'>\r\n";
    echo "<meta property='og:type' content='profile'>\r\n";
    echo "<meta property='og:url' content='$escapedUrl'>\r\n";
    echo "<meta property='og:image' content='$finalImageUrl' >\r\n";
    echo "<meta property='og:site_name' content='Spoome'>\r\n";
    echo "<meta property='og:locale' content='it_IT'>\r\n";

    // Twitter Card Meta Tags
    echo "<meta name='twitter:card' content='summary_large_image'>\r\n";
    echo "<meta name='twitter:title' content='$escapedTitle'>\r\n";
    echo "<meta name='twitter:description' content='" . $title . ": vittorie, statistiche e video esclusivi. Segui tutte le notizie su Spoome.'>\r\n";
    echo "<meta name='twitter:image' content='$finalImageUrl'>\r\n";
    echo "<meta name='twitter:site' content='@SportSpoome'>\r\n";
    echo "<meta name='twitter:creator' content='@SportSpoome'>\r\n";

    // Se c'è un'immagine, aggiungi dimensioni
    if (!empty($imageUrl)) {
        echo "<meta property='og:image:width' content='1200'>\r\n";
        echo "<meta property='og:image:height' content='630'>\r\n";
    }

    echo "<meta name='format-detection' content='telephone=no'>\r\n";
    echo "<meta name='author' content='Spoome'>\r\n";
}

function addMetaTagsEvent(
    string $title,
    string $description,
    string $eventSlug,
    string $imageUrl = ''
): void
{
    // Adattamento URL e immagine di fallback
    $eventSlug = str_replace(" ", "-", $eventSlug);
    $imageUrl = !empty($imageUrl)
        ? $imageUrl
        : "https://www.spoome.it/network/assets/default-event.jpg";

    // Parte statica (per SEO generale)
    $baseTitle = "Spoome - Il punto di riferimento per lo sport";
    $baseDescription = "Segui gli aggiornamenti su Spoome per non perdere neanche un'azione!";

    // Parte dinamica (per evento specifico)
    $titleSEO = htmlspecialchars("$title | $baseTitle", ENT_QUOTES, 'UTF-8');
    $descriptionSEO = htmlspecialchars("$description $baseDescription", ENT_QUOTES, 'UTF-8');
    $escapedUrl = "https://www.spoome.it/network/evento/" . htmlspecialchars($eventSlug, ENT_QUOTES, 'UTF-8');

    // ✅ Meta Tag SEO
    echo "<title>$titleSEO</title>\r\n";
    echo "<meta name='description' content='$descriptionSEO'>\r\n";
    echo "<meta name='robots' content='index, follow'>\r\n";
    echo "<link rel='canonical' href='$escapedUrl'>\r\n";

    // ✅ Open Graph Meta Tags
    echo "<meta property='og:title' content='$titleSEO'>\r\n";
    echo "<meta property='og:description' content='$descriptionSEO'>\r\n";
    echo "<meta property='og:type' content='event'>\r\n";
    echo "<meta property='og:url' content='$escapedUrl'>\r\n";
    echo "<meta property='og:image' content='$imageUrl'>\r\n";
    echo "<meta property='og:site_name' content='Spoome'>\r\n";
    echo "<meta property='og:locale' content='it_IT'>\r\n";

    // ✅ Twitter Card Meta Tags
    echo "<meta name='twitter:card' content='summary_large_image'>\r\n";
    echo "<meta name='twitter:title' content='$titleSEO'>\r\n";
    echo "<meta name='twitter:description' content='$descriptionSEO'>\r\n";
    echo "<meta name='twitter:image' content='$imageUrl'>\r\n";
    echo "<meta name='twitter:site' content='@SportSpoome'>\r\n";
    echo "<meta name='twitter:creator' content='@SportSpoome'>\r\n";

    // ✅ Dimensioni immagine per social
    if (!empty($imageUrl)) {
        echo "<meta property='og:image:width' content='1200'>\r\n";
        echo "<meta property='og:image:height' content='630'>\r\n";
    }

    // ✅ Microdati JSON-LD per evento (Google Rich Snippet)
    if (!empty($eventDate) && !empty($eventLocation)) {
        echo "<script type='application/ld+json'>
        {
            \"@context\": \"https://schema.org\",
            \"@type\": \"SportsEvent\",
            \"name\": \"$title\",
            \"startDate\": \"$eventDate\",
            \"location\": {
                \"@type\": \"Place\",
                \"name\": \"$eventLocation\"
            },
            \"image\": \"$imageUrl\",
            \"description\": \"$descriptionSEO\",
            \"organizer\": {
                \"@type\": \"Organization\",
                \"name\": \"Spoome\",
                \"url\": \"https://www.spoome.it\"
            }
        }
        </script>\r\n";
    }

    // ✅ Altri Meta Tag
    echo "<meta name='format-detection' content='telephone=no'>\r\n";
    echo "<meta name='author' content='Spoome'>\r\n";
    echo "<meta name='event-name' content='$title'>\r\n";

    // ✅ Meta Keywords per posizionamento ampio
    $keywords = htmlspecialchars("$title, evento sportivo, Spoome, $eventLocation", ENT_QUOTES, 'UTF-8');
    echo "<meta name='keywords' content='$keywords'>\r\n";
}



function substr_to_end_of_word($string, $length)
{
    if (strlen($string) <= $length) {
        return $string;
    }
    $substring = substr($string, 0, $length);
    if (substr($substring, -1) === ' ') {
        return trim($substring);
    }
    $lastSpacePosition = strrpos($substring, ' ');
    if ($lastSpacePosition === false) {
        return trim($substring);
    }
    return substr($substring, 0, $lastSpacePosition) . "...";
}

function formatBio($bio)
{
    $output = explode('<br>', $bio);
    $bio = '';
    foreach ($output as $p) {
        $close = '';
        if (strlen($p) < 100) {
            $close = $p . '. ';
        } else {
            $close = $p . '.<br><br>';
        }
        $bio .= $close;
    }
    $bio = trim($bio);
    if (str_ends_with($bio, '..')) {
        $bio = substr($bio, 0, -2) . '.';
    }
    return $bio;
}


function getTodayDate()
{
    $locale = 'it_IT';
    $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE);
    $formatter->setPattern('d MMMM');
    $today = new DateTime();
    return $formatter->format($today);
}

function getTitle($title, $h = 'h3'): string
{
    $title = ucwords(trim($title));
    return <<<HTLM
<div class="col-12">    
    <$h class='mb-3'>$title</$h>
</div>
HTLM;
}


function getShortName(Athlete $a, $len = 20): string
{
    $output = '';
    if (mb_strlen($a->title) <= $len) {
        $output = trim(ucwords($a->title));
    } else {
        $output = mb_substr(trim(ucwords($a->title)), 0, $len);
    }
    return $output;
}


function generateLinkSocial($social, $type): string
{
    if (!$social) {
        return '';
    }
    $icons = [
        'ig' => 'bi-instagram',
        'fb' => 'bi-facebook',
        'x' => 'bi-twitter-x',
        'www' => 'bi-globe-europe-africa',
        'lk' => 'bi-linkedin',
        'yt' => 'bi-youtube',
        'tv' => 'bi bi-tv',
        'tiktok' => 'bi-tiktok',
        'store' => 'bi-shop',
    ];
    if (!array_key_exists($type, $icons)) {
        return '';
    }
    return "<a class='link-secondary me-2' href='" . $social . "' target='_blank'><i class='fs-5 bi " . $icons[$type] . "'></i></a>";
}

function checkAuthApi(): bool
{
    return true;
    $header = apache_request_headers();
    $token = $header['authorization'] ?? '';

    if ($token === API_BEARER) {
        return true;
    } else {
        return false;
    }
}

function showErrors(): void
{
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}


function AthleteToArray(Athlete $ea): array
{
    if ($ea) {
        return [
            'id' => $ea->id,
            'title' => $ea->title ?? '',
            "photo" => $ea->photo ?? '',
            "name" => $ea->name ?? '',
            "surname" => $ea->surname ?? '',
            'birthplace' => $ea->birthplace ?? '',
            'birthdate' => $ea->birthdate ?? '',
            'birthyear' => $ea->birthyear ?? '',
            "activity" => $ea->activity ?? '',
            "nationality" => $ea->nationality ?? '',
            "bio" => $ea->bio ?? '',
            "shortbio" => extractShortBio($ea->bio ?? ''),
            "sport" => $ea->sport ? $ea->sport : '',
            "sex" => $ea->sex ?? '',
            "instagram" => $ea->instagram ?? '',
            "facebook" => $ea->facebook ?? '',
            "twitter" => $ea->twitter ?? '',
            "linkedin" => $ea->linkedin ?? '',
            "website" => $ea->website,
            "query" => $ea->query,
        ];
    } else {
        return [];
    }
}

function formattaData($data)
{
    $dateTime = new DateTime($data);
    $giorno = ltrim($dateTime->format('d'), '0');
    $meseInglese = $dateTime->format('F');
    $mesi = [
        'January' => 'Gennaio',
        'February' => 'Febbraio',
        'March' => 'Marzo',
        'April' => 'Aprile',
        'May' => 'Maggio',
        'June' => 'Giugno',
        'July' => 'Luglio',
        'August' => 'Agosto',
        'September' => 'Settembre',
        'October' => 'Ottobre',
        'November' => 'Novembre',
        'December' => 'Dicembre'
    ];
    $meseItaliano = $mesi[$meseInglese];
    return "$giorno $meseItaliano";
}


function slugify($string)
{
    // Mappatura manuale dei caratteri speciali
    $map = [
        'Š' => 'S', 'š' => 's', 'Đ' => 'Dj', 'đ' => 'dj', 'Ž' => 'Z', 'ž' => 'z',
        'Č' => 'C', 'č' => 'c', 'Ć' => 'C', 'ć' => 'c', 'Ñ' => 'N', 'ñ' => 'n'
    ];

    // Sostituzione caratteri speciali
    $string = strtr($string, $map);

    // Traslitterazione sicura con iconv
    $string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);

    // Sostituzione caratteri non alfanumerici con "-"
    $string = preg_replace('/[^a-zA-Z0-9]+/', '-', $string);

    // Rimozione eventuali "-" iniziali e finali
    return strtolower(trim($string, '-'));
}


function getLinkAtleta($id, $title): string
{
    if ($id && $title) {
        $slug = slugify($title);
        return SUB_ROOT . "/atleti/" . $id . "-" . $slug;
    }
    return '';
}

function toSanitize(string $value): string
{
    // Conversione a minuscolo
    $output = mb_strtolower(trim($value), 'UTF-8');

    // Rimozione di accenti e caratteri speciali
    $output = strtr($output, [
        'à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'â' => 'a', 'ê' => 'e',
        'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n'
    ]);

    // Sostituzione di spazi e apostrofi con trattino
    $output = str_replace([' ', "'"], '-', $output);

    // Rimozione di caratteri non consentiti (lascia solo lettere, numeri e trattini)
    $output = preg_replace('/[^a-z0-9-]/', '', $output);

    // Rimozione di trattini multipli o all'inizio/fine della stringa
    $output = preg_replace('/-+/', '-', $output);
    $output = trim($output, '-');

    return $output;
}

function cleanUrl($url)
{
    $host = parse_url($url ?? '', PHP_URL_HOST);
    return $host ?: $url;
}

















