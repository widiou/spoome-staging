<?php

namespace Spoome\Domain\Links;

/**
 * Esito immutabile di un fetch via SafeHttpFetcher. `contentType` è già normalizzato (lowercase, senza
 * parametri); `truncated` segnala che il corpo è stato tagliato al cap byte (per l'immagine = rifiuto).
 */
final class FetchResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body,
        public readonly bool $truncated = false,
        public readonly ?string $location = null,
    ) {
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
