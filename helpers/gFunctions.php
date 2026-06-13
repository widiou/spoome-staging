<?php
/**
 * Improved helper & search functions for spoome.it athlete streams.
 * NOTE: Output structure is preserved so the front‑end keeps working.
 * Author: ChatGPT – June 2025
 */

// -----------------------------------------------------------------------------
// 🛠  Global configuration
// -----------------------------------------------------------------------------

const CACHE_TTL_HOURS = 12;                // Validity of cache in hours

const NEWS_CX = '22b03366d821f4b4b';       // Google Custom Search IDs

const SOCIAL_CX = '25e6f012592fe4eac';

const VIDEO_CX = 'd79f0cafd0e9c4534';

/**
 * Common wrapper around file_get_contents with timeout & Accept‑Language header.
 */
function httpGetJson(string $url, int $timeout = 4): ?array
{
    return \Spoome\Services\GoogleSearch::http($url, $timeout);
}

/**
 * Cache loader — delega a Spoome\Services\Cache (mantenuta per retrocompatibilità).
 */
function loadCache(string $cacheFile): ?array
{
    return \Spoome\Services\Cache::get($cacheFile, CACHE_TTL_HOURS);
}

/**
 * Cache writer (atomica) — delega a Spoome\Services\Cache.
 */
function saveCache(string $cacheFile, array $data): void
{
    \Spoome\Services\Cache::put($cacheFile, $data);
}

/**
 * Try to normalise any date string into RFC 3339. Fallback to empty string.
 */
function normaliseDate(?string $str): string
{
    if (!$str) {
        return '';
    }

    // Remove surrounding whitespace
    $str = trim($str);

    // Handle relative Italian phrases (e.g. "2 giorni fa", "3 ore fa")
    if (preg_match('/(\d+)\s+(ora|ore|giorni|giorno)\s+fa/i', $str, $m)) {
        $quantity = (int)$m[1];
        $unit     = strtolower($m[2]);
        $date     = new DateTime();
        switch ($unit) {
            case 'ora':
            case 'ore':
                $date->modify("-{$quantity} hour");
                break;
            case 'giorni':
            case 'giorno':
                $date->modify("-{$quantity} day");
                break;
        }
        return $date->format(DateTime::ATOM);
    }

    // Try generic DateTime parse
    $parsed = strtotime($str);
    if ($parsed !== false) {
        return date(DateTime::ATOM, $parsed);
    }

    return '';
}

/**
 * Convenience wrapper to generate common Google parameters.
 */
function buildGoogleParams(string $query, string $cx, array $overrides = []): array
{
    return \Spoome\Services\GoogleSearch::params($query, $cx, $overrides);
}

// -----------------------------------------------------------------------------
// 🔍  Core search logic (public facing)
// -----------------------------------------------------------------------------

function searchGoogle(string $query, array $params): array|string
{
    return \Spoome\Services\GoogleSearch::search($query, $params);
}

// -----------------------------------------------------------------------------
// 📰  News stream
// -----------------------------------------------------------------------------
function getNews(Athlete $a): array|string
{
    $cacheFile = __DIR__ . "/cache/atleti/news_{$a->getId()}.json";
    if ($cached = loadCache($cacheFile)) {
        return $cached;
    }

    $query       = strlen($a->query ?? '') > 3 ? $a->query : cleanQuery($a->title);
    $searchQuery = "intitle:\"{$query}\" OR intext:\"{$query}\"";

    $params = buildGoogleParams(
        $searchQuery,
        NEWS_CX,
        [
            'excludeTerms' => 'gossip',
        ]
    );

    $results = searchGoogle($searchQuery, $params);

    if (!is_array($results) || !$results) {
        // fallback: rimuovi il filtro data e semplifica la query
        $searchQuery = "\"{$query}\"";
        $params = buildGoogleParams($searchQuery, NEWS_CX);
        $results = searchGoogle($searchQuery, $params);
    }


    if (is_array($results)) {
        saveCache($cacheFile, $results);
    }

    return $results;
}

function getSocialStream(Athlete $a): array|string
{
    $cacheFile = __DIR__ . "/cache/atleti/social_{$a->getId()}.json";
    if ($cached = loadCache($cacheFile)) {
        return $cached;
    }
    $query = strlen($a->query ?? '') > 3 ? $a->query : cleanQuery($a->title);
    $query = "intitle:\"{$query}\"";
    $params = buildGoogleParams(
        $query,
        SOCIAL_CX,
        [
            'excludeTerms' => 'gossip',
        ]
    );

    $results = searchGoogle($query, $params);
    if (is_array($results)) {
        saveCache($cacheFile, $results);
    }
    return $results;
}

