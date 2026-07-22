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
        $handle = (string) $request->param('handle', '');

        $bytes = '';
        try {
            $bytes = (new OgImageService())->imageFor($handle);
        } catch (\Throwable $e) {
            Logger::error('og_image_failed', ['handle' => $handle, 'err' => $e->getMessage()]);
        }
        if ($bytes === '') {
            $bytes = OgImageService::floor();
        }

        if (!headers_sent()) {
            // Endpoint sessionless: nessun Set-Cookie deve accompagnare un'immagine cachabile (difensivo:
            // il boot già non avvia la sessione per /og/).
            header_remove('Set-Cookie');
            header('Content-Type: image/png');
            header('Content-Length: ' . strlen($bytes));
            // URL versionato (?v=firma) → sicuro cachare a lungo. Il .htaccess "asset" può elevare a immutable
            // per le .png; qui teniamo comunque un valore pubblico esplicito.
            header('Cache-Control: public, max-age=86400');
            // La card non è una pagina: non deve entrare nell'indice come URL a sé.
            header('X-Robots-Tag: noindex');
        }

        echo $bytes;
    }
}
