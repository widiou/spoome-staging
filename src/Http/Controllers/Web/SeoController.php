<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\Config;
use Spoome\Core\Response;
use Spoome\Domain\Profiles\ProfileRepository;

/**
 * Risorse SEO: robots.txt e sitemap.xml, generati dinamicamente.
 * IMPORTANTE: fuori dalla produzione (es. beta /beta/) si scoraggia l'indicizzazione,
 * per non entrare in conflitto/duplicazione con il sito di produzione.
 */
final class SeoController
{
    public function robots(): void
    {
        if (!Config::isProduction()) {
            Response::text("User-agent: *\nDisallow: /\n");
            return;
        }
        $body = "User-agent: *\nAllow: /\n\nSitemap: " . Config::absoluteUrl('sitemap.xml') . "\n";
        Response::text($body);
    }

    public function sitemap(): void
    {
        $urls = [];
        $urls[] = ['loc' => Config::absoluteUrl(''), 'priority' => '1.0'];
        $urls[] = ['loc' => Config::absoluteUrl('atleti'), 'priority' => '0.8'];

        foreach ((new ProfileRepository())->allPublicForSitemap() as $row) {
            $urls[] = [
                'loc'     => Config::absoluteUrl(profile_path($row)),
                'lastmod' => !empty($row['updated_at']) ? substr((string) $row['updated_at'], 0, 10) : null,
                'priority' => '0.6',
            ];
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1) . '</loc>';
            if (!empty($u['lastmod'])) {
                $xml .= '<lastmod>' . $u['lastmod'] . '</lastmod>';
            }
            $xml .= '<priority>' . $u['priority'] . '</priority></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        Response::xml($xml);
    }
}
