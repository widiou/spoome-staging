<?php

function getAthleteFromWikipedia(string $athlete, string $originalAthlete = null): ?Athlete
{
    $originalAthlete = $originalAthlete ?? $athlete;
    $data = getWikipediaContent($athlete);
    if (!$data || empty($data['title'])) {
        error_log("ERRORE: Titolo non trovato per '$athlete'");
        return null;
    }
    if (!empty($data['data']) && preg_match('/#rinvia \[\[(.*?)\]\]/u', trim(strip_tags($data['data'])), $matches)) {
        $redirectedName = trim($matches[1]);
        return getAthleteFromWikipedia($redirectedName, $originalAthlete);
    }
    $bioData = extractBioData($data['data']);
    $sportData = extractSportData($data['data']);
    if (empty($bioData) || empty($sportData)) {
        return null;
    }
    $obja = new Athlete();

    $obja->title = $originalAthlete ?? $athlete;

    $obja->photo = !empty($data['photo']) ? trim($data['photo']) : SQUARE_PLACEHOLDER;
    $obja->name = $bioData['Nome'] ?? "";
    $obja->surname = $bioData['Cognome'] ?? "";
    $obja->birthplace = $bioData['LuogoNascita'] ?? "";
    $obja->birthdate = $bioData['GiornoMeseNascita'] ?? "";
    $obja->birthyear = $bioData['AnnoNascita'] ?? "";
    $obja->activity = $sportData['Attività'] ?? "";
    $obja->nationality = $bioData['Nazionalità'] ?? "";
    $obja->bio = $data['description'] ?? "";
    $obja->sport = $sportData['Disciplina'] ?? $sportData['Sport'] ?? "";
    $obja->sex = $bioData['Sesso'] ?? "";
    try {
        $obja->save();
        return $obja;

    } catch (Exception $e) {
        error_log($e->getMessage());
        return null;
    }
}


function normalizePageTitle($title): array|string
{
    return str_replace(
        ['à', 'è', 'é', 'ì', 'ò', 'ù', 'À', 'È', 'É', 'Ì', 'Ò', 'Ù', 'ā', 'ē', 'ī', 'ō', 'ū', 'Ā', 'Ē', 'Ī', 'Ō', 'Ū', ' '],
        ['a', 'e', 'e', 'i', 'o', 'u', 'A', 'E', 'E', 'I', 'O', 'U', 'a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', '_'],
        $title ?? ''
    );
}

function fetchWikipediaPage($page, $lang = 'it')
{
    $url = "https://$lang.wikipedia.org/w/api.php";
    $params = [
        "action" => "query",
        "format" => "json",
        "prop" => "extracts|pageimages|revisions",
        "exintro" => true,
        "explaintext" => true,
        "titles" => $page,
        "rvprop" => "content",
        "rvsection" => 0,
        "piprop" => "thumbnail|original", // Aggiunto per ottenere l'immagine
        "pithumbsize" => 500 // Specifica la dimensione minima della miniatura
    ];
    return makeCurlRequest($url, $params);
}


function fetchCorrectTitleFromWikidata($title)
{
    $url = "https://www.wikidata.org/w/api.php";
    $params = [
        "action" => "wbsearchentities",
        "search" => $title,
        "language" => "it",
        "type" => "item",
        "format" => "json",
        "limit" => 1,
    ];

    $data = makeCurlRequest($url, $params);

    return $data['search'][0]['label'] ?? null;
}

function makeCurlRequest($url, $params, $timeout = 5)
{
    $apiUrl = $url . "?" . http_build_query($params);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    // Wikimedia richiede uno User-Agent descrittivo: senza, risponde 403.
    curl_setopt($ch, CURLOPT_USERAGENT, 'Spoome/1.0 (https://spoome.it; info@spoome.it)');
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        \Spoome\Core\Logger::warning('Curl error (Wikipedia)', ['err' => curl_error($ch)]);
        curl_close($ch);
        return [];
    }

    curl_close($ch);
    $data = json_decode($response, true);
    return is_array($data) ? $data : [];
}

function parseWikiData($data): array
{
    $photo = '';
    if (isset($data['pageid'])) {
        if (isset($data['thumbnail']['source'])) {
            $photo = $data['thumbnail']['source']; // Prova a prendere l'immagine ridotta
        } elseif (isset($data['original']['source'])) {
            $photo = $data['original']['source']; // Prova a prendere l'immagine originale
        }
    }
    return [
        "title" => $data['titles']['normalized'] ?? $data['titles']['canonical'] ?? $data['title'] ?? '',
        "photo" => $photo,
        "description" => $data['extract'] ?? '',
        "data" => $data['revisions'][0]['*'] ?? '',
    ];
}


function extractBioData($content): array
{
    $bioData = [];
    $regex = '/\{\{(Bio|Divisione amministrativa)(.*?)\}\}/s';

    if (preg_match_all($regex, $content ?? '', $matches)) {
        foreach ($matches[2] as $match) {
            $cleanedMatch = preg_replace('/\s+/', ' ', $match);
            $cleanedMatch = html_entity_decode($cleanedMatch, ENT_QUOTES, 'UTF-8');
            $cleanedMatch = json_decode('"' . str_replace('"', '\"', $cleanedMatch) . '"');
            $fields = explode('|', $cleanedMatch);
            foreach ($fields as $field) {
                [$key, $value] = explode('=', $field, 2) + [null, null];
                if ($key && $value) {
                    $bioData[trim($key)] = parseNestedTemplate(trim(strip_tags($value)));
                }
            }
        }
    }
    return $bioData;
}

