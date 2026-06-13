<?php

namespace Spoome\Services;

/**
 * Motore di ricerca via Google Custom Search (news/social/video).
 * Estratto da helpers/gFunctions.php: httpGetJson/buildGoogleParams/searchGoogle
 * (che ora delegano qui). Le funzioni di formattazione (truncateText, normaliseDate,
 * getIcon, extractDate*, formatDateNews) restano globali e vengono richiamate via "\".
 */
final class GoogleSearch
{
    private const ENDPOINT = 'https://www.googleapis.com/customsearch/v1?';

    /** GET JSON con timeout e Accept-Language. */
    public static function http(string $url, int $timeout = 4): ?array
    {
        $context = \stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header'  => "Accept-Language: it-IT\r\n",
            ],
        ]);

        $raw = @\file_get_contents($url, false, $context);
        if ($raw === false) {
            return null;
        }
        $data = \json_decode($raw, true);

        return \is_array($data) ? $data : null;
    }

    /** Parametri di default per la Custom Search. */
    public static function params(string $query, string $cx, array $overrides = []): array
    {
        $default = [
            'q'    => $query,
            'cx'   => $cx,
            'key'  => \GOOGLE_API_KEY,
            'num'  => 8,
            'lr'   => 'lang_it',
            'safe' => 'high',
            'sort' => 'date',
        ];
        return \array_merge($default, $overrides);
    }

    /**
     * Esegue la ricerca e normalizza i risultati.
     * Ritorna un array di risultati, oppure una stringa HTML "nessun risultato".
     */
    public static function search(string $query, array $params): array|string
    {
        $errorMsg = "<p style='color: var(--gray)'>Nessun post trovato.</p>";

        $params['q'] = $query;
        $url  = self::ENDPOINT . \http_build_query($params);
        $data = self::http($url);

        if (!$data || !isset($data['items'])) {
            return $errorMsg;
        }

        $results       = [];
        $titleRegistry = [];

        foreach ($data['items'] as $item) {
            $title   = \truncateText($item['title'] ?? '', 250);
            $snippet = \truncateText($item['snippet'] ?? '', 150);

            $titleKey = \mb_strtolower($title, 'UTF-8');
            if (isset($titleRegistry[$titleKey])) {
                continue; // duplicato ⇒ salta
            }
            $titleRegistry[$titleKey] = true;

            $metaDate = $item['pagemap']['metatags'][0]['article:published_time']
                ?? $item['pagemap']['metatags'][0]['article:modified_time']
                ?? $item['pagemap']['metatags'][0]['last-modified']
                ?? '';

            $snippetDate = \extractDateFromSnippet($snippet);
            $urlDate     = \extractDateFromUrl($item['link'] ?? '') ?? '';
            $finalDate   = \normaliseDate($metaDate ?: $snippetDate ?: $urlDate);

            $results[] = [
                'title'    => $title,
                'link'     => $item['link'] ?? '',
                'snippet'  => $snippet,
                'thumb'    => $item['pagemap']['cse_thumbnail'][0]['src']
                    ?? 'https://www.spoome.it/' . \SQUARE_PLACEHOLDER,
                'datepost' => $finalDate,
                'newsdate' => \formatDateNews($finalDate),
                'source'   => \str_replace('www.', '', $item['displayLink'] ?? ''),
                'icon'     => \getIcon($item['displayLink'] ?? ''),
                'isEmpty'  => false,
            ];
        }

        \usort($results, static function ($a, $b) {
            return new \DateTime($b['datepost'] ?: '1970-01-01') <=> new \DateTime($a['datepost'] ?: '1970-01-01');
        });

        return $results;
    }
}
