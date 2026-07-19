<?php

namespace Spoome\Domain\Links;

use Spoome\Core\I18n;
use Spoome\Core\Logger;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Auth\RateLimiter;
use Throwable;

/**
 * Unfurl sicuro di un URL → anteprima ricca. Flusso:
 *  (a) host in allow-list oEmbed/video (YouTube, Vimeo) → oEmbed ufficiale + iframe COSTRUITO DA NOI
 *      (mai l'html_embed remoto) → {type:'video', title, image, provider, embed_url, author};
 *  (b) altrimenti fetch pagina HTML e parse Open Graph / Twitter Card / <title> → {type:'link', ...}.
 *
 * OGNI fetch passa da SafeHttpFetcher (SSRF-guard). Ogni campo è conservato GREZZO e la view lo escapa
 * con e(): niente HTML remoto entra mai nel DOM. L'immagine di anteprima NON è re-hostata (R2 differito):
 * si passa dal NOSTRO image-proxy firmato (image_proxy_path) — swap→R2 = un solo punto qui.
 */
final class LinkUnfurlService
{
    private const CACHE_TTL      = 604800;             // 7 giorni
    private const HTML_MAX_BYTES = 2 * 1024 * 1024;    // 2 MB pagina
    private const JSON_MAX_BYTES = 256 * 1024;         // 256 KB oEmbed
    private const RL_MAX         = 30;                 // unfurl/finestra
    private const RL_WINDOW_MIN  = 10;

    private SafeHttpFetcher $http;
    private LinkPreviewRepository $repo;
    private RateLimiter $limiter;

    public function __construct(?SafeHttpFetcher $http = null, ?LinkPreviewRepository $repo = null, ?RateLimiter $limiter = null)
    {
        $this->http = $http ?? new SafeHttpFetcher();
        $this->repo = $repo ?? new LinkPreviewRepository();
        $this->limiter = $limiter ?? new RateLimiter();
    }

    /** @return ServiceResult ok(preview) | fail(422 url · 429 throttle · 502 unreachable) */
    public function unfurl(string $rawUrl, int $profileId, string $ip = 'unknown'): ServiceResult
    {
        $url = $this->normalize($rawUrl);
        if ($url === null) {
            return ServiceResult::fail(I18n::t('link.error.invalid'), 422);
        }

        if ($this->limiter->tooManyByKey('unfurl:' . $profileId, self::RL_MAX, self::RL_WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('auth.error.throttled'), 429);
        }
        $this->limiter->hit('unfurl:' . $profileId, $ip);

        $hash = hash('sha256', $url);

        // Cache fresca? (inclusa la cache negativa: blocked/failed → non ritentare in loop)
        $cached = $this->repo->findFresh($hash);
        if ($cached !== null) {
            if ($cached['status'] !== 'ok') {
                return ServiceResult::fail(I18n::t('link.error.unreachable'), 422);
            }
            return ServiceResult::ok($this->present($cached));
        }

        try {
            $preview = $this->build($url, $hash);
        } catch (Throwable $e) {
            Logger::info('Unfurl fallito', ['url_host' => parse_url($url, PHP_URL_HOST), 'reason' => $e->getMessage()]);
            // Cache negativa breve per non amplificare tentativi verso host lenti/ostili.
            $this->repo->upsert($this->blankRow($url, $hash, 'failed'), 3600);
            return ServiceResult::fail(I18n::t('link.error.unreachable'), 422);
        }

        $this->repo->upsert($preview, self::CACHE_TTL);
        return ServiceResult::ok($this->present($preview));
    }