function getVideo(Athlete $a): array|string
{
    $cacheFile = __DIR__ . "/cache/atleti/video_{$a->getId()}.json";
    if ($cached = loadCache($cacheFile)) {
        return $cached;
    }

    $query = strlen($a->query ?? '') > 3 ? $a->query : cleanQuery($a->title);
    $query = "intitle:\"{$query}\" OR intext:\"{$query}\"";

    $params = buildGoogleParams(
        $query,
        VIDEO_CX,
        [
            'tbm'  => 'vid',
            'excludeTerms' => 'gossip',
        ]
    );

    $results = searchGoogle($query, $params);

    if (is_array($results)) {
        saveCache($cacheFile, $results);
    }

    return $results;
}

function formatDateNews($inputDate)
{
    if (!$inputDate) {
        return '';
    }
    $locale    = 'it_IT';
    $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE);
    $formatter->setPattern('HH:mm d MMM yy');
    $date = new DateTime($inputDate);
    $out  = $formatter->format($date) ?: '';
    return ($out === '00:00 1 gen 70') ? '' : $out;
}

function getIcon($source): string
{
    if (!$source) {
        return 'bi-globe-europe-africa';
    }
    $source = strtolower($source);
    return str_contains($source, 'twitter')   ? 'bi-twitter-x'
        : (str_contains($source, 'facebook') ? 'bi-facebook'
            : (str_contains($source, 'instagram')? 'bi-instagram'
                : 'bi-globe-europe-africa'));
}

function cleanQuery($query): string
{
    // Transliteration & basic sanitisation (same logic, compact form)
    $replace_pairs = [
        "í"=>"i","ú"=>"u","æ"=>"ae","á"=>"a","č"=>"c","ç"=>"c","ă"=>"a","ț"=>"t","Ş"=>"s","ş"=>"s","é"=>"e","è"=>"e","ê"=>"e","ë"=>"e","ì"=>"i","î"=>"i","ï"=>"i","ó"=>"o","ò"=>"o","ô"=>"o","ö"=>"o","ù"=>"u","û"=>"u","ü"=>"u","ý"=>"y","ÿ"=>"y","ć"=>"c","đ"=>"d","ð"=>"d","ñ"=>"n","š"=>"s","ž"=>"z","ß"=>"ss","ğ"=>"g","õ"=>"o","à"=>"a","â"=>"a","ä"=>"a","å"=>"a","ř"=>"r","Æ"=>"AE","Œ"=>"OE","œ"=>"oe","ı"=>"i","ł"=>"l","ń"=>"n","ę"=>"e","ã"=>"a","ø"=>"o","Ť"=>"t"
    ];

    $query = strtr($query, $replace_pairs);
    $query = preg_replace('/[\'"“”‘’«»„…()\[\]{}]/u', '', $query);
    $query = preg_replace('/\s+/', ' ', trim($query));
    $query = preg_replace('/[^a-zA-ZÀ-ÖØ-öø-ÿ\s\-]/u', '', $query);

    return $query;
}

function generateHashtag($name)
{
    $name = strtolower(preg_replace('/[^a-z0-9\s]/', '', $name));
    return '#' . str_replace(' ', '', $name);
}

function cleanSnippet($snippet): string
{
    $snippet = str_replace('...', ' ', $snippet ?? '');
    $snippet = trim(substr($snippet, 11));
    return substr($snippet, 0, 250);
}

function truncateText($text, $maxLength)
{
    return (strlen($text) > $maxLength) ? substr($text, 0, $maxLength - 3) . '...' : $text;
}

function getLiveNews(): array|string
{
    $params = buildGoogleParams(
        'inurl:sky',
        'a65e9d02fc7b348a6',
        [
            'num'  => 3,
            'sort' => 'date',
        ]
    );

    return searchGoogle('inurl:sky', $params);
}

function extractDateFromUrl($url)
{
    if (preg_match('/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $url, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    return null;
}

function extractDateFromSnippet($snippet)
{
    if (preg_match('/(\d{1,2})\s+(\w+)\s+(\d{4})/', $snippet, $m)) {
        return date('Y-m-d', strtotime("{$m[1]} {$m[2]} {$m[3]}"));
    }
    return null;
}

// -----------------------------------------------------------------------------
// End of file
// -----------------------------------------------------------------------------
?>