// ✅ Parsing ricorsivo dei template annidati (esempio: {{cita web}})
function parseNestedTemplate($value)
{

    $regex = '/\{\{(.*?)\}\}/s';
    return preg_replace_callback($regex, function ($matches) {
        $parts = explode('|', $matches[1]);
        $result = [];
        foreach ($parts as $part) {
            [$key, $val] = explode('=', $part, 2) + [null, null];
            if ($key && $val) {
                $result[trim($key)] = trim($val);
            } else {
                $result[] = trim($part);
            }
        }
        return json_encode($result);
    }, $value);
}



function extractSportData($content): array
{
    $output = [];

    // ✅ Primo tentativo: metodo originale (parsing generico)
    $genericData = parseGenericSportData($content);

    // ✅ Secondo tentativo: metodo con regex per infobox sportivi generici
    $specificData = parseSpecificSportData($content);

    // ✅ Merge dei dati con sovrascrittura solo se il campo non è vuoto
    $output = array_merge($genericData, array_filter($specificData));

    return $output;
}

// ✅ Metodo originale (parsing generico)
function parseGenericSportData($content): array
{
    $output = [];
    $content = str_replace(
        ['= {{', '{{', '}}', '[[', ']]', '='],
        ['==', '|', '|', '|', '|', '=='],
        $content
    );

    $content = array_filter(explode('|', $content));

    foreach ($content as $item) {
        $newValue = explode('==', $item);
        if (count($newValue) === 2) {
            $key = trim($newValue[0]);
            $value = trim($newValue[1]);
            $output[$key] = $value;
        }
    }
    return $output;
}

// ✅ Metodo specifico (parsing tramite regex) con regex più flessibile
function parseSpecificSportData($content): array
{
    $output = [];

    // ✅ Regex per catturare qualsiasi Infobox sportivo (nome flessibile)
    $regex = '/\{\{Infobox (sportivo|atleta|squadra|evento)(.*?)\}\}/s';

    if (preg_match_all($regex, $content, $matches)) {
        foreach ($matches[2] as $match) {
            // Pulisce i ritorni di linea e caratteri HTML
            $cleanedMatch = preg_replace('/\s+/', ' ', $match);
            $cleanedMatch = html_entity_decode($cleanedMatch, ENT_QUOTES, 'UTF-8');

            $fields = explode('|', $cleanedMatch);
            foreach ($fields as $field) {
                [$key, $value] = explode('=', $field, 2) + [null, null];
                if ($key && $value) {
                    $key = trim($key);
                    $value = trim(strip_tags($value));
                    if (in_array($key, ['Disciplina', 'Attività', 'Squadra', 'Posizione', 'Nazionalità', 'Sport'])) {
                        $output[$key] = $value;
                    }
                }
            }
        }
    }
    return $output;
}



function parseTemplateFields($templateContent): array
{
    $fields = [];
    foreach (explode('{{"|"}}', $templateContent) as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $fields[trim($key)] = trim($value);
        }
    }
    return $fields;
}

function wikiString($value): string
{
    return ucfirst(str_replace(['[[', ']]', '{{', '}}'], '', $value));
}

function wikiLabel($key): string
{
    $labels = [
        "AnnoNascita" => "Anno",
        "GiornoMeseNascita" => "Nato il",
        "LuogoNascita" => "Nato a",
        "PostNazionalità" => "Attività",
        "NazionalitàNaturalizzato" => "Naturalizzato",
        "Attività3" => "Altra attività",
        "Attività2" => "Altra attività",
        "Epoca" => "NaN",
        "Epoca2" => "NaN",
        "ForzaOrdinamento" => "NaN",
        "PostCognomeVirgola" => "NaN",
        "LuogoNascitaLink" => "NaN",
        "PreData" => "NaN"
    ];
    return $labels[$key] ?? $key;
}

function searchWikipediaWithSport($query, $limit = 10)
{
    $wikipediaUrl = "https://it.wikipedia.org/w/api.php";
    $wikipediaParams = [
        "action" => "query",
        "list" => "search",
        "srsearch" => $query,
        "format" => "json",
        "srlimit" => $limit,
        "srprop" => "snippet|titlesnippet",
    ];

    $wikipediaData = makeCurlRequest($wikipediaUrl, $wikipediaParams);

    if (isset($wikipediaData['error'])) {
        return "Errore API di Wikipedia: " . $wikipediaData['error']['info'];
    }

    $searchResults = $wikipediaData['query']['search'];
    $filteredResults = [];

    foreach ($searchResults as $result) {
        $wikidataId = getWikidataId($result['title']);
        if ($wikidataId && hasSportProperty($wikidataId)) {
            $filteredResults[] = $result;
        }
    }

    return $filteredResults;
}

function getWikidataId($title)
{
    $url = "https://it.wikipedia.org/w/api.php";
    $params = [
        "action" => "query",
        "format" => "json",
        "titles" => $title,
        "prop" => "pageprops",
        "ppprop" => "wikibase_item",
    ];

    $data = makeCurlRequest($url, $params);
    $pageId = array_key_first($data['query']['pages']);

    return $data['query']['pages'][$pageId]['pageprops']['wikibase_item'] ?? null;
}

function hasSportProperty($wikidataId)
{
    $url = "https://www.wikidata.org/w/api.php";
    $params = [
        "action" => "wbgetentities",
        "ids" => $wikidataId,
        "props" => "claims",
        "format" => "json",
        "languages" => "it",
    ];

    $data = makeCurlRequest($url, $params);

    return isset($data['entities'][$wikidataId]['claims']['P641']);
}



