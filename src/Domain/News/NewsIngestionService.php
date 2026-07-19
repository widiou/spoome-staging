<?php

namespace Spoome\Domain\News;

use Spoome\Domain\Links\SafeHttpFetcher;

/**
 * Ingestione delle news: scarica i feed RSS/Atom delle fonti "da aggiornare" (secondo l'intervallo
 * per-fonte) tramite SafeHttpFetcher (SSRF-guard), li parsa e deduplica gli articoli in news_items.
 *
 * Invocabile dall'admin (trigger manuale) e da cron (job CLI). Fire-and-forget per singola fonte:
 * un feed rotto/irraggiungibile non blocca gli altri.
 */
final class NewsIngestionService
{
    private const MAX_BYTES     = 5_000_000; // 5 MB per feed (feed grandi es. FISI ~3,8MB)
    private const MAX_PER_FEED  = 30;        // articoli processati per giro

    private NewsSourceRepository $sources;
    private NewsRepository $news;
    private SafeHttpFetcher $fetcher;

    public function __construct(?NewsSourceRepository $sources = null, ?NewsRepository $news = null, ?SafeHttpFetcher $fetcher = null)
    {
        $this->sources = $sources ?? new NewsSourceRepository();
        $this->news    = $news ?? new NewsRepository();
        $this->fetcher = $fetcher ?? new SafeHttpFetcher();
    }

    /**
     * Aggiorna le fonti dovute (o una specifica se $onlySourceId).
     * @return array{sources:int, added:int, errors:array<int,string>}
     */
    public function run(?int $onlySourceId = null, int $maxSources = 0): array
    {
        $due = $onlySourceId !== null
            ? array_filter([$this->sources->find($onlySourceId)])
            : $this->sources->dueForFetch();

        // Cap opzionale (trigger web sincrono): evita che una richiesta scarichi decine di feed lenti e
        // saturi un worker PHP. Il job CLI/cron passa 0 = nessun cap.
        if ($maxSources > 0 && count($due) > $maxSources) {
            $due = array_slice($due, 0, $maxSources);
        }

        $added  = 0;
        $errors = [];

        foreach ($due as $src) {
            $sid = (int) $src['id'];
            try {
                $res = $this->fetcher->get((string) $src['feed_url'], self::MAX_BYTES);
                if (!$res->isOk() || $res->body === '') {
                    $errors[$sid] = 'http_' . $res->status;
                    $this->sources->touchFetched($sid, null, null); // evita retry a raffica
                    continue;
                }
                $sportId = $this->sources->sportIds($sid)[0] ?? (isset($src['sport_id']) ? (int) $src['sport_id'] : null);
                $added  += $this->ingestBody($sid, $res->body, $sportId);
                $this->sources->touchFetched($sid, null, null);
            } catch (\Throwable $e) {
                $errors[$sid] = $e->getMessage();
                $this->sources->touchFetched($sid, null, null);
            }
        }

        return ['sources' => count($due), 'added' => $added, 'errors' => $errors];
    }

    /** Parsa un corpo RSS o Atom e inserisce gli articoli. @return int nuovi inseriti. */
    private function ingestBody(int $sourceId, string $body, ?int $sportId): int
    {
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET: nessun accesso di rete (anti-SSRF a livello parser). NOCDATA: srotola i CDATA.
        // NB: LIBXML_NOENT NON è impostato di proposito → nessuna sostituzione di entità → niente XXE
        // (file read) né espansione "billion laughs". Su libxml ≥2.9 le entità esterne sono già off di default.
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            return 0;
        }

        $added = 0;
        $count = 0;

        // RSS 2.0: <rss><channel><item>
        if (isset($xml->channel)) {
            foreach ($xml->channel->item as $item) {
                if ($count++ >= self::MAX_PER_FEED) { break; }
                $added += $this->store($sourceId, $sportId,
                    (string) $item->title,
                    (string) $item->link,
                    (string) $item->description,
                    $this->rssImage($item),
                    (string) $item->pubDate
                ) ? 1 : 0;
            }
            return $added;
        }

        // Atom: <feed><entry>
        if (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                if ($count++ >= self::MAX_PER_FEED) { break; }
                $link = '';
                foreach ($entry->link as $l) {
                    $rel = (string) ($l['rel'] ?? '');
                    if ($rel === '' || $rel === 'alternate') { $link = (string) ($l['href'] ?? ''); break; }
                }
                $summary = (string) ($entry->summary ?? $entry->content ?? '');
                $date    = (string) ($entry->published ?? $entry->updated ?? '');
                $added  += $this->store($sourceId, $sportId, (string) $entry->title, $link, $summary, null, $date) ? 1 : 0;
            }
        }
        return $added;
    }

    /** Estrae un'immagine da un <item> RSS (enclosure o namespace media), o null. */
    private function rssImage(\SimpleXMLElement $item): ?string
    {
        $enc = $item->enclosure['url'] ?? null;
        if ($enc !== null && $this->looksImage((string) $item->enclosure['type'] ?? '', (string) $enc)) {
            return (string) $enc;
        }
        foreach (['media', 'content'] as $ns) {
            $media = $item->children('http://search.yahoo.com/mrss/');
            if (isset($media->thumbnail)) { return (string) ($media->thumbnail['url'] ?? '') ?: null; }
            if (isset($media->content))   { return (string) ($media->content['url'] ?? '') ?: null; }
            break;
        }
        return null;
    }

    private function looksImage(string $type, string $url): bool
    {
        return str_starts_with($type, 'image/') || (bool) preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $url);
    }

    private function store(int $sourceId, ?int $sportId, string $title, string $link, string $summary, ?string $image, string $date): bool
    {
        // Il contenuto del feed è ESTERNO e non fidato (anche se la fonte è admin): allow-list di schema
        // su link e immagine → mai `javascript:`/`data:` in un href/src reso (defense-in-depth, oltre alla CSP).
        $link = trim($link);
        if (!preg_match('#^https?://#i', $link)) {
            return false; // scarta item con URL non http(s)
        }
        if ($image !== null && !preg_match('#^https?://#i', trim($image))) {
            $image = null;
        }
        $title = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $summary = trim(html_entity_decode(strip_tags($summary), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $pub = null;
        if ($date !== '') {
            $ts = strtotime($date);
            if ($ts !== false) { $pub = date('Y-m-d H:i:s', $ts); }
        }
        return $this->news->insertItem($sourceId, $link, $title, $summary, $image, $sportId, $pub);
    }
}
