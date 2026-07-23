<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\Logger;
use Spoome\Core\Request;
use Spoome\Domain\Og\OgImageService;

/**
 * Endpoint pubblico dell'og:image di un profilo: `GET /og/atleti/{handle}.png`.
 *
 * Pubblico e SENZA sessione (i crawler — WhatsApp/Telegram/Facebook — non hanno cookie; il front controller
 * salta Session::start per il prefisso /og/). Read-only, nessun CSRF (GET). Espone SOLO dati pubblici del
 * profilo, mai PII sensibile.
 *
 * REGOLA D'ORO (come i nav-helper): non deve MAI dare 500 né un'anteprima rotta. Ogni ramo termina con byte
 * PNG validi (card ricca → card di brand → floor costante).
 */
final class OgImageController
{
    public function show(Request $request): void
    {
        // TODO(rate-limit, difesa in profondità): un throttle per-IP sul PRIMO render dei profili validi
        // sarebbe l'ultima difesa residua. NON riuso `Domain\Auth\RateLimiter` qui: è DB-backed (una INSERT
        // per hit) → su un endpoint pubblico ad alto volume sarebbe amplificazione di scritture, peggiore del
        // vettore che chiude. Il vettore DoS principale (render GD ad ogni hit su handle inesistenti) è già
        // eliminato: la brand-card è renderizzata una volta e servita da disco, le card reali sono cachate.
        // Se servirà, valutare un limiter in-memory (APCu, come Core\Cache) leggero, non il tabellone auth.
        $handle = (string) $request->param('handle', '');

        $bytes = '';
        $fallback = true;
        try {
            $result  = (new OgImageService())->imageFor($handle);
            $bytes   = $result['bytes'];
            $fallback = $result['fallback'];
        } catch (\Throwable $e) {
            Logger::error('og_image_failed', ['handle' => $handle, 'err' => $e->getMessage()]);
        }
        if ($bytes === '') {
            $bytes = OgImageService::floor();
            $fallback = true;
        }

        if (!headers_sent()) {
            // Endpoint sessionless: nessun Set-Cookie deve accompagnare un'immagine cachabile (difensivo:
            // il boot già non avvia la sessione per /og/).
            header_remove('Set-Cookie');
            header('Content-Type: image/png');
            header('Content-Length: ' . strlen($bytes));
            if ($fallback) {
                // RIPIEGO (brand/floor): mai cachato a lungo sull'URL versionato → quando il profilo diventa
                // pubblico / il rendering torna a posto, la card reale rimpiazza subito l'anteprima. Il .htaccess
                // esclude /og/ dalla regola asset-immutable → questo header vince (anti cache-poisoning).
                header('Cache-Control: no-store');
            } else {
                // Card reale: URL content-addressed via ?v=firma → cache lunga sicura.
                header('Cache-Control: public, max-age=31536000, immutable');
            }
            // La card non è una pagina: non deve entrare nell'indice come URL a sé.
            header('X-Robots-Tag: noindex');
        }

        echo $bytes;
    }
}
