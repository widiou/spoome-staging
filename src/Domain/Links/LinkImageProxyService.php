<?php

namespace Spoome\Domain\Links;

use Throwable;

/**
 * Image-proxy indurito per le anteprime link (R2 differito). Il server fetcha l'immagine remota
 * SOLO se l'URL è firmato da noi (LinkSigner) — quindi già derivato da un unfurl — la valida
 * (Content-Type image/* + magic-bytes reali) e ne restituisce i byte per lo streaming same-origin.
 *
 * Doppia barriera anti-SSRF/relay: (1) firma HMAC → niente `u=` arbitrario; (2) SafeHttpFetcher →
 * niente IP interni/redirect ostili. SVG è RIFIUTATO (può contenere script): solo raster.
 * Strutturato in un solo punto: sostituire il fetch con un redirect a R2 = cambio locale qui.
 */
final class LinkImageProxyService
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB per immagine di anteprima

    private SafeHttpFetcher $http;

    public function __construct(?SafeHttpFetcher $http = null)
    {
        $this->http = $http ?? new SafeHttpFetcher();
    }

    /**
     * @return array{ok:bool,status:int,mime:string,body:string}
     *   403 firma non valida/scaduta · 415 non-immagine · 502 non raggiungibile · 200 ok
     */
    public function fetch(string $token): array
    {
        $url = LinkSigner::verify($token);
        if ($url === null) {
            return ['ok' => false, 'status' => 403, 'mime' => '', 'body' => ''];
        }

        try {
            $res = $this->http->get($url, self::MAX_BYTES);
        } catch (Throwable) {
            return ['ok' => false, 'status' => 502, 'mime' => '', 'body' => ''];
        }

        if (!$res->isOk() || $res->truncated || $res->body === '') {
            return ['ok' => false, 'status' => 502, 'mime' => '', 'body' => ''];
        }
        // Content-Type gate: solo image/*
        if (!str_starts_with($res->contentType, 'image/')) {
            return ['ok' => false, 'status' => 415, 'mime' => '', 'body' => ''];
        }
        // Magic-bytes: il content-type dichiarato non basta (difesa oltre l'header).
        $mime = $this->sniffRaster($res->body);
        if ($mime === null) {
            return ['ok' => false, 'status' => 415, 'mime' => '', 'body' => ''];
        }

        return ['ok' => true, 'status' => 200, 'mime' => $mime, 'body' => $res->body];
    }

    /** Ritorna il MIME raster reale dai magic-bytes, o null (SVG/altro → rifiutato). */
    private function sniffRaster(string $bin): ?string
    {
        if (strncmp($bin, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($bin, "\x89PNG\x0D\x0A\x1A\x0A", 8) === 0) {
            return 'image/png';
        }
        if (strncmp($bin, 'GIF87a', 6) === 0 || strncmp($bin, 'GIF89a', 6) === 0) {
            return 'image/gif';
        }
        if (strncmp($bin, 'RIFF', 4) === 0 && strlen($bin) >= 12 && substr($bin, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }
}
