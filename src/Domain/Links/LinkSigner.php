<?php

namespace Spoome\Domain\Links;

use RuntimeException;
use Spoome\Core\Config;

/**
 * Firma HMAC degli URL immagine serviti dall'image-proxy. È il cardine anti-SSRF del proxy:
 * il proxy accetta SOLO URL che ABBIAMO derivato da un unfurl (quindi già passati dai controlli di
 * SafeHttpFetcher a monte) e firmato — mai un `u=` arbitrario fornito da un attaccante.
 *
 * Token = base64url(payload) . '.' . base64url(hmac_sha256(payload, secret)), payload = "exp|url".
 * `exp` limita la validità (difesa in profondità: anche un token trapelato scade).
 *
 * Segreto: `LINK_SIGNING_SECRET` se presente, altrimenti derivato in modo stabile da secret già sul
 * server (DB_PASS + MIGRATION_TOKEN) — così funziona senza provisioning di env aggiuntivo sul deploy
 * (il .env NON viene deployato). L'attaccante non conosce DB_PASS → non può forgiare firme.
 */
final class LinkSigner
{
    // TTL corto (48h): riduce la finestra d'uso di un token trapelato come relay immagini. Le card del
    // feed NON dipendono da questo TTL perché vengono RI-FIRMATE ad ogni render (LinkPreviewPresenter).
    private const TTL_SECONDS = 172800; // 48 ore

    private static function secret(): string
    {
        $explicit = (string) Config::get('LINK_SIGNING_SECRET', '');
        if ($explicit !== '') {
            return $explicit;
        }
        // FAIL-CLOSED: senza secret esplicito, si deriva da segreti già sul server (DB_PASS + MIGRATION_TOKEN).
        // Ma se DB_PASS è vuoto a runtime, il segreto derivato diventerebbe una COSTANTE PUBBLICA nota →
        // token forgiabili. In quel caso è più sicuro NON firmare/verificare affatto: solleva (fail closed).
        $dbPass = (string) Config::get('DB_PASS', '');
        if ($dbPass === '') {
            throw new RuntimeException('link_signing_secret_unavailable');
        }
        return hash(
            'sha256',
            $dbPass . '|spoome-link-proxy-v1|' . (string) Config::get('MIGRATION_TOKEN', '')
        );
    }

    /** Firma un URL immagine e ritorna il token opaco (da mettere in ?u=). */
    public static function sign(string $url): string
    {
        $exp = time() + self::TTL_SECONDS;
        $payload = $exp . '|' . $url;
        $sig = hash_hmac('sha256', $payload, self::secret(), true);
        return self::b64url($payload) . '.' . self::b64url($sig);
    }

    /**
     * Verifica il token e ritorna l'URL originale, oppure null se firma non valida / scaduta / malformata.
     * Confronto in tempo costante (hash_equals) contro attacchi timing.
     */
    public static function verify(string $token): ?string
    {
        $dot = strpos($token, '.');
        if ($dot === false) {
            return null;
        }
        $payload = self::b64urlDecode(substr($token, 0, $dot));
        $sig = self::b64urlDecode(substr($token, $dot + 1));
        if ($payload === null || $sig === null) {
            return null;
        }
        $expected = hash_hmac('sha256', $payload, self::secret(), true);
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $sep = strpos($payload, '|');
        if ($sep === false) {
            return null;
        }
        $exp = (int) substr($payload, 0, $sep);
        $url = substr($payload, $sep + 1);
        if ($exp < time() || $url === '') {
            return null;
        }
        return $url;
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): ?string
    {
        $decoded = base64_decode(strtr($s, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