    /** Costruisce la riga anteprima (grezza) per l'URL. @throws Throwable in caso di fetch non valido. */
    private function build(string $url, string $hash): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $video = $this->tryVideo($url, $host, $hash);
        if ($video !== null) {
            return $video;
        }
        return $this->parsePage($url, $hash, $host);
    }

    /* --------------------------------------------------------------- video (allow-list) ---- */

    /** YouTube/Vimeo: oEmbed ufficiale + iframe costruito da noi. Null se host non allow-listed. */
    private function tryVideo(string $url, string $host, string $hash): ?array
    {
        if ($this->hostMatches($host, ['youtube.com', 'youtu.be', 'youtube-nocookie.com'])) {
            $id = $this->youtubeId($url);
            if ($id === null) {
                return null;
            }
            $oembed = $this->oembed('https://www.youtube.com/oembed?format=json&url=' . rawurlencode($url));
            $img = 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
            return $this->row($url, $hash, 'video', [
                'title'     => $oembed['title'] ?? ('YouTube · ' . $id),
                'author'    => $oembed['author_name'] ?? null,
                'image_url' => $img,
                'site_name' => 'YouTube',
                'provider'  => 'YouTube',
                'embed_url' => 'https://www.youtube-nocookie.com/embed/' . $id,
                'embed_html' => $oembed['html'] ?? null,
                'domain'    => 'youtube.com',
            ]);
        }

        if ($this->hostMatches($host, ['vimeo.com', 'player.vimeo.com'])) {
            $id = $this->vimeoId($url);
            if ($id === null) {
                return null;
            }
            $oembed = $this->oembed('https://vimeo.com/api/oembed.json?url=' . rawurlencode($url));
            return $this->row($url, $hash, 'video', [
                'title'     => $oembed['title'] ?? ('Vimeo · ' . $id),
                'author'    => $oembed['author_name'] ?? null,
                'image_url' => $oembed['thumbnail_url'] ?? null,
                'site_name' => 'Vimeo',
                'provider'  => 'Vimeo',
                'embed_url' => 'https://player.vimeo.com/video/' . $id,
                'embed_html' => $oembed['html'] ?? null,
                'domain'    => 'vimeo.com',
            ]);
        }

        return null;
    }

    /** Interroga un endpoint oEmbed ufficiale via SafeHttpFetcher. Ritorna il JSON decodato o []. */
    private function oembed(string $endpoint): array
    {
        try {
            $res = $this->http->get($endpoint, self::JSON_MAX_BYTES);
            if (!$res->isOk() || $res->truncated) {
                return [];
            }
            $json = json_decode($res->body, true);
            return is_array($json) ? $json : [];
        } catch (Throwable) {
            return []; // oEmbed opzionale: degradiamo a card video con thumbnail id-based
        }
    }

    /* ------------------------------------------------------------------- pagina (OG) ---- */

    private function parsePage(string $url, string $hash, string $host): array
    {
        $res = $this->http->get($url, self::HTML_MAX_BYTES);
        // Content-Type gate: solo HTML/XHTML per la pagina.
        if (!in_array($res->contentType, ['text/html', 'application/xhtml+xml', ''], true)) {
            throw new \RuntimeException('bad_content_type:' . $res->contentType);
        }
        if (!$res->isOk() && ($res->status < 200 || $res->status >= 400)) {
            throw new \RuntimeException('http_' . $res->status);
        }

        $html = $res->body;
        $og = $this->extractMeta($html);

        $title = $og['og:title'] ?? $og['twitter:title'] ?? $this->extractTitle($html);
        $descr = $og['og:description'] ?? $og['twitter:description'] ?? ($og['description'] ?? null);
        $imgRaw = $og['og:image'] ?? $og['og:image:url'] ?? $og['twitter:image'] ?? $og['twitter:image:src'] ?? null;
        $image = $imgRaw !== null ? $this->absolutize($imgRaw, $url) : null;

        return $this->row($url, $hash, 'link', [
            'title'     => $title,
            'description' => $descr,
            'image_url' => $image,
            'site_name' => $og['og:site_name'] ?? null,
            'provider'  => null,
            'domain'    => $this->registrableDomain($host),
        ]);
    }

    /* ------------------------------------------------------------------------- utils ---- */

    /** Compone una riga anteprima completa, valorizzando l'image-proxy firmato. */
    private function row(string $url, string $hash, string $type, array $f): array
    {
        $image = isset($f['image_url']) ? $this->clean($f['image_url'], 2048) : null;
        $proxy = null;
        if ($image !== null && preg_match('#^https?://#i', $image)) {
            // Image-proxy FIRMATO (same-origin): il proxy fetcha SOLO URL che abbiamo firmato noi.
            $proxy = url('link-image') . '?u=' . LinkSigner::sign($image);
        }
        return [
            'url_hash'         => $hash,
            'url'              => $url,
            'type'             => $type,
            'title'            => $this->clean($f['title'] ?? null, 300),
            'description'      => $this->clean($f['description'] ?? null, 600),
            'image_url'        => $image,
            'image_proxy_path' => $proxy,
            'site_name'        => $this->clean($f['site_name'] ?? null, 160),
            'domain'           => $this->clean($f['domain'] ?? null, 255),
            'provider'         => $this->clean($f['provider'] ?? null, 60),
            'author'           => $this->clean($f['author'] ?? null, 200),
            'embed_url'        => isset($f['embed_url']) ? $this->clean($f['embed_url'], 600) : null,
            // NON persistiamo l'embed_html grezzo del provider: non è mai renderizzato (costruiamo noi
            // l'iframe da embed_url) → memorizzarlo sarebbe solo una latente stored-XSS. Sempre NULL.
            'embed_html'       => null,
            'status'           => 'ok',
        ];
    }

    private function blankRow(string $url, string $hash, string $status): array
    {
        return [
            'url_hash' => $hash, 'url' => $url, 'type' => 'link', 'title' => null, 'description' => null,
            'image_url' => null, 'image_proxy_path' => null, 'site_name' => null, 'domain' => null,
            'provider' => null, 'author' => null, 'embed_url' => null, 'embed_html' => null, 'status' => $status,
        ];
    }

    /** Forma pubblica (envelope) consumata da API e composer JS: espone `image` = path proxy same-origin. */
    private function present(array $r): array
    {
        return [
            'url_hash'    => $r['url_hash'],
            'url'         => $r['url'],
            'type'        => $r['type'],
            'title'       => $r['title'],
            'description' => $r['description'],
            'image'       => $r['image_proxy_path'],   // il client rende SOLO il proxy same-origin
            'site_name'   => $r['site_name'],
            'domain'      => $r['domain'],
            'provider'    => $r['provider'],
            'author'      => $r['author'],
            'embed_url'   => $r['embed_url'],
        ];
    }

    /** Normalizza l'URL: forza http/https, lowercase host, rimuove il fragment. Null se non valido. */
    private function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || mb_strlen($raw) > 2000) {
            return null;
        }
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $p = parse_url($raw);
        if ($p === false || empty($p['host'])) {
            return null;
        }
        $scheme = strtolower($p['scheme'] ?? 'https');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = strtolower($p['host']);
        if (!str_contains($host, '.')) {
            return null; // scarta hostname senza TLD (localhost, host interni) prima ancora del DNS
        }
        $url = $scheme . '://' . $host;
        if (isset($p['port'])) {
            $url .= ':' . (int) $p['port'];
        }
        $url .= $p['path'] ?? '';
        if (isset($p['query'])) {
            $url .= '?' . $p['query'];
        }
        return $url;
    }

    /** True se $host è esattamente uno dei domini o un loro sottodominio. */
    private function hostMatches(string $host, array $domains): bool
    {
        foreach ($domains as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return true;
            }
        }
        return false;
    }

    private function youtubeId(string $url): ?string
    {
        $p = parse_url($url);
        $host = strtolower($p['host'] ?? '');
        if (str_ends_with($host, 'youtu.be')) {
            if (preg_match('#/([A-Za-z0-9_-]{11})#', $p['path'] ?? '', $m)) {
                return $m[1];
            }
        }
        parse_str($p['query'] ?? '', $q);
        if (!empty($q['v']) && preg_match('#^[A-Za-z0-9_-]{11}$#', $q['v'])) {
            return $q['v'];
        }
        if (preg_match('#/(?:embed|shorts|v)/([A-Za-z0-9_-]{11})#', $p['path'] ?? '', $m)) {
            return $m[1];
        }
        return null;
    }

    private function vimeoId(string $url): ?string
    {
        if (preg_match('#vimeo\.com/(?:video/)?(\d{6,})#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Estrae i meta OG/Twitter dall'HTML. Regex mirata sui <meta ...>: robusta all'HTML malformato
     * (DOMDocument fallirebbe/aggiungerebbe superficie). I valori restano GREZZI (escape a valle in view).
     * @return array<string,string>
     */
    private function extractMeta(string $html): array
    {
        // Limita al <head> se individuabile (evita di scansionare MB di body).
        $head = stripos($html, '</head>');
        if ($head !== false) {
            $html = substr($html, 0, $head);
        }
        $out = [];
        if (preg_match_all('#<meta\b[^>]*>#i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                $key = null;
                if (preg_match('#\b(?:property|name)\s*=\s*(["\'])\s*(og:[^"\']+|twitter:[^"\']+|description)\s*\1#i', $tag, $km)) {
                    $key = strtolower($km[2]);
                }
                if ($key === null) {
                    continue;
                }
                if (preg_match('#\bcontent\s*=\s*(["\'])(.*?)\1#is', $tag, $cm)) {
                    $out[$key] = html_entity_decode(trim($cm[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }
        return $out;
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return html_entity_decode(trim(preg_replace('/\s+/', ' ', $m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }

    /** Rende assoluto un URL immagine relativo rispetto alla pagina. */
    private function absolutize(string $img, string $base): ?string
    {
        $img = trim($img);
        if ($img === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $img)) {
            return $img;
        }
        if (str_starts_with($img, '//')) {
            return 'https:' . $img;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($img, '/')) {
            return $origin . $img;
        }
        $path = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
        return $origin . $path . $img;
    }

    private function registrableDomain(string $host): string
    {
        return preg_replace('#^www\.#', '', $host);
    }

    /** Pulisce un campo testuale: strip tag/controlli, collassa spazi, tronca. */
    private function clean(?string $v, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = strip_tags($v);
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        $v = trim(preg_replace('/\s+/', ' ', $v));
        if ($v === '') {
            return null;
        }
        return mb_substr($v, 0, $max);
    }
}
