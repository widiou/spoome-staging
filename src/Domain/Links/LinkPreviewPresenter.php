<?php

namespace Spoome\Domain\Links;

/**
 * Forma render-ready di un'anteprima link (da riga DB grezza). I valori restano non-escapati: la view
 * (feed-item) li passa TUTTI da e(). `image` è il path dell'image-proxy same-origin (mai l'URL remoto).
 * Per il video si espone `embed_url` (src dell'iframe sandboxed costruito da noi) — mai l'html remoto.
 */
final class LinkPreviewPresenter
{
    public static function card(array $row): array
    {
        return [
            'type'        => (string) ($row['type'] ?? 'link'),
            'url'         => (string) ($row['url'] ?? ''),
            'title'       => $row['title'] ?? null,
            'description' => $row['description'] ?? null,
            'image'       => self::signedProxy($row),
            'site_name'   => $row['site_name'] ?? null,
            'domain'      => $row['domain'] ?? null,
            'provider'    => $row['provider'] ?? null,
            'author'      => $row['author'] ?? null,
            'embed_url'   => $row['embed_url'] ?? null,
        ];
    }

    /**
     * Path dell'image-proxy same-origin, RI-FIRMATO al momento del render dall'URL immagine grezzo:
     * così il token è sempre fresco e le card non "scadono" col TTL corto della firma. Fallback al path
     * memorizzato solo se manca l'URL sorgente (retrocompatibilità).
     */
    private static function signedProxy(array $row): ?string
    {
        $img = $row['image_url'] ?? null;
        if (is_string($img) && $img !== '' && preg_match('#^https?://#i', $img)) {
            return url('link-image') . '?u=' . LinkSigner::sign($img);
        }
        return $row['image_proxy_path'] ?? null;
    }
}
