<?php

namespace Spoome\Core;

/**
 * Header di sicurezza emessi dal front controller — difesa INDIPENDENTE dal transport.
 *
 * Gli stessi header sono impostati anche via .htaccess (mod_headers), ma quel file è legato
 * alla docroot corrente: se un domani la docroot passa a public/ e la config Apache cambia,
 * gli header via .htaccess potrebbero sparire in silenzio. Emetterli qui garantisce che
 * CSP e header di sicurezza accompagnino OGNI risposta applicativa comunque.
 *
 * Nota: Apache `Header set` (se presente) SOSTITUISCE gli header omonimi impostati da PHP,
 * quindi non c'è duplicazione quando entrambi sono attivi.
 */
final class SecurityHeaders
{
    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Cross-Origin-Opener-Policy: same-origin');
        // frame-src: SOLO gli host di embed video allow-listed (iframe sandboxed costruiti da noi).
        // Le immagini di anteprima passano dall'image-proxy same-origin → img-src 'self' le copre già
        // (nessun host esterno in img-src: niente hotlink, niente leak di IP verso terzi).
        header(
            "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
            . "font-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; "
            . "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
            . "frame-ancestors 'self'; form-action 'self'; upgrade-insecure-requests"
        );
    }
}
